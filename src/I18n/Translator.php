<?php

declare(strict_types=1);

namespace Kaly\I18n;

use RuntimeException;

/**
 * The portable basic translation subset: PHP catalogs, message ids, domains,
 * explicit locales and exact parameter replacement.
 *
 * Advanced pluralization, ICU formatting, resource loaders and extended
 * fallback rules belong to external engines such as Symfony Translation.
 *
 * It is a shared service: it holds catalogs and a default locale, never the
 * locale of the current request. Pass the locale explicitly, or wrap it in a
 * LocalizedTranslator built from the locale resolved for the request.
 *
 * Catalogs are built lazily in memory, once per domain and locale: there is
 * no file cache, PHP files are the cache.
 */
final class Translator implements TranslatorInterface
{
    public const DEFAULT_DOMAIN = 'messages';

    /**
     * @var array<string,array<string,array<string,mixed>>>
     */
    protected array $catalogs = [];
    /**
     * @var array<string>
     */
    protected array $paths = [];
    protected string $defaultLocale = 'en';

    public function __construct(string $defaultLocale = 'en')
    {
        if ($defaultLocale) {
            $this->setDefaultLocale($defaultLocale);
        }
    }

    /**
     * @return array<string>
     */
    public function getPaths(): array
    {
        return $this->paths;
    }

    /**
     * @param array<string> $paths
     */
    public function setPaths(array $paths): self
    {
        $this->paths = $paths;
        return $this;
    }

    public function addPath(string $path): self
    {
        $this->paths[] = $path;
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCatalog(string $name, string $locale): array
    {
        if (!isset($this->catalogs[$name][$locale])) {
            $this->buildCatalog($name, $locale);
        }
        $catalog = $this->catalogs[$name][$locale] ?? [];
        if (is_array($catalog)) {
            return $catalog;
        }
        throw new RuntimeException('Ran into an invalid catalog value, check buildCatalog function');
    }

    /**
     * @param array<array-key,mixed> $strings
     */
    public function addToCatalog(string $name, string $locale, array $strings): self
    {
        if (!isset($this->catalogs[$name][$locale])) {
            $this->buildCatalog($name, $locale);
        }
        // Later additions win key by key, exactly like later paths
        foreach (self::flatten($strings, 'added catalog') as $id => $translation) {
            $this->catalogs[$name][$locale][$id] = $translation;
        }
        return $this;
    }

    protected function buildCatalog(string $name, string $locale): void
    {
        $merged = [];
        foreach ($this->paths as $path) {
            $file = $path . "/{$name}.{$locale}.php";
            if (!is_file($file)) {
                continue;
            }
            $result = require $file;
            if (!is_array($result)) {
                throw new RuntimeException("Translation file '{$file}' must return an array");
            }
            // Later paths win key by key, without dropping the other messages
            foreach (self::flatten($result, $file) as $id => $translation) {
                $merged[$id] = $translation;
            }
        }
        $this->catalogs[$name][$locale] = $merged;
    }

    /**
     * Flatten a nested catalog to dotted ids, à la Symfony.
     *
     * Null values are dropped: they mean absent, like in Symfony. A dotted id
     * produced twice from different shapes is ambiguous and rejected, so
     * every accepted catalog has exactly one reading.
     *
     * @param array<array-key,mixed> $messages
     * @return array<string,mixed>
     */
    protected static function flatten(array $messages, string $source, string $prefix = ''): array
    {
        $flat = [];
        foreach ($messages as $key => $value) {
            $id = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $nested = self::flatten($value, $source, $id);
            } else {
                $nested = $value === null ? [] : [$id => $value];
            }
            foreach ($nested as $nestedId => $nestedValue) {
                if (array_key_exists($nestedId, $flat)) {
                    throw new RuntimeException("Ambiguous translation id '{$nestedId}' in '{$source}'");
                }
                $flat[$nestedId] = $nestedValue;
            }
        }
        return $flat;
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function translate(string $message, array $parameters = [], ?string $domain = null, ?string $locale = null): string
    {
        if ($message === '') {
            return '';
        }
        $domain ??= self::DEFAULT_DOMAIN;
        if (!$locale) {
            $locale = $this->defaultLocale;
        }
        if (!$locale) {
            throw new RuntimeException('No locale set for translation');
        }
        $catalog = $this->getCatalog($domain, $locale);

        // Presence is existence, never truthiness: '' and '0' are valid
        if (array_key_exists($message, $catalog) && (is_string($catalog[$message]) || is_scalar($catalog[$message]))) {
            return strtr((string) $catalog[$message], $parameters);
        }

        // Attempt fallback to lang
        $lang = Locale::language($locale);
        if ($locale !== $lang) {
            return $this->translate($message, $parameters, $domain, $lang);
        }

        // Attempt fallback in default locale
        if ($locale !== $this->defaultLocale && $this->defaultLocale) {
            return $this->translate($message, $parameters, $domain, $this->defaultLocale);
        }

        // A missing key returns the id itself, formatted with the parameters
        return strtr($message, $parameters);
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
}
