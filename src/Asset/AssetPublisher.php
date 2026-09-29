<?php

declare(strict_types=1);

namespace Kaly\Asset;

use InvalidArgumentException;
use Kaly\Middleware\Builtin\FileServer;
use Kaly\Util\Env;
use Kaly\Util\Fs;
use RuntimeException;

/**
 * Publishes browser-ready asset sources to an immutable versioned directory:
 *
 * ```text
 * assets/*               -> public/assets/<version>/app/*
 * modules/Admin/assets/* -> public/assets/<version>/admin/*
 * ```
 *
 * Publishing is a build step owned by the application (eg: `bin/build.php`,
 * composer script, CI), never a runtime operation and never a framework CLI:
 *
 * ```php
 * $publisher = new AssetPublisher($sources, $paths->publicDir());
 * $publisher->publish();
 * ```
 *
 * Rules: symlinks fail loudly, dot segments (`.env`, `.git/`, ...) are never
 * published, executable extensions are refused. An existing destination is
 * considered already published and left untouched: a given version is
 * immutable.
 */
final class AssetPublisher
{
    public function __construct(
        private AssetSources $sources,
        private string $publicDir,
        private ?string $version = null,
    ) {
        $this->publicDir = Fs::dir($publicDir);
    }

    /**
     * Publish every source and write `public/assets/.version` atomically.
     * Returns the effective version.
     *
     * @throws RuntimeException On symlinks or unreadable sources
     */
    public function publish(?string $version = null): string
    {
        $resolved = $version ?? $this->version;
        if ($resolved !== null) {
            $resolved = trim($resolved);
        }
        if ($resolved === null || $resolved === '') {
            if (Env::has(Assets::ENV_VERSION)) {
                $resolved = trim(Env::getString(Assets::ENV_VERSION));
            }
        }
        if ($resolved === null || $resolved === '') {
            $resolved = $this->contentHash();
        }
        self::assertValidVersion($resolved);

        $dest = Fs::toDir($this->publicDir, 'assets', $resolved);
        if (is_dir($dest)) {
            $this->writeVersionFile($resolved);
            return $resolved;
        }

        foreach ($this->sources->all() as $namespace => $dir) {
            $this->publishNamespace($namespace, $dir, $dest);
        }
        Fs::ensureDir($dest);

        $this->writeVersionFile($resolved);

        return $resolved;
    }

    /**
     * Deterministic content hash over sorted relative paths and file
     * contents (SHA-256, truncated to 10 chars).
     */
    public function contentHash(): string
    {
        $ctx = hash_init('sha256');
        $sources = $this->sources->all();
        ksort($sources);
        foreach ($sources as $namespace => $dir) {
            foreach ($this->collect($namespace, $dir) as $relative => $file) {
                hash_update($ctx, $namespace . "\0" . $relative . "\0");
                hash_update_file($ctx, $file);
            }
        }
        return substr(hash_final($ctx), 0, 10);
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertValidVersion(string $version): void
    {
        if ($version === '' || $version === '.' || $version === '..' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $version)) {
            throw new InvalidArgumentException("Invalid asset version '{$version}'");
        }
    }

    private function publishNamespace(string $namespace, string $dir, string $dest): void
    {
        foreach ($this->collect($namespace, $dir) as $relative => $file) {
            $target = Fs::toDir($dest, $namespace, str_replace('/', DIRECTORY_SEPARATOR, $relative));
            Fs::ensureDir(dirname($target));
            if (!copy($file, $target)) {
                throw new RuntimeException("Could not publish '{$file}'");
            }
        }
    }

    /**
     * List publishable files of one namespace: relative path => absolute file.
     * Dot segments are skipped, symlinks fail, forbidden extensions are refused.
     *
     * @return array<string,string>
     *
     * @throws RuntimeException
     */
    private function collect(string $namespace, string $dir): array
    {
        $files = [];
        if (!is_dir($dir)) {
            return $files;
        }
        if (is_link($dir)) {
            throw new RuntimeException("Asset source '@{$namespace}' is a symlink: '{$dir}'");
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_PATHNAME),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );
        /** @var string $pathname */
        foreach ($iterator as $pathname) {
            $relative = str_replace(DIRECTORY_SEPARATOR, '/', (string) substr($pathname, strlen(Fs::dir($dir)) + 1));
            $segments = explode('/', $relative);
            $dotted = false;
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.' || $segment === '..' || str_starts_with($segment, '.')) {
                    $dotted = true;
                    break;
                }
            }
            if ($dotted) {
                continue;
            }
            if (is_link($pathname)) {
                throw new RuntimeException("Asset symlink is not supported: '{$pathname}'");
            }
            if (!is_file($pathname)) {
                continue;
            }
            $extension = strtolower(pathinfo($pathname, PATHINFO_EXTENSION));
            if (in_array($extension, FileServer::FORBIDDEN_EXTENSIONS, true)) {
                throw new RuntimeException("Asset extension '.{$extension}' is not publishable: '{$pathname}'");
            }
            $files[$relative] = $pathname;
        }
        ksort($files);

        return $files;
    }

    private function writeVersionFile(string $version): void
    {
        $dir = Fs::toDir($this->publicDir, 'assets');
        Fs::ensureDir($dir);
        $tmp = Fs::toDir($dir, '.' . Assets::VERSION_FILE . '.' . getmypid() . '.tmp');
        Fs::putFile($tmp, $version . "\n");
        // Atomic on the same filesystem; fallback to copy on Windows quirks
        if (!@rename($tmp, Fs::toDir($dir, Assets::VERSION_FILE))) {
            Fs::putFile(Fs::toDir($dir, Assets::VERSION_FILE), $version . "\n");
            @unlink($tmp);
        }
    }
}
