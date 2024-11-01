<?php

declare(strict_types=1);

namespace Kaly\View;

use RuntimeException;

/**
 * @method string asset(string $name)
 * @mixin AssetsExtension
 */
class Template
{
    protected Engine $engine;
    protected string $file;
    public ?string $layout = null;
    protected ?string $blockName = null;

    public function __construct(Engine $engine, string $file)
    {
        $this->engine = $engine;
        $this->file = $file;
    }

    /**
     * Escape html
     * @param string $v
     * @return string
     */
    public function h(mixed $v): string
    {
        return $this->engine->getEscaper()->escape('h', $v);
    }

    /**
     * Escape html attributes
     * @param string $v
     * @return string
     */
    public function a(mixed $v): string
    {
        return $this->engine->getEscaper()->escape('a', $v);
    }

    /**
     * Escape urls
     * @param string $v
     * @return string
     */
    public function u(mixed $v): string
    {
        return $this->engine->getEscaper()->escape('u', $v);
    }

    /**
     * Escape css
     * @param string $v
     * @return string
     */
    public function c(mixed $v): string
    {
        return $this->engine->getEscaper()->escape('c', $v);
    }

    /**
     * Escape js
     * @param string $v
     * @return string
     */
    public function j(mixed $v): string
    {
        return $this->engine->getEscaper()->escape('j', $v);
    }

    /**
     * Define the layout that will wrap this template
     * Layouts can be nested
     *
     * @param string $name
     * @return $this
     */
    public function layout(string $name): self
    {
        $this->layout = $name;
        return $this;
    }

    /**
     * @param string $name
     * @param array<string,mixed> $data
     * @return string
     */
    public function render(string $name, array $data = []): string
    {
        return $this->engine->render($name, $data);
    }

    /**
     * Shortcut syntax for includes folder
     * @param string $name
     * @param array<string,mixed> $data
     * @return string
     */
    public function include(string $name, array $data = []): string
    {
        return $this->engine->render("includes/$name", $data);
    }

    /**
     * Shortcut syntax for loops
     * The current item is referred as "item" in the template
     * @param string $name
     * @param array<string,mixed> $data
     * @return string
     */
    public function loop(string $name, array $data = []): string
    {
        $html = '';
        foreach ($data as $row) {
            $html .= $this->include($name, ['item' => $row]);
        }
        return $html;
    }

    public function block(string $name): string
    {
        return $this->engine->getBlock($name);
    }

    public function startBlock(string $name): void
    {
        if ($this->blockName) {
            throw new RuntimeException('You cannot nest blocks within other blocks.');
        }
        $this->blockName = $name;
        ob_start();
    }

    public function stopBlock(): void
    {
        if ($this->blockName === null) {
            throw new RuntimeException('You must begin a block before you can stop it.');
        }
        $this->engine->setBlock($this->blockName, ob_get_clean() ?: "");
        $this->blockName = null;
    }

    /**
     * Magic method used to call extension functions.
     *
     * @param string $name function name.
     * @param array<mixed> $arguments function arguments.
     * @return mixed result of the function.
     * @throws RuntimeException if the extension or function was not added.
     */
    public function __call(string $name, array $arguments)
    {
        foreach ($this->engine->getExtensions() as $extension) {
            foreach ($extension->getFunctions() as $function => $callback) {
                if ($function === $name) {
                    return ($callback)(...$arguments);
                }
            }
        }
        throw new RuntimeException(sprintf('Calling an undefined function "%s"', $name));
    }
}
