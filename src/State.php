<?php

declare(strict_types=1);

namespace Kaly;

use Psr\Http\Message\ServerRequestInterface;

/**
 * This state class encapsulate data for the current request
 */
class State
{
    protected ServerRequestInterface $request;
    protected ?Translator $translator;
    protected string $locale;

    public function setLocaleFromRequest(): void
    {
        if ($this->request == null) {
            return;
        }
        $locale = Http::getPreferredLanguage($this->request);
        if ($locale) {
            $this->setLocale($locale);
        }
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale, bool $updateTranslator = true): self
    {
        $this->locale = $locale;
        // Based on current request, we need to adjust our translator
        if ($updateTranslator && $this->translator) {
            $this->translator->setCurrentLocale($locale);
        }
        return $this;
    }

    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function setRequest(ServerRequestInterface $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function getTranslator(): ?Translator
    {
        return $this->translator;
    }

    public function setTranslator(Translator $translator = null): self
    {
        $this->translator = $translator;
        return $this;
    }
}
