<?php

declare(strict_types=1);

namespace Kaly;

use RuntimeException;

/**
 * This basic translator supports a limited subset of symfony translator features.
 * It's php storage format is mostly similar so they are interchangeable.
 */
class Translator
{
    public const DEFAULT_DOMAIN = "messages";
    // ISO 639 2 or 3, or 4 for future use, alpha
    public const LOCALE_LANGUAGE = "language";
    // ISO 15924 4 alpha
    public const LOCALE_SCRIPT = "script";
    // ISO 3166-1 2 alpha or 3 digit
    public const LOCALE_COUNTRY = "country";
    public const LOCALE_PRIVATE = "private";

    /**
     * @var array<string, mixed>
     */
    protected array $catalogs = [];
    /**
     * @var array<string>
     */
    protected array $paths = [];
    protected ?string $defaultLocale = null;
    protected ?string $currentLocale = null;
    protected ?string $cacheDir = null;
    protected ?string $baseDomain = null;

    public function __construct(string $defaultLocale = null, string $currentLocale = null)
    {
        if (!$currentLocale) {
            $currentLocale = $defaultLocale;
        }
        if ($defaultLocale) {
            $this->setDefaultLocale($defaultLocale);
        }
        if ($currentLocale) {
            $this->setCurrentLocale($currentLocale);
        }
        // Add system path
        $this->addPath(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'lang');
    }

    /**
     * @return array<string, string>
     */
    public static function parseLocale(string $locale): array
    {
        $pattern = "/^(?<language>[A-Za-z]{2,4})([_-](?<script>[A-Za-z]{4}|[0-9]{3}))?([_-](?<country>[A-Za-z]{2}|[0-9]{3}))?([_-]x[_-](?<private>[A-Za-z0-9-_]+))?$/";
        $matches = [];
        $results = preg_match($pattern, $locale, $matches);
        if (!$results) {
            throw new RuntimeException("Failed to parse locale string '$locale'");
        }
        $matches = array_filter($matches, "is_string", ARRAY_FILTER_USE_KEY);
        $matches['script'] = $matches['script'] ?? '';
        $matches['country'] = $matches['country'] ?? '';
        $matches['private'] = $matches['private'] ?? '';
        return $matches;
    }

    public static function getLangFromLocale(string $locale): string
    {
        return strtolower(explode("-", str_replace("_", "-", $locale), 3)[0]);
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
        return $this->catalogs[$name][$locale];
    }

    /**
     * @param array<string, string|array> $strings
     */
    public function addToCatalog(string $name, string $locale, array $strings): self
    {
        if (!isset($this->catalogs[$name][$locale])) {
            $this->buildCatalog($name, $locale);
        }
        $this->catalogs[$name][$locale] = array_merge_distinct($this->catalogs[$name][$locale], $strings);
        return $this;
    }

    protected function buildCatalog(string $name, string $locale): void
    {
        foreach ($this->paths as $path) {
            if (!isset($this->catalogs[$name])) {
                $this->catalogs[$name] = [];
            }
            if (!isset($this->catalogs[$name][$locale])) {
                $this->catalogs[$name][$locale] = [];
            }
            $file = $path . "/$name.$locale.php";
            if (!is_file($file)) {
                continue;
            }
            $result = require $file;
            if (!is_array($result)) {
                throw new RuntimeException("Translation file '$file' must return an array");
            }
            $this->catalogs[$name][$locale] = $result;
        }
        // Update cache file if set
        if ($this->cacheDir) {
            $file = $this->cacheDir . DIRECTORY_SEPARATOR . "$name.$locale.php";
            $export = var_export($this->catalogs, true);
            file_put_contents($file, "<?php return $export;");
        }
    }

