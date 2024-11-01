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
    public ?string $template = null;
    public bool $json = false;

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return (array)$this;
    }

    /**
     * @param array<string,mixed> $arr
     * @return self
     */
    public static function fromArray(array $arr): self
    {
        $inst = new self();
        foreach ($arr as $k => $v) {
            $inst->$k = $v;
        }
        return $inst;
    }
}
