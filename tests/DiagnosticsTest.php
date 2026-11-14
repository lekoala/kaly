<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Closure;
use Kaly\Core\App;
use Kaly\Core\ErrorHandler;
use Kaly\Core\Module;
use Kaly\Router\RouteDefinition;
use Kaly\Router\Router;
use Kaly\Router\Routes;
use Kaly\Router\TrailingSlash;
use Kaly\Tests\Mocks\TestObject2;
use Kaly\Tests\Support\TempDir;
use Kaly\Util\Fs;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerAwareInterface;
use TestModule\Controller\AliasController;
use TestModule\Controller\ShopController;
use Throwable;

/**
 * The detection ladder: each classic mistake is introduced on purpose, and
 * the test locks the step that detects it (boot, table compilation, first
 * resolution) and the information the message carries — never its wording.
 * See docs/versioning.md#detection.
 */
class DiagnosticsTest extends TestCase
{
    private ?string $dir = null;

    protected function tearDown(): void
    {
        ErrorHandler::restoreDefaults();
        if ($this->dir !== null) {
            TempDir::remove($this->dir);
        }
    }

    // #region Helpers

    /**
     * @param Closure(Routes): void $routes
     * @param list<string> $locales
     */
    private static function router(Closure $routes, array $locales = [], bool $localePrefixes = false, bool $localized = false): Router
    {
        $module = (new Module(__DIR__ . '/modules/TestModule'))
            ->mount('shop')
            ->routes($routes);
        if ($localized) {
            $module->localized();
        }
        $module->autoloadFiles();
        return new Router([$module], null, $locales, TrailingSlash::Remove, $localePrefixes);
    }

    private static function match(Router $router, string $path = '/shop/x'): void
    {
        $router->match(new ServerRequest('GET', $path));
    }

    /**
     * Assert the failure happens, and that its message carries each fragment
     */
    private function assertFailsWith(callable $step, string ...$fragments): void
    {
        try {
            $step();
        } catch (Throwable $e) {
            foreach ($fragments as $fragment) {
                $this->assertStringContainsString($fragment, $e->getMessage());
            }
            return;
        }
        $this->fail('The mistake was not detected');
    }

    /**
     * An application with one module per config.php body
     *
     * @param array<string,string> $modules Module folder => body of the config closure
     */
    private function app(array $modules): App
    {
        $this->dir = sys_get_temp_dir() . '/kaly-diagnostics-' . uniqid();
        foreach ($modules as $name => $body) {
            Fs::ensureDir("{$this->dir}/modules/{$name}");
            file_put_contents(
                "{$this->dir}/modules/{$name}/config.php",
                "<?php\nreturn static function (Kaly\\Core\\Module \$module, Kaly\\Di\\Definitions \$di): void {\n{$body}\n};\n",
            );
        }
        return App::create($this->dir);
    }

    // #endregion

    // #region Table compilation

    public function testAMissingMiddlewareFailsWhenTheTableCompiles(): void
    {
        $router = self::router(static function (Routes $routes): void {
            $routes->addDefinition(
                new RouteDefinition(
                    '/x',
                    ShopController::class,
                    'health',
                    name: 'health',
                    middlewares: [
                        'App\\Missing\\Middleware',
                    ],
                ),
            );
        });

        $this->assertFailsWith(static fn() => self::match($router), "module 'test-module'", 'App\Missing\Middleware', "'/x'", "'health'");
    }

    public function testAnUnsatisfiableSignatureFailsWhenTheTableCompiles(): void
    {
        $router = self::router(static function (Routes $routes): void {
            $routes->get('/x', [ShopController::class, 'show']);
        });

        $this->assertFailsWith(static fn() => self::match($router), "'/x'", "'slug'", ShopController::class . '::show');
    }

    public function testADuplicateNameFailsWhenTheTableCompiles(): void
    {
        $router = self::router(static function (Routes $routes): void {
            $routes->get('/x', [ShopController::class, 'health'])->name('twice');
            $routes->get('/y', [AliasController::class, 'hello'])->name('twice');
        });

        $this->assertFailsWith(
            static fn() => self::match($router),
            "'twice'",
            ShopController::class . '::health',
            AliasController::class . '::hello',
        );
    }

    public function testCollidingRoutesFailWhenTheTableCompiles(): void
    {
        // Placeholder names are erased, the requirement is the same
        $router = self::router(static function (Routes $routes): void {
            $routes->get('/x/{id}', [AliasController::class, 'item'])->where('id', '\d+');
            $routes->get('/x/{slug}', [ShopController::class, 'show'])->where('slug', '\d+');
        });

        $this->assertFailsWith(
            static fn() => self::match($router),
            "'/x/{id}'",
            "'/x/{slug}'",
            AliasController::class . '::item',
            ShopController::class . '::show',
        );
    }

