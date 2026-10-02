<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\Exception\HttpException;
use Kaly\Http\Exception\HttpExceptionInterface;
use Kaly\Http\ExceptionHandler;
use Kaly\Http\ExceptionHandlerInterface;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translatable;
use Kaly\I18n\TranslatorInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The default exception handler, with localized public error bodies.
 *
 * Only an already public HTTP error that is also Translatable is translated,
 * and only when the request carries a resolved locale: Translatable alone
 * never exposes anything, and without a locale the historical body is kept.
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
        if ($exception instanceof HttpExceptionInterface && $exception instanceof Translatable && $request !== null) {
            $ctx = HttpContext::tryFrom($request);
            if ($ctx !== null && $ctx->hasLocale()) {
                $body = $exception->translate(new LocalizedTranslator($this->translator, $ctx->locale()));
                $exception = new class($exception->status(), $exception->getResponseHeaders(), $body, $exception) extends HttpException {
                    public function __construct(int $status, array $headers, string $body, Throwable $previous)
                    {
                        parent::__construct($body, $status, $headers, $previous);
                    }
                };
            }
        }
        return $this->handler->toResponse($exception, $request);
    }
}
