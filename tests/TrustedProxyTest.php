<?php

declare(strict_types=1);

namespace Kaly\Tests;

use InvalidArgumentException;
use Kaly\Http\Middleware\TrustedProxy;
use Kaly\Http\RequestUtils;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TrustedProxyTest extends TestCase
{
    /**
     * @param list<string> $proxies
     * @param array<string,string> $headers
     */
    private function forward(
        array $proxies,
        string $remote,
        array $headers,
        string $url = 'http://backend.local:8080/path?q=1',
    ): ServerRequestInterface {
        $handler = new class implements RequestHandlerInterface {
            public ?ServerRequestInterface $request = null;

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->request = $request;
                return new Response();
            }
        };
        $request = new ServerRequest('GET', $url, $headers, null, '1.1', ['REMOTE_ADDR' => $remote]);
        (new TrustedProxy($proxies))->process($request, $handler);
        $this->assertNotNull($handler->request);
        return $handler->request;
    }

    public function testATrustedProxyRestoresSchemeHostAndClient(): void
    {
        $request = $this->forward(['10.0.0.0/8'], '10.1.2.3', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'example.test',
            'X-Forwarded-For' => '203.0.113.7',
        ]);

        $this->assertSame('https://example.test/path?q=1', (string) $request->getUri());
        $this->assertSame('example.test', $request->getHeaderLine('Host'));
        $this->assertSame('203.0.113.7', RequestUtils::getIp($request));
    }

    public function testAnUntrustedPeerIsIgnored(): void
    {
        $request = $this->forward(['10.0.0.0/8'], '198.51.100.1', [
            'X-Forwarded-Proto' => 'https',
            'X-Forwarded-Host' => 'evil.test',
            'X-Forwarded-For' => '203.0.113.7',
        ]);

        $this->assertSame('http://backend.local:8080/path?q=1', (string) $request->getUri());
        $this->assertSame('198.51.100.1', RequestUtils::getIp($request));
    }

    public function testTheClientIsTheNearestUntrustedHop(): void
    {
        // The leftmost entry is client input: only the trusted chain is believed
        $request = $this->forward(['10.0.0.1', '192.0.2.0/24'], '10.0.0.1', [
            'X-Forwarded-For' => '1.1.1.1, 203.0.113.7, 192.0.2.10',
        ]);
        $this->assertSame('203.0.113.7', RequestUtils::getIp($request));

        $request = $this->forward(['10.0.0.1'], '10.0.0.1', ['X-Forwarded-For' => 'garbage, 203.0.113.7']);
        $this->assertSame('203.0.113.7', RequestUtils::getIp($request));

        $request = $this->forward(['10.0.0.1'], '10.0.0.1', ['X-Forwarded-For' => '203.0.113.7, garbage']);
        $this->assertSame('10.0.0.1', RequestUtils::getIp($request));
    }

    public function testPorts(): void
    {
        // A scheme change drops the backend port
        $request = $this->forward(['10.0.0.1'], '10.0.0.1', ['X-Forwarded-Proto' => 'https']);
        $this->assertSame('https://backend.local/path?q=1', (string) $request->getUri());

        $request = $this->forward(['10.0.0.1'], '10.0.0.1', ['X-Forwarded-Proto' => 'https', 'X-Forwarded-Port' => '8443']);
        $this->assertSame('https://backend.local:8443/path?q=1', (string) $request->getUri());

        $request = $this->forward(['10.0.0.1'], '10.0.0.1', ['X-Forwarded-Host' => 'example.test:8443']);
        $this->assertSame('http://example.test:8443/path?q=1', (string) $request->getUri());

        $request = $this->forward(['::1'], '::1', ['X-Forwarded-Host' => '[2001:db8::1]:8443']);
        $this->assertSame('[2001:db8::1]', $request->getUri()->getHost());
        $this->assertSame(8443, $request->getUri()->getPort());
    }

    public function testInvalidValuesAreIgnored(): void
    {
        $request = $this->forward(['10.0.0.1'], '10.0.0.1', [
            'X-Forwarded-Proto' => 'javascript',
            'X-Forwarded-Host' => 'evil.test/path',
            'X-Forwarded-Port' => '99999',
        ]);
        $this->assertSame('http://backend.local:8080/path?q=1', (string) $request->getUri());
    }

    public function testIpv6Ranges(): void
    {
        $request = $this->forward(['fd00::/8'], 'fd12::1', ['X-Forwarded-Proto' => 'https']);
        $this->assertSame('https', $request->getUri()->getScheme());

        $request = $this->forward(['fd00::/8'], 'fe80::1', ['X-Forwarded-Proto' => 'https']);
        $this->assertSame('http', $request->getUri()->getScheme());
    }

    public function testInvalidProxiesFailEarly(): void
    {
        $invalid = ['not-an-ip', '10.0.0.0/33', '10.0.0.0/x', '::1/129'];
        $refused = 0;
        foreach ($invalid as $proxy) {
            try {
                new TrustedProxy([$proxy]);
            } catch (InvalidArgumentException) {
                $refused++;
            }
        }
        $this->assertSame(count($invalid), $refused);
    }
}
