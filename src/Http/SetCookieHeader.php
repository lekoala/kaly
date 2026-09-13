<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * Builds raw Set-Cookie header values.
 *
 * Single place where the header shape is decided, shared by application
 * cookies (Cookies) and the session cookie (Session).
 */
final class SetCookieHeader
{
    /**
     * Normalize a SameSite value to a mode accepted by setcookie().
     *
     * Common casings are canonicalized, anything else is dropped instead
     * of failing at runtime or emitting an invalid header.
     *
     * @return 'Lax'|'lax'|'None'|'none'|'Strict'|'strict'|null
     */
    public static function normalizeSameSite(mixed $value): ?string
    {
        return match ($value) {
            'None', 'none' => 'None',
            'Lax', 'lax' => 'Lax',
            'Strict', 'strict' => 'Strict',
            default => null,
        };
    }

    /**
     * Build a raw Set-Cookie header value.
     *
     * @param array{lifetime?:int,path?:string,domain?:string,secure?:bool,httponly?:bool,samesite?:string,partitioned?:bool} $params
     */
    public static function build(string $name, string $value, array $params, bool $expire = false): string
    {
        $cookie = urlencode($name) . '=' . urlencode($value);

        if ($expire) {
            $cookie .= '; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0';
        } elseif (!empty($params['lifetime'])) {
            // lifetime is a duration, not an absolute timestamp
            $lifetime = intval($params['lifetime']);
            $expires = gmdate('D, d M Y H:i:s T', time() + $lifetime);
            $cookie .= "; Expires={$expires}; Max-Age={$lifetime}";
        }

        if (!empty($params['domain'])) {
            $cookie .= "; Domain={$params['domain']}";
        }

        if (!empty($params['path'])) {
            $cookie .= "; Path={$params['path']}";
        }

        $samesite = self::normalizeSameSite($params['samesite'] ?? null);
        if ($samesite !== null) {
            $cookie .= "; SameSite={$samesite}";
        }

        if (!empty($params['secure'])) {
            $cookie .= '; Secure';
        }

        if (!empty($params['httponly'])) {
            $cookie .= '; HttpOnly';
        }

        // CHIPS, php 8.4+. Browsers require it to be paired with Secure.
        if (!empty($params['partitioned'])) {
            $cookie .= '; Partitioned';
        }

        return $cookie;
    }
}
