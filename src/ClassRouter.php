<?php

declare(strict_types=1);

namespace Kaly;

use Stringable;
use ReflectionClass;
use ReflectionNamedType;
use Nyholm\Psr7\Response;
use Kaly\Exceptions\RouterException;
use Psr\Container\ContainerInterface;
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

    /**
     * Match a request and resolves it
     * @return Response|string|Stringable|array<string, mixed>
     */
    public function match(ServerRequestInterface $request, ContainerInterface $di = null)
    {
        $uri = trim($request->getUri()->getPath(), '/');

        $parts = array_filter(explode("/", $uri));

        $class = $this->findController($parts);
        if (!class_exists($class)) {
            throw new RouterException("Route '$uri' not found. Class '$class' was not found.");
        }

        $refl = new ReflectionClass($class);

        $action = $this->findAction($refl, $parts);
        $result = $this->callAction($refl, $action, $parts, $di);

        return $result;
    }

    protected static function camelize(string $str, bool $firstChar = true): string
    {
        if (!$str) {
            return $str;
        }
        $str = str_replace(' ', '', ucwords(str_replace('-', ' ', $str)));
        if (!$firstChar) {
            $str[0] = strtolower($str[0]);
        }
        return $str;
    }

    /**
     * Find a controller based on the first two parts of the request
     * @param string[] $parts
     */
    protected function findController(array &$parts): string
    {
        $namespace = $this->defaultNamespace;

        // Check the first segment if it exists
        $part = array_shift($parts) ?? 'index';
        $camelPart = self::camelize($part);

        // Does it match a specific namespace?
        // More specific namespaces always have priority over default
        if (in_array($camelPart, $this->allowedNamespaces)) {
            $namespace = $camelPart;
            $part = array_shift($parts) ?? 'index';
            $camelPart = self::camelize($part);
        }

        // Does it match a controller ?
        $controller = $camelPart . $this->controllerSuffix;
        $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $controller;

        // Does controller exists ? Otherwise fallback to index and restore segment
        if (!class_exists($class)) {
            $defaultController =  $this->defaultControllerName . $this->controllerSuffix;
            $class = $namespace . '\\' . $this->controllerNamespace . '\\' . $defaultController;
            array_unshift($parts, $part);
        }

        return $class;
    }

    /**
     * Find a matching action based on the next part of the request
     * @param ReflectionClass<object> $refl
     * @param string[] $params
     */
    protected function findAction(ReflectionClass $refl, array &$params): string
    {
        $class = $refl->getName();

        // Index is used by default. If first parameter is a valid method, use that instead
        $action = $params[0] ?? 'index';
        $action = self::camelize($action, false);
        if (count($params) && $refl->hasMethod($action)) {
            array_shift($params);

            // Don't allow controller/index to be called directly because it would create duplicated urls
            if ($action == 'index') {
                throw new RouterException("Action 'index' cannot be called directly on '$class'");
            }
        }

        // Is this action available ?
        if (!$refl->hasMethod($action)) {
            throw new RouterException("Controller '$class' does not have an action '$action'");
        }

        return $action;
    }

    /**
     * Tries to call action with remaining parts of the request
     * @param ReflectionClass<object> $refl
     * @param string $action
     * @param string[] $params
     * @param ContainerInterface $di
     * @return Response|string|Stringable|array<string, mixed>
     */
    protected function callAction(ReflectionClass $refl, string $action, array $params = [], ContainerInterface $di = null)
    {
        $class = $refl->getName();

        $method = $refl->getMethod($action);
        if (!$method->isPublic()) {
            throw new RouterException("Action '$action' is not public on '$class'");
        }

        // Verify parameters
        $actionParams = $method->getParameters();
        $i = 0;
        $acceptMany = false;
        foreach ($actionParams as $actionParam) {
            $paramName = $actionParam->getName();
            if (!$actionParam->isOptional() && !isset($params[$i])) {
                throw new RouterException("Param '$paramName' is required for action '$action' on '$class'");
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
                            throw new RouterException("Param '$paramName' is invalid for action '$action' on '$class'");
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
            throw new RouterException("Too many parameters for action '$action' on '$class'");
        }

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
}
