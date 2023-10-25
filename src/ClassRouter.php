<?php

declare(strict_types=1);

namespace Kaly;

use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use Psr\Http\Message\UriInterface;
use Kaly\Interfaces\RouterInterface;
use Kaly\Exceptions\NotFoundException;
use Kaly\Exceptions\RedirectException;
use Psr\Http\Message\ServerRequestInterface;
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
    protected const PARAM_LOCALE = "locale";

    protected string $defaultNamespace = 'App';
    protected string $controllerNamespace = 'Controller';
    protected string $controllerSuffix = 'Controller';
    protected string $defaultControllerName = 'Index';
    protected string $defaultAction = 'index';

    /**
     * A map of module => mapped module
     * @var array<string, string>
     */
    protected array $allowedNamespaces = [];
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
     * ISO 639 2 or 3, or 4 for future use, alpha
     */
    protected int $localeLength = 4;

    protected ServerRequestInterface $request;
    /**
     * @var string[]
     */
    protected array $parts;

    /**
     * Match a request and returns an array of parameters
     * @return array<string, mixed>
     */
    public function match(ServerRequestInterface $request): array
    {
        $this->request = $request;

        $routeParams = [];

        $this->redirectTrailingSlash();
        $this->parts = $this->collectParts();

        $routeParams[RouterInterface::SEGMENTS] = array_merge([], $this->parts);

        // Maybe we have a locale as a prefix
        $locale = $this->findLocale();
        $routeParams[RouterInterface::LOCALE] = $locale;

        // Do we have a specific module ?
        $module = $this->findModule();
        $routeParams[RouterInterface::MODULE] = $module;
        $routeParams[RouterInterface::NAMESPACE] = $this->allowedNamespaces[$module] ?? $module;

        $this->enforceLocaleModuleUri($routeParams);

        // First we need to check if we have the controller
        $controller = $this->findController($routeParams[RouterInterface::NAMESPACE]);
        $routeParams[RouterInterface::CONTROLLER] = $controller;
        // We need a reflection for next methods
        $reflectionClass = new ReflectionClass($controller);

        // If the action exists (or index if set)
        $action = $this->findAction($reflectionClass);
        $routeParams[RouterInterface::ACTION] = $action;

        // Remaining parts are passed as arguments to the action
        $params = $this->collectParameters($reflectionClass, $action);
        $routeParams[RouterInterface::PARAMS] = $params;

        // This will allow us to find a matching template to render controller's result
        $template = $this->matchTemplate($routeParams);
        $routeParams[RouterInterface::TEMPLATE] = $template;

        return $routeParams;
    }

    /**
     * @return string[]
     */
    protected function collectParts(): array
    {
        $trimmedPath = trim($this->request->getUri()->getPath(), '/');
        $parts = array_filter(explode("/", $trimmedPath));
        return $parts;
    }

    /**
     * @throws RedirectException
     */
    protected function redirectTrailingSlash(): void
    {
        $uri = $this->request->getUri();
        $path = $uri->getPath();
        if ($this->forceTrailingSlash) {
            if (!str_ends_with($path, '/')) {
                $newUri = $uri->withPath($path . "/");
                throw new RedirectException($newUri);
            }
        } else {
            if (str_ends_with($path, '/')) {
                $newUri = $uri->withPath(rtrim($path, '/'));
                throw new RedirectException($newUri);
            }
        }
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
                $handler = str_replace("->", "::", $handler);
                $parts = explode("::", $handler);
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
                throw new RuntimeException("Cannot generate an url without a controller");
            }
            $class = $handler[RouterInterface::CONTROLLER];
            if (!is_string($class)) {
                throw new RuntimeException("Controller must be a string");
            }
            /** @var string $action */
            $action = $handler[RouterInterface::ACTION] ?? $this->defaultAction;
            /** @var string $locale */
            $locale = $handler[RouterInterface::LOCALE] ?? '';
        }

        // Validate it's a real class and action
        if (!class_exists($class)) {
            throw new RuntimeException("Handler '$class' does not exist");
        }
        if (!method_exists($class, $action)) {
            throw new RuntimeException("Invalid handler method '$action'");
        }

        $refl = new ReflectionClass($class);
        $method = $refl->getMethod($action);

        $classParts = explode("\\", $class);
        $baseClass = array_pop($classParts);
        $controllerName = (string)preg_replace("/" . $this->controllerSuffix . "$/", "", $baseClass);
        $namespace = implode("\\", $classParts);

        // Get module for class
        $allowedNamespaces = array_flip($this->allowedNamespaces);
        $realModuleNamespace = $moduleNamespace = str_replace("\\" . $this->controllerNamespace, "", $namespace);
        if (isset($allowedNamespaces[$moduleNamespace])) {
            $realModuleNamespace = $allowedNamespaces[$moduleNamespace];
        }

        $url = '';
        if ($locale && !in_array($locale, $this->allowedLocales)) {
            throw new RuntimeException("Invalid locale '$locale'");
        }
        if ($this->defaultNamespace != $realModuleNamespace) {
            $strmodule = decamelize($realModuleNamespace);
            $url .= "/$strmodule";
        }
        if ($controllerName != $this->defaultControllerName || $action != $this->defaultAction || count($params)) {
            $strcontroller = decamelize($controllerName);
            $url .= "/$strcontroller";
        }
        if ($action != $this->defaultAction || count($params)) {
            // Check for rest style action
            $action = preg_replace("/(Get|Post|Delete|Put|Head|Patch)$/", "", $action);
            $url .= "/$action";
        }
        if ($locale && $url) {
            if (
                empty($this->restrictLocaleToNamespaces)
                || in_array($realModuleNamespace, $this->restrictLocaleToNamespaces)
            ) {
                $url = "/$locale" . $url;
            }
        }
        // append params
        foreach ($params as $k => $v) {
            if (!is_string($v)) {
                continue;
            }
            $url .= "/$v";
        }
        if ($this->forceTrailingSlash) {
            $url .= "/";
        }

        return $url;
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    protected function matchTemplate(array $routeParams): string
    {
        $method = $this->request->getMethod();

        /** @var string $controller  */
        $controller = $routeParams[RouterInterface::CONTROLLER];
        $controllerFolder = mb_strtolower(get_class_name($controller));
        $controllerFolder = mb_substr($controllerFolder, 0, - (strlen($this->controllerSuffix)));

        /** @var string $action  */
        $action = $routeParams[RouterInterface::ACTION];
        // Remove method from action
        if (str_ends_with($action, ucfirst(strtolower($method)))) {
            $action = substr($action, 0, strlen($action) - strlen($method));
        }
        $viewName = $controllerFolder . '/' . $action;
        if (
            isset($routeParams[RouterInterface::MODULE])
            && $routeParams[RouterInterface::MODULE] !== $this->defaultNamespace
        ) {
            $viewName = '@' . $routeParams[RouterInterface::MODULE] . '/' . $viewName;
        }

        return $viewName;
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    protected function enforceLocaleModuleUri(array &$routeParams): void
    {
        $uri = $this->request->getUri();

        /** @var string $module  */
        $module = $routeParams[RouterInterface::MODULE];
        /** @var string $locale  */
        $locale = $routeParams[RouterInterface::LOCALE];
        /** @var string[] $parts  */
        $parts = $routeParams[RouterInterface::SEGMENTS];

        $isRestricted = true;
        if (!empty($this->restrictLocaleToNamespaces)) {
            $isRestricted = in_array($module, $this->restrictLocaleToNamespaces);
        }

        // Is there a locale when it shouldn't be ?
        if ($module && $locale && !$isRestricted) {
            $newUri = $this->getRedirectUri($locale, '');
            throw new RedirectException($newUri);
        }
        // If we have a multilingual setup, the locale is required except for restrict namespaces
        if (count($this->allowedLocales) > 1 && !$locale && $isRestricted) {
            // Except on the home page
            if (count($this->parts) > 0) {
                $newUri = $uri->withPath($this->allowedLocales[0] . $uri->getPath());
                throw new RedirectException($newUri);
            }
        }
        // Single language is forced through the router
        if (!$locale && !empty($this->allowedLocales)) {
            $routeParams[RouterInterface::LOCALE] = $this->allowedLocales[0];
        }
    }

    protected function getRedirectUri(string $remove, string $replace = ''): UriInterface
    {
        $uri = $this->request->getUri();
        $path = $uri->getPath();
        if ($replace) {
            $replace = '/' . $replace;
        }
        $path = str_replace('/' . $remove, $replace, $path);
        $path = rtrim($path, '/');
        if ($this->forceTrailingSlash) {
            $path .= '/';
        }
        return $uri->withPath($path);
    }

    protected function findLocale(): ?string
    {
        if (empty($this->allowedLocales) || empty($this->parts[0])) {
            return null;
        }
        $part = strtolower($this->parts[0]);

        $locale = null;
        if (in_array($part, $this->allowedLocales)) {
            array_shift($this->parts);
            $locale = $part;
        }

        // Don't allow the default locale as the only parameter
        if ($locale && count($this->parts) === 0 && $locale == $this->allowedLocales[0]) {
            throw new RedirectException($this->getRedirectUri($locale, ''));
        }

        return $locale;
    }

    protected function findModule(): string
    {
        $module = $this->defaultNamespace;

        // Check the first segment if it exists
        $part = $this->parts[0] ?? '';
        $camelPart = camelize($part);

        // Does it match a specific namespace? (not the default one)
        // More specific namespaces always have priority over default
        if (array_key_exists($camelPart, $this->allowedNamespaces)) {
            // Don't allow calling camelized parts, we use lowercase
            if ($part && $part !== strtolower($part)) {
                throw new RedirectException($this->getRedirectUri($part, decamelize($part)));
            }

            $module = $camelPart;
            // Remove from parts
            array_shift($this->parts);
        }
        return $module;
    }

    /**
     * Find a controller based on the first two parts of the request
     * @return class-string
     */
    protected function findController(string $namespace): string
    {
        $uri = $this->request->getUri();
        $path = $uri->getPath();

        // Check the first segment if it exists
        $part = $this->parts[0] ?? '';
        $camelPart = camelize($part);

        // Don't allow calling camelized parts, we use lowercase
        if ($part && $part === $camelPart) {
            $newUri = $this->getRedirectUri($camelPart, $part);
            throw new RedirectException($newUri);
        }

        // Do not allow direct /index calls
        $defaultController = strtolower($this->defaultControllerName);
        if ($part === $defaultController && count($this->parts) === 1) {
            $newUri = $this->getRedirectUri($defaultController, '');
            throw new RedirectException($newUri);
        }

        // Default to index or match controller
        if (!$part) {
            $defaultController =  $this->defaultControllerName . $this->controllerSuffix;
            $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $defaultController;
        } else {
            $controller = $camelPart . $this->controllerSuffix;
            $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $controller;
        }

        // Does controller exists ?
        if (!class_exists($class)) {
            throw new NotFoundException("Route '$path' not found. '$class' doesn't exists.");
        }
        $refl = new ReflectionClass($class);
        if ($refl->isAbstract()) {
            throw new NotFoundException("Route '$path' not found. '$class' isn't instantiable.");
        }

        array_shift($this->parts);

        return $class;
    }

    /**
     * Find a matching action based on the next part of the request
     * @param ReflectionClass<object> $refl
     */
    protected function findAction(ReflectionClass $refl): string
    {
        $method = $this->request->getMethod();
        $class = $refl->getName();

        $testPart = $this->parts[0] ?? '';

        // Index or __invoke is used by default
        $action = $refl->hasMethod(RouterInterface::FALLBACK_ACTION) ?
            RouterInterface::FALLBACK_ACTION :
            $this->defaultAction;

        // If first parameter is a valid method, use that instead
        if ($testPart) {
            // Action should be lowercase camelcase
            $testAction = camelize($testPart, false);
            // Rest style routing
            // Method is added at the end to avoid confusion with getters
            $testActionWithMethod = $testAction . ucfirst(strtolower($method));

            // Don't allow controller/index to be called directly because it would create duplicated urls
            // This only applies if no other parameters is passed in the url
            if ($testAction == $this->defaultAction && count($this->parts) === 1) {
                $newUri = $this->getRedirectUri($this->defaultAction, '');
                throw new RedirectException($newUri);
            }

            // Shift param if method is found
            if ($refl->hasMethod($testActionWithMethod)) {
                array_shift($this->parts);
                $action = $testActionWithMethod;
            } elseif ($refl->hasMethod($testAction)) {
                array_shift($this->parts);
                $action = $testAction;
            }

            // More validation will take place in collectParameters
        }

        // Is this action available ?
        if (!$refl->hasMethod($action)) {
            throw new NotFoundException("Controller '$class' does not have an action '$action'");
        }

        return $action;
    }

    /**
     * Tries to call action with remaining parts of the request
     * @param ReflectionClass<object> $refl
     * @param string $action
     * @return array<array-key, mixed>
     */
    protected function collectParameters(ReflectionClass $refl, string $action): array
    {
        $class = $refl->getName();

        $method = $refl->getMethod($action);
        if (!$method->isPublic()) {
            throw new NotFoundException("Action '$action' is not public on '$class'");
        }

        // Verify parameters
        $actionParams = $method->getParameters();

        // First parameter must accept a request object to show it's a valid action
        $firstParam = array_shift($actionParams);
        if (!$firstParam) {
            throw new NotFoundException("Action '$action' cannot handle a request");
        }
        $fpType = $firstParam->getType();
        if (!$fpType instanceof ReflectionNamedType || $fpType->getName() !== ServerRequestInterface::class) {
            throw new NotFoundException("Action '$action' cannot handle a request");
        }

        /** @var array<string, mixed> $params  */
        $params = $this->parts;
        $i = 0;
        foreach ($actionParams as $actionParam) {
            $paramName = $actionParam->getName();
            if (!$actionParam->isOptional() && !isset($this->parts[$i])) {
                throw new NotFoundException("Param '$paramName' is required for action '$action' on '$class'");
            }
            /** @var string $value  */
            $value = $this->parts[$i] ?? '';
            $type = $actionParam->getType();

            // getName is only available for ReflectionNamedType and __toString is deprecated
            if ($type instanceof ReflectionNamedType && $value && $type->isBuiltin()) {
                // Transform & validate
                $value = match ($type->getName()) {
                    'bool' => boolval($value),
                    'array' => explode(",", $value),
                    'string' => filter_var($value, FILTER_SANITIZE_FULL_SPECIAL_CHARS),
                    'int' => intval($value),
                    'float' => floatval($value),
                    default => $value,
                };

                // Update value
                $params[$i] = $value;
            }

            // Extra parameters are accepted
            if ($actionParam->isVariadic()) {
                return $params;
            }
            $i++;
        }
        if (count($params) > count($actionParams)) {
            throw new NotFoundException("Too many parameters for action '$action' on '$class'");
        }

        return $params;
    }

    /**
     * Get the value of allowedNamespaces
     * @return string[]
     */
    public function getAllowedNamespaces(): array
    {
        return $this->allowedNamespaces;
    }

    /**
     * Set the value of allowedNamespaces
     * @param array<string, string> $allowedNamespaces
     */
    public function setAllowedNamespaces(array $allowedNamespaces): self
    {
        $this->allowedNamespaces = $allowedNamespaces;
        return $this;
    }

    public function addAllowedNamespace(string $namespace, string $mapping = null): self
    {
        if (!$mapping) {
            $mapping = $namespace;
        }
        if (str_contains($mapping, "\\")) {
            throw new InvalidArgumentException("Mapping cannot contain namespace separator");
        }
        $this->allowedNamespaces[$mapping] = $namespace;
        return $this;
    }

    /**
     * Get the value of defaultNamespace
     */
    public function getDefaultNamespace(): string
    {
        return $this->defaultNamespace;
    }

    /**
     * Set the value of defaultNamespace
     */
    public function setDefaultNamespace(string $defaultNamespace, string $mapping = null): self
    {
        $this->defaultNamespace = $defaultNamespace;
        $this->addAllowedNamespace($defaultNamespace, $mapping);
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
