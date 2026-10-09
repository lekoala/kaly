<?php

declare(strict_types=1);

namespace Kaly\Debug;

use Psr\Http\Message\ResponseInterface;

/** Formats a completed profile as HTTP Server-Timing metrics. */
final class ServerTiming
{
    public static function apply(ResponseInterface $response, Profile $profile): ResponseInterface
    {
        $metrics = $profile->metrics();
        if ($metrics === []) {
            return $response;
        }
        $values = [];
        foreach ($metrics as $name => $metric) {
            $values[] = sprintf('%s;dur=%.1F', $name, $metric['duration']);
        }
        return $response->withAddedHeader('Server-Timing', implode(', ', $values));
    }
}
