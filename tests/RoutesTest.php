<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Ex;
use Kaly\Router\Routes;
use Kaly\Tests\Mocks\DispatcherController;
use Kaly\Tests\Mocks\RouteHandlerFixture;
use PHPUnit\Framework\TestCase;

class RoutesTest extends TestCase
{
    public function testGetBuildsADefinition(): void
    {
        $routes = new Routes();
        $routes->get('/patients/{id}', [DispatcherController::class, 'stringResult']);

        [$definition] = $routes->definitions();
        $this->assertSame('/patients/{id}', $definition->path);
        $this->assertSame(DispatcherController::class, $definition->controller);
        $this->assertSame('stringResult', $definition->action);
        $this->assertSame(['GET'], $definition->methods);
    }

    public function testAGroupedRouteCanStillBeNamedFromOutsideItsCallback(): void
    {
        $routes = new Routes();
        $pending = null;

        $routes
            ->prefix('/api')
            ->group(function (Routes $scoped) use (&$pending): void {
                $pending = $scoped->get('/thing', [RouteHandlerFixture::class, 'show']);
            });

        // The handle outlives the callback that created it: a draft addressed by
        // index used to be absorbed as a copy, silently dropping this
        $pending?->name('thing');

        [$definition] = $routes->definitions();
        $this->assertSame('/api/thing', $definition->path);
        $this->assertSame('thing', $definition->name);
    }

    public function testFluentConfiguration(): void
    {
        $routes = new Routes();
        $routes
            ->get('/patients/{id}', [DispatcherController::class, 'stringResult'])
            ->name('patient.show')
            ->where('id', '\d+')
            ->middleware('AuthMiddleware')
            ->default('tab', 'info')
            ->priority(10);

        [$definition] = $routes->definitions();
        $this->assertSame('patient.show', $definition->name);
        $this->assertSame(['id' => '\d+'], $definition->requirements);
        $this->assertSame(['AuthMiddleware'], $definition->middlewares);
        $this->assertSame(['tab' => 'info'], $definition->defaults);
        $this->assertSame(10, $definition->priority);
    }

    public function testGroupPrefixAndSharedMiddleware(): void
    {
        $routes = new Routes();
        $routes
            ->prefix('/staff')
            ->middleware('StaffMiddleware')
            ->group(function (Routes $routes): void {
                $routes->get('/agenda', [DispatcherController::class, 'stringResult'])->name('staff.agenda');
            });

        [$definition] = $routes->definitions();
        $this->assertSame('/staff/agenda', $definition->path);
        $this->assertSame(['StaffMiddleware'], $definition->middlewares);
    }

    public function testStringGroupPrefix(): void
    {
        $routes = new Routes();
        $routes->group('/api', function (Routes $routes): void {
            $routes->post('/patients', [DispatcherController::class, 'stringResult']);
        });

        [$definition] = $routes->definitions();
        $this->assertSame('/api/patients', $definition->path);
        $this->assertSame(['POST'], $definition->methods);
    }

    public function testMapAcceptsSeveralMethods(): void
    {
        $routes = new Routes();
        $routes->map(['GET', 'HEAD'], '/x', [DispatcherController::class, 'stringResult']);

        [$definition] = $routes->definitions();
        $this->assertSame(['GET', 'HEAD'], $definition->methods);
    }

    public function testHandlerStringForms(): void
    {
        $this->assertSame([RouteHandlerFixture::class, 'show'], Routes::normalizeHandler(RouteHandlerFixture::class . '::show'));
        $this->assertSame([RouteHandlerFixture::class, '__invoke'], Routes::normalizeHandler(RouteHandlerFixture::class));
    }

    public function testHandlerMustExistAndBeAdmissible(): void
    {
        $this->expectException(Ex::class);
        Routes::normalizeHandler([RouteHandlerFixture::class, 'missing']);
    }

    public function testHandlerRejectsProtectedMethods(): void
    {
        $this->expectException(Ex::class);
        Routes::normalizeHandler([RouteHandlerFixture::class, 'hidden']);
    }

    public function testHandlerRejectsStaticMethods(): void
    {
        $this->expectException(Ex::class);
        Routes::normalizeHandler([RouteHandlerFixture::class, 'staticAction']);
    }

    public function testHandlerRejectsMagicMethods(): void
    {
        $this->expectException(Ex::class);
        Routes::normalizeHandler([RouteHandlerFixture::class, '__construct']);
    }

    public function testNestedGroupsAccumulatePrefixAndMiddlewares(): void
    {
        $routes = new Routes();
        $routes
            ->prefix('/api')
            ->middleware('Auth')
            ->group(function (Routes $routes): void {
                $routes
                    ->middleware('Inner')
                    ->group(function (Routes $routes): void {
                        $routes
                            ->get('/users/{id}', [RouteHandlerFixture::class, 'show'])
                            ->name('users.show')
                            ->where('id', '\d+')
                            ->default('tab', 'info')
                            ->priority(5)
                            ->middleware('Route');
                    });
            });

        [$definition] = $routes->definitions();
        $this->assertSame('/api/users/{id}', $definition->path);
        $this->assertSame(['Auth', 'Inner', 'Route'], $definition->middlewares);
        $this->assertSame(['id' => '\d+'], $definition->requirements);
        $this->assertSame(['tab' => 'info'], $definition->defaults);
        $this->assertSame(5, $definition->priority);
        $this->assertSame('users.show', $definition->name);
    }

    public function testStringGroupInsideScopedGroup(): void
    {
        $routes = new Routes();
        $routes
            ->prefix('/api')
            ->group(function (Routes $routes): void {
                $routes->group('/v2', function (Routes $routes): void {
                    $routes->get('/users', [RouteHandlerFixture::class, 'show']);
                });
            });

        [$definition] = $routes->definitions();
        $this->assertSame('/api/v2/users', $definition->path);
    }

    public function testDeclaringDirectlyOnAScopedViewFails(): void
    {
        $routes = new Routes();
        // A scope only declares through group(); a direct get() would write
        // into a view nobody reads and silently drop the route
        $this->expectException(Ex::class);
        $routes->prefix('/api')->get('/x', [DispatcherController::class, 'stringResult']);
    }
}
