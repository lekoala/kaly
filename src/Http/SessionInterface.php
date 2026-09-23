<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request-scoped session storage.
 *
 * Native PHP sessions are supported for sequential request execution only:
 * `$_SESSION` and the session id are global to the process, so concurrent
 * runtimes (Fibers, workers handling overlapping requests) must provide
 * another implementation (eg: ArraySession).
 */
interface SessionInterface extends ArrayDataInterface
{
    /**
     * Retrieves and remove a value
     *
     * @param int|bool|string|float|array<mixed>|object|null $default
     * @return int|bool|string|float|array<mixed>|object|null
     */
    public function pull(string $key, int|bool|string|float|array|object|null $default = null): int|bool|string|float|array|object|null;

    /**
     * The same as start, except it won't start again if already started
     */
    public function open(): void;

    /**
     * Start a session. Will throw errors if already started. Use open instead
     */
    public function start(): void;

    /**
     * @return bool True if session was closed by this call
     */
    public function close(): bool;

    public function destroy(): void;

    public function discard(): void;

    public function regenerateId(): void;

    public function isActive(): bool;

    public function getId(): ?string;

    public function setId(string $sessionId): void;

    public function getName(): string;

    public function getIdFromRequest(ServerRequestInterface $request): ?string;

    public function setIdFromRequest(ServerRequestInterface $request): void;

    /**
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string,partitioned?:bool}
     */
    public function getCookieParams(): array;

    /**
     * Write a session cookie to the PSR-7 response.
     * Cookie will only be added if necessary
     */
    public function addToResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface;
}
