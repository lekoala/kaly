<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Asset\AssetView;
use Kaly\Auth\AuthView;
use Kaly\Ex;
use Kaly\Http\Csp\Csp;
use Kaly\Http\Csrf\CsrfView;
use Kaly\I18n\LocalizedTranslator;
use Kaly\Router\UrlView;
use Kaly\View\RenderEnvironmentInterface;

/**
 * The typed capabilities of a single render, composed by Core.
 *
 * Page data belongs to the application; this object carries what Kaly itself
 * contributes to a render (translation, url and asset generation,
 * authentication, CSRF, CSP). The two travel separately to the renderer, so a
 * capability is never silently replaced by a page local. Whenever an
 * environment exists, its names are reserved for the whole render.
 *
 * It holds references, not deep copies: CSRF and CSP stay lazy and AuthView
 * observes the request authentication. An environment is built per render and
 * is never stored on a shared engine.
 */
final readonly class RenderEnvironment implements RenderEnvironmentInterface
{
    /** @var list<string> */
    public const RESERVED = [
        'i18n',
        'url',
        'asset',
        'auth',
        'csrf',
        'csp',
    ];

    public function __construct(
        public LocalizedTranslator $i18n,
        public UrlView $url,
        public AssetView $asset,
        public AuthView $auth,
        public CsrfView $csrf,
        public Csp $csp,
    ) {}

    public function assertCompatible(array $data): void
    {
        foreach (self::RESERVED as $name) {
            if (array_key_exists($name, $data)) {
                throw new Ex("View data cannot contain the reserved variable '{$name}'");
            }
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function variables(): array
    {
        return [
            'i18n' => $this->i18n,
            'url' => $this->url,
            'asset' => $this->asset,
            'auth' => $this->auth,
            'csrf' => $this->csrf,
            'csp' => $this->csp,
        ];
    }
}
