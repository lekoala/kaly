<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Core\Ex;
use Kaly\Di\Injector;
use Kaly\Http\ContentType;
use Kaly\Text\LocaleResolver;
use Kaly\Text\LocalizedTranslator;
use Kaly\Text\TranslatorInterface;
use Kaly\Util\Json;
use Kaly\View\RendererInterface;
use Kaly\View\View;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RequestDispatcher implements MiddlewareInterface
{
    // Request attributes
    public const ATTR_IP_REQUEST = 'client-ip';
    public const ATTR_REQUEST_ID_REQUEST = 'request-id';
    public const ATTR_ROUTE_REQUEST = 'route';
    public const ATTR_LOCALE_REQUEST = LocaleResolver::ATTR_LOCALE_REQUEST;

    // Reserved render variable holding the translator bound to the request locale
    public const VAR_I18N = 'i18n';

    public function __construct(
        protected RouterInterface $router,
        protected Injector $injector,
        protected TranslatorInterface $translator,
        protected LocaleResolver $localeResolver,
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
        protected ?RendererInterface $renderer = null,
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $route = $this->router->match($request);

        // Expose the client IP as a request attribute for controllers
        if ($request->getAttribute(self::ATTR_IP_REQUEST) === null) {
            $serverParams = $request->getServerParams();
            $ip = $serverParams['REMOTE_ADDR'] ?? '0.0.0.0';
            if (!is_string($ip)) {
                $ip = '0.0.0.0';
            }
            $request = $request->withAttribute(self::ATTR_IP_REQUEST, $ip);
        }

        // Resolve the locale before invoking the controller so that actions run
        // with the correct locale. The shared translator is never mutated: the
        // locale travels with the request and with the localized translator.
        $locale = $this->localeResolver->resolve($request, $route->locale);
        $request = $request->withAttribute(self::ATTR_LOCALE_REQUEST, $locale);

        // Expose the resolved route on the request before building and
        // invoking the controller so that actions can read it.
        $request = $request->withAttribute(self::ATTR_ROUTE_REQUEST, $route->toArray());

        $result = $this->dispatch($request, $route);

        return $this->prepareResponse($result, $locale);
    }

    /**
     * @return ResponseInterface|View|array<mixed>|string|null
     */
    protected function dispatch(ServerRequestInterface $request, Route $route): ResponseInterface|View|array|string|null
    {
        $class = $route->controller;
        if (!$class) {
            throw new Ex('Controller not found');
        }

        // Each request gets a fresh instance of the controller
        $instance = $this->injector->make($class, request: $request);

        $action = $route->action ?? RouterInterface::FALLBACK_ACTION;

        // Routing params get passed to the action
        $arguments = $route->params;

        // Syntax sugar for handling post: only forward a real parsed body so
        // that an empty request does not override an optional/default argument.
        if (in_array($request->getMethod(), ['POST', 'PUT', 'PATCH'], true)) {
            $body = $request->getParsedBody();
            if (is_array($body) || is_object($body)) {
                $arguments[] = $body;
            }
        }

        $callable = [$instance, $action];
        if (!is_callable($callable)) {
            throw new Ex("Action '{$action}' is not callable");
        }

        $result = $this->injector->invoke($callable, ...$arguments);
        if (
            $result === null
            || is_string($result)
            || is_array($result)
            || $result instanceof ResponseInterface
            || $result instanceof View
        ) {
            return $result;
        }

        throw new Ex('Controllers must return a ResponseInterface, a View, an array, a string or null. Got: ' . get_debug_type($result));
    }

    /**
     * @param ResponseInterface|View|array<mixed>|string|null $result
     */
    protected function prepareResponse(ResponseInterface|View|array|string|null $result, string $locale): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if ($result instanceof View) {
            if ($this->renderer === null) {
                throw new Ex('A View was returned but no renderer is configured. Bind a Kaly\View\RendererInterface implementation.');
            }
            // Each render gets its own localized translator under a reserved
            // variable, so templates never depend on shared translator state.
            $data = [
                ...$result->data,
                self::VAR_I18N => new LocalizedTranslator($this->translator, $locale),
            ];
            return $this->createResponse($this->renderer->render($result->template, $data), ContentType::HTML);
        }
        if (is_array($result)) {
            return $this->createResponse(Json::encode($result), ContentType::JSON);
        }
        return $this->createResponse((string) $result, ContentType::HTML);
    }

    protected function createResponse(string $body, string $contentType): ResponseInterface
    {
        return $this->responseFactory
            ->createResponse(200)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streamFactory->createStream($body));
    }
}
