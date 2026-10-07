<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * One entry of an Accept-Language header: a language range and the weight the
 * client gave it, with the position it was written at.
 *
 * @internal Part of the Accept-Language negotiation, see AcceptLanguage
 */
final class AcceptLanguageEntry
{
    public function __construct(
        public readonly string $language,
        public readonly float $quality,
        public readonly int $position,
    ) {}
}
