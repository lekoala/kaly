<?php

declare(strict_types=1);

use Kaly\Core\App;
use Kaly\Core\Module;
use Kaly\Di\Definitions;
use Kaly\Log\FileLogger;
use Kaly\Router\Routes;
use Kaly\Tests\Mocks\DenyMiddleware;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
use Kaly\Tests\Mocks\TraceGroupMiddleware;
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;
use TestModule\Controller\AliasController;
use TestModule\Controller\GuardedController;
use TestModule\Controller\ShopController;

$value_is_not_leaked = 'test';

// PSR-17 factories are discovered (nyholm/psr7 is installed)
return static function (Module $module, Definitions $di): void {
    $di->bind(TestInterface::class, TestObject::class)->set(
        RendererInterface::class,
        new KalyTplRenderer(new ViewEngine(__DIR__ . '/templates')),
    )->set(App::DEBUG_LOGGER, function (): FileLogger {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        if (!is_string($script)) {
            $script = '';
        }
        $pos = strpos($script, 'vendor' . DIRECTORY_SEPARATOR . 'bin');
        $basePath = $pos === false ? '' : substr($script, 0, $pos);
        return new FileLogger("{$basePath}/tests.log");
    });

    // Local routes, below /test-module/
    $module->routes(function (Routes $routes): void {
        $routes->get('/alias/hello', [AliasController::class, 'hello'])->name('alias.hello');
        $routes->get('/legacy/hello', [AliasController::class, 'hello'])->name('alias.hello-legacy');
        $routes->get('/alias/item/{id}', [AliasController::class, 'item'])->name('alias.item')->where('id', '\d+');
        $routes->post('/alias/save', [AliasController::class, 'savePost'])->name('alias.save');
        $routes->get('/alias/priority', [AliasController::class, 'priority'])->name('alias.priority-low');
        $routes->get('/alias/priority', [AliasController::class, 'priority'])->name('alias.priority-high')->priority(100);

        // Route middlewares are enforced by the framework
        $routes
            ->prefix('/locked')
            ->middleware(DenyMiddleware::class)
            ->group(function (Routes $routes): void {
                $routes->get('/health', [ShopController::class, 'health'])->name('locked.health');
            });
        $routes
            ->prefix('/guarded')
            ->middleware(TraceGroupMiddleware::class)
            ->group(function (Routes $routes): void {
                $routes->get('/trace', [GuardedController::class, 'index'])->name('guarded.trace');
            });
    });

    // Paths owned outside of the module segment
    $module->claim('/shop', function (Routes $routes): void {
        $routes->get('/{slug}', [ShopController::class, 'show'])->name('shop.show')->where('slug', '[a-z-]+');
        $routes->post('/{slug}/buy', [ShopController::class, 'buyPost'])->name('shop.buy');
        // Defaults are generate-only: every placeholder still matches literally.
        $routes->get('/featured/{tab}', [ShopController::class, 'featured'])->name('shop.featured')->default('tab', 'all');
    });
    $module->claim('/api', function (Routes $routes): void {
        $routes->get('/health', [ShopController::class, 'health'])->name('api.health');
    });
};
