<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Di\Reflection;
use Kaly\Util\Fs;

/**
 * The conventional directories of an application
 *
 * ```text
 * base/
 *   modules/     one folder per module
 *   public/      the document root
 *   resources/   shared, non public files
 *   temp/        caches, compiled files
 * ```
 */
final readonly class Paths
{
    public const MODULES = 'modules';
    public const PUBLIC = 'public';
    public const TEMP = 'temp';
    public const RESOURCES = 'resources';

    public string $base;

    public function __construct(string $base)
    {
        $this->base = Fs::dir($base);
    }

    public function modules(): string
    {
        return Fs::toDir($this->base, self::MODULES);
    }

    public function publicDir(): string
    {
        return Fs::toDir($this->base, self::PUBLIC);
    }

    public function resources(): string
    {
        return Fs::toDir($this->base, self::RESOURCES);
    }

    public function temp(): string
    {
        return Fs::toDir($this->base, self::TEMP);
    }

    /**
     * A dedicated temp folder, created if needed: tempFor(Translator::class)
     * gives temp/translator
     */
    public function tempFor(string|object $name): string
    {
        $dir = Fs::toDir($this->temp(), strtolower(Reflection::getShortClassName($name)));
        Fs::ensureDir($dir);
        return $dir;
    }

    /**
     * Create the conventional directories (useful in development)
     */
    public function ensureAll(): void
    {
        Fs::ensureDir($this->publicDir());
        Fs::ensureDir($this->resources());
        Fs::ensureDir($this->modules());
        Fs::ensureDir($this->temp());
    }
}
