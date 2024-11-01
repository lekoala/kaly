<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Util\Fs;
use Kaly\Util\Refl;

trait SystemDirectories
{
    public const FOLDER_MODULES = "modules";
    public const FOLDER_PUBLIC = "public";
    public const FOLDER_TEMP = "temp";
    public const FOLDER_RESOURCES = "resources";

    protected string $baseDir;

    public function getBaseDir(): string
    {
        return $this->baseDir;
    }

    protected function setupDirectories(): void
    {
        Fs::ensureDir($this->getPublicDir());
        Fs::ensureDir($this->getResourcesDir());
        Fs::ensureDir($this->getModulesDir());
        Fs::ensureDir($this->getTempDir());
    }

    public function getTempDir(): string
    {
        return Fs::toDir($this->baseDir, self::FOLDER_TEMP);
    }

    public function getTempDirFor(string|object $name): string
    {
        $name = strtolower(Refl::getClassWithoutNamespace($name));
        $dir = Fs::toDir($this->getTempDir(), $name);
        Fs::ensureDir($dir);
        return $dir;
    }

    public function getPublicDir(): string
    {
        return Fs::toDir($this->baseDir, self::FOLDER_PUBLIC);
    }

    public function getResourcesDir(): string
    {
        return Fs::toDir($this->baseDir, self::FOLDER_RESOURCES);
    }

    public function getModulesDir(): string
    {
        return Fs::toDir($this->baseDir, self::FOLDER_MODULES);
    }

    public function getClientModuleDir(string $module): string
    {
        return Fs::toDir($this->getModulesDir(), $module, 'client', 'dist');
    }
}
