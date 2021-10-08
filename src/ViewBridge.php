<?php

declare(strict_types=1);

namespace Kaly;

/**
 * Configure and use view rendering engines
 */
class ViewBridge
{
    /**
     * @param array<string, mixed> $definitions
     */
    public static function configureTwig(App $app, array &$definitions): void
    {
        // Twig has CoreExtension, EscaperExtension and OptimizerExtension loaded by default
        if ($app->getDebug()) {
            // @link https://twig.symfony.com/doc/3.x/functions/dump.html
            $definitions[\Twig\Environment::class . '->'][] = function (\Twig\Environment $twig) {
                $twig->enableDebug();
                // Adds dump function to templates
                $twig->addExtension(new \Twig\Extension\DebugExtension());
            };
        }
        $definitions[\Twig\Environment::class . '->'][] = function (\Twig\Environment $twig) use ($app) {
            $function = new \Twig\TwigFunction('t', function (string $message, array $parameters = [], string $domain = null) {
                return t($message, $parameters, $domain);
            });
            $twig->addFunction($function);
            // We define early to make sure they are compiled
            $twig->addGlobal("_state", null);
            $twig->addGlobal("_config", null);
            $twig->addGlobal("_route", null);
            $twig->addGlobal("_controller", null);
            //
            if (!$app->getDebug()) {
                $twig->setCache($app->makeTempFolder('twig'));
            }
        };
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, mixed> $body
     */
    public static function renderTwig(Di $di, array $routeParams, array $body = []): ?string
    {
        // Check if we have a twig instance
        if (!$di->has(\Twig\Loader\LoaderInterface::class)) {
            return null;
        }
        /** @var \Twig\Environment $twig  */
        $twig = $di->get(\Twig\Environment::class);

        // Set some globals to allow pulling data from our controller or state
        // Defaults globals are _self, _context, _charset
        $twig->addGlobal("_state", $di->get(State::class));
        $twig->addGlobal("_config", $di->get(SiteConfig::class));
        $twig->addGlobal("_route", $routeParams);
        if (!empty($routeParams['controller'])) {
            $twig->addGlobal("_controller", $di->get($routeParams['controller']));
        }

        // Build view path based on route parameters
        $viewFile = $routeParams['template'];
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
    }

    /**
     * @param array<string, mixed> $routeParams
     * @param array<string, mixed> $body
     */
    public static function renderPlates(Di $di, array $routeParams, array $body = []): ?string
    {
        // Check if we have a engine instance
        if (!$di->has(\League\Plates\Engine::class)) {
            return null;
        }
        /** @var \League\Plates\Engine $engine  */
        $engine = $di->get(\League\Plates\Engine::class);

        $globals = [
            "_state" => $di->get(State::class),
            "_config" => $di->get(SiteConfig::class),
            "_route" => $routeParams,
        ];
        if (!empty($routeParams['controller'])) {
            $globals['_controller']  = $di->get($routeParams['controller']);
        }
        $engine->addData($globals);

        // Build view path based on route parameters
        /** @var string $viewFile  */
        $viewFile = $routeParams['template'];

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
