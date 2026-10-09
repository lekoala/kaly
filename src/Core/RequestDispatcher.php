<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Di\Injector;
use Kaly\Ex;
use Kaly\Http\ContentType;
use Kaly\Http\Input\InputMapperInterface;
use Kaly\Http\Input\RequestInput;
use Kaly\Http\JsonResult;
use Kaly\Router\Route;
use Kaly\Util\Json;
use Kaly\View\View;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;

/**
 * The terminal handler of the pipeline.
 *
 * It no longer routes: the route and the locale are established by the
 * RoutingHandler and read from the context. It only goes from a resolved route
 * to a PSR-7 response.
 */
final class RequestDispatcher implements RequestHandlerInterface
{
    /**
     * Constructor parameters carrying the current cycle, by name, to avoid
     * reflecting on every request. The parameter name is irrelevant to
     * callers: what matters is the type (ServerRequestInterface or
     * HttpContext). Explicit route bindings always win.
     *
     * @var array<class-string,array<string,class-string>>
     */
    private static array $contextParams = [];

    public function __construct(
        protected Injector $injector,
        protected ViewResponder $views,
        protected ResponseFactoryInterface $responseFactory,
        protected StreamFactoryInterface $streamFactory,
        protected ?InputMapperInterface $inputMapper = null,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $ctx = HttpContext::from($request);

        // Running unrouted or without a locale is a broken invariant, not
        // something to paper over with a default: the routing step is fixed.
        $start = $ctx->profile() !== null ? hrtime(true) : null;
        try {
            $result = $this->dispatch($ctx, $ctx->route());
        } finally {
            if ($start !== null) {
                $ctx->profile()?->record('controller', hrtime(true) - $start);
            }
        }

        return $this->prepareResponse($result, $ctx);
    }

    /**
     * @return ResponseInterface|View|JsonResult|array<mixed>|string
     */
    protected function dispatch(HttpContext $ctx, Route $route): ResponseInterface|View|JsonResult|array|string
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
        if ($result === null) {
            throw new Ex(
                "Controller '{$class}::{$action}' returned null: return an explicit response instead "
                . '(eg: a 204 response from the response factory), a View, a JsonResult, an array or a string',
            );
        }
        if (
            is_string($result)
            || is_array($result)
            || $result instanceof ResponseInterface
            || $result instanceof View
            || $result instanceof JsonResult
        ) {
            return $result;
        }

        throw new Ex(
            'Controllers must return a ResponseInterface, a View, a JsonResult, an array or a string, got: ' . get_debug_type($result),
        );
    }

    /**
     * Build the named arguments for the controller constructor.
     *
     * The current request and context are provided under the real parameter
     * name whenever the constructor types one of them: since kaly-di 0.3 the
     * injector rejects unknown named arguments instead of ignoring them, so
     * they are only passed when declared. Resolver bindings pass through
     * untouched, so a typo there still fails fast.
     *
     * @param class-string $class
     * @param array<string,mixed> $bindings Controller constructor arguments, by name
     * @return array<string,mixed>
     */
    private function controllerArguments(string $class, array $bindings, ServerRequestInterface $request, HttpContext $ctx): array
    {
        $arguments = $bindings;
        foreach (self::contextParamTypes($class) as $paramName => $type) {
            if (array_key_exists($paramName, $arguments)) {
                continue;
            }
            $arguments[$paramName] = $type === HttpContext::class ? $ctx : $request;
        }
        return $arguments;
    }

    /**
     * @param class-string $class
     * @return array<string,class-string>
     */
    private static function contextParamTypes(string $class): array
    {
        if (!array_key_exists($class, self::$contextParams)) {
            try {
                $constructor = (new ReflectionClass($class))->getConstructor();
                $map = [];
                foreach ($constructor?->getParameters() ?? [] as $parameter) {
                    $type = $parameter->getType();
                    if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
                        continue;
                    }
                    $typeName = $type->getName();
                    if ($typeName === HttpContext::class) {
                        $map[$parameter->getName()] = HttpContext::class;
                    } elseif ($typeName === ServerRequestInterface::class || is_a($typeName, ServerRequestInterface::class, true)) {
                        $map[$parameter->getName()] = ServerRequestInterface::class;
                    }
                }
                self::$contextParams[$class] = $map;
            } catch (ReflectionException) {
                // Let Injector::make() report the real problem (unknown class)
                return [];
            }
        }
        return self::$contextParams[$class];
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
     * @param ResponseInterface|View|JsonResult|array<mixed>|string $result
     */
    protected function prepareResponse(ResponseInterface|View|JsonResult|array|string $result, HttpContext $ctx): ResponseInterface
    {
        if ($result instanceof ResponseInterface) {
            return $result;
        }
        if ($result instanceof JsonResult) {
            return $this->createResponse($this->serializeJson($result->data, $ctx), ContentType::JSON, $result->status, $result->headers);
        }
        if ($result instanceof View) {
            return $this->views->respond($result, $ctx);
        }
        if (is_array($result)) {
            return $this->createResponse($this->serializeJson($result, $ctx), ContentType::JSON);
        }
        return $this->createResponse((string) $result, ContentType::HTML);
    }

    /**
     * @param array<mixed> $data
     */
    private function serializeJson(array $data, HttpContext $ctx): string
    {
        $start = $ctx->profile() !== null ? hrtime(true) : null;
        try {
            return Json::encode($data);
        } finally {
            if ($start !== null) {
                $ctx->profile()?->record('serialization', hrtime(true) - $start);
            }
        }
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
