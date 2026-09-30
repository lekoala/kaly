<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * In-memory, request-scoped session storage.
 *
 * Concurrency-safe by construction: no `$_SESSION`, no `session_*()` calls,
 * no shared static state. Each instance owns its data, so overlapping Fibers
 * or workers cannot leak into each other.
 *
 * For tests and isolated cycles only: nothing persists between two requests.
 * This is not a production backend.
 */
final class ArraySession implements SessionInterface
{
    private string $name;
    private ?string $sessionId = null;
    private bool $started = false;

    /**
     * @var array<string,mixed>
     */
    private array $data = [];

    /**
     * @var array<string,mixed>
     */
    private array $originalData = [];

    /**
     * @var array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     */
    private array $cookieParams;

    /**
     * @param array<string,mixed> $options Supports 'name' plus cookie params
     *  (lifetime, path, domain, secure, httponly, samesite). Explicit options
     *  win over the policy baseline.
     */
    public function __construct(array $options = [], ?CookiePolicy $policy = null)
    {
        $options = array_merge(($policy ?? CookiePolicy::baseline())->toArray(), $options);

        $name = $options['name'] ?? null;
        if ($name !== null && (!is_string($name) || $name === '')) {
            throw new InvalidArgumentException('Session name must be a string');
        }
        $this->name = $name ?? 'KALYSESSID';

        $lifetime = $options['lifetime'] ?? 0;
        $path = $options['path'] ?? '/';
        $domain = $options['domain'] ?? '';
        $secureOpt = $options['secure'] ?? false;
        $httponly = $options['httponly'] ?? true;
        $samesite = $options['samesite'] ?? 'Lax';
        $this->cookieParams = [
            'lifetime' => is_numeric($lifetime) ? (int) $lifetime : 0,
            'path' => is_string($path) ? $path : '/',
            'domain' => is_string($domain) ? $domain : '',
            'secure' => is_bool($secureOpt) ? $secureOpt : (bool) $secureOpt,
            'httponly' => is_bool($httponly) ? $httponly : (bool) $httponly,
            'samesite' => is_string($samesite) ? $samesite : 'Lax',
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $this->open();
        return array_key_exists($key, $this->data) ? $this->data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->open();
        $this->data[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->open();
        return isset($this->data[$key]);
    }

    public function remove(string $key): void
    {
        $this->open();
        unset($this->data[$key]);
    }

    public function clear(): void
    {
        $this->open();
        $this->data = [];
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }

    public function all(): array
    {
        $this->open();
        return $this->data;
    }

    public function regenerateId(): void
    {
        $this->open();
        $this->sessionId = bin2hex(random_bytes(16));
    }

    public function destroy(): void
    {
        $this->data = [];
        $this->originalData = [];
        $this->sessionId = null;
        $this->started = false;
    }

    // #region In-memory-only concrete details (not part of the portable contract)

    public function getId(): ?string
    {
        return $this->sessionId === '' ? null : $this->sessionId;
    }

    public function setId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return $this->started;
    }

    public function close(): bool
    {
        if (!$this->started) {
            return false;
        }
        $this->started = false;
        return true;
    }

    public function discard(): void
    {
        $this->data = $this->originalData;
    }

    /**
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     */
    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function commitToResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->close();

        $id = $this->getId();
        if ($id === null) {
            return $response;
        }

        $cookies = $request->getCookieParams();
        $current = $cookies[$this->getName()] ?? null;
        if ($current === $id) {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', SetCookieHeader::build($this->getName(), $id, $this->getCookieParams()));
    }

    // #endregion

    private function open(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;
        $this->sessionId ??= bin2hex(random_bytes(16));
        $this->originalData = $this->data;
    }
}
