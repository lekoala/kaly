<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\Exception\HttpExceptionInterface;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\Input\InputException;
use Kaly\Http\Input\ValidationException;
use Kaly\I18n\LocaleResolver;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translatable;
use Kaly\I18n\TranslatorInterface;
use Kaly\Validation\ValidationResult;
use Kaly\Validation\Violation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The default exception handler, with localized public error bodies.
 *
 * Only an already public HTTP error is translated, using the resolved locale
 * or the request locale resolver before routing. A validation result keeps
 * its structure: every violation is translated on its own, falling back to
 * its always displayable message when the catalog has nothing. Any other
 * Translatable error keeps its historical single-body translation.
 * Translation composes here because the Http layer never depends on I18n.
 */
final class LocalizedExceptionHandler implements ExceptionHandlerInterface
{
    public function __construct(
        private readonly ExceptionHandler $handler,
        private readonly TranslatorInterface $translator,
        private readonly LocaleResolver $localeResolver = new LocaleResolver(),
    ) {}

    public function toResponse(Throwable $exception, ?ServerRequestInterface $request = null): ResponseInterface
    {
        $body = null;
        $validation = null;
        if ($exception instanceof HttpExceptionInterface && $request !== null) {
            $ctx = HttpContext::tryFrom($request);
            $locale = $ctx !== null && $ctx->hasLocale() ? $ctx->locale() : $this->localeResolver->resolve($request);
            $i18n = new LocalizedTranslator($this->translator, $locale);
            if ($exception instanceof InputException || $exception instanceof ValidationException) {
                $validation = $this->translated($exception, $i18n);
                $body = $validation->violations()[0]->fallback;
            } elseif ($exception instanceof Translatable) {
                $body = $exception->translate($i18n);
            }
        }
        return $this->handler->toResponse($exception, $request, $body, $validation);
    }

    private function translated(InputException|ValidationException $exception, LocalizedTranslator $i18n): ValidationResult
    {
        $resolve = new ViolationMessageResolver($i18n);
        $violations = [];
        foreach ($exception->validation()->violations() as $violation) {
            $violations[] = new Violation(
                $violation->field,
                $violation->code,
                $violation->messageId,
                $resolve->resolve($violation),
                $violation->parameters,
                $violation->domain,
            );
        }
        return new ValidationResult($violations);
    }
}
