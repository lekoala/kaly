<?php

declare(strict_types=1);

namespace Kaly;

use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

/**
 * This state class encapsulate data for the current request
 */
class State
{
    protected ?ServerRequestInterface $request = null;
    protected Translator $translator;
    /**
     * @var array<string, mixed>
     */
    protected array $route;

    public function __construct(Translator $translator, Auth $auth)
    {
        $this->translator = $translator;
    }

    public function setLocaleFromRequest(): void
    {
        if ($this->request === null) {
            return;
        }
        $locale = $this->request->getAttribute("locale");
        if (!$locale) {
            $locale = Http::getPreferredLanguage($this->request);
        }
        if ($locale) {
            // Make sure it's valid
            Translator::parseLocale($locale);

            $this->translator->setCurrentLocale($locale);
        }
    }

    public function getLocale(): ?string
    {
        return $this->getTranslator()->getCurrentLocale();
    }

    public function getRequest(): ServerRequestInterface
    {
        if (!$this->request) {
            throw new RuntimeException("Request is not set yet");
        }
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

    /**
     * @return array<string, mixed>
     */
    public function getRoute(): array
    {
        return $this->route;
    }

    /**
     * @param array<string, mixed> $route
     */
    public function setRoute(array $route): self
    {
        $this->route = $route;
        return $this;
    }
}
