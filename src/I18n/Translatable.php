<?php

declare(strict_types=1);

namespace Kaly\I18n;

/**
 * A value that knows how to produce its own display message.
 *
 * It receives an already localized context: it never chooses the engine nor
 * the locale. Implementations must not pass an explicit locale to the given
 * translator, they translate with the bound locale or a withLocale() copy.
 */
interface Translatable
{
    public function translate(LocalizedTranslator $i18n): string;
}
