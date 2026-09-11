<?php

declare(strict_types=1);

namespace Kaly\Text;

/**
 * The minimal contract Kaly needs from a translator.
 *
 * Kaly only standardizes a message id, a parameter bag, a domain and a locale:
 * it must be possible to plug any engine (the native Translator, Symfony, ...)
 * behind this interface. Loading catalogs, caching and fallbacks belong to the
 * implementation, not to the consumers.
 *
 * Implementations are shared services and must not carry a per request state:
 * a null locale always means the default locale configured on the service,
 * which stays stable for its whole lifetime. Bind the locale of the current
 * request with a LocalizedTranslator instead.
 */
interface TranslatorInterface
{
    /**
     * Translate a message id.
     *
     * @param array<string,mixed> $parameters
     * @param string|null $domain Null means the default domain of the implementation
     * @param string|null $locale Null means the default locale of the implementation
     */
    public function translate(string $message, array $parameters = [], ?string $domain = null, ?string $locale = null): string;
}
