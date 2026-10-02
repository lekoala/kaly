<?php

declare(strict_types=1);

namespace Kaly\I18n;

/**
 * A typed translation identifier.
 *
 * Backed enums are the expected implementation: the enum case carries the
 * message id, autocompletion and refactoring stay safe, and completeness is
 * a loop over cases(). A null domain means the default domain of the engine.
 */
interface TranslationKey
{
    public function id(): string;

    public function domain(): ?string;
}
