<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Core\Ex;
use Kaly\Http\MethodNotAllowedException;
use Kaly\Http\RedirectException;
use Kaly\Http\RequestInput;
use Kaly\Util\Str;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;

/**
 * Resolves the conventional urls of a module: `controller/action/params`.
 *
 * ```text
 * /shop/cart/add/42   ->   Shop\Controller\CartController::add(42)
 * /shop/              ->   Shop\Controller\IndexController::index()
 * ```
 *
 * - the action is the camelized segment, preferably suffixed by the HTTP verb
 *   (`addPost` for a POST, see RestActionNaming)
 * - remaining segments are the action scalar parameters, strictly coerced: a
 *   value that does not fit its type does not match (404)
 * - a trailing RequestInput parameter is built from the query and the body
 * - one canonical url per action: `/index/`, `/Cart/` and friends redirect
 *
 * It is the last resolver of a module (priority 1000). Reflection is memoized
 * in memory, per process: in a worker it is paid once.
 */
final class ConventionResolver implements ResolverInterface
{
    public const PRIORITY = 1000;
    private const CONTROLLER_NAMESPACE = 'Controller';
    private const CONTROLLER_SUFFIX = 'Controller';
    private const DEFAULT_CONTROLLER = 'Index';
    private const DEFAULT_ACTION = 'index';

    /**
     * HTTP methods understood by the rest-style action suffix convention.
     */
    private const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    /**
     * Controller declarations never change during the life of the process
     *
     * @var array<string,ReflectionClass<object>|false>
     */
    private static array $reflections = [];

    public function __construct(
        private bool $forceTrailingSlash = true,
    ) {}

    /**
     * @throws RouteNotFoundException When the url does not map to an action, with the reason
     */
    public function resolve(RouteRequest $request): Route
    {
        // A miss throws RouteNotFoundException: the router treats it as "not
        // mine" and keeps the reason for the 404 debug page
        $m = new ConventionMatch($request->request, $request->segments);
        $controller = $this->findController($m, $request->module);
        $reflection = $this->reflect($controller);
        assert($reflection instanceof ReflectionClass);
        $action = $this->findAction($m, $reflection);
        $params = $this->collectParameters($m, $reflection, $action);

        $route = $request->route(
            $controller,
            $action,
            $params,
            inputClass: $m->inputClass,
            middlewares: RouteMiddlewares::ofAction($controller, $action),
        );
        return $route;
    }

    /**
     * The conventional path of an action, relative to the module entry point
     * ('' for the module index)
     *
     * @param class-string $controller
     * @param array<array-key,mixed> $params Positional action arguments
     */
    public function path(string $controller, string $action, array $params = []): string
    {
        if (!method_exists($controller, $action)) {
            throw new RuntimeException("Invalid handler '{$controller}::{$action}'");
        }
        $baseClass = substr($controller, (int) strrpos($controller, '\\') + 1);
        $controllerName = (string) preg_replace('/' . self::CONTROLLER_SUFFIX . '$/', '', $baseClass);

        $path = '';
        $hasParams = $params !== [];
        if ($controllerName !== self::DEFAULT_CONTROLLER || $action !== self::DEFAULT_ACTION || $hasParams) {
            $path .= '/' . Str::decamelize($controllerName);
        }
        if ($action !== self::DEFAULT_ACTION || $hasParams) {
            // Rest style actions are reached without their verb suffix
            $path .= '/' . RestActionNaming::stripVerbSuffix($action);
        }
        foreach ($params as $value) {
            if (is_scalar($value)) {
                $path .= '/' . rawurlencode((string) $value);
            }
        }
        return $path;
    }

    /**
     * @return ReflectionClass<object>|false
     */
    private function reflect(string $class): ReflectionClass|false
    {
        if (!array_key_exists($class, self::$reflections)) {
            self::$reflections[$class] = class_exists($class) ? new ReflectionClass($class) : false;
        }
        return self::$reflections[$class];
    }

    private function redirect(ConventionMatch $m, string $remove, string $replace = ''): RedirectException
    {
        return new RedirectException(RedirectUris::replaceSegment($m->request, $remove, $replace, $this->forceTrailingSlash));
    }

