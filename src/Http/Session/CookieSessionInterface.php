<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

/**
 * A session carried by a cookie.
 *
 * SessionInterface knows nothing about transport; this sub-interface is the
 * capability a cookie-based provider needs to write the session back to the
 * response: which name and id the cookie carries, which parameters it is
 * emitted with, and whether the client cookie must be expired. A provider
 * only ever speaks to this interface, never to a concrete session class.
 */
interface CookieSessionInterface extends SessionInterface
{
    public function getId(): ?string;

    public function setId(string $id): void;

    public function getName(): string;

    /**
     * Whether destroy() ran since the session was (re)started: the client
     * cookie must be expired on commit even though no id is left to write.
     */
    public function isDestroyed(): bool;

    /**
     * Release the underlying storage before the response leaves.
     *
     * @return bool True if the session was open and got closed by this call
     */
    public function close(): bool;

    /**
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string,partitioned?:bool}
     */
    public function getCookieParams(): array;
}
