<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Ex;
use Kaly\Core\Module;
use Kaly\Router\Router;
use Kaly\Router\RouterInterface;
use Kaly\Tests\Support\HttpFactory;
use Nyholm\Psr7\Uri;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Modules configure themselves with a closure and are routable by convention
 * without any incantation.
 */
class ModuleConventionTest extends TestCase
{
    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
    }

    private function get(App $app, string $path): ResponseInterface
    {
        return $app->handle(HttpFactory::createRequestFromGlobals()->withUri(new Uri($path)));
    }

    public function testEveryModuleIsMountedUnderItsName(): void
    {
        $app = App::create(__DIR__);

        $this->assertSame('mapped', (string) $this->get($app, '/mapped-module/')->getBody());
        $router = $app->get(RouterInterface::class);
        $this->assertInstanceOf(Router::class, $router);
        $this->assertSame(
            ['*' => ['lang-module' => 'lang-module', 'mapped-module' => 'mapped-module', 'test-module' => 'test-module']],
            $router->getMounts(),
        );
    }

    public function testAModuleCanChooseItsMountOrOptOut(): void
    {
        $app = App::create(__DIR__ . '/data/apps/private', false);

        $this->assertSame('shop', (string) $this->get($app, '/boutique/')->getBody());
        $this->assertSame(404, $this->get($app, '/shop/')->getStatusCode());
        $this->assertSame(404, $this->get($app, '/hidden/')->getStatusCode());
    }

    public function testAConfigUsingThisIsRefusedWithAHint(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Module 'LegacyModule' config.php uses \$this");

        (new Module(__DIR__ . '/data/modules/LegacyModule'))->loadConfig();
    }

    public function testAConfigMustReturnAClosure(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage('config.php must return a function');

        (new Module(__DIR__ . '/data/modules/BadReturnModule'))->loadConfig();
    }

    public function testDefinitionsAreLockedAfterTheConfig(): void
    {
        $module = new Module(__DIR__ . '/modules/TestModule');
        $module->loadConfig();

        $this->assertTrue($module->definitions()->isLocked());
    }

    public function testAMountIsASingleCanonicalSegment(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage('must be a single lowercase url segment');

        $module = new Module(__DIR__ . '/modules/MappedModule');
        $module->mount('Mapped');
        new Router([$module]);
    }

    public function testASegmentCannotBeMountedTwice(): void
    {
        $this->expectException(Ex::class);
        $this->expectExceptionMessage("Segment 'shared' is mounted by both");

        $a = (new Module(__DIR__ . '/modules/MappedModule'))->mount('shared');
        $b = (new Module(__DIR__ . '/modules/LangModule'))->mount('shared');
        new Router([$a, $b]);
    }

    public function testABootFailureStillProducesAServerError(): void
    {
        $this->expectOutputString('Server error');

        App::create(__DIR__ . '/data/apps/broken', false)->debug(false)->run();
    }
}
