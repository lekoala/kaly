<?php

declare(strict_types=1);

namespace Kaly\Text;

use Kaly\Http\ServerRequest;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * Decides which locale a request runs with.
 *
 * The locale belongs to the request, not to the translator: resolving it is a
 * separate responsibility so that a shared translator never has to be mutated
 * for the current visitor.
 *
 * Priority:
 * 1. the locale imposed by the route
 * 2. the locale attribute injected by a middleware
 * 3. Accept-Language negotiation among the allowed locales
 * 4. the default locale
 */
class LocaleResolver
{
    public const ATTR_LOCALE_REQUEST = 'locale';

    /**
     * @param array<string> $allowedLocales Empty means any parseable locale is accepted
     */
    public function __construct(
        protected string $defaultLocale = 'en',
        protected array $allowedLocales = [],
    ) {}

    /**
     * Resolve the locale of a request. Always returns a usable locale.
     */
    public function resolve(ServerRequestInterface $request, ?string $routeLocale = null): string
    {
        // 1. The route wins: a locale prefix is an explicit choice of the visitor
        if ($routeLocale) {
            return $this->normalize($routeLocale);
        }

        // 2. A middleware may have decided already (eg: a stored preference)
        $attribute = $request->getAttribute(self::ATTR_LOCALE_REQUEST);
        if (is_string($attribute) && $attribute !== '') {
            return $this->normalize($attribute);
        }

        // 3. Negotiate with the browser
        $allowed = $this->allowedLocales === [] ? null : $this->allowedLocales;
        $preferred = ServerRequest::createFromRequest($request)->getPreferredLanguage($allowed);
        if (is_string($preferred) && $preferred !== '') {
            return $this->normalize($preferred);
        }

        // 4. No usable preference
        return $this->defaultLocale;
    }

    /**
     * Resolve the locale and expose it on the request under the locale attribute,
     * even when the visitor expressed no preference.
     */
    public function apply(ServerRequestInterface $request, ?string $routeLocale = null): ServerRequestInterface
    {
        return $request->withAttribute(self::ATTR_LOCALE_REQUEST, $this->resolve($request, $routeLocale));
    }

    /**
     * Unknown, malformed or disallowed locales fall back to the default rather
     * than failing the request.
     */
    protected function normalize(string $locale): string
    {
        try {
            Translator::parseLocale($locale);
        } catch (RuntimeException) {
            return $this->defaultLocale;
        }
        if ($this->allowedLocales !== [] && !in_array($locale, $this->allowedLocales, true)) {
            return $this->defaultLocale;
        }
        return $locale;
    }

    public function getDefaultLocale(): string
    {
        return $this->defaultLocale;
    }

    public function setDefaultLocale(string $defaultLocale): self
    {
        $this->defaultLocale = $defaultLocale;
        return $this;
    }

    /**
     * @return array<string>
     */
    public function getAllowedLocales(): array
    {
        return $this->allowedLocales;
    }

    /**
     * @param array<string> $allowedLocales
     */
    public function setAllowedLocales(array $allowedLocales): self
    {
        $this->allowedLocales = $allowedLocales;
        return $this;
    }
}
