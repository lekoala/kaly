<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Asset\Assets;
use Kaly\Asset\AssetsInterface;
use Kaly\Asset\AssetSources;
use Kaly\Core\Ex;
use Kaly\Core\HttpContext;
use Kaly\Di\Injector;
use Kaly\Http\ContentType;
use Kaly\Http\InputMapperInterface;
use Kaly\Http\JsonResponse;
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
use ReflectionClass;
use ReflectionException;
use ReflectionParameter;

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
    // Reserved render variable generating urls for the request locale
    public const VAR_URL = 'url';
    // Reserved render variable generating asset urls
    public const VAR_ASSET = 'asset';

    public function __construct(
        protected Injector $injector,
        protected TranslatorInterface $translator,
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
        protected ?RendererInterface $renderer = null,
        protected ?InputMapperInterface $inputMapper = null,
        protected ?AssetsInterface $assets = null,
    ) {
        // `asset` always exists, like `url` and `i18n`: without an explicit
        // binding the fallback only fails when actually called in prod
        // without a published version.
        $this->assets ??= new Assets(new AssetSources([]), sys_get_temp_dir());
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = HttpContext::from($request);

        // Running unrouted or without a locale is a broken invariant, not
        // something to paper over with a default: the routing step is fixed.
        $result = $this->dispatch($ctx, $ctx->route());

        return $this->prepareResponse($result, $ctx);
    }

    /**
     * @return ResponseInterface|View|JsonResponse|array<mixed>|string|null
     */
    protected function dispatch(HttpContext $ctx, Route $route): ResponseInterface|View|JsonResponse|array|string|null
    {
        $class = $route->controller;
        if ($class === '') {
            throw new Ex('Controller not found');
        }

        $request = $ctx->request();

        // Each request gets a fresh instance of the controller. What the
        // resolver found along with the route (eg: a page) reaches its
        // constructor by name.
        $instance = $this->injector->make($class, ...$this->controllerArguments($class, $route->bindings, $request, $ctx));

        $action = $route->action;

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
            || $result instanceof JsonResponse
        ) {
            return $result;
        }

        throw new Ex(
            'Controllers must return a ResponseInterface, a View, a JsonResponse, an array, a string or null. Got: '
                . get_debug_type($result),
        );
    }

    /**
     * Constructor argument names by class, to avoid reflecting on every request.
     *
     * @var array<class-string,list<string>>
     */
    private static array $constructorParams = [];

    /**
     * Build the named arguments for the controller constructor.
     *
     * The framework conveniences (`request`, `ctx`) are only passed when the
     * constructor declares them: since kaly-di 0.3 the injector rejects
     * unknown named arguments instead of ignoring them. Resolver bindings
     * pass through untouched, so a typo there still fails fast.
     *
     * @param class-string $class
     * @param array<string,mixed> $bindings Controller constructor arguments, by name
     * @return array<string,mixed>
     */
    private function controllerArguments(string $class, array $bindings, ServerRequestInterface $request, HttpContext $ctx): array
    {
        $arguments = $bindings;
        foreach (['request' => $request, 'ctx' => $ctx] as $name => $value) {
            if (!array_key_exists($name, $arguments) && in_array($name, self::constructorParamNames($class), true)) {
                $arguments[$name] = $value;
            }
        }
        return $arguments;
    }

    /**
     * @param class-string $class
     * @return list<string>
     */
    private static function constructorParamNames(string $class): array
    {
        if (!array_key_exists($class, self::$constructorParams)) {
            try {
                $constructor = (new ReflectionClass($class))->getConstructor();
                self::$constructorParams[$class] = $constructor === null
                    ? []
                    : array_map(static fn(ReflectionParameter $p): string => $p->getName(), $constructor->getParameters());
            } catch (ReflectionException) {
                // Let Injector::make() report the real problem (unknown class)
                return [];
            }
        }
        return self::$constructorParams[$class];
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
     * @param ResponseInterface|View|JsonResponse|array<mixed>|string|null $result
     */
    protected function prepareResponse(ResponseInterface|View|JsonResponse|array|string|null $result, HttpContext $ctx): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if ($result instanceof JsonResponse) {
            return $this->createResponse(Json::encode($result->data), ContentType::JSON, $result->status, $result->headers);
        }
        if ($result instanceof View) {
            if ($this->renderer === null) {
                throw new Ex('A View was returned but no renderer is configured. Bind a Kaly\View\RendererInterface implementation.');
            }
            // Each render gets its own localized translator under a reserved
            // variable, so templates never depend on shared translator state,
            // and url + asset generators bound to the request locale.
            // The constructor guarantees $this->assets is set.
            $assets = $this->assets;
            assert($assets !== null);
            $data = [
                ...$result->data,
                self::VAR_I18N => new LocalizedTranslator($this->translator, $ctx->locale()),
                self::VAR_URL => $ctx->url(...),
                self::VAR_ASSET => $assets->url(...),
            ];
            return $this->createResponse($this->renderer->render($result->template, $data), ContentType::HTML, $result->status);
        }
        if (is_array($result)) {
            return $this->createResponse(Json::encode($result), ContentType::JSON);
        }
        return $this->createResponse((string) $result, ContentType::HTML);
    }

    /**
     * @param array<string,string> $headers
     */
    protected function createResponse(string $body, string $contentType, int $status = 200, array $headers = []): ResponseInterface
    {
        $response = $this->responseFactory
            ->createResponse($status)
            ->withHeader('Content-Type', $contentType)
            ->withBody($this->streamFactory->createStream($body));
        foreach ($headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
