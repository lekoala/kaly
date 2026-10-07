<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;

/**
 * Fail-closed inspection of raw URI paths for the HTTP guard.
 *
 * This is not a canonicalizer: ambiguous or dangerous representations are
 * rejected, never rewritten into an acceptable path.
 *
 * Pipeline: raw URI path, structural validation, exactly one decoding pass,
 * then segment inspection.
 */
final class HttpPath
{
    /**
     * Split a raw URI path into decoded segments, rejecting ambiguity.
     *
     * @return array<string> Decoded segments, without leading/trailing empties
     *
     * @throws InvalidArgumentException On ambiguous or dangerous encoding
     */
    public static function segments(string $path): array
    {
        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Invalid path encoding');
        }

        if (str_contains($path, '\\')) {
            throw new InvalidArgumentException('Invalid path separator');
        }

        $raw = explode('/', $path);
        $decoded = [];

        foreach ($raw as $segment) {
            if ($segment === '') {
                $decoded[] = '';

                continue;
            }

            if (preg_match('/%(?![0-9a-fA-F]{2})/', $segment) === 1) {
                throw new InvalidArgumentException('Invalid path encoding');
            }

            $one = rawurldecode($segment);

            if (str_contains($one, "\0") || str_contains($one, '/') || str_contains($one, '\\')) {
                throw new InvalidArgumentException('Invalid path encoding');
            }

            if (preg_match('/%[0-9a-fA-F]{2}/', $one) === 1) {
                throw new InvalidArgumentException('Invalid path encoding');
            }

            $decoded[] = $one;
        }

        if ($decoded[0] === '') {
            array_shift($decoded);
        }

        while ($decoded !== [] && end($decoded) === '') {
            array_pop($decoded);
        }

        return $decoded;
    }
}
