<?php

declare(strict_types=1);

namespace Kaly;

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
 * - Handles multiple namespaces (default one if omitted)
 * - Check for locale as a prefix (can be restricted to a set of namespaces)
 * - Collect url parameters based on method signature
 */
class ClassRouter implements RouterInterface
{
    protected string $defaultNamespace = 'App';
    protected string $controllerNamespace = 'Controller';
    protected string $controllerSuffix = 'Controller';
    protected string $defaultControllerName = 'Index';
    protected string $defaultAction = 'index';

    /**
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

    /**
     * Match a request and returns an array of parameters
     * @return array<string, mixed>
     */
    public function match(ServerRequestInterface $request): array
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        $routeParams = [];

        // Make sure we have a trailing slash or not
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

        $trimmedPath = trim($path, '/');
        $parts = array_filter(explode("/", $trimmedPath));
        $routeParams[RouterInterface::SEGMENTS] = $parts;

        // Maybe we have a locale as a prefix
        $locale = $this->findLocale($parts, $uri);
        $routeParams[RouterInterface::LOCALE] = $locale;

        // Do we have a specific module ?
        $module = $this->findModule($parts, $uri);
        $routeParams[RouterInterface::MODULE] = $module;
        $routeParams[RouterInterface::NAMESPACE] = $this->allowedNamespaces[$module] ?? $module;

        $this->enforceLocaleModuleUri($routeParams, $uri);

        // First we need to check if we have the controller
        $controller = $this->findController($routeParams[RouterInterface::NAMESPACE], $parts, $uri);
        $routeParams[RouterInterface::CONTROLLER] = $controller;
        // We need a reflection for next methods
        $refl = new ReflectionClass($controller);

        // If the action exists (or index if set)
        $action = $this->findAction($refl, $parts, $uri, $request->getMethod());
        $routeParams[RouterInterface::ACTION] = $action;

        // Remaining parts are passed as arguments to the action
        $params = $this->collectParameters($refl, $action, $parts);
        $routeParams[RouterInterface::PARAMS] = $params;

        // This will allow us to find a matching template to render controller's result
        $template = $this->matchTemplate($routeParams, $request->getMethod());
        $routeParams[RouterInterface::TEMPLATE] = $template;

