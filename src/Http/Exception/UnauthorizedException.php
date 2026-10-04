<?php

declare(strict_types=1);

namespace Kaly\Http\Exception;

use InvalidArgumentException;

/**
 * The request lacks valid authentication credentials.
 *
 * A 401 answer always carries at least one WWW-Authenticate challenge, so the
 * challenge is mandatory: 401 says authenticate, 403 says forbidden.
 */
final class UnauthorizedException extends HttpException
{
    public function __construct(string $challenge, string $message = 'Unauthorized')
    {
        $challenge = trim($challenge);

        if ($challenge === '') {
            throw new InvalidArgumentException('Challenge must not be empty');
        }

        parent::__construct($message, 401, ['WWW-Authenticate' => $challenge]);
    }
}
