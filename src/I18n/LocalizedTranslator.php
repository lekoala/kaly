<?php

declare(strict_types=1);

namespace Kaly\I18n;

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

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * The bound locale is always passed explicitly to the engine, so a shared
     * engine never has to be mutated to serve this request.
     *
     * The nullable locale is an explicit escape hatch (eg: a preview in
     * another language). The normal path passes no locale and uses the bound
     * one, or a withLocale() copy for a deliberate switch.
     *
     * @param array<string,mixed> $parameters
     */
    public function translate(string $message, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        return $this->translator->translate($message, $parameters, $domain, $locale ?? $this->locale);
    }

    /**
     * Resolve a display value according to its type, never by guessing.
     *
     * A string is always a literal and is returned unchanged without calling
     * the engine. A TranslationKey is translated by id and domain. A
     * Translatable produces its own message. When a value implements both,
     * Translatable wins because it carries the richer behaviour.
     */
    public function resolve(string|TranslationKey|Translatable $value): string
    {
        return match (true) {
            $value instanceof Translatable => $value->translate($this),
            $value instanceof TranslationKey => $this->translate($value->id(), domain: $value->domain()),
            default => $value,
        };
    }

    public function withLocale(string $locale): self
    {
        if ($locale === $this->locale) {
            return $this;
        }
        return new self($this->translator, $locale);
    }
}