    /**
     * @param array<string, mixed> $parameters
     */
    public function translate(string $message, array $parameters = [], string $domain = null, string $locale = null): string
    {
        if (!$domain) {
            $domain = $this->baseDomain ?? self::DEFAULT_DOMAIN;
        }
        if (!$locale) {
            $locale = $this->currentLocale;
        }
        if (!$locale) {
            throw new RuntimeException("No locale set for translation");
        }
        $catalog = $this->getCatalog($domain, $locale);

        // nested ids supports à la symfony
        $parts = explode(".", $message);
        $index = 0;
        $translation = $catalog;
        do {
            $translation = $translation[$parts[$index]] ?? '';
            if (!is_array($translation)) {
                break;
            }
            $index++;
            if (!isset($parts[$index])) {
                break;
            }
        } while (isset($translation[$parts[$index]]));

        // Not found in nested array
        if (is_array($translation)) {
            $translation = '{{' . $message . '}}';
        }

        // Attempt fallback to lang
        $lang = self::getLangFromLocale($locale);
        if (!$translation && $locale != $lang) {
            return $this->translate($message, $parameters, $domain, $lang);
        }

        // Attempt fallback in default locale
        if (!$translation && $locale != $this->defaultLocale && $this->defaultLocale) {
            return $this->translate($message, $parameters, $domain, $this->defaultLocale);
        }

        // Handling plurals in a minimalistic yet powerful fashion
        if (isset($parameters['%count%'])) {
            $c = intval($parameters['%count%']);
            $translation = $this->processPlurals($translation, $c);
        }

        $translation = $this->replaceContext($translation, $parameters);

        if (!$translation) {
            $translation = '{{' . $message . '}}';
        }

        return $translation;
    }

    /**
     * @param array<string, mixed> $replacements
     */
    protected function replaceContext(string $translation, array $replacements): string
    {
        $replace = [];
        foreach ($replacements as $key => $val) {
            // We support two style : {} replacements and raw %param% replacements
            if (str_starts_with($key, '%')) {
                $replace[$key] = $val;
            } else {
                $replace['{' . $key . '}'] = $val;
            }
        }
        return strtr($translation, $replace);
    }

    protected function processPlurals(string $translation, int $c): string
    {
        // This could be a simple convention singular|plural
        // Or have specific rules if starts with { or ][
        $parts = explode("|", $translation);
        // The last one is the valid one by default
        $translation = end($parts);
        $partsNum = count($parts);
        foreach ($parts as $idx => $part) {
            $char = $part[0];
            $matches = [];

            // It can start with a denominator or it is a simple array
            if ($char == '{') {
                $results = preg_match('/{([0-9]*)}(.*)/u', $part, $matches);
                if ($results && $matches[1] == $c) {
                    $translation = $matches[2];
                    break;
                }
            } elseif ($char == ']') {
                // We don't parse these, consider it's a good one
                $results = preg_match('/\](.*)\[(.*)/u', $part, $matches);
                if ($results) {
                    $translation = $matches[2];
                    break;
                }
            } elseif ($partsNum === 2) {
                // We have a simple pair of singular/plural
                if ($c <= 1 && $idx === 0) {
                    $translation = $part;
                    break;
                } elseif ($c > 1 && $idx == 1) {
                    $translation = $part;
                    break;
                }
            } elseif ($partsNum === 3) {
                // We have a triple pair of none/singular/plural
                if ($c === 0 && $idx === 0) {
                    $translation = $part;
                    break;
                } elseif ($c === 1 && $idx == 1) {
                    $translation = $part;
                    break;
                } elseif ($c > 1 && $idx === 2) {
                    $translation = $part;
                    break;
                }
            }
        }
        return $translation;
    }

    public function getDefaultLocale(): ?string
    {
        return $this->defaultLocale;
    }

    public function setDefaultLocale(string $defaultLocale): self
    {
        $this->defaultLocale = $defaultLocale;
        return $this;
    }

    public function getCurrentLocale(): ?string
    {
        return $this->currentLocale;
    }

    public function setCurrentLocale(string $currentLocale): self
    {
        $this->currentLocale = $currentLocale;
        return $this;
    }

    public function getBaseDomain(): ?string
    {
        return $this->baseDomain;
    }

    public function setBaseDomain(string $baseDomain): self
    {
        $this->baseDomain = $baseDomain;
        return $this;
    }

    public function getCacheDir(): ?string
    {
        return $this->cacheDir;
    }

    public function setCacheDir(string $cacheDir): self
    {
        $this->cacheDir = $cacheDir;

        // Load data from cache
        if (is_dir($this->cacheDir)) {
            $files = glob_recursive($this->cacheDir . '/*.php');
            foreach ($files as $file) {
                $arr = require $file;
                if (!is_array($arr)) {
                    throw new RuntimeException("Cached translation file did not return an array");
                }
                $this->catalogs = array_merge_distinct($this->catalogs, $arr);
            }
        }

        return $this;
    }

    public function clearCache(): bool
    {
        if (!$this->cacheDir) {
            return false;
        }
        if (is_dir($this->cacheDir)) {
            $files = glob_recursive($this->cacheDir . '/*.php');
            foreach ($files as $file) {
                unlink($file);
            }
            return true;
        }
        return false;
    }
}
