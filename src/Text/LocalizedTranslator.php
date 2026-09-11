<?php

declare(strict_types=1);

namespace Kaly\Text;

/**
 * An immutable translator bound to one locale.
 *
 * This is what a request (or a render) hands over to the code that actually
 * translates: the engine keeps its catalogs in memory and stays shared, while
 * the locale of the current visitor travels with this small object instead of
 * being written on the shared service.
 */
final class LocalizedTranslator implements TranslatorInterface
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly string $locale,
    ) {}

    public function getLocale(): string
    {
        return $this->locale;
    }

    /**
     * The bound locale is always passed explicitly to the engine, so a shared
     * engine never has to be mutated to serve this request.
     *
     * @param array<string,mixed> $parameters
     */
    public function translate(string $message, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translator->translate($message, $parameters, $domain, $locale ?? $this->locale);
    }

    public function withLocale(string $locale): self
    {
        if ($locale === $this->locale) {
            return $this;
        }
        return new self($this->translator, $locale);
    }
}
