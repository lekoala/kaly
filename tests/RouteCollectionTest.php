<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Ex;
use Kaly\Router\RouteCollection;
use Kaly\Router\RouteDefinition;
use Kaly\Router\Routes;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use PHPUnit\Framework\TestCase;

/**
 * Build-time rules of the explicit table: absolute name uniqueness,
 * fail-fast on equivalent match spaces, deterministic precedence.
 */
class RouteCollectionTest extends TestCase
{
    /**
     * @param array{
     *     path?:string,
     *     action?:string,
     *     methods?:list<string>,
     *     name?:string|null,
     *     requirements?:array<string,string>,
     *     defaults?:array<string,mixed>,
     *     middlewares?:list<class-string>,
     *     priority?:int
     * } $overrides
     */
    private function definition(string $path, string $action = 'show', array $overrides = []): RouteDefinition
    {
        return new RouteDefinition(
            $overrides['path'] ?? $path,
            RouteHandlerFixture::class,
            $overrides['action'] ?? $action,
            $overrides['methods'] ?? ['GET'],
            $overrides['name'] ?? null,
            $overrides['requirements'] ?? [],
            $overrides['defaults'] ?? [],
            $overrides['middlewares'] ?? [],
            $overrides['priority'] ?? 0,
        );
    }

    public function testDuplicateNamesFailFastWhateverThePriority(): void
    {
        $this->expectException(Ex::class);
        new RouteCollection([
            $this->definition('/a', 'show', ['name' => 'same', 'priority' => 10]),
            $this->definition('/b', 'show', ['name' => 'same', 'priority' => 20]),
        ]);
    }

    public function testDuplicateNamesFailFastOnIdenticalPaths(): void
    {
        $this->expectException(Ex::class);
        new RouteCollection([
            $this->definition('/a', 'show', ['name' => 'same']),
            $this->definition('/a', 'show', ['name' => 'same']),
        ]);
    }

    public function testTextuallyIdenticalRoutesCollide(): void
    {
        $this->expectException(Ex::class);
        new RouteCollection([
            $this->definition('/users/{id}', 'show'),
            $this->definition('/users/{id}', 'show'),
        ]);
    }

    public function testEquivalentPatternsCollideDespiteDifferentPlaceholderNames(): void
    {
        $this->expectException(Ex::class);
        new RouteCollection([
            $this->definition('/users/{id}', 'show'),
            $this->definition('/users/{slug}', 'show'),
        ]);
    }

    public function testDifferingRequirementsDoNotCollide(): void
    {
        $collection = new RouteCollection([
            $this->definition('/users/{id}', 'show', ['requirements' => ['id' => '\d+']]),
            $this->definition('/users/{slug}', 'show', ['requirements' => ['slug' => '[a-z]+']]),
        ]);
        $this->assertSame(2, $collection->count());
    }

    public function testDisjointMethodsDoNotCollide(): void
    {
        $routes = new Routes();
        $routes->get('/users/{id}', [RouteHandlerFixture::class, 'show']);
        $routes->post('/users/{id}', [RouteHandlerFixture::class, 'show']);
        $this->assertSame(2, (new RouteCollection($routes->definitions()))->count());
    }

    public function testDifferentPrioritiesResolveTheCollision(): void
    {
        $collection = new RouteCollection([
            $this->definition('/users/{id}', 'show', ['priority' => 0]),
            $this->definition('/users/{slug}', 'show', ['priority' => 10]),
        ]);
        $this->assertSame(2, $collection->count());
        $entries = $collection->entries();
        $this->assertSame('/users/{slug}', $entries[0]['definition']->path);
    }

    public function testMutatingADefinitionAfterCompilationDoesNotAffectTheTable(): void
    {
        $definition = $this->definition('/before', 'show', ['name' => 'test']);
        $collection = new RouteCollection([$definition]);

        // The declaration is still the mutable DSL object...
        $definition->path = '/after';
        $definition->name = 'changed';

        // ...but the table kept its own copy
        $this->assertSame('/before', $collection->entries()[0]['definition']->path);
        $this->assertNotNull($collection->byName('test'));
        $this->assertNull($collection->byName('changed'));
    }

    public function testByNameReturnsACopyThatCannotMutateTheTable(): void
    {
        $collection = new RouteCollection([
            $this->definition('/before', 'show', ['name' => 'test']),
        ]);

        $found = $collection->byName('test');
        $this->assertNotNull($found);
        $found->path = '/evil';

        $this->assertSame('/before', $collection->byName('test')?->path);
        $this->assertSame('/before', $collection->entries()[0]['definition']->path);
    }

    public function testEmptyMethodsOverlapEverything(): void
    {
        $routes = new Routes();
        $routes->map([], '/users/{id}', [RouteHandlerFixture::class, 'show']);
        $routes->get('/users/{slug}', [RouteHandlerFixture::class, 'show']);
        $this->expectException(Ex::class);
        new RouteCollection($routes->definitions());
    }

    public function testToArrayExposesMatchingOrder(): void
    {
        $collection = new RouteCollection([
            $this->definition('/users/{id}', 'show', ['name' => 'low', 'priority' => 0]),
            $this->definition('/users/{slug}', 'show', ['name' => 'high', 'priority' => 10]),
        ]);
        $rows = $collection->toArray();
        $this->assertSame('high', $rows[0]['name']);
        $this->assertSame('low', $rows[1]['name']);
        $this->assertSame(['path', 'locale', 'methods', 'name', 'controller', 'action', 'priority', 'middlewares'], array_keys($rows[0]));
    }

    public function testSnapshotsDoNotMutateTheCollection(): void
    {
        $collection = new RouteCollection([
            $this->definition('/users/{id}', 'show', ['name' => 'one']),
        ]);
        $entries = $collection->entries();
        $entries[] = $entries[0];
        unset($entries[0]);
        $rows = $collection->toArray();
        $rows[] = $rows[0];

        $this->assertSame(1, $collection->count());
        $this->assertCount(1, $collection->entries());
        $this->assertCount(1, $collection->toArray());
    }
}