        return $routeParams;
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
            if (is_string($handler)) {
                $handler = str_replace("->", "::", $handler);
                $parts = explode("::", $handler);
            } else {
                $parts = $handler;
            }
            $class = $parts[0];
            $action = $parts[1] ?? $this->defaultAction;
            if (isset($params['locale'])) {
                $locale = $params['locale'];
                unset($params['locale']);
            }
        } else {
            if (empty($handler[RouterInterface::CONTROLLER])) {
                throw new RuntimeException("Cannot generate an url without a controller");
            }
            $class = $handler[RouterInterface::CONTROLLER];
            $action = $handler[RouterInterface::ACTION] ?? $this->defaultAction;
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
        $allowedNamespaces = $this->allowedNamespaces;
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
            if (empty($this->restrictLocaleToNamespaces) || in_array($realModuleNamespace, $this->restrictLocaleToNamespaces)) {
                $url = "/$locale" . $url;
            }
        }
        // append params
        foreach ($params as $k => $v) {
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
    protected function matchTemplate(array $routeParams, string $method): string
    {
        $controllerFolder = mb_strtolower(get_class_name($routeParams[RouterInterface::CONTROLLER]));
        $controllerFolder = mb_substr($controllerFolder, 0, - (strlen($this->controllerSuffix)));

        $action = $routeParams[RouterInterface::ACTION];
        // Remove method from action
        if (str_ends_with($action, ucfirst(strtolower($method)))) {
            $action = substr($action, 0, strlen($action) - strlen($method));
        }
        $viewName = $controllerFolder . '/' . $action;
        if (isset($routeParams[RouterInterface::MODULE]) && $routeParams[RouterInterface::MODULE] !== $this->defaultNamespace) {
            $viewName = '@' . $routeParams[RouterInterface::MODULE] . '/' . $viewName;
        }

        return $viewName;
    }

    /**
     * @param array<string, mixed> $routeParams
     */
    protected function enforceLocaleModuleUri(array &$routeParams, UriInterface $uri): void
    {
        $module = $routeParams[RouterInterface::MODULE];
        $locale = $routeParams[RouterInterface::LOCALE];
        $parts = $routeParams[RouterInterface::SEGMENTS];

        $isRestricted = true;
        if (!empty($this->restrictLocaleToNamespaces)) {
            $isRestricted = in_array($module, $this->restrictLocaleToNamespaces);
        }

        // Is there a locale when it shouldn't be ?
        if ($module && $locale && !$isRestricted) {
            $newUri = $this->getRedirectUri($uri, $locale, '');
            throw new RedirectException($newUri);
        }
        // If we have a multilingual setup, the locale is required except for restrict namespaces
        if (count($this->allowedLocales) > 1 && !$locale && $isRestricted) {
            // Except on the home page
            if (count($parts) > 0) {
                $newUri = $uri->withPath($this->allowedLocales[0] . $uri->getPath());
                throw new RedirectException($newUri);
            }
        }
        // Single language is forced through the router
        if (!$locale && !empty($this->allowedLocales)) {
            $routeParams[RouterInterface::LOCALE] = $this->allowedLocales[0];
        }
    }

    protected function getRedirectUri(UriInterface $uri, string $remove, string $replace = ''): UriInterface
    {
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

    /**
     * @param array<mixed> $parts
     */
    protected function findLocale(array &$parts, UriInterface $uri): ?string
    {
        if (empty($this->allowedLocales) || empty($parts[0])) {
            return null;
        }
        $part = strtolower($parts[0]);

        $locale = null;
        if (in_array($part, $this->allowedLocales)) {
            array_shift($parts);
            $locale = $part;
        } elseif (strlen($part) <= $this->localeLength) {
            throw new NotFoundException("Invalid locale '$part'");
        }

        // Don't allow the default locale as the only parameter
        if ($locale && count($parts) === 0 && $locale == $this->allowedLocales[0]) {
            $newUri = $this->getRedirectUri($uri, $locale, '');
            throw new RedirectException($newUri);
        }

        return $locale;
    }

    /**
     * @param array<mixed> $parts
     */
    protected function findModule(array &$parts, UriInterface $uri): string
    {
        $module = $this->defaultNamespace;

        // Check the first segment if it exists
        $part = $parts[0] ?? '';
        $camelPart = camelize($part);

        // Does it match a specific namespace? (not the default one)
        // More specific namespaces always have priority over default
        if (array_key_exists($camelPart, $this->allowedNamespaces)) {
            // Don't allow calling camelized parts, we use lowercase
            if ($part && $part !== strtolower($part)) {
                $newUri = $this->getRedirectUri($uri, $part, decamelize($part));
                throw new RedirectException($newUri);
            }

            $module = $camelPart;
            // Remove from parts
            array_shift($parts);
        }
        return $module;
    }

    /**
     * Find a controller based on the first two parts of the request
     * @param string[] $parts
     * @return class-string
     */
    protected function findController(string $namespace, array &$parts, UriInterface $uri): string
    {
        $path = $uri->getPath();

        // Check the first segment if it exists
        $part = $parts[0] ?? '';
        $camelPart = camelize($part);

        // Don't allow calling camelized parts, we use lowercase
        if ($part && $part === $camelPart) {
            $newUri = $this->getRedirectUri($uri, $camelPart, $part);
            throw new RedirectException($newUri);
        }

        // Do not allow direct /index calls
        $defaultController = strtolower($this->defaultControllerName);
        if ($part === $defaultController && count($parts) === 1) {
            $newUri = $this->getRedirectUri($uri, $defaultController, '');
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

        array_shift($parts);

        return $class;
    }

    /**
     * Find a matching action based on the next part of the request
     * @param ReflectionClass<object> $refl
     * @param string[] $params
     */
    protected function findAction(ReflectionClass $refl, array &$params, UriInterface $uri, string $method): string
    {
        $class = $refl->getName();

        $testPart = $params[0] ?? '';

        // Index or __invoke is used by default
        $action = $refl->hasMethod('__invoke') ? '__invoke' : $this->defaultAction;

        // If first parameter is a valid method, use that instead
        if ($testPart) {
            // Action should be lowercase camelcase
            $testAction = camelize($testPart, false);
            // Rest style routing
            // Method is added at the end to avoid confusion with getters
            $testActionWithMethod = $testAction . ucfirst(strtolower($method));

            // Don't allow controller/index to be called directly because it would create duplicated urls
            // This only applies if no other parameters is passed in the url
            if ($testAction == $this->defaultAction && count($params) === 1) {
                $newUri = $this->getRedirectUri($uri, $this->defaultAction, '');
                throw new RedirectException($newUri);
            }

            // Shift param if method is found
            if ($refl->hasMethod($testActionWithMethod)) {
                array_shift($params);
                $action = $testActionWithMethod;
            } elseif ($refl->hasMethod($testAction)) {
                array_shift($params);
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
     * @param string[] $params
     * @return array<string, mixed>
     */
    protected function collectParameters(ReflectionClass $refl, string $action, array $params = [])
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
        $firstParamType = $firstParam->getType();
        if (!$firstParamType instanceof ReflectionNamedType) {
            throw new NotFoundException("Action '$action' cannot handle a request");
        }
        if ($firstParamType->getName() !== ServerRequestInterface::class) {
            throw new NotFoundException("Action '$action' cannot handle a request");
        }

        $i = 0;
        $acceptMany = false;
        foreach ($actionParams as $actionParam) {
            $paramName = $actionParam->getName();
            if (!$actionParam->isOptional() && !isset($params[$i])) {
                throw new NotFoundException("Param '$paramName' is required for action '$action' on '$class'");
            }
            /** @var string $value  */
            $value = $params[$i] ?? null;
            $type = $actionParam->getType();

            // getName is only available for ReflectionNamedType and __toString is deprecated
            if ($type instanceof ReflectionNamedType) {
                // It's a default type
                if ($value && $type->isBuiltin()) {
                    switch ($type->getName()) {
                        case 'bool':
                            // We expect only 1 and 0
                            if ($value != 1 && $value != 0) {
                                throw new NotFoundException("Param '$paramName' is invalid for action '$action' on '$class'");
                            }
                            $value = boolval($value);
                            break;
                        case 'array':
                            // We expect a comma separated list
                            $value = explode(",", $value);
                            break;
                        case 'string':
                            $value = filter_var($value, FILTER_SANITIZE_STRING);
                            break;
                        case 'int':
                            if (!is_numeric($value)) {
                                throw new NotFoundException("Param '$paramName' is not a valid int for action '$action' on '$class'");
                            }
                            $value = intval($value);
                            break;
                        case 'float':
                            if (!is_numeric($value)) {
                                throw new NotFoundException("Param '$paramName' is not a valid float for action '$action' on '$class'");
                            }
                            $value = floatval($value);
                            break;
                    }

                    // Update value
                    $params[$i] = $value;
                }
            }

            // Extra parameters are accepted for ...args type of parameters
            if ($actionParam->isVariadic()) {
                $acceptMany = true;
            }
            $i++;
        }
        if (!$acceptMany && count($params) > count($actionParams)) {
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
