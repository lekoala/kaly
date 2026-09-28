<?php

declare(strict_types=1);

namespace Kaly\Router;

use InvalidArgumentException;
use Kaly\Core\Ex;
use Kaly\Http\MethodNotAllowedException;
use Kaly\Http\RedirectException;
use Kaly\Http\RequestInput;
use Kaly\Util\Str;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;

/**
 * Takes an uri and map it to a class
 *
 * - Handles multiple namespaces (using a default namespace if omitted)
 * - Check for locale as a prefix (can be restricted to a given set)
 * - Collect url parameters based on method signature
 */
class ClassRouter implements RouterInterface
{
    protected const PARAM_LOCALE = 'locale';

    /**
     * HTTP methods understood by the rest-style action suffix convention.
     */
    protected const HTTP_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

    protected string $defaultNamespace = 'App';
    protected string $controllerNamespace = 'Controller';
    protected string $controllerSuffix = 'Controller';
    protected string $defaultControllerName = 'Index';
    protected string $defaultAction = 'index';

    /**
     * Mounted module namespaces, by url segment: 'test-module' => 'TestModule'.
     * The default namespace needs no mount, it answers without prefix.
     * @var array<string,string>
     */
    protected array $mounts = [];
    /**
     * @var string[]
     */
    protected array $allowedLocales = [];
    /**
     * @var string[]
     */
    protected array $restrictLocaleToNamespaces = [];
    protected bool $forceTrailingSlash = true;
    /**
     * ISO 639 2 or 3, or 4 for future use
     */
    protected int $localeLength = 4;

    /**
     * Match a request and returns an array of parameters
     *
     * The router is a shared service: everything that belongs to the request
     * being matched lives in a local ConventionMatch, never on $this.
     */
    public function match(ServerRequestInterface $request): Route
    {
        RedirectUris::ensureTrailingSlash($request, $this->forceTrailingSlash);

        $m = new ConventionMatch($request, $this->collectParts($request));
        $route = new Route();

        $route->segments = array_merge([], $m->parts);

        // Maybe we have a locale as a prefix
        $locale = $this->findLocale($m);
        $route->locale = $locale;

        // Do we have a specific module ?
        $module = $this->findModule($m);
        $route->module = $module;
        $route->namespace = $module;

        $this->enforceLocaleModuleUri($m, $route);

        // First we need to check if we have the controller
        $controller = $this->findController($m, $route->namespace);

        $route->controller = $controller;
        // We need a reflection for next methods
        $reflectionClass = new ReflectionClass($controller);

        // If the action exists (or index if set)
        $action = $this->findAction($m, $reflectionClass);
        $route->action = $action;

        // Remaining parts are passed as arguments to the action
        $params = $this->collectParameters($m, $reflectionClass, $action);
        $route->params = $params;
        $route->inputClass = $m->inputClass;
        $route->middlewares = RouteMiddlewares::ofAction($controller, $action);

        return $route;
    }

    /**
     * Build a redirect uri by replacing a path segment.
     *
     * Thin wrapper over RedirectUris: the router configuration travels
     * implicitly so call sites stay intention-revealing.
     */
    protected function getRedirectUri(ConventionMatch $m, string $remove, string $replace = ''): UriInterface
    {
        return RedirectUris::replaceSegment($m->request, $remove, $replace, $this->forceTrailingSlash);
    }

    /**
     * @return string[]
     */
    protected function collectParts(ServerRequestInterface $request): array
    {
        $trimmedPath = trim($request->getUri()->getPath(), '/');
        // Only drop empty segments: "0" is a valid value and must be preserved.
        // array_values keeps the parts as a list even with duplicated slashes.
        $parts = array_filter(explode('/', $trimmedPath), static fn(string $part): bool => $part !== '');
        return array_values($parts);
    }

