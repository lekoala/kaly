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
 *   temp/        caches and other disposable runtime files
 *     logs/      disposable runtime logs
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
        return Fs::join($this->base, self::MODULES);
    }

    public function publicDir(): string
    {
        return Fs::join($this->base, self::PUBLIC);
    }

    public function resources(): string
    {
        return Fs::join($this->base, self::RESOURCES);
    }

    public function assets(): string
    {
        return Fs::join($this->base, 'assets');
    }

    public function temp(): string
    {
        return Fs::join($this->base, self::TEMP);
    }

    /**
     * The disposable runtime logs folder
     */
    public function logs(): string
    {
        return Fs::join($this->temp(), 'logs');
    }

    /**
     * A dedicated temp folder, created if needed
     */
    public function tempFor(string|object $name): string
    {
        $dir = Fs::join($this->temp(), strtolower(Reflection::getShortClassName($name)));
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
