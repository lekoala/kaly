<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;
use Kaly\Util\Fs;

/**
 * Whether an HTTP path must never reach the application.
 *
 * This is the HTTP guard policy, distinct from the physical serving policy
 * (PublicFilePolicy): dotted application routes such as `/sitemap.xml` or
 * `/robots.txt` are legitimate, while sensitive names and executable source
 * files are not.
 */
final class SensitivePathPolicy
{
    public static function isSensitive(string $uriPath): bool
    {
        try {
            $segments = HttpPath::segments($uriPath);
        } catch (InvalidArgumentException) {
            return true;
        }

        foreach ($segments as $index => $segment) {
            if ($segment === '.' || $segment === '..') {
                return true;
            }

            if (str_starts_with($segment, '.')) {
                if (!($index === 0 && strtolower($segment) === '.well-known')) {
                    return true;
                }

                continue;
            }

            $lower = strtolower($segment);

            if ($lower === 'composer.json' || $lower === 'composer.lock') {
                return true;
            }

            if (PublicFilePolicy::isForbiddenExtension(Fs::extension($segment))) {
                return true;
            }
        }

        return false;
    }
}
