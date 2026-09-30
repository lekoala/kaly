<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;
use Kaly\Ex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Native PHP session storage, for sequential request execution only.
 *
 * Implements the portable applicative contract only (get/set/has/remove/clear/
 * pull/all + regenerateId/destroy). Everything transport-related (session id
 * lookup, cookie emission, remember-me, id regeneration timing) is driven by
 * the NativePhpSessionProvider; the extra public methods below (getId, setId,
 * getName, close, isActive, discard, getCookieParams, commitToResponse) are
 * concrete details for code that explicitly opted into native sessions, not
 * part of the portable contract.
 *
 * Worker usage: since PHP's native session state is global to the process, a
 * new instance must be created for every request and `$_SESSION` must
 * not be shared between requests that could run concurrently (eg: coroutines).
 * `start()` always resets the native session id so a previous request cannot
 * leak into the next one.
 *
 * Concurrent runtimes must bind a SessionProviderInterface returning a
 * request-scoped SessionInterface implementation instead.
 *
 * @phpstan-type SessionOptions array{name?:string,save_path?:string,regen_interval?:int,expiry_key?:string,remember_lifetime?:int,remember_key?:string,lifetime?:int,path?:string,domain?:string,secure?:bool,httponly?:bool,samesite?:string,partitioned?:bool}
 *
 * @link https://github.com/upscalesoftware/swoole-session
 * @link https://github.com/yiisoft/session
 * @link https://github.com/psr7-sessions/storageless
 * @link https://github.com/middlewares/php-session
 */
class NativePhpSession implements SessionInterface
{
    public const SAMESITE_MODES = ['None', 'Lax', 'Strict'];

    private const DEFAULT_BEHAVIOR = [
        'regen_interval' => 3600,
        'expiry_key' => '_expiry',
        'remember_lifetime' => 31_536_000,
        'remember_key' => '_remember',
    ];

    private CookiePolicy $policy;
    private string $name;
    private ?string $sessionId = null;
    /**
     * Set by destroy(): the client cookie must be expired on commit even
     * though no id is left to write.
     */
    private bool $destroyed = false;
    /**
     * Options passed to session_start(). Cookie settings carry the cookie_
     * prefix. Behavior keys (regen_interval, ...) are consumed, not forwarded.
     *
     * @link https://www.php.net/manual/en/session.configuration.php
     * @var array<string,mixed>
     */
    private array $options = [];
    /**
     * @var array{regen_interval:int,expiry_key:string,remember_lifetime:int,remember_key:string}
     */
    private array $behavior;

    /**
     * @param array<string,mixed> $options Explicit 'name' wins over session_name();
     *  cookie entries (lifetime, path, domain, secure, httponly, samesite,
     *  partitioned) win over the policy baseline; behavior entries tune id
     *  regeneration and remember-me. 'save_path' sets session_save_path().
     */
    public function __construct(array $options = [], ?CookiePolicy $policy = null)
    {
        $this->policy = $policy ?? CookiePolicy::baseline();

        $behavior = array_merge(self::DEFAULT_BEHAVIOR, array_intersect_key($options, self::DEFAULT_BEHAVIOR));
        $regenInterval = $behavior['regen_interval'] ?? self::DEFAULT_BEHAVIOR['regen_interval'];
        $expiryKey = $behavior['expiry_key'] ?? self::DEFAULT_BEHAVIOR['expiry_key'];
        $rememberLifetime = $behavior['remember_lifetime'] ?? self::DEFAULT_BEHAVIOR['remember_lifetime'];
        $rememberKey = $behavior['remember_key'] ?? self::DEFAULT_BEHAVIOR['remember_key'];
        $this->behavior = [
            'regen_interval' => is_numeric($regenInterval) ? (int) $regenInterval : self::DEFAULT_BEHAVIOR['regen_interval'],
            'expiry_key' => is_string($expiryKey) && $expiryKey !== '' ? $expiryKey : self::DEFAULT_BEHAVIOR['expiry_key'],
            'remember_lifetime' => is_numeric($rememberLifetime) ? (int) $rememberLifetime : self::DEFAULT_BEHAVIOR['remember_lifetime'],
            'remember_key' => is_string($rememberKey) && $rememberKey !== '' ? $rememberKey : self::DEFAULT_BEHAVIOR['remember_key'],
        ];

        if (isset($options['save_path']) && is_string($options['save_path']) && $options['save_path'] !== '') {
            session_save_path($options['save_path']);
        }

        $name = $options['name'] ?? null;
        if ($name !== null && (!is_string($name) || $name === '')) {
            throw new InvalidArgumentException('Session name must be a string');
        }
        $this->name = $name ?? session_name() ?: 'PHPSESSID';

        $cookieParams = array_merge(self::cookieDefaults($this->policy), self::explicitCookieParams($options));
        $cookieOptions = [];
        foreach ($cookieParams as $k => $v) {
            $cookieOptions["cookie_{$k}"] = $v;
        }
        // Built for a PSR-7 response: the session cookie belongs to the
        // response (see commitToResponse), php must not send headers.
        // Passed to session_start() only, the ini state stays untouched.
        $forward = $options;
        unset(
            $forward['name'],
            $forward['save_path'],
            $forward['regen_interval'],
            $forward['expiry_key'],
            $forward['remember_lifetime'],
            $forward['remember_key'],
            $forward['lifetime'],
            $forward['path'],
            $forward['domain'],
            $forward['secure'],
            $forward['httponly'],
            $forward['samesite'],
            $forward['partitioned'],
        );
        $this->options = array_merge(
            $cookieOptions,
            [
                'use_cookies' => '0',
                'use_only_cookies' => '1',
                'use_trans_sid' => '0',
                'cache_limiter' => '',
            ],
            $forward,
        );
    }

