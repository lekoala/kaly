<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\I18n\LocalizedTranslator;
use Kaly\Validation\Violation;

/**
 * Resolves one violation's display message through a localized translator.
 *
 * It composes two layers that stay independent: `Validation` owns the
 * violation, `I18n` owns the translation. A missing catalog entry falls back
 * to the violation's always displayable message, the same rule the exception
 * handler applies to error bodies. It stays deliberately narrow: one
 * violation in, one message out.
 */
final readonly class ViolationMessageResolver
{
    public function __construct(
        private LocalizedTranslator $translator,
    ) {}

    public function resolve(Violation $violation): string
    {
        $message = $this->translator->translate($violation->messageId, $violation->parameters, $violation->domain);

        // The engine returns the id itself when a key is missing: for a
        // violation that means the fallback wins.
        return $message === $violation->messageId ? $violation->fallback : $message;
    }
}
