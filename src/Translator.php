<?php

declare(strict_types=1);

namespace Kaly;

use RuntimeException;

/**
 * This basic translator supports a limited subset
 * of symfony translator features.
 * It's php storage format is mostly similar so they
 * are interchangeable.
 */
class Translator
{
    /**
     * @var array<string, mixed>
     */
    protected array $catalogs = [];
    /**
     * @var array<string>
     */
    protected array $paths = [];
    protected ?string $defaultLocale;
    protected ?string $currentLocale;

    public function __construct(string $defaultLocale = null, string $currentLocale = null)
    {
        if (!$currentLocale) {
            $currentLocale = $defaultLocale;
        }
        $this->defaultLocale = $defaultLocale;
        $this->currentLocale = $currentLocale;
    }

    public function getPaths(): array
    {
        return $this->paths;
    }

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

    protected function getCatalog(string $name, string $locale)
    {
        if (!isset($this->catalogs[$name][$locale])) {
            $this->buildCatalog($name, $locale);
        }
        return $this->catalogs[$name][$locale];
    }

    protected function buildCatalog(string $name, string $locale)
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
    }

    public function translate(string $message, array $parameters = [], string $domain = null, string $locale = null): string
    {
        if (!$domain) {
            $domain = "messages";
        }
        if (!$locale) {
            $locale = $this->currentLocale;
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

        // Not found in array
        if (is_array($translation)) {
            $translation = '';
        }

        // Attempt fallback in default locale
        if (!$translation && $locale != $this->defaultLocale) {
            return $this->translate($message, $parameters, $domain, $this->defaultLocale);
        }

        // Replace context
        $replace = [];
        foreach ($parameters as $key => $val) {
            $replace['{' . $key . '}'] = $val;
        }
        $translation = strtr($translation, $replace);

        return $translation;
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

    public function getCurrentLocale(): string
    {
        return $this->currentLocale;
    }

    public function setCurrentLocale(string $currentLocale): self
    {
        $this->currentLocale = $currentLocale;
        return $this;
    }
}
