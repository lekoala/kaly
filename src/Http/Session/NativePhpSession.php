<?php

declare(strict_types=1);

namespace Kaly\Http\Session;

use InvalidArgumentException;
use Kaly\Ex;
use Kaly\Http\Cookie\CookiePolicy;
use Throwable;

/**
 * Native PHP session storage, for sequential request execution only.
 *
 * Implements the portable applicative contract (get/set/has/remove/clear/
 * pull/all + regenerateId/destroy) plus the CookieSessionInterface transport
 * read-model (getId, setId, getName, isDestroyed, close, getCookieParams) that
 * the provider consumes through SessionCookie. Everything transport-related
 * (session id lookup and cookie emission)
 * is driven by the NativePhpSessionProvider; isActive and discard remain
 * concrete details for code that explicitly opted into native sessions.
 *
 * Worker usage: since PHP's native session state is global to the process, a
 * new instance must be created for every request and `$_SESSION` must
 * not be shared between requests that could run concurrently (eg: coroutines).
 * Opening storage always resets the native session id so a previous request
 * cannot leak into the next one; an active session belongs to one instance.
 *
 * Concurrent runtimes must bind a SessionProviderInterface returning a
 * request-scoped SessionInterface implementation instead.
 *
 * @phpstan-type SessionOptions array{name?:string,save_path?:string,lifetime?:int,path?:string,domain?:string,secure?:bool,httponly?:bool,samesite?:string,partitioned?:bool}
 *
 * @link https://github.com/upscalesoftware/swoole-session
 * @link https://github.com/yiisoft/session
 * @link https://github.com/psr7-sessions/storageless
 * @link https://github.com/middlewares/php-session
 */
final class NativePhpSession implements CookieSessionInterface
{
    private static ?self $owner = null;

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
     * prefix.
     *
     * @link https://www.php.net/manual/en/session.configuration.php
     * @var array<string,mixed>
     */
    private array $options = [];

    /**
     * @param array<string,mixed> $options Explicit 'name' wins over session_name();
     *  cookie entries (lifetime, path, domain, secure, httponly, samesite,
     *  partitioned) win over the policy baseline; 'save_path' is applied when
     *  storage starts. Rotation and persistent login are application decisions.
     */
    public function __construct(array $options = [], ?CookiePolicy $policy = null)
    {
        $this->policy = $policy ?? CookiePolicy::baseline();

        foreach (['regen_interval', 'expiry_key', 'remember_lifetime', 'remember_key'] as $key) {
            if (array_key_exists($key, $options)) {
                throw new InvalidArgumentException("Session option '{$key}' is no longer supported");
            }
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
        // response (see SessionCookie::commit), php must not send headers.
        // Passed to session_start() only, the ini state stays untouched.
        $forward = $options;
        unset(
            $forward['name'],
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
                'use_strict_mode' => '1',
                'cache_limiter' => '',
            ],
            $forward,
        );
    }

    // #region SessionInterface (portable applicative contract)

    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->loadSession()) {
            return $default;
        }
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
        if (!$this->loadSession()) {
            return false;
        }
        return isset($_SESSION[$key]);
    }

    public function remove(string $key): void
    {
        if (!$this->loadSession()) {
            return;
        }
        unset($_SESSION[$key]);
    }

    public function clear(): void
    {
        if (!$this->loadSession()) {
            return;
        }
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
        if (!$this->loadSession()) {
            return [];
        }
        return self::sessionData();
    }

    public function regenerateId(): void
    {
        $this->ensureStarted();
        try {
            $rotated = session_regenerate_id(true);
        } catch (Throwable $e) {
            throw new Ex('Failed to regenerate session id', 0, $e);
        }
        if (!$rotated) {
            throw new Ex('Failed to regenerate session id');
        }
        $this->sessionId = session_id() ?: null;
    }

    public function destroy(): void
    {
        // A cookie or close() can identify persisted storage without an open
        // native session. Load it before destroying it, just as for a read.
        if ($this->getId() !== null) {
            $this->ensureStarted();
        }
        if ($this->isActive()) {
            if (!session_destroy()) {
                throw new Ex('Failed to destroy session');
            }
            self::$owner = null;
            $_SESSION = [];
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
        if ($this->isActive()) {
            throw new Ex('Cannot replace the id of an active session');
        }
        $this->sessionId = $sessionId;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isActive(): bool
    {
        return (
            self::$owner === $this
            && session_status() === PHP_SESSION_ACTIVE
            && session_id() === $this->sessionId
            && session_name() === $this->name
        );
    }

    /**
     * @return bool True if session was closed by this call
     */
    public function close(): bool
    {
        if ($this->isActive()) {
            $closed = session_write_close();
            if (!$closed) {
                throw new Ex('Failed to close session');
            }
            self::$owner = null;
            $_SESSION = [];
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
            if (!session_abort()) {
                throw new Ex('Failed to discard session');
            }
            self::$owner = null;
            $_SESSION = [];
            session_id('');
        }
    }

    public function isDestroyed(): bool
    {
        return $this->destroyed;
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

    /**
     * Load existing storage without creating an anonymous session.
     */
    private function loadSession(): bool
    {
        if (!$this->isActive() && $this->getId() === null) {
            return false;
        }
        $this->ensureStarted();
        return true;
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
            if (!session_start($this->options)) {
                throw new Ex('Failed to start session');
            }
            self::$owner = $this;
            $this->sessionId = session_id() ?: null;
            // A fresh start supersedes a previous destroy()
            $this->destroyed = false;
        } catch (Throwable $e) {
            throw new Ex('Failed to start session', 0, $e);
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
