<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * One entry of an Accept header: a media type and the weight the client gave
 * it, with the position it was written at.
 *
 * @internal Part of the Accept negotiation, see Accept
 */
final class AcceptEntry
{
    public function __construct(
        public readonly MediaType $media,
        public readonly float $quality,
        public readonly int $position,
    ) {}
}
