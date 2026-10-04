<?php

declare(strict_types=1);

namespace Kaly\Http\Csrf;

use Kaly\Ex;
use Kaly\Http\Session\SessionInterface;

/**
 * A synchronizer token bound to the session, masked on every exposure.
 *
 * One session owns one ASCII secret, never sent as-is: each token() call
 * prepends a fresh random mask to mask XOR secret, base64url encoded. This
 * follows the same XOR-mask construction used by Yii, so two renders look
 * different while validating against the same stored secret.
 *
 * A generic session primitive: it only knows SessionInterface, never the
 * whole HTTP context. The secret is ASCII on purpose, so any backend
 * (including JSON-serialized ones) can store it.
 */
final class Csrf
{
    public const SESSION_KEY = '_csrf';

    public const FIELD = '_csrf';

    public const HEADER = 'X-CSRF-Token';

    private const SECRET_BYTES = 32;

    public function token(SessionInterface $session): string
    {
        return self::mask($this->secret($session));
    }

    public function validate(SessionInterface $session, #[\SensitiveParameter] string $token): bool
    {
        $secret = $session->get(self::SESSION_KEY);

        if (!is_string($secret) || $secret === '') {
            return false;
        }

        $raw = self::decode($token);

        if ($raw === null) {
            return false;
        }

        $expected = strlen($secret) * 2;

        if ($expected === 0 || strlen($raw) !== $expected) {
            return false;
        }

        $half = intdiv(strlen($raw), 2);

        if ($half <= 0) {
            return false;
        }

        $mask = substr($raw, 0, $half);
        $masked = substr($raw, $half);

        if (strlen($mask) !== strlen($secret)) {
            return false;
        }

        return hash_equals($secret, $masked ^ $mask);
    }

    public function refresh(SessionInterface $session): string
    {
        $secret = self::generateSecret();
        $session->set(self::SESSION_KEY, $secret);

        return self::mask($secret);
    }

    public function clear(SessionInterface $session): void
    {
        $session->remove(self::SESSION_KEY);
    }

    private function secret(SessionInterface $session): string
    {
        $secret = $session->get(self::SESSION_KEY);

        if (!is_string($secret) || $secret === '') {
            $secret = self::generateSecret();
            $session->set(self::SESSION_KEY, $secret);
        }

        return $secret;
    }

    private static function generateSecret(): string
    {
        return self::encode(random_bytes(self::SECRET_BYTES));
    }

    private static function mask(#[\SensitiveParameter] string $secret): string
    {
        if ($secret === '') {
            throw new Ex('Cannot mask an empty CSRF secret');
        }

        $mask = random_bytes(strlen($secret));

        return self::encode($mask . ($mask ^ $secret));
    }

    private static function encode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function decode(#[\SensitiveParameter] string $token): ?string
    {
        if ($token === '' || preg_match('#^[A-Za-z0-9_-]+$#', $token) !== 1) {
            return null;
        }

        $padded = strtr($token, '-_', '+/');
        $remainder = strlen($padded) % 4;

        if ($remainder === 1) {
            return null;
        }

        if ($remainder !== 0) {
            $padded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }
}