    /**
     * Find a controller based on the first remaining segment
     * @return class-string
     */
    private function findController(ConventionMatch $m, string $namespace): string
    {
        $path = $m->request->getUri()->getPath();

        $part = $m->parts[0] ?? '';
        $camelPart = Str::camelize($part);

        // Don't allow calling camelized parts, we use lowercase
        if ($part && $part === $camelPart) {
            throw $this->redirect($m, $camelPart, Str::decamelize($camelPart));
        }

        // Do not allow direct /index calls
        $defaultController = strtolower(self::DEFAULT_CONTROLLER);
        if ($part === $defaultController && count($m->parts) === 1) {
            throw $this->redirect($m, $defaultController);
        }

        $controller = ($part ? $camelPart : self::DEFAULT_CONTROLLER) . self::CONTROLLER_SUFFIX;
        $class = $namespace . '\\' . self::CONTROLLER_NAMESPACE . '\\' . $controller;

        // It must be autoloadable and instantiable
        $reflection = $this->reflect($class);
        if ($reflection === false) {
            throw new RouteNotFoundException("Route '{$path}' not found, '{$class}' doesn't exists");
        }
        if ($reflection->isAbstract()) {
            throw new RouteNotFoundException("Route '{$path}' not found, '{$class}' isn't instantiable");
        }

        array_shift($m->parts);

        /** @var class-string $class */
        return $class;
    }

    /**
     * Find a matching action based on the next remaining segment
     * @param ReflectionClass<object> $refl
     */
    private function findAction(ConventionMatch $m, ReflectionClass $refl): string
    {
        $method = $m->request->getMethod();
        $class = $refl->getName();

        $testPart = $m->parts[0] ?? '';

        // Index or __invoke is used by default
        $action = $refl->hasMethod(RouterInterface::FALLBACK_ACTION) ? RouterInterface::FALLBACK_ACTION : self::DEFAULT_ACTION;

        if ($testPart) {
            // Action should be lowercase camelcase
            $testAction = Str::camelize($testPart, false);
            // Rest style routing: the HTTP method is added at the end to avoid
            // confusion with getters
            $methodSuffix = RestActionNaming::methodSuffix($method);
            $testActionWithMethod = $testAction . $methodSuffix;

            // Don't allow controller/index to be called directly because it would create duplicated urls
            // This only applies if no other parameters is passed in the url
            if ($testAction === self::DEFAULT_ACTION && count($m->parts) === 1) {
                throw $this->redirect($m, self::DEFAULT_ACTION);
            }

            // A url naming an action suffixed by another HTTP verb must not
            // run for this request (eg: GET /index/change-post/ is a 405).
            $verbSuffix = RestActionNaming::verbSuffix($testAction);
            if ($verbSuffix !== null && $this->isRoutableAction($refl, $testAction) && $verbSuffix !== $methodSuffix) {
                $baseAction = RestActionNaming::stripVerbSuffix($testAction);
                throw new MethodNotAllowedException(
                    $this->findAllowedMethods($refl, $baseAction),
                    "Method {$method} is not allowed for action '{$baseAction}'",
                );
            }

            if ($this->isRoutableAction($refl, $testActionWithMethod)) {
                array_shift($m->parts);
                $action = $testActionWithMethod;
            } elseif ($this->isRoutableAction($refl, $testAction)) {
                array_shift($m->parts);
                $action = $testAction;
            } else {
                // The action may exist for other HTTP verbs only
                $allowed = $this->findAllowedMethods($refl, $testAction);
                if ($allowed !== []) {
                    throw new MethodNotAllowedException($allowed, "Method {$method} is not allowed for action '{$testAction}'");
                }
            }
        }

        if (!$this->isRoutableAction($refl, $action)) {
            throw new RouteNotFoundException("Controller '{$class}' does not have an action '{$action}'");
        }

        return $action;
    }

    /**
     * Can this method be reached through routing? Protected and magic methods
     * (except __invoke) are never exposed.
     *
     * @param ReflectionClass<object> $refl
     */
    private function isRoutableAction(ReflectionClass $refl, string $action): bool
    {
        if ($action === '' || !$refl->hasMethod($action)) {
            return false;
        }
        if (str_starts_with($action, '__') && $action !== RouterInterface::FALLBACK_ACTION) {
            return false;
        }
        return $refl->getMethod($action)->isPublic();
    }