    /**
     * @param string|array<mixed> $handler
     * @param array<string, mixed> $params
     * @return string
     */
    public function generate($handler, array $params = []): string
    {
        $locale = null;
        if (is_string($handler) || array_is_list($handler)) {
            // Split string
            if (is_string($handler)) {
                $handler = str_replace('->', '::', $handler);
                $parts = explode('::', $handler);
            } else {
                // Use array as an exploded string
                $parts = $handler;
            }
            /** @var string $class  */
            $class = $parts[0];
            /** @var string $action  */
            $action = $parts[1] ?? $this->defaultAction;
            if (isset($params[self::PARAM_LOCALE])) {
                /** @var string $locale */
                $locale = $params[self::PARAM_LOCALE];
                unset($params[self::PARAM_LOCALE]);
            }
        } else {
            if (empty($handler[RouterInterface::CONTROLLER])) {
                throw new RuntimeException('Cannot generate an url without a controller');
            }
            $class = $handler[RouterInterface::CONTROLLER];
            if (!is_string($class)) {
                throw new RuntimeException('Controller must be a string');
            }
            /** @var string $action */
            $action = $handler[RouterInterface::ACTION] ?? $this->defaultAction;
            /** @var string $locale */
            $locale = $handler[RouterInterface::LOCALE] ?? '';
        }

        // Validate it's a real class and action
        if (!class_exists($class)) {
            throw new RuntimeException("Handler '{$class}' does not exist");
        }
        if (!method_exists($class, $action)) {
            throw new RuntimeException("Invalid handler method '{$action}'");
        }

        $refl = new ReflectionClass($class);
        $method = $refl->getMethod($action);

        $classParts = explode("\\", $class);
        $baseClass = array_pop($classParts);
        $controllerName = (string) preg_replace('/' . $this->controllerSuffix . '$/', '', $baseClass);
        $namespace = implode("\\", $classParts);

        // Get the mount point of the module
        $moduleNamespace = str_replace("\\" . $this->controllerNamespace, '', $namespace);

        $url = '';
        if ($locale && !in_array($locale, $this->allowedLocales, true)) {
            throw new RuntimeException("Invalid locale '{$locale}'");
        }
        if ($this->defaultNamespace !== $moduleNamespace) {
            $segment = array_search($moduleNamespace, $this->mounts, true);
            if ($segment === false) {
                throw new RuntimeException("Namespace '{$moduleNamespace}' is not mounted on the convention router");
            }
            $url .= "/{$segment}";
        }
        if ($controllerName !== $this->defaultControllerName || $action !== $this->defaultAction || count($params)) {
            $strcontroller = Str::decamelize($controllerName);
            $url .= "/{$strcontroller}";
        }
        if ($action !== $this->defaultAction || count($params)) {
            // Check for rest style action
            $action = preg_replace('/(Post|Delete|Put|Head|Patch|Get|Options)$/', '', $action);
            $url .= "/{$action}";
        }
        if ($locale && $url) {
            if (empty($this->restrictLocaleToNamespaces) || in_array($moduleNamespace, $this->restrictLocaleToNamespaces, true)) {
                $url = "/{$locale}" . $url;
            }
        }
        // append params
        foreach ($params as $k => $v) {
            if (!is_string($v)) {
                continue;
            }
            $url .= "/{$v}";
        }
        if ($this->forceTrailingSlash) {
            $url .= '/';
        }

        return $url;
    }

    /**
     * Make sure locale is present if needed. See allowedLocales.
     * @param Route $route
     * @return void
     */
    protected function enforceLocaleModuleUri(ConventionMatch $m, Route $route): void
    {
        $uri = $m->request->getUri();

        $module = $route->module;
        $locale = $route->locale;

        $isRestricted = true;
        if (!empty($this->restrictLocaleToNamespaces)) {
            $isRestricted = in_array($module, $this->restrictLocaleToNamespaces, true);
        }

        // Is there a locale when it shouldn't be ?
        if ($module && $locale && !$isRestricted) {
            $newUri = $this->getRedirectUri($m, $locale, '');
            throw new RedirectException($newUri);
        }
        // If we have a multilingual setup, the locale is required except for restricted namespaces
        if (count($this->allowedLocales) > 1 && !$locale && $isRestricted) {
            // Except on the home page
            if (count($m->parts) > 0) {
                $newUri = $uri->withPath($this->allowedLocales[0] . $uri->getPath());
                throw new RedirectException($newUri);
            }
        }
        // Single language is forced through the router
        if (!$locale && !empty($this->allowedLocales)) {
            $route->locale = $this->allowedLocales[0];
        }
    }

