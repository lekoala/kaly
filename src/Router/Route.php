<?php

declare(strict_types=1);

namespace Kaly\Router;

class Route
{
    public ?string $locale = null;
    /**
     * @var array<string>
     */
    public array $segments = [];
    public ?string $module = null;
    public ?string $namespace = null;
    /**
     * @var class-string
     */
    public ?string $controller = null;
    public ?string $action = null;
    /**
     * @var array<int<0,max>|string,mixed>
     */
    public array $params = [];

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'locale' => $this->locale,
            'segments' => $this->segments,
            'module' => $this->module,
            'namespace' => $this->namespace,
            'controller' => $this->controller,
            'action' => $this->action,
            'params' => $this->params,
        ];
    }
}
