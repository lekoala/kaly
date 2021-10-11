<?php

declare(strict_types=1);

namespace Kaly;

use Psr\Http\Message\ServerRequestInterface;

/**
 * This state class encapsulate data for the current request
 */
class State
{
    protected ?ServerRequestInterface $request = null;
    protected Translator $translator;

    public function __construct(Translator $translator)
    {
        $this->translator = $translator;
    }

    public function setLocaleFromRequest(): void
    {
        if ($this->request == null) {
            return;
        }
        $locale = $this->request->getAttribute("locale");
        if (!$locale) {
            $locale = Http::getPreferredLanguage($this->request);
        }
        if ($locale) {
            $this->translator->setCurrentLocale($locale);
        }
    }

    public function getLocale(): ?string
    {
        return $this->getTranslator()->getCurrentLocale();
    }

    public function getRequest(): ?ServerRequestInterface
    {
        return $this->request;
    }

    public function setRequest(ServerRequestInterface $request): self
    {
        $this->request = $request;
        return $this;
    }

    public function getTranslator(): Translator
    {
        return $this->translator;
    }

    public function setTranslator(Translator $translator): self
    {
        $this->translator = $translator;
        return $this;
    }
}
