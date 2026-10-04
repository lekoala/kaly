<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The parsed Authorization header of a request.
 *
 * Pure HTTP: a single header value (multiples are rejected, never merged),
 * a case-insensitive scheme in token syntax, Basic credentials decoded
 * strictly with the username split on the first colon and no control
 * characters, Bearer as an opaque token in b64token syntax. Validation of
 * the credentials themselves belongs to the application.
 */
final readonly class Authorization
{
    public function __construct(
        private string $scheme,
        private string $credentials,
    ) {}

    public static function from(ServerRequestInterface $request): ?self
    {
        $values = $request->getHeader('Authorization');

        if (count($values) !== 1) {
            return null;
        }

        $header = trim($values[0]);

        if ($header === '') {
            return null;
        }

        $space = strpos($header, ' ');

        if ($space === false) {
            return null;
        }

        $scheme = strtolower(trim(substr($header, 0, $space)));
        $credentials = trim(substr($header, $space + 1));

        if ($scheme === '' || $credentials === '' || !self::isToken($scheme)) {
            return null;
        }

        return new self($scheme, $credentials);
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function credentials(): string
    {
        return $this->credentials;
    }

    /**
     * @return array{username:string,password:string}|null
     */
    public function basic(): ?array
    {
        if ($this->scheme !== 'basic') {
            return null;
        }

        $decoded = base64_decode($this->credentials, true);

        if ($decoded === false || preg_match('/[\x00-\x1F\x7F]/', $decoded) === 1) {
            return null;
        }

        $colon = strpos($decoded, ':');

        if ($colon === false) {
            return null;
        }

        return [
            'username' => substr($decoded, 0, $colon),
            'password' => substr($decoded, $colon + 1),
        ];
    }

    public function bearer(): ?string
    {
        if ($this->scheme !== 'bearer') {
            return null;
        }

        if (preg_match('#^[A-Za-z0-9\-._~+/]+={0,2}$#', $this->credentials) !== 1) {
            return null;
        }

        return $this->credentials;
    }

    private static function isToken(string $value): bool
    {
        return preg_match('/^[!#$%&\'*+\-.^_`|~0-9A-Za-z]+$/', $value) === 1;
    }
}
