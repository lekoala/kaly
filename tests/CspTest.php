<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\HttpContext;
use Kaly\Core\RequestDispatcher;
use Kaly\Core\RoutingHandler;
use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Http\Csp\Csp;
use Kaly\I18n\LocaleResolver;
use Kaly\I18n\Translator;
use Kaly\Router\Route;
use Kaly\Router\RouterInterface;
use Kaly\Tests\Mocks\DispatcherController;
use Kaly\View\RendererInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ServerRequestInterface;

/**
 * One lazily generated nonce per response, shared by templates and the
 * outgoing header: two cycles never agree, one cycle never disagrees.
 */
class CspTest extends TestCase
{
    public function testNonceIsStableAndUrlSafe(): void
    {
        $csp = new Csp();
        $nonce = $csp->nonce();

        $this->assertSame($nonce, $csp->nonce());
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{24}$/', $nonce);
    }

    public function testTwoInstancesNeverShareANonce(): void
    {
        $this->assertNotSame((new Csp())->nonce(), (new Csp())->nonce());
    }

    public function testTwoContextsNeverShareANonce(): void
    {
        $first = new HttpContext(new ServerRequest('GET', '/'));
        $second = new HttpContext(new ServerRequest('GET', '/'));

        $this->assertSame($first->csp(), $first->csp());
        $this->assertSame($first->csp()->nonce(), $first->csp()->nonce());
        $this->assertNotSame($first->csp()->nonce(), $second->csp()->nonce());
    }

    public function testDispatcherExposesTheSameObject(): void
    {
        $renderer = new CapturingRenderer();
        $router = new class implements RouterInterface {
            public function match(ServerRequestInterface $request): Route
            {
                return new Route(controller: DispatcherController::class, action: 'viewResult', locale: 'en');
            }

            public function url(string $name, array $params = [], ?string $locale = null): string
            {
                return '/';
            }

            public function urlFor(string|array $handler, array $params = [], ?string $locale = null): string
            {
                return '';
            }
        };
        $factory = new Psr17Factory();
        $dispatcher = new RequestDispatcher(
            new Injector(new Container(new Definitions())),
            (new Translator('en'))->addPath(__DIR__ . '/data/lang'),
            $factory,
            $factory,
            $renderer,
        );
        $routing = new RoutingHandler($router, new LocaleResolver('en', ['en']), $dispatcher);

        $ctx = new HttpContext(new ServerRequest('GET', '/'));
        $response = $routing->handle($ctx->bind(new ServerRequest('GET', '/')));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($ctx->csp(), $renderer->csp);
        $this->assertStringContainsString('nonce="' . $ctx->csp()->nonce() . '"', (string) $response->getBody());
    }
}

final class CapturingRenderer implements RendererInterface
{
    public ?Csp $csp = null;

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = []): string
    {
        $csp = $data['csp'] ?? null;

        if ($csp instanceof Csp) {
            $this->csp = $csp;

            return '<script nonce="' . $csp->nonce() . '">';
        }

        return '';
    }
}
