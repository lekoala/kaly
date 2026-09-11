<?php

declare(strict_types=1);

namespace Kaly\Text\Adapter;

use Kaly\Text\TranslatorInterface;
use Symfony\Contracts\Translation\TranslatorInterface as SymfonyTranslatorContract;

/**
 * Bridges symfony/translation to Kaly's translator abstraction.
 *
 * Requires symfony/translation (see composer "suggest"). Kaly never abstracts
 * Symfony features (ICU messages, loaders, resource caching): configure those
 * on the Symfony translator itself.
 *
 * Symfony already accepts a locale per call, so the adapter never has to change
 * its state for the current visitor.
 */
final class SymfonyTranslator implements TranslatorInterface
{
    public function __construct(
        private readonly SymfonyTranslatorContract $translator,
    ) {}

    /**
     * @param array<string,mixed> $parameters
     */
    public function translate(string $message, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translator->trans($message, $parameters, $domain, $locale);
    }
}