    // #region SessionInterface (portable applicative contract)

    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        $data = self::sessionData();
        return array_key_exists($key, $data) ? $data[$key] : $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        $this->ensureStarted();
        $_SESSION = [];
    }

    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }

    public function all(): array
    {
        $this->ensureStarted();
        return self::sessionData();
    }

    public function regenerateId(): void
    {
        $this->ensureStarted();
        try {
            if (session_regenerate_id(true)) {
                $this->sessionId = session_id() ?: null;
            }
        } catch (Throwable $e) {
            throw new Ex('Failed to regenerate session id', 0, $e);
        }
    }

    public function destroy(): void
    {
        if ($this->isActive()) {
            session_destroy();
            session_id('');
        }
        // Also covers a session that was never started: the client cookie must
        // still be expired, otherwise the browser keeps a dangling session id
        $this->sessionId = null;
        $this->destroyed = true;
    }

    // #endregion

    // #region Native-only concrete details (not part of the portable contract)

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
        return session_status() === PHP_SESSION_ACTIVE;
    }

    /**
     * @return bool True if session was closed by this call
     */
    public function close(): bool
    {
        if ($this->isActive()) {
            $closed = session_write_close();
            // Do not leave the native session id behind: it would be reused
            // by the next request handled in the same process.
            session_id('');
            return $closed;
        }
        return false;
    }

    public function discard(): void
    {
        if ($this->isActive()) {
            session_abort();
            session_id('');
        }
    }

    /**
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string,partitioned?:bool}
     */
    public function getCookieParams(): array
    {
        $options = [];
        foreach ($this->options as $k => $v) {
            if (!str_starts_with($k, 'cookie_')) {
                continue;
            }
            if (!is_scalar($v) && $v !== null) {
                continue;
            }
            $options[str_replace('cookie_', '', $k)] = $v;
        }
        $params = [
            'lifetime' => (int) ($options['lifetime'] ?? 0),
            'path' => (string) ($options['path'] ?? '/'),
            'domain' => (string) ($options['domain'] ?? ''),
            'secure' => (bool) ($options['secure'] ?? false),
            'httponly' => (bool) ($options['httponly'] ?? true),
            'samesite' => (string) ($options['samesite'] ?? 'Lax'),
        ];
        if (array_key_exists('partitioned', $options)) {
            $params['partitioned'] = (bool) $options['partitioned'];
        }
        return $params;
    }

    /**
     * Write the session cookie to the PSR-7 response if the id is new.
     * Closes the native session first so the lock is released.
     */
    public function commitToResponse(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->isActive()) {
            $this->close();
        }

        $name = $this->getName();
        $cookies = $request->getCookieParams();
        $current = $cookies[$name] ?? null;

        // A destroyed session clears the client cookie, there is no id left
        if ($this->destroyed) {
            return $current === null
                ? $response
                : $response->withAddedHeader('Set-Cookie', SetCookieHeader::build($name, '', $this->getCookieParams(), true));
        }

        $id = $this->getId();
        if ($id === null || $current === $id) {
            return $response;
        }

        return $response->withAddedHeader('Set-Cookie', SetCookieHeader::build($name, $id, $this->getCookieParams()));
    }

    // #endregion

    /**
     * Read the native session storage as a string-keyed array.
     *
     * @return array<string,mixed>
     */
    private static function sessionData(): array
    {
        /** @var array<string,mixed> $data */
        $data = $_SESSION ?? [];
        return $data;
    }

    private function ensureStarted(): void
    {
        if ($this->isActive()) {
            return;
        }
        $this->startSession();
    }

    /**
     * Start the native session for this instance. Always resets the native
     * session context to the id carried by this instance (or an empty id for
     * a request without a session cookie): without the empty reset, PHP would
     * reuse the id left over by a previous request in the same worker process.
     */
    private function startSession(): void
    {
        self::checkSessionCanStart();

        // The name is process-global in PHP: align it for this start only.
        if ($this->name !== '' && $this->name !== session_name()) {
            session_name($this->name);
        }
        session_id($this->sessionId ?? '');

        try {
            session_start($this->options);
            $this->sessionId = session_id() ?: null;
            // A fresh start supersedes a previous destroy()
            $this->destroyed = false;
            $this->runIdRegeneration();
        } catch (Throwable $e) {
            throw new Ex('Failed to start session', 0, $e);
        }
    }

    /**
     * Regenerate the session ID if needed.
     */
    private function runIdRegeneration(): void
    {
        $interval = $this->behavior['regen_interval'];
        $key = $this->behavior['expiry_key'];
        if ($interval <= 0) {
            return;
        }
        $expiry = time() + $interval;
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = $expiry;
        }
        if ($_SESSION[$key] < time() || $_SESSION[$key] > $expiry) {
            $this->regenerateId();
            $_SESSION[$key] = $expiry;
        }
    }

    /**
     * Checks whether the session can be started.
     */
    public static function checkSessionCanStart(): void
    {
        if (session_status() === PHP_SESSION_DISABLED) {
            throw new Ex('PHP sessions are disabled');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            throw new Ex('Failed to start the session: already started by PHP');
        }
    }

    /**
     * PSR-7 compatibility: never auto-start, never let PHP emit cookies or
     * rewrite urls. We emit the Set-Cookie header ourselves.
     *
     * Process-global by nature (ini entries); called once by the provider.
     *
     * @link https://paul-m-jones.com/post/2016/04/12/psr-7-and-session-cookies/
     */
    public static function configureForPsr7(): void
    {
        ini_set('session.auto_start', '0');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cache_limiter', '');
    }

    /**
     * The cookie params a session cookie is built from: php stays the source
     * of the settings it alone knows about (path, domain, secure, partitioned
     * as configured in php.ini), while the application CookiePolicy wins
     * over it. Nothing is ever written back to php.
     *
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string,partitioned?:bool}
     */
    public static function cookieDefaults(?CookiePolicy $policy = null): array
    {
        return array_merge(session_get_cookie_params(), ($policy ?? CookiePolicy::baseline())->toArray());
    }

    /**
     * @param array<string,mixed> $options
     * @return array{lifetime?:int,path?:string,domain?:string,secure?:bool,httponly?:bool,samesite?:string,partitioned?:bool}
     */
    private static function explicitCookieParams(array $options): array
    {
        /** @var array{lifetime?:int,path?:string,domain?:string,secure?:bool,httponly?:bool,samesite?:string,partitioned?:bool} $params */
        $params = [];
        $lifetime = $options['lifetime'] ?? null;
        if (is_numeric($lifetime)) {
            $params['lifetime'] = (int) $lifetime;
        }
        foreach (['path', 'domain', 'samesite'] as $k) {
            if (isset($options[$k]) && is_string($options[$k])) {
                $params[$k] = $options[$k];
            }
        }
        foreach (['secure', 'httponly', 'partitioned'] as $k) {
            if (array_key_exists($k, $options) && is_bool($options[$k])) {
                $params[$k] = $options[$k];
            }
        }
        return $params;
    }
}
