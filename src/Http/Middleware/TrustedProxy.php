<?php

declare(strict_types=1);

namespace Kaly\Http\Middleware;

use InvalidArgumentException;
use Kaly\Http\RequestUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Restores what the client sent from the X-Forwarded-* headers of a trusted
 * reverse proxy: scheme, host, port and client ip.
 *
 * The headers are only read when REMOTE_ADDR is one of the trusted proxies,
 * anyone else can forge them. The trusted proxy must overwrite them rather
 * than append to client values: the first X-Forwarded-Proto/Host/Port value
 * wins, and the client ip is the nearest X-Forwarded-For entry that is not a
 * trusted proxy itself. An invalid value is ignored, never half applied.
 *
 * Runs in the incoming band, before anything reads the uri: the session and
 * application cookies derive Secure from the restored scheme.
 *
 * ```php
 * $app->middleware()->incoming(TrustedProxy::class);
 * $definitions->set(TrustedProxy::class, static fn(): TrustedProxy => new TrustedProxy(['10.0.0.0/8']));
 * ```
 */
final readonly class TrustedProxy implements MiddlewareInterface
{
    private const HOST = '/^(?:\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?)*)(?::(\d{1,5}))?$/';

    /**
     * @var list<array{string,int}> Packed network address and prefix length
     */
    private array $networks;

    /**
     * @param list<string> $proxies Ip addresses or CIDR ranges (`10.0.0.0/8`, `fd00::/8`)
     */
    public function __construct(array $proxies)
    {
        $networks = [];
        foreach ($proxies as $proxy) {
            $networks[] = self::network($proxy);
        }
        $this->networks = $networks;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $remote = RequestUtils::getServerParam($request, 'REMOTE_ADDR') ?? '';
        if (!$this->isTrusted($remote)) {
            return $handler->handle($request);
        }

        $uri = $request->getUri();
        $scheme = $uri->getScheme();
        $proto = strtolower(self::first($request, 'X-Forwarded-Proto'));
        $portChanged = false;
        if ($proto === 'http' || $proto === 'https') {
            $portChanged = $proto !== $scheme;
            $uri = $uri->withScheme($proto);
        }

        $host = self::first($request, 'X-Forwarded-Host');
        if (preg_match(self::HOST, $host, $matches) === 1) {
            $port = isset($matches[1]) ? self::port($matches[1]) : null;
            $uri = $uri->withHost(isset($matches[1]) ? substr($host, 0, -strlen($matches[1]) - 1) : $host)->withPort($port);
            $portChanged = false;
        }

        $port = self::port(self::first($request, 'X-Forwarded-Port'));
        if ($port !== null) {
            $uri = $uri->withPort($port);
        } elseif ($portChanged) {
            // The backend port means nothing to the client: use the default
            $uri = $uri->withPort(null);
        }

        return $handler->handle($request->withUri(
            $uri,
            preserveHost: false,
        )->withAttribute(RequestUtils::CLIENT_IP_ATTRIBUTE, $this->clientIp($request, $remote)));
    }

    /**
     * Walk X-Forwarded-For from the nearest hop: the first entry that is not
     * a trusted proxy is the client.
     */
    private function clientIp(ServerRequestInterface $request, string $remote): string
    {
        $client = $remote;
        $hops = array_map(trim(...), explode(',', $request->getHeaderLine('X-Forwarded-For')));
        foreach (array_reverse($hops) as $hop) {
            if (filter_var($hop, FILTER_VALIDATE_IP) === false) {
                break;
            }
            $client = $hop;
            if (!$this->isTrusted($hop)) {
                break;
            }
        }
        return $client;
    }

    private function isTrusted(string $ip): bool
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return false;
        }
        foreach ($this->networks as [$network, $prefix]) {
            if (strlen($network) !== strlen($packed)) {
                continue;
            }
            $bytes = intdiv($prefix, 8);
            if (substr($packed, 0, $bytes) !== substr($network, 0, $bytes)) {
                continue;
            }
            $bits = $prefix % 8;
            if ($bits === 0) {
                return true;
            }
            $mask = (0xFF << (8 - $bits)) & 0xFF;
            if ((ord($packed[$bytes]) & $mask) === (ord($network[$bytes]) & $mask)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{string,int}
     */
    private static function network(string $proxy): array
    {
        $ip = $proxy;
        $prefix = null;
        if (str_contains($proxy, '/')) {
            [$ip, $prefix] = explode('/', $proxy, 2);
        }
        $packed = @inet_pton($ip);
        $max = $packed === false ? 0 : strlen($packed) * 8;
        if ($packed === false || $prefix !== null && (!ctype_digit($prefix) || (int) $prefix > $max)) {
            throw new InvalidArgumentException("Invalid trusted proxy '{$proxy}', expected an ip address or a CIDR range");
        }
        return [$packed, $prefix === null ? $max : (int) $prefix];
    }

    private static function first(ServerRequestInterface $request, string $header): string
    {
        return trim(explode(',', $request->getHeaderLine($header))[0]);
    }

    private static function port(string $value): ?int
    {
        if (!ctype_digit($value) || strlen($value) > 5) {
            return null;
        }
        $port = (int) $value;
        return $port >= 1 && $port <= 65_535 ? $port : null;
    }
}
