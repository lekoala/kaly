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
    protected string $locale;

    public function setLocaleFromRequest(): void
    {
        if ($this->request == null) {
            return;
        }
        $locale = Http::getPreferredLanguage($this->request);
        if ($locale) {
            $this->locale = $locale;
        }
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): self
    {
        $this->locale = $locale;
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
}
