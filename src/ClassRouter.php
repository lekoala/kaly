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

/**
 * Takes an uri and map it to a class
 *
 * - Handles multiple namespaces (default one if omitted)
 * - Check for locale as a prefix (can be restricted to a set of namespaces)
 * - Collect url parameters based on method signature
 */
class ClassRouter implements RouterInterface
{
    public const MODULE = "module";
    public const CONTROLLER = "controller";
    public const ACTION = "action";
    public const PARAMS = "params";
    public const LOCALE = "locale";
    public const SEGMENTS = "segments";

    protected string $defaultNamespace = 'App';
    protected string $controllerNamespace = 'Controller';
    protected string $controllerSuffix = 'Controller';
    protected string $defaultControllerName = 'Index';
    /**
     * @var string[]
     */
    protected array $allowedNamespaces = [];
    /**
     * @var string[]
     */
    protected array $allowedLocales = [];
    protected array $restrictLocaleToNamespaces = [];
    protected bool $forceTrailingSlash = true;
    protected int $localeLength = 2;

    /**
     * Match a request and returns an array of parameters
     * @return array<string, mixed>
     */
    public function match(ServerRequestInterface $request)
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

        $routeParams = [];

        // Make sure we have a trailing slash
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
        $routeParams[self::SEGMENTS] = $parts;

        // Maybe we have a locale as a prefix
        $locale = $this->findLocale($parts);
        $routeParams[self::LOCALE] = $locale;

        // Do we have a specific module ?
        $module = $this->findModule($parts, $uri);
        $routeParams[self::MODULE] = $module;

        $this->enforceLocaleModuleUri($routeParams, $uri);

        // First we need to check if we have the controller
        $controller = $this->findController($module, $parts, $uri);
        $routeParams[self::CONTROLLER] = $controller;
        // We need a reflection for next methods
        $refl = new ReflectionClass($controller);

        // If the action exists (or index if set)
        $action = $this->findAction($refl, $parts, $uri);
        $routeParams[self::ACTION] = $action;

        // Remaining parts are passed as arguments to the action
        $params = $this->collectParameters($refl, $action, $parts);
        $routeParams[self::PARAMS] = $params;

        return $routeParams;
    }

    protected function enforceLocaleModuleUri(array &$routeParams, UriInterface $uri): void
    {
        $module = $routeParams[self::MODULE];
        $locale = $routeParams[self::LOCALE];
        $parts = $routeParams[self::SEGMENTS];

        $isRestricted = true;
        if (!empty($this->restrictLocaleToNamespaces)) {
            $isRestricted = in_array($module, $this->restrictLocaleToNamespaces);
        }

        // Is there a locale when it shouldn't be ?
        if ($module && $locale && !$isRestricted) {
            $newUri = $this->getRedirectUri($uri, $locale);
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
            $routeParams[self::LOCALE] = $this->allowedLocales[0];
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
    protected function findLocale(array &$parts): ?string
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
        if (in_array($camelPart, $this->allowedNamespaces)) {
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
        if ($part === 'index') {
            $newUri = $this->getRedirectUri($uri, 'index', '');
            throw new RedirectException($newUri);
        }

        // Default to index
        if (!$part) {
            $part = 'index';
            $camelPart = camelize($part);
        }

        // Does it match a controller ?
        $controller = $camelPart . $this->controllerSuffix;
        $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $controller;

        // Does controller exists ? Otherwise fallback to index
        if (!class_exists($class)) {
            $defaultController =  $this->defaultControllerName . $this->controllerSuffix;
            $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $defaultController;
        } else {
            // Class exist, shift param
            array_shift($parts);
        }
        if (!class_exists($class)) {
            throw new NotFoundException("Route '$path' not found. '$class' doesn't exists.");
        }

        return $class;
    }

    /**
     * Find a matching action based on the next part of the request
     * @param ReflectionClass<object> $refl
     * @param string[] $params
     */
    protected function findAction(ReflectionClass $refl, array &$params, UriInterface $uri): string
    {
        $class = $refl->getName();

        $testPart = $params[0] ?? '';

        // Index or __invoke is used by default. If first parameter is a valid method, use that instead
        $action = $refl->hasMethod('index') ? 'index' : '__invoke';
        if ($testPart) {
            // Action should be lowercase camelcase
            $testAction = camelize($testPart, false);
            // Don't allow controller/index to be called directly because it would create duplicated urls
            // This only applies if no other parameters is passed in the url
            if ($testAction == 'index' && count($params) === 1) {
                $newUri = $this->getRedirectUri($uri, 'index', '');
                throw new RedirectException($newUri);
            }

            // Shift param if method is found
            if ($refl->hasMethod($testAction)) {
                array_shift($params);
                $action = $testAction;
            }
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
        $i = 0;
        $acceptMany = false;
        foreach ($actionParams as $actionParam) {
            $paramName = $actionParam->getName();
            if (!$actionParam->isOptional() && !isset($params[$i])) {
                throw new NotFoundException("Param '$paramName' is required for action '$action' on '$class'");
            }
            $value = $params[$i] ?? null;
            $type = $actionParam->getType();

            //TODO: probably possible to add more validation here
            if ($type instanceof ReflectionNamedType) {
                switch ($type->getName()) {
                    case 'string':
                        break;
                    case 'int':
                    case 'float':
                        if (!is_numeric($value)) {
                            throw new NotFoundException("Param '$paramName' is invalid for action '$action' on '$class'");
                        }
                        break;
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
     * @param string[] $allowedNamespaces
     * @return $this
     */
    public function setAllowedNamespaces(array $allowedNamespaces)
    {
        $allowedNamespaces = array_diff($allowedNamespaces, [$this->defaultNamespace]);
        $this->allowedNamespaces = $allowedNamespaces;
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
     * @return $this
     */
    public function setDefaultNamespace(string $defaultNamespace)
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
     * @return $this
     */
    public function setControllerNamespace(string $controllerNamespace)
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
     * @return $this
     */
    public function setControllerSuffix(string $controllerSuffix)
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
     * @return $this
     */
    public function setDefaultControllerName(string $defaultControllerName)
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
     * @return $this
     */
    public function setForceTrailingSlash(bool $forceTrailingSlash)
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
     * @return $this
     */
    public function setAllowedLocales(array $allowedLocales, ?array $namespaces = null)
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
     * @return $this
     */
    public function setRestrictLocaleToNamespaces(array $restrictLocaleToNamespaces)
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
     * @return $this
     */
    public function setLocaleLength(int $localeLength)
    {
        $this->localeLength = $localeLength;
        return $this;
    }
}
