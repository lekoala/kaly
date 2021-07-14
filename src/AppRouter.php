<?php

declare(strict_types=1);

namespace Kaly;

use Kaly\Di;
use Exception;
use Kaly\Http;
use Stringable;
use Kaly\Router\ClassRouter;
use Kaly\Router\RouterException;
use Kaly\Router\RouterInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Kaly\Exceptions\ResponseExceptionInterface;

trait AppRouter
{
    /**
     * @return ResponseInterface|string|Stringable|array<string, mixed>
     */
    protected function routeRequest(ServerRequestInterface $request, Di $di)
    {
        /** @var RouterInterface $router */
        $router = $di->get(RouterInterface::class);
        $result = $router->match($request, $di);
        return $result;
    }

    public function processRequest(ServerRequestInterface $request, Di $di): ResponseInterface
    {
        $code = 200;
        $body = null;
        try {
            $body = $this->routeRequest($request, $di);
        } catch (ResponseExceptionInterface $ex) {
            $body = $ex->getResponse();
        } catch (RouterException $ex) {
            $code = $ex->getCode();
            $body = $this->debug ? $ex->getMessage() : 'The page could not be found';
        } catch (Exception $ex) {
            $code = 500;
            $body = $this->debug ? $ex->getMessage() : 'Server error';
        }

        // We have a response, return early
        if ($body && $body instanceof ResponseInterface) {
            return $body;
        }

        // We don't have a suitable response, transform body
        $json = $request->getHeader('Accept') == Http::CONTENT_TYPE_JSON;
        $forceJson = boolval($request->getQueryParams()['_json'] ?? false);
        $json = $json || $forceJson;
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
