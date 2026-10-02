<?php

declare(strict_types=1);

namespace Kaly\Asset;

use InvalidArgumentException;
use Kaly\Util\Env;
use Kaly\Util\Fs;
use RuntimeException;

/**
 * Produces asset URLs. Like the router, it never checks that the file
 * exists: filesystem verification belongs to AssetServer (dev) and
 * AssetPublisher (build).
 *
 * ```text
 * dev:  /_assets/app/app.js       (DEV_PREFIX, served by AssetServer)
 * prod: /assets/8af319c42d/app/app.js
 * ```
 */
final class Assets implements AssetsInterface
{
    /**
     * The url prefix of development asset urls, also the prefix AssetServer
     * answers on.
     */
    public const DEV_PREFIX = '/_assets/';

    public const ENV_VERSION = 'APP_ASSETS_VERSION';
    public const VERSION_FILE = '.version';

    private ?string $resolvedVersion = null;

    public function __construct(
        private AssetSources $sources,
        private string $publicDir,
        private bool $dev = false,
        private ?string $version = null,
        private string $baseUrl = '',
    ) {
        $this->publicDir = Fs::dir($publicDir);
        $this->baseUrl = rtrim($baseUrl, '/');
    }

    public function url(string $asset): string
    {
        [$namespace, $path] = self::split($asset);
        // Fail fast on unknown namespaces; existence of the file itself is
        // deliberately not checked here.
        $this->sources->get($namespace);

        if ($this->dev) {
            return $this->baseUrl . self::DEV_PREFIX . $namespace . '/' . $path;
        }

        $version = $this->resolveVersion();
        return $this->baseUrl . '/assets/' . $version . '/' . $namespace . '/' . $path;
    }

    /**
     * @return array{0:string,1:string} [namespace, path]
     *
     * @throws InvalidArgumentException
     */
    public static function split(string $asset): array
    {
        $asset = trim(str_replace('\\', '/', $asset));
        if ($asset === '') {
            throw new InvalidArgumentException('Asset name must not be empty');
        }

        $namespace = 'app';
        $path = $asset;
        if (str_starts_with($asset, '@')) {
            $slash = strpos($asset, '/');
            if ($slash === false || $slash <= 1 || $slash === (strlen($asset) - 1)) {
                throw new InvalidArgumentException("Asset '{$asset}' must look like '@namespace/path'");
            }
            $namespace = substr($asset, 1, $slash - 1);
            $path = substr($asset, $slash + 1);
        }

        if (!preg_match('/^[a-z0-9][a-z0-9-]*$/D', $namespace)) {
            throw new InvalidArgumentException("Invalid asset namespace '@{$namespace}'");
        }
        self::assertValidPath($path, $asset);

        return [$namespace, $path];
    }

    /**
     * @throws InvalidArgumentException
     */
    public static function assertValidPath(string $path, string $original = ''): void
    {
        $original = $original === '' ? $path : $original;
        if (
            $path === ''
            || str_starts_with($path, '/')
            || str_contains($path, '\\')
            || str_contains($path, "\0")
            || Fs::hasDotSegment($path)
        ) {
            throw new InvalidArgumentException("Invalid asset path '{$original}'");
        }
    }

    /**
     * The production version: explicit constructor value, then
     * APP_ASSETS_VERSION, then the `.version` file written by the publisher.
     * Resolved lazily so an application that never calls asset() never fails.
     *
     * @throws RuntimeException When nothing was published
     */
    public function resolveVersion(): string
    {
        if ($this->resolvedVersion !== null) {
            return $this->resolvedVersion;
        }

        if ($this->version !== null && trim($this->version) !== '') {
            return $this->resolvedVersion = trim($this->version);
        }

        if (Env::has(self::ENV_VERSION)) {
            $env = trim(Env::getString(self::ENV_VERSION));
            if ($env !== '') {
                return $this->resolvedVersion = $env;
            }
        }

        $file = Fs::toDir($this->publicDir, 'assets', self::VERSION_FILE);
        if (is_file($file)) {
            $version = trim(Fs::getFile($file));
            if ($version !== '') {
                return $this->resolvedVersion = $version;
            }
        }

        throw new RuntimeException('Assets have not been published: set APP_ASSETS_VERSION or run your asset publisher');
    }
}
