<?php

declare(strict_types=1);

namespace Kaly\Util;

use Exception;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * @link https://github.com/nette/utils/blob/master/src/Utils/FileSystem.php
 * @link https://github.com/nette/utils/blob/master/src/Utils/Finder.php
 * @link https://github.com/symfony/symfony/blob/7.1/src/Symfony/Component/Filesystem/Filesystem.php
 */
final class Fs
{
    /**
     * Opens file or URL
     *
     * @param string|bool $filename
     * @param string $mode
     * @param boolean $use_include_path
     * @param resource|null $context
     * @return resource
     * @throws Exception if the file cannot be opened
     */
    public static function open($filename, string $mode = 'rb', bool $use_include_path = true, mixed $context = null)
    {
        if (is_bool($filename)) {
            throw new Exception("fopen cannot get a boolean filename");
        }
        $res = fopen($filename, $mode, $use_include_path, $context);
        if ($res === false) {
            throw new Exception("Failed to open $filename");
        }
        return $res;
    }

    /**
     * The file pointed to by stream is closed.
     *
     * @param resource $stream The file pointer must be valid, and must point to a file successfully
     * opened by fopen or fsockopen.
     * @throws Exception if the stream could not be closed
     */
    public static function close($stream): void
    {
        $res = fclose($stream);
        if ($res === false) {
            throw new Exception("Failed to close stream");
        }
    }

    public static function convertToByte(?string $val): int
    {
        $val ??= '';
        if (!$val) {
            return 0;
        }
        $val = str_ireplace(['mb', 'gb', 'kb'], ['m', 'g', 'k'], $val);
        return ini_parse_quantity($val);
    }

    public static function memoryLimit(): int
    {
        return self::convertToByte(ini_get("memory_limit"));
    }

    public static function rmDir(string $dir, bool $recursive = true): bool
    {
        if (!is_dir($dir)) {
            return false;
        }
        if (!$recursive) {
            return rmdir($dir);
        }
        $it = new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new RecursiveIteratorIterator($it, RecursiveIteratorIterator::CHILD_FIRST);
        /** @var \SplFileInfo $file */
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        return rmdir($dir);
    }

    public static function mkDir(string $dir, int $flags = 0755, bool $recursive = true): bool
    {
        if (!is_dir($dir)) {
            return mkdir($dir, $flags, $recursive);
        }
        return true;
    }

    /**
     * Check if a directory contains children
     *
     * @link https://stackoverflow.com/questions/6786014/php-fastest-way-to-find-if-directory-has-children
     * @param string $dir
     * @return bool
     */
    public static function dirContainsChildren($dir)
    {
        $result = false;
        if ($dh = opendir($dir)) {
            while (!$result && ($file = readdir($dh)) !== false) {
                $result = $file !== "." && $file !== "..";
            }
            closedir($dh);
        }
        return $result;
    }

    public static function getFile(string $filename): string
    {
        $contents = file_get_contents($filename);
        if ($contents === false) {
            $contents = '';
        }
        return $contents;
    }

    public static function putFile(string $filename, string $data): bool
    {
        $dir = dirname($filename);
        self::mkdir($dir);
        $res = file_put_contents($filename, $data);
        return $res !== false;
    }

    public static function contentType(string $filename): string
    {
        $res = mime_content_type($filename);
        if ($res === false) {
            return 'application/octet-stream';
        }
        return $res;
    }

    /**
     * Concatenate arguments using DIRECTORY_SEPARATOR
     * @param string[] ...$args
     * @return string
     */
    public static function toDir(...$args): string
    {
        $args = array_filter($args);
        //@phpstan-ignore-next-line
        return implode(DIRECTORY_SEPARATOR, $args);
    }

    /**
     * Returns the dir without ending slash or backslash
     * @param string $dir
     * @return string
     */
    public static function dir(string $dir): string
    {
        return rtrim($dir, '\/');
    }

    /**
     * Make sure the directory exists, creating it if needed
     * @link https://www.digitalocean.com/community/questions/proper-permissions-for-web-server-s-directory
     * @throws Exception when the dir cannot be created
     */
    public static function ensureDir(string $dir): void
    {
        if (!is_dir($dir)) {
            $result = mkdir($dir, 0755, true);
            if (!$result) {
                throw new Exception("Could not create $dir");
            }
        }
    }

    /**
     * @param int $bytes
     * @param integer $decimals
     * @return string
     */
    public static function humanFilesize($bytes, $decimals = 2): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        $factor = floor(log($bytes, 1024));
        return sprintf("%.{$decimals}f ", $bytes / 1024 ** $factor) . ['B', 'KB', 'MB', 'GB', 'TB', 'PB'][$factor];
    }


    /**
     * Slightly modified version of http://www.geekality.net/2011/05/28/php-tail-tackling-large-files/
     * @author Torleif Berger, Lorenzo Stanco
     * @link http://stackoverflow.com/a/15025877/995958
     * @license http://creativecommons.org/licenses/by/3.0/
     */
    public static function tail(string $filename, int $lines = 1, bool $adaptive = true): string
    {
        // Open file in read only - force binary mode
        $f = fopen($filename, "rb");
        if ($f === false) {
            return '';
        }

        // Sets buffer size, according to the number of lines to retrieve.
        // This gives a performance boost when reading a few lines from the file.
        if (!$adaptive) {
            $buffer = 4096;
        } else {
            $buffer = ($lines < 2 ? 64 : ($lines < 10 ? 512 : 4096));
        }
        // Jump to last character
        fseek($f, -1, SEEK_END);
        // Read it and adjust line number if necessary
        // (Otherwise the result would be wrong if file doesn't end with a blank line)
        if (fread($f, 1) != "\n") {
            $lines -= 1;
        }

        // Start reading
        $output = '';
        $chunk = '';
        // While we would like more
        while (ftell($f) > 0 && $lines >= 0) {
            // Figure out how far back we should jump
            $seek = min(ftell($f), $buffer);
            // Do the jump (backwards, relative to where we are)
            fseek($f, -$seek, SEEK_CUR);
            // Read a chunk and prepend it to our output
            $output = ($chunk = fread($f, $seek)) . $output;
            if ($chunk === false) {
                continue;
            }
            // Jump back to where we started reading
            fseek($f, -mb_strlen($chunk, '8bit'), SEEK_CUR);
            // Decrease our line counter
            $lines -= substr_count($chunk, "\n");
        }
        // While we have too many lines
        // (Because of buffer size we might have read too many)
        while ($lines++ < 0) {
            // Find first newline and remove all text before that
            $output = substr($output, strpos($output, "\n") + 1);
        }
        // Close file and return
        fclose($f);
        return trim($output);
    }

    public static function relativePath(string $baseDir, string $path): string
    {
        return str_replace($baseDir, '', $path);
    }

    /**
     * A recursive glob
     * @return array<string>
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
