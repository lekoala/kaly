<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\Exception\HttpException;
use Kaly\Http\Exception\HttpExceptionInterface;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\Http\Input\InputException;
use Kaly\Http\Input\ValidationException;
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
 * Only an already public HTTP error is translated, and only when the request
 * carries a resolved locale. An exception carrying a validation result keeps
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
    ) {}

    public function toResponse(Throwable $exception, ?ServerRequestInterface $request = null): ResponseInterface
    {
        if ($exception instanceof HttpExceptionInterface && $request !== null) {
            $ctx = HttpContext::tryFrom($request);
            if ($ctx !== null && $ctx->hasLocale()) {
                $i18n = new LocalizedTranslator($this->translator, $ctx->locale());
                if ($exception instanceof InputException || $exception instanceof ValidationException) {
                    $exception = $this->translated($exception, $i18n);
                } elseif ($exception instanceof Translatable) {
                    $body = $exception->translate($i18n);
                    $exception = new class($exception->status(), $exception->getResponseHeaders(), $body, $exception) extends
                        HttpException {
                        public function __construct(int $status, array $headers, string $body, Throwable $previous)
                        {
                            parent::__construct($body, $status, $headers, $previous);
                        }
                    };
                }
            }
        }
        return $this->handler->toResponse($exception, $request);
    }

    private function translated(
        InputException|ValidationException $exception,
        LocalizedTranslator $i18n,
    ): InputException|ValidationException {
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
        $result = new ValidationResult($violations);
        if ($exception instanceof ValidationException) {
            return new ValidationException($result, $exception);
        }
        return new InputException($result, $exception);
    }
}
