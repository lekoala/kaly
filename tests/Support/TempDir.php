<?php

declare(strict_types=1);

namespace Kaly\Tests\Support;

use Kaly\Util\Fs;
use Throwable;

/**
 * Test-only temp directory helpers.
 *
 * Removal is retried: on Windows an unlink can briefly fail while a handle
 * (a file stream the test forgot to close, the indexer, the antivirus) is
 * still open.
 */
final class TempDir
{
    public static function remove(string $dir, int $attempts = 5): void
    {
        for ($try = 1;; $try++) {
            try {
                Fs::removeDir($dir);
                return;
            } catch (Throwable $e) {
                if ($try >= $attempts) {
                    throw $e;
                }
                clearstatcache();
                usleep(10_000 * $try);
            }
        }
    }
}
