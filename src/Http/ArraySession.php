<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * In-memory, request-scoped session storage.
 *
 * Concurrency-safe by construction: no `$_SESSION`, no `session_*()` calls,
 * no shared static state. Each instance owns its data, so overlapping Fibers
 * or workers cannot leak into each other.
 */
final class ArraySession implements SessionInterface
{
    private string $name;
    private ?string $sessionId = null;
    private bool $started = false;

    /**
     * @var array<string,int|bool|string|float|array<mixed>|object|null>
     */
    private array $data = [];

    /**
     * @var array<string,int|bool|string|float|array<mixed>|object|null>
     */
    private array $originalData = [];

    /**
     * @var array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     */
    private array $cookieParams;

    /**
     * @param array<string,mixed> $options Supports 'name' plus cookie params
     *  (lifetime, path, domain, secure, httponly, samesite).
     */
    public function __construct(array $options = [], ?ServerRequestInterface $request = null)
    {
        $name = $options['name'] ?? null;
        if ($name !== null && (!is_string($name) || $name === '')) {
            throw new InvalidArgumentException('Session name must be a string');
        }
        $this->name = $name ?? 'KALYSESSID';

        $secure = $request !== null && $request->getUri()->getScheme() === 'https';
        $lifetime = $options['lifetime'] ?? 0;
        $path = $options['path'] ?? '/';
        $domain = $options['domain'] ?? '';
        $secureOpt = $options['secure'] ?? $secure;
        $httponly = $options['httponly'] ?? true;
        $samesite = $options['samesite'] ?? 'Lax';
        if ($request !== null) {
            $domain = $request->getUri()->getHost();
        } elseif (!is_string($domain)) {
            $domain = '';
        }
        $this->cookieParams = [
            'lifetime' => is_numeric($lifetime) ? (int) $lifetime : 0,
            'path' => is_string($path) ? $path : '/',
            'domain' => $domain,
            'secure' => is_bool($secureOpt) ? $secureOpt : (bool) $secureOpt,
            'httponly' => is_bool($httponly) ? $httponly : (bool) $httponly,
            'samesite' => is_string($samesite) ? $samesite : 'Lax',
        ];

        if ($request) {
            $this->setIdFromRequest($request);
        }
    }

    public function get(string $key, $default = null)
    {
        $this->open();
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, $value): void
    {
        $this->open();
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        $this->open();
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->open();
        $this->data = [];
    }

    public function has(string $key): bool
    {
        $this->open();
        return isset($this->data[$key]);
    }

    public function hasChanged(): bool
    {
        return $this->data !== $this->originalData;
    }

    public function getChanges(): array
    {
        $arr = [];
        foreach ($this->data as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $old = $this->originalData[$k] ?? null;
            if ($old !== $v) {
                $arr[$k] = [$old, $v];
            }
        }
        return $arr;
    }

    public function all(): array
    {
        $this->open();
        return $this->data;
    }

    public function isEmpty(): bool
    {
        return count($this->data) === 0;
    }

    public function jsonSerialize(): object
    {
        return (object) $this->data;
    }

    public function pull(string $key, int|bool|string|float|array|object|null $default = null): int|bool|string|float|array|object|null
    {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }

    public function open(): void
    {
        if ($this->started) {
            return;
        }
        $this->start();
    }

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->sessionId ??= bin2hex(random_bytes(16));
        $this->originalData = $this->data;
    }

    public function close(): bool
    {
        if (!$this->started) {
            return false;
        }
        $this->started = false;
        return true;
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->originalData = [];
        $this->sessionId = null;
        $this->started = false;
    }

    public function discard(): void
    {
        $this->data = $this->originalData;
    }

    public function regenerateId(): void
    {
        $this->open();
        $this->sessionId = bin2hex(random_bytes(16));
    }

    public function isActive(): bool
    {
        return $this->started;
    }

    public function getId(): ?string
    {
        return $this->sessionId === '' ? null : $this->sessionId;
    }

    public function setId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getIdFromRequest(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        $param = $cookies[$this->getName()] ?? null;
        if ($param !== null && !is_string($param)) {
            throw new InvalidArgumentException('Session cookie value must be a string');
        }
        return $param;
    }

    public function setIdFromRequest(ServerRequestInterface $request): void
    {
        $id = $this->getIdFromRequest($request);
        if ($id !== null) {
            $this->setId($id);
        }
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function addToResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        $this->close();

        $id = $this->getId();
        if ($id === null) {
            return $response;
        }

        if ($this->getIdFromRequest($request) === $id) {
            return $response;
        }

        $cookie = SetCookieHeader::build($this->getName(), $id, $this->getCookieParams());

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
