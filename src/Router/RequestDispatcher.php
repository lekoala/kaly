<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Core\Ex;
use Kaly\Di\Injector;
use Kaly\Http\ContentType;
use Kaly\Http\HttpContext;
use Kaly\Text\LocalizedTranslator;
use Kaly\Text\TranslatorInterface;
use Kaly\Util\Json;
use Kaly\View\RendererInterface;
use Kaly\View\View;
use LogicException;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * The terminal handler of the pipeline.
 *
 * It no longer routes: the route and the locale are established by the
 * RoutingHandler and read from the context. It only goes from a resolved route
 * to a PSR-7 response.
 */
class RequestDispatcher implements RequestHandlerInterface
{
    // Reserved render variable holding the translator bound to the request locale
    public const VAR_I18N = 'i18n';

    // Used when nothing resolved a locale, which should not happen behind the routing handler
    public const FALLBACK_LOCALE = 'en';

    public function __construct(
        protected Injector $injector,
        protected TranslatorInterface $translator,
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
        protected ?RendererInterface $renderer = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = HttpContext::from($request);

        $route = $ctx->route ?? throw new LogicException('Request has not been routed.');

        $result = $this->dispatch($ctx, $route);

        return $this->prepareResponse($result, $ctx->locale ?? self::FALLBACK_LOCALE);
    }

    /**
     * @return ResponseInterface|View|array<mixed>|string|null
     */
    protected function dispatch(HttpContext $ctx, Route $route): ResponseInterface|View|array|string|null
    {
        $class = $route->controller;
        if (!$class) {
            throw new Ex('Controller not found');
        }

        $request = $ctx->request;

        // Each request gets a fresh instance of the controller
        $instance = $this->injector->make($class, request: $request, ctx: $ctx);

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
