<?php

declare(strict_types=1);

namespace Kaly\I18n;

use DirectoryIterator;
use Kaly\Ex;

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
 * no file cache, PHP files are the cache. Directory contents are indexed once
 * per path, so missing catalogs need no repeated filesystem lookup.
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
    /**
     * @var array<string,array<string,list<string>>>
     */
    private array $catalogFiles = [];
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
        $this->catalogFiles = [];
        return $this;
    }

    public function addPath(string $path): self
    {
        $this->paths[] = $path;
        unset($this->catalogFiles[$path]);
        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCatalog(string $name, string $locale): array
    {
        $locale = Locale::normalize($locale);
        if (!isset($this->catalogs[$name][$locale])) {
            $this->buildCatalog($name, $locale);
        }
        $catalog = $this->catalogs[$name][$locale] ?? [];
        if (is_array($catalog)) {
            return $catalog;
        }
        throw new Ex('Ran into an invalid catalog value, check buildCatalog function');
    }

    /**
     * @param array<array-key,mixed> $strings
     */
    public function addToCatalog(string $name, string $locale, array $strings): self
    {
        $locale = Locale::normalize($locale);
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
        if (
            !Locale::isValid($locale)
            || str_contains($locale, '/')
            || str_contains($locale, '\\')
            || $name === ''
            || str_contains($name, '..')
            || str_contains($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
        ) {
            throw new Ex('Invalid translation domain or locale');
        }
        $merged = [];
        $found = false;
        foreach ($this->paths as $path) {
            $file = $this->catalogFile($path, $name, $locale);
            if ($file === null) {
                continue;
            }
            $found = true;
            $result = require $file;
            if (!is_array($result)) {
                throw new Ex("Translation file '{$file}' must return an array");
            }
            // Later paths win key by key, without dropping the other messages
            foreach (self::flatten($result, $file) as $id => $translation) {
                $merged[$id] = $translation;
            }
        }
        if ($found) {
            $this->catalogs[$name][$locale] = $merged;
        }
    }

    private function catalogFile(string $path, string $name, string $locale): ?string
    {
        if (!isset($this->catalogFiles[$path])) {
            $files = [];
            if (is_dir($path)) {
                foreach (new DirectoryIterator($path) as $entry) {
                    $filename = $entry->getFilename();
                    if (!str_ends_with($filename, '.php') || !$entry->isFile()) {
                        continue;
                    }
                    $stem = substr($filename, 0, -4);
                    $separator = strrpos($stem, '.');
                    if ($separator === false) {
                        continue;
                    }
                    $fileName = substr($stem, 0, $separator);
                    $fileLocale = Locale::normalize(substr($stem, $separator + 1));
                    $files[$fileName . '.' . $fileLocale][] = $entry->getPathname();
                }
            }
            $this->catalogFiles[$path] = $files;
        }
        $matches = $this->catalogFiles[$path][$name . '.' . $locale] ?? [];
        if (count($matches) > 1) {
            throw new Ex("Duplicate translation catalogs for '{$name}.{$locale}' in '{$path}'");
        }
        return $matches[0] ?? null;
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
                    throw new Ex("Ambiguous translation id '{$nestedId}' in '{$source}'");
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
            throw new Ex('No locale set for translation');
        }
        $locales = array_unique([$locale, Locale::language($locale), $this->defaultLocale, Locale::language($this->defaultLocale)]);
        foreach ($locales as $candidate) {
            $catalog = $this->getCatalog($domain, $candidate);
            if (array_key_exists($message, $catalog) && is_scalar($catalog[$message])) {
                return strtr((string) $catalog[$message], $parameters);
            }
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
