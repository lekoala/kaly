<?php

declare(strict_types=1);

namespace Kaly\Router;

use Kaly\Core\Module;
use ReflectionClass;
use ReflectionMethod;

/**
 * Compiles method `#[Route]` attributes of a module into RouteDefinitions.
 *
 * Deliberately tiny and specialized — not a generic attribute registry:
 * Kaly already knows that `modules/Foo/src/Controller/PatientController.php`
 * under module namespace `Foo` is `Foo\Controller\PatientController` (the
 * same convention Module autoloading relies on), so no PHP parsing or
 * Composer plugin is needed. Reflection runs at boot, never per request.
 *
 * The attribute is an explicit alias to an already admissible action:
 * admissibility is enforced here through Routes::normalizeHandler(), so a
 * `#[Route]` on a protected/magic method fails fast at boot instead of
 * silently exposing (or hiding) a handler.
 */
final class AttributeRouteLoader
{
    /**
     * @return list<RouteDefinition>
     */
    public function load(Module $module): array
    {
        $controllerDir = $module->getSrcDir() . '/Controller';
        if (!is_dir($controllerDir)) {
            return [];
        }
        $prefix = $module->getNamespace() . '\\Controller\\';
        $out = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($controllerDir, \FilesystemIterator::SKIP_DOTS));
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $relative = substr($file->getPathname(), strlen($controllerDir) + 1, -4);
            $class = $prefix . str_replace('/', '\\', $relative);
            if (!class_exists($class)) {
                continue;
            }
            foreach ($this->routeAttributesOf($class) as [$method, $attribute]) {
                [$controller, $action] = Routes::normalizeHandler([$class, $method]);
                $out[] = new RouteDefinition(
                    $attribute->path,
                    $controller,
                    $action,
                    $attribute->methods,
                    $attribute->name,
                    $attribute->requirements,
                    $attribute->defaults,
                    $attribute->middlewares,
                    $attribute->priority,
                );
            }
        }
        return $out;
    }

    /**
     * @param class-string $class
     * @return list<array{0:string,1:RouteAttribute}>
     */
    private function routeAttributesOf(string $class): array
    {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract() || $reflection->isInterface()) {
            return [];
        }
        $out = [];
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic() || $method->isConstructor() || $method->isDestructor()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            foreach ($method->getAttributes(RouteAttribute::class) as $reflectionAttribute) {
                $out[] = [$method->getName(), $reflectionAttribute->newInstance()];
            }
        }
        return $out;
    }
}
