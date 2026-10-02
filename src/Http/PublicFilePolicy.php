<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * The public-file serving policy, shared by the file server middlewares and
 * the asset publisher: what is refused there cannot be published either.
 *
 * This policy belongs to the public directory only: a legitimate private
 * download may well be a `.zip`, an `archive.xml` or any other extension.
 */
final class PublicFilePolicy
{
    /**
     * Extensions that must never be served as static files.
     */
    public const FORBIDDEN_EXTENSIONS = [
        'php',
        'phtml',
        'phar',
        'php3',
        'php4',
        'php5',
        'php7',
        'php8',
        'pht',
        'inc',
        'cgi',
        'pl',
    ];

    /**
     * Whether an extension must never be served or published, whatever its
     * case.
     */
    public static function isForbiddenExtension(string $extension): bool
    {
        return in_array(strtolower($extension), self::FORBIDDEN_EXTENSIONS, true);
    }
}
