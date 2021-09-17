<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Util;
use Stringable;
use ReflectionClass;
use ReflectionNamedType;
use Psr\Http\Message\UriInterface;
use Kaly\Interfaces\RouterInterface;
use Psr\Container\ContainerInterface;
use Kaly\Exceptions\NotFoundException;
use Kaly\Exceptions\RedirectException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Takes an uri and map it to a class
 */
class ClassRouter implements RouterInterface
{
    protected string $defaultNamespace = 'App';
    protected string $controllerNamespace = 'Controller';
    protected string $controllerSuffix = 'Controller';
    protected string $defaultControllerName = 'Index';
    /**
     * @var string[]
     */
    protected array $allowedNamespaces = [];
    protected bool $forceTrailingSlash = true;
    protected array $routeParams = [];

    public function getRouteParams(): array
    {
        return $this->routeParams;
    }

    /**
     * Match a request and resolves it
     * @return ResponseInterface|string|Stringable|array<string, mixed>
     */
    public function match(ServerRequestInterface $request, ContainerInterface $di = null)
    {
        $uri = $request->getUri();
        $path = $uri->getPath();

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

        // First we need to check if we have the controller
        // Parts used to match controller are removed (module and/or controller)
        $class = $this->findController($parts, $uri);
        if (!class_exists($class)) {
            throw new NotFoundException("Route '$path' not found. Class '$class' was not found.");
        }

        $refl = new ReflectionClass($class);

        // Then if the action exists
        // Part used to match the action is removed (none for index)
        $action = $this->findAction($refl, $parts, $uri);

        // Remaining parts are passed as arguments to the action
        $result = $this->callAction($refl, $action, $parts, $di);

        $this->routeParams["handler"] = $class;
        $this->routeParams["action"] = $action;

        return $result;
    }

    /**
     * Find a controller based on the first two parts of the request
     * @param string[] $parts
     */
    protected function findController(array &$parts, UriInterface $uri): string
    {
        $namespace = $this->defaultNamespace;

        // Check the first segment if it exists
        $part = array_shift($parts) ?? '';
        $camelPart = Util::camelize($part);

        // Does it match a specific namespace?
        // More specific namespaces always have priority over default
        if (in_array($camelPart, $this->allowedNamespaces)) {
            $this->routeParams["module"] = $part;
            $namespace = $camelPart;
            $part = array_shift($parts) ?? '';
            $camelPart = Util::camelize($part);
        } else {
            $this->routeParams["module"] = 'default';
        }

        // Do not allow direct /index calls
        if ($part === 'index') {
            $newUri = $uri->withPath('/');
            throw new RedirectException($newUri);
        }

        if (!$part) {
            $part = 'index';
            $camelPart = Util::camelize($part);
        }

        // Does it match a controller ?
        $controller = $camelPart . $this->controllerSuffix;
        $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $controller;

        // Does controller exists ? Otherwise fallback to index and restore segment
        if (!class_exists($class)) {
            $defaultController =  $this->defaultControllerName . $this->controllerSuffix;
            $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $defaultController;
            array_unshift($parts, $part);

            $this->routeParams["controller"] = 'index';
            $this->routeParams["controller_fallback"] = true;
        } else {
            $this->routeParams["controller"] = $part;
            $this->routeParams["controller_fallback"] = false;
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

        // Index is used by default. If first parameter is a valid method, use that instead
        $action = 'index';
        if (count($params)) {
            $testAction = Util::camelize($params[0], false);
            // Don't allow controller/index to be called directly because it would create duplicated urls
            // This only applies if no other parameters is passed in the url
            if ($testAction == 'index' && count($params) === 1) {
                $newUri = $uri->withPath(substr($uri->getPath(), -6));
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
     * @param ContainerInterface $di
     * @return ResponseInterface|string|Stringable|array<string, mixed>
     */
    protected function callAction(ReflectionClass $refl, string $action, array $params = [], ContainerInterface $di = null)
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

        $this->routeParams["parameters"] = $params;

        // Use DI if available
        if ($di) {
            $inst = $di->get($class);
        } else {
            $inst = $refl->newInstance();
        }

        return $method->invokeArgs($inst, $params);
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
}
