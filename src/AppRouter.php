<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Di;
use Exception;
use Kaly\Http;
use Kaly\Router\ClassRouter;
use Kaly\Interfaces\RouterInterface;
use Kaly\Exceptions\NotFoundException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Kaly\Interfaces\ResponseProviderInterface;

/**
 * @mixin App
 */
trait AppRouter
{
    public function processRequest(ServerRequestInterface $request, Di $di): ResponseInterface
    {
        $code = 200;
        $body = null;
        $routeParams = [];
        try {
            $router = $di->get(RouterInterface::class);
            $body = $router->match($request, $di);
            $routeParams = $router->getRouteParams($di);
        } catch (ResponseProviderInterface $ex) {
            // Will be converted to a response later
            $body = $ex;
        } catch (NotFoundException $ex) {
            $code = $ex->getCode();
            $body = $this->debug ? Util::getExceptionMessageChainAsString($ex, true) : 'The page could not be found';
        } catch (Exception $ex) {
            $code = 500;
            $body = $this->debug ? Util::getExceptionMessageChainAsString($ex, true) : 'Server error';
        }

        $json = $request->getHeader('Accept') == Http::CONTENT_TYPE_JSON;
        $forceJson = boolval($request->getQueryParams()['_json'] ?? false);
        $json = $json || $forceJson;

        // We may want to return a view instead, check for twig interface
        if (!empty($routeParams)) {
            $hasTwig = $this->hasDefinition(\Twig\Loader\LoaderInterface::class);
            if ($hasTwig) {
                /** @var \Twig\Environment $twig  */
                $twig = $di->get(\Twig\Environment::class);
                if ($this->debug) {
                    $twig->enableDebug();
                }

                if (!$json && (!$body || is_array($body))) {
                    $viewName = $routeParams['controller'] . '/' . $routeParams['action'];
                    if ($routeParams['module'] !== 'default') {
                        $viewName = '@' . $routeParams['module'] . '/' . $viewName;
                    }
                    $viewFile = $viewName . ".twig";
                    if ($twig->getLoader()->exists($viewFile)) {
                        $context = $body ? $body : [];
                        $body = $twig->render($viewFile, $context);
                    }
                }
            }
        }

        if ($body) {
            // We have a response provider
            if ($body instanceof ResponseProviderInterface) {
                $body = $body->getResponse();
            }

            // We have a response, return early
            if ($body instanceof ResponseInterface) {
                return $body;
            }
        }

        // We don't have a suitable response, transform body
        $headers = [];

        // We want and can return a json response
        if ($json && is_array($body)) {
            $response = Http::createJsonResponse($body, $code, $headers);
        } else {
            $response = Http::createHtmlResponse($body, $code, $headers);
        }
        return $response;
    }

    /**
     * You can use this function to add a default router definition
     * to the DI container
     *
     * @param array<string> $modules
     * @return callable
     */
    protected function defineBaseRouter(array $modules): callable
    {
        return function () use ($modules) {
            $classRouter = new ClassRouter();
            $classRouter->setAllowedNamespaces($modules);
            return $classRouter;
        };
    }
}
