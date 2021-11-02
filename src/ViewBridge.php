<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Interfaces\RouterInterface;
use RuntimeException;

/**
 * Configure and use view rendering engines
 */
class ViewBridge
{
    /**
     * Add this to your composer scripts
     *
     * "modules-publish": [
     *   "Kaly\\ViewBridge::composerPublishModulesAssets"
     * ]
     *
     * @phpstan-ignore-next-line
     * @param \Composer\Script\Event $event
     */
    public static function composerPublishModulesAssets($event): void
    {
        // @phpstan-ignore-next-line
        $vendorDir = $event->getComposer()->getConfig()->get('vendor-dir');
        require $vendorDir . '/autoload.php';

        $baseDir = dirname($vendorDir);
        $app = new App($baseDir, false);
        self::publishModulesAssets($app);
    }

    /**
     * @return array<string, int>
     */
    public static function publishModulesAssets(App $app): array
    {
        $modules = $app->getModules();
        $result = [];
        foreach ($modules as $module) {
            $clientDir = $app->getClientModuleDir($module);
            if (!is_dir($clientDir)) {
                $result[$module] = 0;
                continue;
            }
            $publicResourceDir = $app->getResourceDir($module, true);

            // Copy all assets
            $files = glob_recursive($clientDir . '/*');
            $i = 0;
            foreach ($files as $file) {
                $baseFile = str_replace($clientDir, '', $file);
                $destFile = $publicResourceDir . DIRECTORY_SEPARATOR . $baseFile;
                $destFileDir = dirname($destFile);
                if (!is_dir($destFileDir)) {
                    mkdir($destFileDir, 0755, true);
                }
                copy($file, $destFile);
                $i++;
            }
            $result[$module] = $i;
        }
        return $result;
    }

    /**
     * @return array<string, \Closure>
     */
    protected static function getFunctions(App $app): array
    {
        return [
            't' => function (string $message, array $parameters = [], string $domain = null) {
                return t($message, $parameters, $domain);
            },
            'set_base_domain' => function (string $domain) use ($app) {
                /** @var Translator $translator  */
                $translator = $app->getDi()->get(Translator::class);
                $translator->setBaseDomain($domain);
            },
            'asset' => function (string $file) use ($app) {
                $route = $app->getRequest()->getAttribute(App::ROUTE_REQUEST_ATTR);
                if (!$route || !is_array($route)) {
                    throw new RuntimeException("Invalid route request attribute");
                }
                /** @var string $module  */
                $module = $route[RouterInterface::MODULE] ?? '';
                $resourcesFolder = App::RESOURCES_FOLDER;

                // Copy on the fly requested assets
                if ($app->getDebug()) {
                    $publicResource = $app->getResourceDir($module, true) . DIRECTORY_SEPARATOR . $file;
                    $sourceFile = $app->getClientModuleDir($module) . DIRECTORY_SEPARATOR . $file;
                    if (is_file($sourceFile)) {
                        // File is overwritten if it already exists
                        copy($sourceFile, $publicResource);
                    }
                }

                return "/$resourcesFolder/$module/$file";
            }
        ];
    }

    /**
     * @param array<string, mixed> $definitions
     */
    public static function configureTwig(App $app, array &$definitions): void
    {
        $newDefinitions = [];
        // Twig has CoreExtension, EscaperExtension and OptimizerExtension loaded by default
        if ($app->getDebug()) {
            // @link https://twig.symfony.com/doc/3.x/functions/dump.html
            $newDefinitions[\Twig\Environment::class . '->'][] = function (\Twig\Environment $twig) {
                $twig->enableDebug();
                // Adds dump function to templates
                $twig->addExtension(new \Twig\Extension\DebugExtension());
            };
        }
        $newDefinitions[\Twig\Environment::class . '->'][] = function (\Twig\Environment $twig) use ($app) {
            foreach (self::getFunctions($app) as $functionName => $closure) {
                $twig->addFunction(new \Twig\TwigFunction($functionName, $closure));
            }
            // We define early to make sure they are compiled
            $twig->addGlobal("_config", null);
            $twig->addGlobal("_route", null);
            $twig->addGlobal("_controller", null);
            // We need a cache to make this faster
            if (!$app->getDebug()) {
                $twig->setCache($app->makeTemp('twig'));
            }
        };
        $definitions = array_merge_distinct($definitions, $newDefinitions);
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $body
     */
    public static function renderTwig(Di $di, array $route, array $body = []): ?string
    {
        // Check if we have a twig instance
        if (!$di->has(\Twig\Loader\LoaderInterface::class)) {
            return null;
        }
        /** @var \Twig\Environment $twig  */
        $twig = $di->get(\Twig\Environment::class);

        // Set some globals to allow pulling data from our controller
        // Defaults globals are _self, _context, _charset
        $twig->addGlobal("_config", $di->get(SiteConfig::class));
        $twig->addGlobal("_route", $route);

        $controller = $route['controller'];
        if ($controller && is_string($controller)) {
            $twig->addGlobal("_controller", $di->get($controller));
        }

        // Build view path based on route parameters
        /** @var string $viewFile  */
        $viewFile = $route['template'];
        if (!str_ends_with($viewFile, '.twig')) {
            $viewFile .= ".twig";
        }
        // If we have a view, render with body as context
        if (!$twig->getLoader()->exists($viewFile)) {
            return null;
        }
        $context = $body ? $body : [];
        $body = $twig->render($viewFile, $context);
        return $body;
    }

    /**
     * @param array<string, mixed> $definitions
     */
    public static function configurePlates(App $app, array &$definitions): void
    {
        $newDefinitions = [];
        $newDefinitions[\League\Plates\Engine::class . '->'][] = function (\League\Plates\Engine $engine) use ($app) {
            foreach (self::getFunctions($app) as $functionName => $closure) {
                // @phpstan-ignore-next-line
                $engine->registerFunction($functionName, $closure);
            }
        };
        $definitions = array_merge_distinct($definitions, $newDefinitions);
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $body
     */
    public static function renderPlates(Di $di, array $route, array $body = []): ?string
    {
        // Check if we have a engine instance
        if (!$di->has(\League\Plates\Engine::class)) {
            return null;
        }
        /** @var \League\Plates\Engine $engine  */
        $engine = $di->get(\League\Plates\Engine::class);

        $globals = [
            "_config" => $di->get(SiteConfig::class),
            "_route" => $route,
        ];
        $controller = $route['controller'];
        if ($controller && is_string($controller)) {
            $globals['_controller']  = $di->get($controller);
        }
        $engine->addData($globals);

        // Build view path based on route parameters
        /** @var string $viewFile  */
        $viewFile = $route['template'];

        // Remplace @syntax/ with ::
        if (str_starts_with($viewFile, '@')) {
            $viewParts = explode('/', $viewFile);
            $shift = array_shift($viewParts);
            $shift = trim($shift, "@") . "::";
            $viewFile = $shift . implode("/", $viewParts);
        }
        // If we have a view, render with body as context
        if (!$engine->exists($viewFile)) {
            return null;
        }
        $context = $body ? $body : [];
        $body = $engine->render($viewFile, $context);
        return $body;
    }
}
