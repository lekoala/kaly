<?php

declare(strict_types=1);

namespace Kaly\I18n;

use Kaly\Http\Input\ValidationException;
use Throwable;

/**
 * A validation failure carrying a typed translation key.
 *
 * The literal counterpart stays on ValidationException: a string message is
 * kept unchanged and never translated. This class is the opt-in side, where
 * the message is a TranslationKey translated with its id, domain and
 * parameters. getMessage() stays diagnosable without i18n: the raw id.
 */
class TranslatableValidationException extends ValidationException implements Translatable
{
    private readonly TranslationKey $translation;

    /**
     * @param array<string,mixed> $parameters
     */
    public function __construct(
        TranslationKey $message,
        int $code = 422,
        ?Throwable $previous = null,
        private readonly array $parameters = [],
    ) {
        $this->translation = $message;
        parent::__construct($message->id(), $code, $previous);
    }

    public function translation(): TranslationKey
    {
        return $this->translation;
    }

    /**
     * @return array<string,mixed>
     */
    public function parameters(): array
    {
        return $this->parameters;
    }

    public function translate(LocalizedTranslator $i18n): string
    {
        return $i18n->translate($this->translation->id(), $this->parameters, $this->translation->domain());
    }
}
