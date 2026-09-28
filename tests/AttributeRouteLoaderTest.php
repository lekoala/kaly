<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\Module;
use Kaly\Router\AttributeRouteLoader;
use Kaly\Router\RouteDefinition;
use Kaly\Router\Routes;
use PHPUnit\Framework\TestCase;
use TestModule\Controller\AttributeDemoController;

class AttributeRouteLoaderTest extends TestCase
{
    public function testLoadsAttributeDefinitionsFromControllers(): void
    {
        require_once __DIR__ . '/modules/TestModule/src/Controller/AttributeDemoController.php';

        $loader = new AttributeRouteLoader();
        $definitions = $loader->load(new Module(__DIR__ . '/modules/TestModule'));

        $names = array_map(static fn(RouteDefinition $d): ?string => $d->name, $definitions);
        $this->assertContains('attr.hello', $names);
        $this->assertContains('attr.hello-legacy', $names);
        $this->assertContains('attr.item', $names);
        $this->assertContains('attr.save', $names);
    }

    public function testRepeatableAttributeExposesSeveralUrls(): void
    {
        require_once __DIR__ . '/modules/TestModule/src/Controller/AttributeDemoController.php';

        $loader = new AttributeRouteLoader();
        $definitions = $loader->load(new Module(__DIR__ . '/modules/TestModule'));

        $hello = array_values(array_filter($definitions, static fn(RouteDefinition $d): bool => $d->action === 'hello'));
        $paths = array_map(static fn(RouteDefinition $d): string => $d->path, $hello);
        sort($paths);
        $this->assertSame(['/attr/hello', '/legacy/hello'], $paths);
    }

    public function testSkipsModulesWithoutControllers(): void
    {
        $loader = new AttributeRouteLoader();
        // InvalidModule has no config.php and no Controller dir: nothing to load.
        $this->assertSame([], $loader->load(new Module(__DIR__ . '/modules/InvalidModule')));
    }

    public function testAttributeEqualsDsl(): void
    {
        require_once __DIR__ . '/modules/TestModule/src/Controller/AttributeDemoController.php';

        $fromDsl = new Routes();
        $fromDsl->get('/attr/hello', [AttributeDemoController::class, 'hello'])->name('attr.hello');
        $fromDsl->get('/legacy/hello', [AttributeDemoController::class, 'hello'])->name('attr.hello-legacy');
        $fromDsl->get('/attr/item/{id}', [AttributeDemoController::class, 'item'])->name('attr.item')->where('id', '\d+');
        $fromDsl->post('/attr/save', [AttributeDemoController::class, 'savePost'])->name('attr.save');
        $fromDsl->get('/attr/priority', [AttributeDemoController::class, 'priority'])->name('attr.priority-low');
        $fromDsl->get('/attr/priority', [AttributeDemoController::class, 'priority'])->name('attr.priority-high')->priority(100);

        $loader = new AttributeRouteLoader();
        $fromAttribute = $loader->load(new Module(__DIR__ . '/modules/TestModule'));
        // Only the demo controller carries attributes in this module.
        $fromAttribute = array_values(array_filter(
            $fromAttribute,
            static fn(RouteDefinition $d): bool => $d->controller === AttributeDemoController::class,
        ));

        $this->assertEquals($this->sorted($fromDsl->definitions()), $this->sorted($fromAttribute));
    }

    /**
     * The architectural boundary: whatever an attribute expresses must be
     * expressible through Routes, so both sources compile to the same model.
     *
     * @param list<RouteDefinition> $definitions
     * @return list<RouteDefinition>
     */
    private function sorted(array $definitions): array
    {
        usort($definitions, static fn(RouteDefinition $a, RouteDefinition $b): int => [$a->name, $a->path] <=> [$b->name, $b->path]);
        return $definitions;
    }
}