    protected function findLocale(ConventionMatch $m): ?string
    {
        if (empty($this->allowedLocales) || empty($m->parts[0])) {
            return null;
        }
        $part = strtolower($m->parts[0]);

        $locale = null;
        if (in_array($part, $this->allowedLocales, true)) {
            array_shift($m->parts);
            $locale = $part;
        }

        // Don't allow the default locale as the only parameter
        if ($locale && count($m->parts) === 0 && $locale === $this->allowedLocales[0]) {
            throw new RedirectException($this->getRedirectUri($m, $locale, ''));
        }

        return $locale;
    }

    protected function findModule(ConventionMatch $m): string
    {
        // Check the first segment if it exists
        $part = $m->parts[0] ?? '';
        if ($part === '') {
            return $this->defaultNamespace;
        }

        // Does it match a mounted module? A mount always has priority over the
        // default namespace: /admin/something reaches the Admin module, not an
        // AdminController of the default one
        $segment = Str::decamelize($part);
        $namespace = $this->mounts[$segment] ?? null;
        if ($namespace === null) {
            return $this->defaultNamespace;
        }

        // A single canonical url: /TestModule/ or /Test-Module/ redirect
        if ($part !== $segment) {
            throw new RedirectException($this->getRedirectUri($m, $part, $segment));
        }

        array_shift($m->parts);

        return $namespace;
    }

    /**
     * Find a controller based on the first two parts of the request
     * @return class-string
     */
    protected function findController(ConventionMatch $m, ?string $namespace): string
    {
        $uri = $m->request->getUri();
        $path = $uri->getPath();

        // Check the first segment if it exists
        $part = $m->parts[0] ?? '';
        $camelPart = Str::camelize($part);

        // Don't allow calling camelized parts, we use lowercase
        if ($part && $part === $camelPart) {
            $newUri = $this->getRedirectUri($m, $camelPart, $part);
            throw new RedirectException($newUri);
        }

        // Do not allow direct /index calls
        $defaultController = strtolower($this->defaultControllerName);
        if ($part === $defaultController && count($m->parts) === 1) {
            $newUri = $this->getRedirectUri($m, $defaultController, '');
            throw new RedirectException($newUri);
        }

        // Default to index or match controller
        if (!$part) {
            $defaultController = $this->defaultControllerName . $this->controllerSuffix;
            $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $defaultController;
        } else {
            $controller = $camelPart . $this->controllerSuffix;
            $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $controller;
        }

        // Does controller exists ? it must be autoloadable
        if (!class_exists($class)) {
            throw new RouteNotFoundException("Route '{$path}' not found, '{$class}' doesn't exists");
        }
        $refl = new ReflectionClass($class);
        if ($refl->isAbstract()) {
            throw new RouteNotFoundException("Route '{$path}' not found, '{$class}' isn't instantiable");
        }

        array_shift($m->parts);

        return $class;
    }

