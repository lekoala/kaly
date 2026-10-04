<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;

/**
 * The parsed Authorization header of a request.
 *
 * Pure HTTP: the scheme is case-insensitive, Basic credentials are decoded
 * strictly with the username split on the first colon, Bearer stays an opaque
 * token. Validation of the credentials belongs to the application.
 */
final readonly class Authorization
{
    public function __construct(
        private string $scheme,
        private string $credentials,
    ) {}

    public static function from(ServerRequestInterface $request): ?self
    {
        $header = trim($request->getHeaderLine('Authorization'));

        if ($header === '') {
            return null;
        }

        $space = strpos($header, ' ');
        if ($space === false) {
            return null;
        }

        $scheme = strtolower(trim(substr($header, 0, $space)));
        $credentials = trim(substr($header, $space + 1));

        if ($scheme === '' || $credentials === '') {
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

        if ($decoded === false) {
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

        return $this->credentials;
    }
}
