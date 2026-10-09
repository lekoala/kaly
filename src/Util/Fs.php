<?php

declare(strict_types=1);

namespace Kaly\Util;

use Kaly\Ex;

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
     * Join filesystem path segments lexically using DIRECTORY_SEPARATOR at boundaries.
     *
     * Empty segments are ignored, but "0" is preserved. Only the first non-empty
     * segment may be rooted or carry a Windows drive prefix. Unix, drive and UNC
     * roots and interior separators are preserved; trailing separators are removed.
     * No filesystem access or resolution of . / .. occurs: this is not a path
     * traversal security boundary.
     *
     * @throws Ex When a later segment is rooted or carries a drive prefix
     */
    public static function join(string ...$segments): string
    {
        $path = '';
        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }
            if ($path === '') {
                $path = self::dir($segment);
                continue;
            }
            if ($segment[0] === '/' || $segment[0] === '\\' || preg_match('/^[A-Za-z]:/', $segment)) {
                throw new Ex("Cannot join rooted or drive-prefixed path segment '{$segment}' after the first segment");
            }
            // A bare drive prefix is drive-relative: C: + file must stay C:file.
            $separator = preg_match('/^[A-Za-z]:$/D', $path) ? '' : DIRECTORY_SEPARATOR;
            $path = rtrim($path, '\\/') . $separator . self::dir($segment);
        }
        return $path;
    }

    /**
     * Remove trailing slashes or backslashes, preserving Unix, drive and UNC roots.
     * Interior separators are left untouched.
     */
    public static function dir(string $dir): string
    {
        $trimmed = rtrim($dir, '\\/');
        if ($dir !== '' && $trimmed === '') {
            return $dir[0];
        }
        if ($trimmed !== $dir && preg_match('/^[A-Za-z]:$/D', $trimmed)) {
            return $trimmed . $dir[2];
        }
        return $trimmed;
    }

    /**
     * The extension of a filename, without the dot (`index.php` gives `php`).
     *
     * The case is preserved: whether an extension is case-insensitive is a
     * policy decision, not a filesystem one.
     */
    public static function extension(string $filename): string
    {
        return pathinfo($filename, PATHINFO_EXTENSION);
    }

    /**
     * Create the directory recursively if needed, tolerating concurrent creation.
     * The mode defaults to 0o755 and is subject to umask; Windows ignores it.
     *
     * @throws Ex When the directory cannot be created
     */
    public static function ensureDir(string $dir, int $mode = 0o755): void
    {
        if (!is_dir($dir) && !@mkdir($dir, $mode, true) && !is_dir($dir)) {
            throw new Ex("Cannot create directory '{$dir}'");
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
