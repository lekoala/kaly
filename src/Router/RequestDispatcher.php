<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Core\Ex;
use Kaly\Core\HttpContext;
use Kaly\Di\Injector;
use Kaly\Http\ContentType;
use Kaly\Http\InputMapperInterface;
use Kaly\Http\RequestInput;
use Kaly\Text\LocalizedTranslator;
use Kaly\Text\TranslatorInterface;
use Kaly\Util\Json;
use Kaly\View\RendererInterface;
use Kaly\View\View;
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

    public function __construct(
        protected Injector $injector,
        protected TranslatorInterface $translator,
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
        protected ?RendererInterface $renderer = null,
        protected ?InputMapperInterface $inputMapper = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = HttpContext::from($request);

        // Running unrouted or without a locale is a broken invariant, not
        // something to paper over with a default: the routing step is fixed.
        $result = $this->dispatch($ctx, $ctx->route());

        return $this->prepareResponse($result, $ctx->locale());
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

        $request = $ctx->request();

        // Each request gets a fresh instance of the controller
        $instance = $this->injector->make($class, request: $request, ctx: $ctx);

        $action = $route->action ?? RouterInterface::FALLBACK_ACTION;

        if (!is_callable([$instance, $action])) {
            throw new Ex("Action '{$action}' is not callable");
        }

        // Route segments get passed to the action
        $arguments = $route->params;

        // The trailing input is built from the query and the body
        if ($route->inputClass !== null) {
            $arguments[] = $this->mapInput($request, $route->inputClass);
        }

        // The injector constructs controllers, it never supplies action
        // arguments: nothing can be resolved from the container here.
        $result = $instance->{$action}(...$arguments);
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
     * @param class-string<RequestInput> $inputClass
     */
    protected function mapInput(ServerRequestInterface $request, string $inputClass): RequestInput
    {
        if ($this->inputMapper === null) {
            throw new Ex("Action input '{$inputClass}' cannot be built: bind a " . InputMapperInterface::class);
        }

        return $this->inputMapper->map($request, $inputClass);
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
