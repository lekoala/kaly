<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Di\Reflection;
use Kaly\Ex;
use Kaly\Http\Exception\MethodNotAllowedException;
use Kaly\Http\Exception\RedirectException;
use Kaly\Http\Input\RequestInput;
use Kaly\Util\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Resolves the conventional urls of a module: `controller/action/params`.
 *
 * ```text
 * /shop/cart/add/42   ->   Shop\Controller\CartController::add(42)
 * /shop/              ->   Shop\Controller\IndexController::index()
 * ```
 *
 * - the action is the camelized segment, preferably suffixed by the HTTP verb
 *   (`addPost` for a POST, see RestActionNaming); a bare name only answers
 *   GET and HEAD
 * - remaining segments are the action scalar parameters, strictly coerced: a
 *   value that does not fit its type does not match (404)
 * - a trailing RequestInput parameter is built from the query and the body
 * - one canonical url per action: `/index/`, `/Cart/` and friends redirect
 *
 * It is the last resolver of a module (priority 1000). Reflection goes through
 * the kaly-di cache, per process: in a worker it is paid once.
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
     * The only HTTP methods an action without a verb suffix answers.
     */
    private const BARE_METHODS = ['GET', 'HEAD'];

    public function __construct(
        private TrailingSlash $trailingSlash = TrailingSlash::Preserve,
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
        $reflection = Reflection::tryClass($controller);
        assert($reflection instanceof ReflectionClass);
        $action = $this->findAction($m, $reflection, $request->ownedActions);
        $params = $this->collectParameters($m, $reflection, $action);

        $route = $request->route($controller, $action, $params, inputClass: $m->inputClass);
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
            throw new RouteGenerationException("Invalid handler '{$controller}::{$action}'");
        }
        $baseClass = substr($controller, (int) strrpos($controller, '\\') + 1);
        $controllerName = (string) preg_replace('/' . self::CONTROLLER_SUFFIX . '$/', '', $baseClass);

        // Rest style actions are reached without their verb suffix, and
        // spelled after their declaration whatever the case given here
        $action = (new ReflectionMethod($controller, $action))->getName();
        $baseAction = RestActionNaming::stripVerbSuffix($action);
        $invokable = $action === RouterInterface::FALLBACK_ACTION;

        $path = '';
        $hasParams = $params !== [];
        if ($controllerName !== self::DEFAULT_CONTROLLER || !$invokable && $baseAction !== self::DEFAULT_ACTION || $hasParams) {
            $path .= '/' . Str::decamelize($controllerName);
        }
        if (!$invokable && ($baseAction !== self::DEFAULT_ACTION || $hasParams)) {
            $path .= '/' . self::segmentOf($action);
        }
        foreach ($params as $value) {
            if (is_scalar($value)) {
                $path .= '/' . rawurlencode((string) $value);
            }
        }
        return $path;
    }

    private function redirect(ConventionMatch $m, string $remove, string $replace = ''): RedirectException
    {
        return new RedirectException(RedirectUris::replaceSegment(
            $m->request,
            $remove,
            $replace,
            $this->trailingSlash,
            count(RoutePath::segments($m->request->getUri()->getPath())) - count($m->parts),
        ));
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

        $controller = ($part ? $camelPart : self::DEFAULT_CONTROLLER) . self::CONTROLLER_SUFFIX;
        $class = $namespace . '\\' . self::CONTROLLER_NAMESPACE . '\\' . $controller;

        // It must be autoloadable and instantiable. Misses come from the url
        // and are never cached, so a worker cannot grow without bound
        $reflection = Reflection::tryClass($class);
        if ($reflection === null) {
            throw new RouteNotFoundException("Route '{$path}' not found, '{$class}' doesn't exist");
        }
        if ($reflection->isAbstract()) {
            throw new RouteNotFoundException("Route '{$path}' not found, '{$class}' isn't instantiable");
        }

        $canonical = Str::decamelize(substr($reflection->getShortName(), 0, -strlen(self::CONTROLLER_SUFFIX)));
        if ($part !== '' && $part !== $canonical) {
            throw $this->redirect($m, $part, $canonical);
        }
        if ($part === strtolower(self::DEFAULT_CONTROLLER) && count($m->parts) === 1) {
            throw $this->redirect($m, $part);
        }

        array_shift($m->parts);

        /** @var class-string $class */
        return $class;
    }

    /**
     * Find a matching action based on the next remaining segment
     *
     * The segment names the base action, never a method: `/change/` is the
     * url of changePost(), `/change-post/` is not. An explicitly routed
     * candidate is refused outright: the path is never reinterpreted through
     * a sibling action or the index fallback.
     *
     * @param ReflectionClass<object> $refl
     * @param array<string,true> $owned Canonical actions explicit routing owns
     */
    private function findAction(ConventionMatch $m, ReflectionClass $refl, array $owned): string
    {
        $method = $m->request->getMethod();
        $class = $refl->getName();

        $testPart = $m->parts[0] ?? '';
        $base = Str::camelize($testPart, false);
        // __invoke is the implicit default, never an action segment. The
        // literal word is still available as an argument of that default.
        if ($testPart !== '' && strcasecmp($base, RouterInterface::FALLBACK_ACTION) !== 0) {
            foreach ([$base, ...$this->candidates($base, $method)] as $candidate) {
                if ($this->isOwnedAction($refl, $candidate, $owned)) {
                    throw new RouteNotFoundException(
                        "Action '{$candidate}' of '{$class}' is explicitly routed and has no conventional url",
                    );
                }
            }

            // Do not allow controller/index to be called directly because it would create duplicated urls
            // This only applies if no other parameters is passed in the url
            if ($base === self::DEFAULT_ACTION && count($m->parts) === 1 && $this->canonicalSegment($refl, $base, $owned) !== null) {
                throw $this->redirect($m, $testPart);
            }

            $action = $this->selectAction($refl, $base, $method, $owned);
            $allowed = $action === null ? $this->allowedMethods($refl, $base, $owned, array_slice($m->parts, 1), $m) : [];
            // One spelling per action: /index/typedInt/ is /index/typed-int/
            $canonical = $this->canonicalSegment($refl, $base, $owned);
            if ($canonical !== null && $testPart !== $canonical) {
                throw $this->redirect($m, $testPart, $canonical);
            }
            if ($action !== null) {
                array_shift($m->parts);
                return $action;
            }
            if ($allowed !== []) {
                throw new MethodNotAllowedException($allowed, "Method {$method} is not allowed for action '{$base}'");
            }

            // Otherwise the segment is a parameter of the default action
        }

        // Index or __invoke is used by default
        $default = $refl->hasMethod(RouterInterface::FALLBACK_ACTION) ? RouterInterface::FALLBACK_ACTION : self::DEFAULT_ACTION;
        $action = $this->selectAction($refl, $default, $method, $owned);
        if ($action !== null) {
            return $action;
        }
        $allowed = $this->allowedMethods($refl, $default, $owned, $m->parts, $m);
        if ($allowed !== []) {
            throw new MethodNotAllowedException($allowed, "Method {$method} is not allowed for action '{$default}'");
        }
        throw new RouteNotFoundException("Controller '{$class}' does not have an action '{$default}'");
    }

    /**
     * The methods that may answer a base action for an HTTP method, by
     * preference: the verb suffixed action, then for HEAD the GET one, then
     * for a read the bare name. A base ending with a verb is never bare: the
     * verb is always a suffix (`lastPostGet()` is the GET of `/last-post/`).
     *
     * @return list<string>
     */
    private function candidates(string $base, string $method): array
    {
        $candidates = [$base . RestActionNaming::methodSuffix($method)];
        if ($method === 'HEAD') {
            $candidates[] = $base . 'Get';
        }
        if (in_array($method, self::BARE_METHODS, true) && RestActionNaming::verbSuffix($base) === null) {
            $candidates[] = $base;
        }
        return $candidates;
    }

    /**
     * The url segment of a base action, spelled from its declared name since
     * PHP method names ignore case, or null when no method answers it.
     *
     * @param ReflectionClass<object> $refl
     * @param array<string,true> $owned Canonical actions explicit routing owns
     */
    private function canonicalSegment(ReflectionClass $refl, string $base, array $owned): ?string
    {
        foreach (self::HTTP_METHODS as $httpMethod) {
            foreach ($this->candidates($base, $httpMethod) as $candidate) {
                if ($this->isRoutableAction($refl, $candidate, $owned)) {
                    return self::segmentOf($refl->getMethod($candidate)->getName());
                }
            }
        }
        return null;
    }

    /**
     * The url segment of a declared action name: `changePost` is `change`,
     * `typedInt` is `typed-int`.
     */
    private static function segmentOf(string $action): string
    {
        return Str::decamelize(RestActionNaming::stripVerbSuffix($action));
    }

    /**
     * The action answering a base action for an HTTP method, if any.
     *
     * @param ReflectionClass<object> $refl
     * @param array<string,true> $owned Canonical actions explicit routing owns
     * @throws Ex When the bare name and its Get variant would both answer GET
     */
    private function selectAction(ReflectionClass $refl, string $base, string $method, array $owned): ?string
    {
        if (
            RestActionNaming::verbSuffix($base) === null
            && $this->isRoutableAction($refl, $base, $owned)
            && $this->isRoutableAction($refl, $base . 'Get', $owned)
        ) {
            throw new Ex("Actions '{$base}' and '{$base}Get' of '{$refl->getName()}' both answer GET, keep only one");
        }
        foreach ($this->candidates($base, $method) as $candidate) {
            if ($this->isRoutableAction($refl, $candidate, $owned)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Can this method be reached through routing? The rule is shared with
     * declared routes: public, non-static, non-magic except __invoke, and
     * never an action explicit routing owns.
     *
     * @param ReflectionClass<object> $refl
     * @param array<string,true> $owned Canonical actions explicit routing owns
     */
    private function isRoutableAction(ReflectionClass $refl, string $action, array $owned): bool
    {
        return (
            !$this->isOwnedAction($refl, $action, $owned)
            && $action !== ''
            && $refl->hasMethod($action)
            && ActionSignature::isAdmissible($refl->getMethod($action))
        );
    }

    /**
     * Whether this method exists as a routable action but explicit routing
     * owns it, whatever the letter case it was declared with.
     *
     * @param ReflectionClass<object> $refl
     * @param array<string,true> $owned Canonical actions explicit routing owns
     */
    private function isOwnedAction(ReflectionClass $refl, string $action, array $owned): bool
    {
        return (
            $action !== ''
            && $refl->hasMethod($action)
            && ActionSignature::isAdmissible($refl->getMethod($action))
            && isset($owned[RouteCollection::canonicalTarget($refl->getName(), $action)])
        );
    }

    /**
     * The HTTP methods whose variant of a base action would match the url:
     * the method exists AND its signature takes the remaining segments. A
     * variant that could not consume them is no reason for a 405, the url is
     * a 404 for every method.
     *
     * @param ReflectionClass<object> $refl
     * @param array<string,true> $owned Canonical actions explicit routing owns
     * @param string[] $parts The segments the action would receive
     * @return list<string>
     */
    private function allowedMethods(ReflectionClass $refl, string $base, array $owned, array $parts, ConventionMatch $m): array
    {
        $allowed = [];
        foreach (self::HTTP_METHODS as $httpMethod) {
            $action = $this->selectAction($refl, $base, $httpMethod, $owned);
            if ($action === null) {
                continue;
            }
            try {
                $this->collectParameters(new ConventionMatch($m->request, array_values($parts)), $refl, $action);
            } catch (RouteNotFoundException) {
                continue;
            }
            $allowed[] = $httpMethod;
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

        /** @var list<string> $values */
        $values = array_map(RoutePath::decode(...), $m->parts);
        /** @var array<int<0,max>|string,mixed> $params */
        $params = $values;
        $i = 0;
        $extra = false;
        foreach ($actionParams as $actionParam) {
            $paramName = $actionParam->getName();

            $satisfiedByUrl = isset($values[$i]);
            if (!$actionParam->isOptional() && !$actionParam->isDefaultValueAvailable() && !$satisfiedByUrl) {
                throw new RouteNotFoundException("Param '{$paramName}' is required for action '{$action}' on '{$class}'");
            }

            // Services belong to the constructor: an action argument is either a
            // route segment or the trailing input, never something the container
            // could fill in silently.
            if (ActionSignature::hasClassType($actionParam)) {
                throw new Ex(
                    "Parameter '{$paramName}' of action '{$action}' on '{$class}' must be a route scalar"
                    . ' or a trailing '
                    . RequestInput::class,
                );
            }

            // Strictly coerce and validate built-in typed parameters.
            // An invalid value does not match the route (404).
            $type = $actionParam->getType();
            if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                if ($actionParam->isVariadic()) {
                    // Every segment consumed by the variadic is an argument
                    for ($j = $i, $count = count($values); $j < $count; $j++) {
                        if ($values[$j] !== '') {
                            $params[$j] = ActionSignature::coerce($type->getName(), $values[$j]);
                        }
                    }
                } elseif (isset($values[$i]) && $values[$i] !== '') {
                    $params[$i] = ActionSignature::coerce($type->getName(), $values[$i]);
                }
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
        $inputClass = ActionSignature::inputOf($actionParams, $action, $class);
        if ($inputClass !== null) {
            array_pop($actionParams);
        }
        return $inputClass;
    }
}