    /**
     * The HTTP methods supported by a base action through the rest-style
     * action suffix convention.
     *
     * @param ReflectionClass<object> $refl
     * @return list<string>
     */
    private function findAllowedMethods(ReflectionClass $refl, string $baseAction): array
    {
        if ($baseAction === '') {
            return [];
        }
        $allowed = [];
        foreach (self::HTTP_METHODS as $httpMethod) {
            if ($this->isRoutableAction($refl, $baseAction . RestActionNaming::methodSuffix($httpMethod))) {
                $allowed[] = $httpMethod;
            }
        }
        return $allowed;
    }

    /**
     * The remaining segments are the action arguments
     *
     * @param ReflectionClass<object> $refl
     * @return array<int<0,max>|string,mixed>
     */
    private function collectParameters(ConventionMatch $m, ReflectionClass $refl, string $action): array
    {
        $class = $refl->getName();
        $actionParams = $refl->getMethod($action)->getParameters();

        // A trailing RequestInput is supplied by the dispatcher, not by the url
        $m->inputClass = $this->extractInputClass($actionParams, $class, $action);

        /** @var array<int<0,max>|string,mixed> $params */
        $params = $m->parts;
        $i = 0;
        $extra = false;
        foreach ($actionParams as $actionParam) {
            $paramName = $actionParam->getName();

            $satisfiedByUrl = isset($m->parts[$i]);
            if (!$actionParam->isOptional() && !$actionParam->isDefaultValueAvailable() && !$satisfiedByUrl) {
                throw new RouteNotFoundException("Param '{$paramName}' is required for action '{$action}' on '{$class}'");
            }

            // Services belong to the constructor: an action argument is either a
            // route segment or the trailing input, never something the container
            // could fill in silently.
            if (self::hasClassType($actionParam)) {
                throw new Ex(
                    "Parameter '{$paramName}' of action '{$action}' on '{$class}' must be a route scalar"
                    . ' or a trailing '
                    . RequestInput::class,
                );
            }

            // Strictly coerce and validate built-in typed parameters.
            // An invalid value does not match the route (404).
            $value = $m->parts[$i] ?? '';
            $type = $actionParam->getType();
            if ($type instanceof ReflectionNamedType && $value !== '' && $type->isBuiltin()) {
                $params[$i] = RouteParamCoercer::coerce($type->getName(), $value);
            }

            if ($actionParam->isVariadic()) {
                $extra = true;
            }
            $i++;
        }
        if (!$extra && count($params) > count($actionParams)) {
            throw new RouteNotFoundException("Too many parameters for action '{$action}' on '{$class}'");
        }
        return $params;
    }

    /**
     * Pull the trailing RequestInput out of the parameters the url has to
     * satisfy. There is at most one and it is always last.
     *
     * @param ReflectionParameter[] $actionParams Mutated: the input is removed
     * @return class-string<RequestInput>|null
     */
    private function extractInputClass(array &$actionParams, string $class, string $action): ?string
    {
        $last = count($actionParams) - 1;
        $inputClass = null;
        foreach ($actionParams as $i => $actionParam) {
            $name = self::inputClassOf($actionParam);
            if ($name === null) {
                continue;
            }
            if ($i !== $last) {
                throw new Ex(
                    RequestInput::class
                    . " parameter '{$actionParam->getName()}' must be the last parameter"
                    . " of action '{$action}' on '{$class}'",
                );
            }
            $inputClass = $name;
        }

        if ($inputClass !== null) {
            array_pop($actionParams);
        }

        return $inputClass;
    }

    /**
     * @return class-string<RequestInput>|null
     */
    private static function inputClassOf(ReflectionParameter $param): ?string
    {
        $type = $param->getType();
        if (!$type instanceof ReflectionNamedType || $type->isBuiltin()) {
            return null;
        }
        $name = $type->getName();
        if (!is_a($name, RequestInput::class, true)) {
            return null;
        }
        /** @var class-string<RequestInput> $name */
        return $name;
    }

    /**
     * Does this parameter expect an object? Such a parameter can never be
     * satisfied by a url segment.
     */
    private static function hasClassType(ReflectionParameter $param): bool
    {
        $type = $param->getType();
        $types = $type instanceof ReflectionUnionType ? $type->getTypes() : [$type];
        foreach ($types as $t) {
            if ($t instanceof ReflectionNamedType && !$t->isBuiltin()) {
                return true;
            }
        }
        return false;
    }
}