    public function testCollisionsCompareCanonicalPatternsNotRegexEquivalence(): void
    {
        // A known limit, kept on purpose: \d+ and [0-9]+ are not proven equal
        $router = self::router(static function (Routes $routes): void {
            $routes->get('/x/{id}', [AliasController::class, 'item'])->name('item')->where('id', '\d+');
            $routes->get('/x/{slug}', [ShopController::class, 'show'])->name('show')->where('slug', '[0-9]+');
        });

        $this->assertSame('/shop/x/1', $router->url('test-module:show', ['slug' => '1']));
    }

    public function testAMissingActionFailsWhenTheTableCompiles(): void
    {
        $router = self::router(static function (Routes $routes): void {
            $routes->get('/x', [ShopController::class, 'missing']);
        });

        $this->assertFailsWith(static fn() => self::match($router), "module 'test-module'", ShopController::class . '::missing');
    }

    public function testAMissingControllerFailsWhenTheTableCompiles(): void
    {
        $router = self::router(static function (Routes $routes): void {
            $routes->get('/x', ['TestModule\Controller\MissingController', 'index']);
        });

        $this->assertFailsWith(static fn() => self::match($router), "module 'test-module'", 'TestModule\Controller\MissingController');
    }

    public function testPathsPerLocaleWithPrefixesNeedALocalizedModule(): void
    {
        $router = self::router(
            static function (Routes $routes): void {
                $routes->get(['fr' => '/medecins', 'nl' => '/artsen'], [ShopController::class, 'health'])->name('doctors');
            },
            ['fr', 'nl'],
            localePrefixes: true,
        );

        $this->assertFailsWith(static fn() => self::match($router, '/shop/medecins'), "'/medecins'", 'fr, nl', 'localized()');
    }

    public function testPathsPerLocaleWithoutPrefixesAreNotAnError(): void
    {
        // Distinct full paths match and generate symmetrically: do not "fix" this
        $router = self::router(static function (Routes $routes): void {
            $routes->get(['fr' => '/medecins', 'nl' => '/artsen'], [ShopController::class, 'health'])->name('doctors');
        }, ['fr', 'nl']);

        foreach (['fr' => '/shop/medecins', 'nl' => '/shop/artsen'] as $locale => $url) {
            $this->assertSame($url, $router->url('test-module:doctors', [], $locale));
            $this->assertSame($locale, $router->match(new ServerRequest('GET', $url))->locale);
        }
    }

    // #endregion

    // #region Boot

    public function testALocalizedModuleWithoutPrefixesFailsAtBoot(): void
    {
        $this->assertFailsWith(
            static fn() => self::router(static fn(Routes $routes) => null, ['fr', 'nl'], localized: true),
            "'test-module'",
            'App::routing(localePrefixes: true)',
            'localized()',
        );
    }

    public function testALocalizedModuleWithoutLocalesFailsAtBoot(): void
    {
        $this->assertFailsWith(
            static fn() => self::router(static fn(Routes $routes) => null, [], localePrefixes: true, localized: true),
            "'test-module'",
            'no locales',
        );
    }

    public function testAMergeConflictFailsAtBootNamingBothModules(): void
    {
        $app = $this->app([
            'Billing' => '$di->set("mailer", stdClass::class);',
            'Shop' => '$di->set("mailer", ArrayObject::class);',
        ]);

        $this->assertFailsWith($app->boot(...), "'mailer'", "'Billing'", "'Shop'", 'rebind()');
    }

    public function testABindingToAMissingClassFailsAtBootWithAssertions(): void
    {
        $app = $this->app(['Shop' => '$di->bind(Psr\Log\LoggerAwareInterface::class, "App\Missing\Logger");']);

        $this->assertFailsWith($app->boot(...), "'Shop'", 'App\Missing\Logger');
    }

    // #endregion

    // #region First resolution

    public function testABindingToAMissingClassFailsAtFirstResolutionWithoutAssertions(): void
    {
        $app = $this->app(['Shop' => '$di->bind(Psr\Log\LoggerAwareInterface::class, "App\Missing\Logger");']);

        $previous = ini_set('zend.assertions', '0');
        try {
            $app->boot();
        } finally {
            ini_set('zend.assertions', (string) $previous);
        }

        $this->assertFailsWith(static fn() => $app->container()->get(LoggerAwareInterface::class), 'App\Missing\Logger');
    }

    public function testAnUnresolvableConstructorFailsAtFirstResolution(): void
    {
        $app = $this->app(['Shop' => ''])->boot();

        $this->assertFailsWith(static fn() => $app->container()->get(TestObject2::class), TestObject2::class, 'v');
    }

    // #endregion
}
