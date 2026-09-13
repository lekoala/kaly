<?php

declare(strict_types=1);

namespace Kaly\Util;

use Exception;

/**
 * Filesystem helpers used by bootstrap, modules and static file serving.
 *
 * Reference implementations: nette/utils FileSystem and Finder,
 * symfony/filesystem Filesystem.
 *
 * @link https://github.com/nette/utils/blob/master/src/Utils/FileSystem.php
 * @link https://github.com/nette/utils/blob/master/src/Utils/Finder.php
 * @link https://github.com/symfony/symfony/blob/7.1/src/Symfony/Component/Filesystem/Filesystem.php
 */
final class Fs
{
    /**
     * Create a directory if it does not exist.
     *
     * @param int $flags Permissions used on creation
     */
    public static function mkDir(string $dir, int $flags = 0o755, bool $recursive = true): bool
    {
        if (!is_dir($dir)) {
            return mkdir($dir, $flags, $recursive);
        }
        return true;
    }

    /**
     * Read a whole file, returning an empty string when unreadable.
     */
    public static function getFile(string $filename): string
    {
        $contents = file_get_contents($filename);
        if ($contents === false) {
            $contents = '';
        }
        return $contents;
    }

    /**
     * Write a file, creating its parent directory first.
     */
    public static function putFile(string $filename, string $data): bool
    {
        $dir = dirname($filename);
        self::mkDir($dir);
        $res = file_put_contents($filename, $data);
        return $res !== false;
    }

    /**
     * Guess the mime type, defaulting to binary stream.
     */
    public static function contentType(string $filename): string
    {
        $res = mime_content_type($filename);
        if ($res === false) {
            return 'application/octet-stream';
        }
        return $res;
    }

    /**
     * Join path segments with the directory separator, skipping empty parts.
     *
     * @param string[] ...$args Path segments
     */
    public static function toDir(...$args): string
    {
        $args = array_filter($args);
        /** @var array<string> $args */
        return implode(DIRECTORY_SEPARATOR, $args);
    }

    /**
     * Return the directory without trailing slash or backslash.
     */
    public static function dir(string $dir): string
    {
        return rtrim($dir, '\/');
    }

    /**
     * Create the directory if needed, throwing when creation fails.
     *
     * See https://www.digitalocean.com/community/questions/proper-permissions-for-web-server-s-directory.
     *
     * @throws Exception When the directory cannot be created
     */
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            $result = mkdir($dir, 0o755, true);
            if (!$result) {
                throw new Exception("Could not create {$dir}");
            }
        }
    }

    /**
     * Strip the base directory prefix from a path.
     */
    public static function relativePath(string $baseDir, string $path): string
    {
        return str_replace($baseDir, '', $path);
    }

    /**
     * Check that a path resolves inside the given directory.
     *
     * Both paths are resolved with realpath() first, so relative segments and
     * symlinks cannot escape the base directory. A directory boundary is
     * enforced so that "/var/www/public-other" is not considered inside
     * "/var/www/public".
     */
    public static function isInside(string $dir, string $path): bool
    {
        $realDir = realpath($dir);
        $realPath = realpath($path);
        if ($realDir === false || $realPath === false) {
            return false;
        }
        $realDir = rtrim($realDir, '/\\');
        return $realPath === $realDir || str_starts_with($realPath, $realDir . DIRECTORY_SEPARATOR);
    }

    /**
     * Find files matching a pattern, searching subdirectories recursively.
     *
     * @return array<string> Matching file paths
     */
    public static function glob(string $pattern, int $flags = 0): array
    {
        $files = glob($pattern, $flags);
        if (!$files) {
            $files = [];
        }
        $dirs = glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT);
        if (!$dirs) {
            $dirs = [];
        }
        foreach ($dirs as $dir) {
            $files = array_merge($files, self::glob($dir . '/' . basename($pattern), $flags));
        }
        return $files;
    }
}
