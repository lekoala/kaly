<?php

declare(strict_types=1);

namespace Kaly\Asset;

use InvalidArgumentException;
use Kaly\Util\Fs;

/**
 * The known asset roots, by namespace: `app` for the application, one entry
 * per module that has an `assets/` directory.
 *
 * ```text
 * app   -> /project/assets
 * admin -> /project/modules/Admin/assets
 * ```
 *
 * Shared by Assets (URL generation), AssetPublisher (build) and AssetServer
 * (development) so the namespace mapping lives in exactly one place.
 */
final class AssetSources
{
    /**
     * @param array<string,string> $sources Namespace => absolute directory
     */
    public function __construct(
        private array $sources = [],
    ) {
        $normalized = [];
        foreach ($this->sources as $namespace => $dir) {
            $namespace = trim((string) $namespace);
            if ($namespace === '' || !preg_match('/^[a-z0-9][a-z0-9-]*$/D', $namespace)) {
                throw new InvalidArgumentException("Invalid asset namespace '{$namespace}'");
            }
            $normalized[$namespace] = Fs::dir($dir);
        }
        $this->sources = $normalized;
    }

    /**
     * @return array<string,string> Namespace => absolute directory
     */
    public function all(): array
    {
        return $this->sources;
    }

    public function has(string $namespace): bool
    {
        return array_key_exists($namespace, $this->sources);
    }

    /**
     * The absolute source directory of a namespace.
     *
     * @throws InvalidArgumentException When the namespace is unknown
     */
    public function get(string $namespace): string
    {
        if (!array_key_exists($namespace, $this->sources)) {
            throw new InvalidArgumentException("Unknown asset namespace '@{$namespace}'");
        }
        return $this->sources[$namespace];
    }
}
