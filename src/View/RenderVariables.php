<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * Reserved template variables injected by the framework for a render.
 *
 * They are per-render capabilities (translator, URL/asset generators,
 * authentication, CSRF, CSP), not page locals. A renderer that supports shared
 * data should expose them to the whole render, including partials and layouts,
 * instead of only the root template.
 */
final class RenderVariables
{
    /** Translator bound to the request locale. */
    public const I18N = 'i18n';

    /** URL generator bound to the request locale. */
    public const URL = 'url';

    /** Asset URL generator. */
    public const ASSET = 'asset';

    /** Authentication view helper. */
    public const AUTH = 'auth';

    /** CSRF token view helper. */
    public const CSRF = 'csrf';

    /** CSP nonce. */
    public const CSP = 'csp';

    /** @var list<string> */
    public const SHARED = [
        self::I18N,
        self::URL,
        self::ASSET,
        self::AUTH,
        self::CSRF,
        self::CSP,
    ];
}
