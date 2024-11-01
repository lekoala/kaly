<?php

declare(strict_types=1);

namespace Kaly\Router;

use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Kaly\Text\Translator;
use Kaly\Router\RouterInterface;
use Kaly\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Kaly\Router\Route;
use Kaly\Core\App;
use Kaly\Core\Ex;
use Kaly\Http\ContentType;
use Kaly\Http\HttpFactory;
use Kaly\Router\JsonRouteInterface;
use Kaly\View\TemplateProviderInterface;
use JsonSerializable;
use Kaly\Core\AbstractController;
use Kaly\Http\NotFoundException;

class RequestDispatcher implements MiddlewareInterface
{
    // Request attributes
    public const ATTR_IP_REQUEST = "client-ip";
    public const ATTR_REQUEST_ID_REQUEST = "request-id";
    public const ATTR_ROUTE_REQUEST = "route";
    public const ATTR_LOCALE_REQUEST = "locale";

    protected App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $container = $this->app->getContainer();

        $translator = $container->get(Translator::class);
        if ($request instanceof ServerRequest) {
            $translator->setLocaleFromRequest($request);
        }

        $router = $container->get(RouterInterface::class);
        $route = $router->match($request);

        $response = $this->dispatch($request, $route);

        $request = $request->withAttribute(self::ATTR_ROUTE_REQUEST, $route->toArray());
        if ($route->locale) {
            $request = $request->withAttribute(self::ATTR_LOCALE_REQUEST, $route->locale);
            $translator->setCurrentLocale($route->locale);
        }

        return $this->prepareResponse($request, $response);
    }

    /**
     * @return string|array<mixed>|ResponseInterface
     */
    protected function dispatch(ServerRequestInterface $request, Route &$route)
    {
        $class = $route->controller;
        if (!$class) {
            throw new Ex("Controller not found");
        }

        // Each request gets a fresh instance of the controller
        if (is_subclass_of($class, AbstractController::class)) {
            $inst = new $class($request, $this->app);
        } else {
            $inst = $this->app->getInjector()->make($class, null, false);
        }

        // Check for interfaces
        if ($inst instanceof JsonRouteInterface) {
            $route->json = true;
        }
        if ($inst instanceof TemplateProviderInterface) {
            $route->template = $inst->getTemplate();
        }

        $action = $route->action ?? '__invoke';

        // Routing params gets passed to the action
        $arguments = $route->params ?? [];

        // Syntax sugar for handling post
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'])) {
            $arguments[] = $request->getParsedBody();
        }

        $result = null;
        $callable = [$inst, $action];
        if (is_callable($callable)) {
            $result = $this->app->getInjector()->invoke($callable, ...$arguments);
        }

        // Special handling for JsonSerializable
        if ($result && is_object($result) && $result instanceof JsonSerializable) {
            $route->json = true;
            $result = $result->jsonSerialize();
        }

        // Special handling for boolean results
        if (is_bool($result)) {
            if ($result) {
                $result = 'OK';
            } else {
                throw new NotFoundException();
            }
        }

        assert(is_string($result) || is_array($result) || $result instanceof ResponseInterface);

        return $result;
    }

    /**
     * @param ResponseInterface|string|array<mixed>|null $response
     */
    protected function prepareResponse(
        ServerRequestInterface $request,
        ResponseInterface|string|array|null $response = null,
    ): ResponseInterface {
        // We have a response, return early
        if ($response && $response instanceof ResponseInterface) {
            return $response;
        }

        $attr = $request->getAttribute(self::ATTR_ROUTE_REQUEST);
        if (!is_array($attr)) {
            $attr = [];
        }
        $route = Route::fromArray($attr);
        $forceJson = boolval($request->getQueryParams()['_json'] ?? false);
        $priorityList = [
            ContentType::HTML
        ];
        if ($forceJson || $route->json) {
            $priorityList = [
                ContentType::JSON,
                ContentType::HTML
            ];
        }

        $acceptHtml = true;
        $requestedJson = false;
        if ($request instanceof ServerRequest) {
            $preferredType = $request->getPreferredContentType($priorityList);
            $acceptHtml = $preferredType == ContentType::HTML;
            $acceptJson = $preferredType == ContentType::JSON;
            $requestedJson = $acceptJson || $forceJson;
        }

        // We may want to return a template that matches route params if possible
        if ($acceptHtml && !$route->json && $route->template) {
            $renderedBody = $this->renderTemplate($route, $response);
            if ($renderedBody) {
                $body = $renderedBody;
                $response = $this->app->respond($body);
            }
        }

        if (!($response instanceof ResponseInterface)) {
            // We don't have a suitable response, transform body
            $headers = [];

            // We want and can return a json response
            if ($requestedJson && $route->json) {
                $response = HttpFactory::createJsonResponse($response, 200, $headers);
            } elseif (!is_array($response)) {
                $response = HttpFactory::createHtmlResponse($response, 200, $headers);
            } else {
                throw new Ex("Invalid response");
            }
        }

        return $response;
    }

    /**
     * @param string|array<mixed>|null $body
     */
    protected function renderTemplate(Route $route, string|array|null $body = null): ?string
    {
        // We only support empty body or a context array
        if ($body && !is_array($body)) {
            return null;
        }
        // We need a template param
        if (empty($route->template)) {
            return null;
        }

        $engine = $this->app->getViewEngine();
        if ($engine->has($route->template)) {
            /** @var array<string, mixed> $body  */
            if (!$body) {
                $body = [];
            }
            $body = $engine->render($route->template, $body);
        } else {
            $body = $engine->getEscaper()->escape($body);
        }

        return $body;
    }
}