    /**
     * Find a matching action based on the next part of the request
     * @param ReflectionClass<object> $refl
     */
    protected function findAction(ConventionMatch $m, ReflectionClass $refl): string
    {
        $method = $m->request->getMethod();
        $class = $refl->getName();

        $testPart = $m->parts[0] ?? '';

        // Index or __invoke is used by default
        $action = $refl->hasMethod(RouterInterface::FALLBACK_ACTION) ? RouterInterface::FALLBACK_ACTION : $this->defaultAction;

        // If first parameter is a valid method, use that instead
        if ($testPart) {
            // Action should be lowercase camelcase
            $testAction = Str::camelize($testPart, false);
            // Rest style routing: the HTTP method is added at the end to avoid
            // confusion with getters
            $methodSuffix = RestActionNaming::methodSuffix($method);
            $testActionWithMethod = $testAction . $methodSuffix;

            // Don't allow controller/index to be called directly because it would create duplicated urls
            // This only applies if no other parameters is passed in the url
            if ($testAction === $this->defaultAction && count($m->parts) === 1) {
                $newUri = $this->getRedirectUri($m, $this->defaultAction, '');
                throw new RedirectException($newUri);
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

            // Shift param if method is found
            if ($this->isRoutableAction($refl, $testActionWithMethod)) {
                array_shift($m->parts);
                $action = $testActionWithMethod;
            } elseif ($this->isRoutableAction($refl, $testAction)) {
                array_shift($m->parts);
                $action = $testAction;
            } else {
                // The action may exist for other HTTP verbs only
                $allowed = $this->findAllowedMethods($refl, $testAction);
                if (!empty($allowed)) {
                    throw new MethodNotAllowedException($allowed, "Method {$method} is not allowed for action '{$testAction}'");
                }
            }

            // More validation will take place in collectParameters
        }

        // Is this action available ?
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
    protected function isRoutableAction(ReflectionClass $refl, string $action): bool
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
     * List the HTTP methods supported by a base action through the
     * rest-style action suffix convention.
     *
     * @param ReflectionClass<object> $refl
     * @return string[]
     */
    protected function findAllowedMethods(ReflectionClass $refl, string $baseAction): array
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
     * Tries to call action with remaining parts of the request
     * @param ReflectionClass<object> $refl
     * @param string $action
     * @return array<int<0,max>|string,mixed>
     */
    protected function collectParameters(ConventionMatch $m, ReflectionClass $refl, string $action): array
    {
        $class = $refl->getName();

        $method = $refl->getMethod($action);
        if (!$method->isPublic()) {
            throw new RouteNotFoundException("Action '{$action}' is not public on '{$class}'");
        }

        // Verify parameters
        $actionParams = $method->getParameters();

        // A trailing RequestInput is supplied by the dispatcher, not by the url
        $m->inputClass = $this->extractInputClass($actionParams, $class, $action);

        /** @var array<string,mixed> $params  */
        $params = $m->parts;
        $i = 0;
        $extra = false;
        foreach ($actionParams as $actionParam) {
            $paramName = $actionParam->getName();

            // Every remaining parameter is a url segment: the trailing input
            // has already been removed above.
            $satisfiedByUrl = isset($m->parts[$i]);
            if (!$actionParam->isOptional() && !$actionParam->isDefaultValueAvailable() && !$satisfiedByUrl) {
                throw new RouteNotFoundException("Param '{$paramName}' is required for action '{$action}' on '{$class}'");
            }

            $value = $m->parts[$i] ?? '';
            $type = $actionParam->getType();

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
            if ($type instanceof ReflectionNamedType && $value !== '' && $type->isBuiltin()) {
                $value = RouteParamCoercer::coerce($type->getName(), $value);

                // Update value
                $params[$i] = $value;
            }

            // Extra parameters are accepted
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
     * Pull the trailing RequestInput out of the parameters the router has to
     * satisfy from the url. There is at most one and it is always last.
     *
     * @param ReflectionParameter[] $actionParams Mutated: the input is removed
     * @return class-string<RequestInput>|null
     */
    protected function extractInputClass(array &$actionParams, string $class, string $action): ?string
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
    protected static function inputClassOf(ReflectionParameter $param): ?string
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
    protected static function hasClassType(ReflectionParameter $param): bool
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

    /**
     * Mounted namespaces, by url segment
     * @return array<string,string>
     */
    public function getMounts(): array
    {
        return $this->mounts;
    }

    /**
     * Expose a module namespace under a url segment: mount('shop', 'Shop')
     * routes /shop/cart/ to Shop\Controller\CartController.
     *
     * The app mounts every module automatically, under its decamelized name.
     */
    public function mount(string $segment, string $namespace): self
    {
        if ($segment === '' || $segment !== Str::decamelize($segment) || str_contains($segment, '/')) {
            throw new InvalidArgumentException("Mount segment '{$segment}' must be a single lowercase url segment (eg: 'my-module')");
        }
        $existing = $this->mounts[$segment] ?? null;
        if ($existing !== null && $existing !== $namespace) {
            throw new InvalidArgumentException("Segment '{$segment}' is already mounted for '{$existing}'");
        }
        $this->mounts[$segment] = $namespace;
        return $this;
    }

    /**
     * The namespace answering without prefix
     */
    public function getDefaultNamespace(): string
    {
        return $this->defaultNamespace;
    }

    public function setDefaultNamespace(string $defaultNamespace): self
    {
        $this->defaultNamespace = $defaultNamespace;
        return $this;
    }

    /**
     * Get the value of controllerNamespace
     */
    public function getControllerNamespace(): string
    {
        return $this->controllerNamespace;
    }

    /**
     * Set the value of controllerNamespace
     */
    public function setControllerNamespace(string $controllerNamespace): self
    {
        $this->controllerNamespace = $controllerNamespace;
        return $this;
    }

    /**
     * Get the value of controllerSuffix
     */
    public function getControllerSuffix(): string
    {
        return $this->controllerSuffix;
    }

    /**
     * Set the value of controllerSuffix
     */
    public function setControllerSuffix(string $controllerSuffix): self
    {
        $this->controllerSuffix = $controllerSuffix;
        return $this;
    }

    /**
     * Get the value of defaultControllerName
     */
    public function getDefaultControllerName(): string
    {
        return $this->defaultControllerName;
    }

    /**
     * Set the value of defaultControllerName
     */
    public function setDefaultControllerName(string $defaultControllerName): self
    {
        $this->defaultControllerName = $defaultControllerName;
        return $this;
    }

    /**
     * Get the value of forceTrailingSlash
     */
    public function getForceTrailingSlash(): bool
    {
        return $this->forceTrailingSlash;
    }

    /**
     * Set the value of forceTrailingSlash
     */
    public function setForceTrailingSlash(bool $forceTrailingSlash): self
    {
        $this->forceTrailingSlash = $forceTrailingSlash;
        return $this;
    }

    /**
     * Get the value of allowedLocales
     * @return string[]
     */
    public function getAllowedLocales(): array
    {
        return $this->allowedLocales;
    }

    /**
     * Set the value of allowedLocales
     * @param string[] $allowedLocales
     * @param string[] $namespaces
     */
    public function setAllowedLocales(array $allowedLocales, ?array $namespaces = null): self
    {
        $this->allowedLocales = $allowedLocales;
        if ($namespaces !== null) {
            $this->restrictLocaleToNamespaces = $namespaces;
        }
        return $this;
    }

    /**
     * Get the value of restrictLocaleToNamespaces
     * @return string[]
     */
    public function getRestrictLocaleToNamespaces(): array
    {
        return $this->restrictLocaleToNamespaces;
    }

    /**
     * Set the value of restrictLocaleToNamespaces
     * @param string[] $restrictLocaleToNamespaces
     */
    public function setRestrictLocaleToNamespaces(array $restrictLocaleToNamespaces): self
    {
        $this->restrictLocaleToNamespaces = $restrictLocaleToNamespaces;
        return $this;
    }

    /**
     * Get the value of localeLength
     */
    public function getLocaleLength(): int
    {
        return $this->localeLength;
    }

    /**
     * Set the value of localeLength
     */
    public function setLocaleLength(int $localeLength): self
    {
        $this->localeLength = $localeLength;
        return $this;
    }
}
