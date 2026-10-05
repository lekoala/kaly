<?php

declare(strict_types=1);

namespace Kaly\Validation;

/**
 * A single refused value.
 *
 * The code is the stable machine contract for API consumers, the message id
 * plus domain resolve the human message through i18n, and the fallback is
 * always displayable when no translation exists. Parameters only feed message
 * rendering and are never exposed in the public JSON body.
 */
final readonly class Violation
{
    /**
     * @param array<string,mixed> $parameters
     */
    public function __construct(
        public ?string $field,
        public string $code,
        public string $messageId,
        public string $fallback,
        public array $parameters = [],
        public ?string $domain = 'validation',
    ) {}
}
