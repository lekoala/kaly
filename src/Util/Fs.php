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
        self::ensureDir($dir);
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
     * Whether a relative path carries a segment that is empty (`a//b`),
     * `.`, `..`, or dot-prefixed (`.env`, `a/.git/x`): the segments never
     * valid in a published or served path.
     */
    public static function hasDotSegment(string $path): bool
    {
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove a directory and its contents.
     */
    public static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var string $pathname */
        foreach ($iterator as $pathname) {
            if (is_dir($pathname) && !is_link($pathname)) {
                rmdir($pathname);
                continue;
            }
            unlink($pathname);
        }
        rmdir($dir);
    }

    /**
     * Strip the base directory prefix from a path. Only a leading prefix is
     * stripped: a path that does not start with the base is returned untouched.
     */
    public static function relativePath(string $baseDir, string $path): string
    {
        if (str_starts_with($path, $baseDir)) {
            return substr($path, strlen($baseDir));
        }
        return $path;
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
