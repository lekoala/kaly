<?php

declare(strict_types=1);

namespace Kaly\Http;

use Exception;
use InvalidArgumentException;
use Kaly\Core\Ex;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * A session that can work in workers even if we use $_SESSION under the hood
 * It can be used with a middleware, or without
 * Also work for non psr-7 contexts
 *
 * Worker usage: since PHP's native session state is global to the process, a
 * new Session instance must be created for every request and `$_SESSION` must
 * not be shared between requests that could run concurrently (eg: coroutines).
 * `start()` always resets the native session id so a previous request cannot
 * leak into the next one.
 *
 * @phpstan-type SessionParams array{'regen_interval'?:int,'expiry_key'?:string,'remember_lifetime'?:int,'remember_key'?:string,'lifetime'?:int,'httponly'?:bool,'samesite'?:('Lax'|'lax'|'None'|'none'|'Strict'|'strict'),'csrf_key'?:string}
 * @phpstan-type AllSessionParams array{'regen_interval':int,'expiry_key':string,'remember_lifetime':int,'remember_key':string,'lifetime':int,'httponly':bool,'samesite':('Lax'|'lax'|'None'|'none'|'Strict'|'strict'),'csrf_key':string}
 * @link https://github.com/upscalesoftware/swoole-session
 * @link https://github.com/yiisoft/session
 * @link https://github.com/psr7-sessions/storageless
 * @link https://github.com/middlewares/php-session
 */
class Session implements ArrayDataInterface
{
    public const SAMESITE_MODES = ['None', 'Lax', 'Strict'];

    /**
     * @var AllSessionParams
     */
    protected static array $config = [
        'regen_interval' => 3600,
        'expiry_key' => '_expiry',
        'remember_lifetime' => 31_536_000,
        'remember_key' => '_remember',
        'lifetime' => 0, // When the browser closes
        'httponly' => true,
        'samesite' => 'Lax',
        'csrf_key' => '_csrf',
    ];
    protected ?string $sessionId = null;
    /**
     * @var array<string,mixed>
     */
    protected array $originalData = [];
    /**
     * Options to pass to session_start. Cookie settings must start with cookie_
     * @link https://www.php.net/manual/en/session.configuration.php
     * @var array<string,mixed>
     */
    protected array $options = [];

    /**
     * @param array<string,mixed> $options
     * @param ServerRequestInterface|null $request
     */
    public function __construct(array $options = [], ?ServerRequestInterface $request = null)
    {
        if ($request) {
            $cookiesParameters = self::getOptionsForRequest($request);
        } else {
            $cookiesParameters = self::getCookieDefaults();
        }
        $cookiesParameters = array_combine(
            array_map(static fn($v): string => "cookie_{$v}", array_keys($cookiesParameters)),
            $cookiesParameters,
        );
        $this->options = array_merge($cookiesParameters, $options);
        if ($request) {
            $this->setIdFromRequest($request);
        }
    }

    /**
     * Use with caution, this will not update the file last modification date
     * @link https://www.php.net/manual/en/function.session-start.php#125487
     */
    public function setReadAndClose(): void
    {
        $this->options['read_and_close'] = true;
    }

    // region Interface

    /**
     * {@inheritDoc}
     */
    public function get(string $key, $default = null)
    {
        $this->open();
        //@phpstan-ignore-next-line
        return $_SESSION[$key] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, $value): void
    {
        $this->open();
        $_SESSION[$key] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): void
    {
        $this->open();
        unset($_SESSION[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): void
    {
        $this->open();
        $_SESSION = [];
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        $this->open();
        return isset($_SESSION[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function hasChanged(): bool
    {
        return $_SESSION !== $this->originalData;
    }

    /**
     * {@inheritDoc}
     */
    public function getChanges(): array
    {
        $arr = [];
        foreach ($_SESSION as $k => $v) {
            if (!is_string($k)) {
                continue;
            }
            $old = $this->originalData[$k] ?? null;
            if ($old !== $v) {
                $arr[$k] = [$old, $v];
            }
        }
        return $arr;
    }

    /**
     * {@inheritDoc}
     */
    public function all(): array
    {
        $this->open();
        //@phpstan-ignore-next-line
        return $_SESSION;
    }

    /**
     * {@inheritDoc}
     */
    public function isEmpty(): bool
    {
        return !count($_SESSION);
    }

    /**
     * {@inheritDoc}
     */
    public function jsonSerialize(): object
    {
        return (object) $_SESSION;
    }

    // endregion

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

    /**
     * The same as start, except it won't start again if already started
     */
    public function open(): void
    {
        if ($this->isActive()) {
            return;
        }

        $this->start();
    }

    /**
     * Start a session. Will throw errors if already started. Use open instead
     */
    public function start(): void
    {
        self::checkSessionCanStart();

        // Always reset the native session context to the id carried by this
        // instance (or an empty id for a request without a session cookie).
        // Without the empty reset, PHP would reuse the id left over by a
        // previous request handled in the same worker process.
        session_id($this->sessionId ?? '');

        try {
            session_start($this->options);
            $this->sessionId = session_id() ?: null;
            //@phpstan-ignore-next-line
            $this->originalData = $_SESSION;
            $this->runIdRegeneration();
        } catch (Throwable $e) {
            throw new Ex('Failed to start session', 0, $e);
        }
    }

    public function isActive(): bool
    {
        return session_status() === PHP_SESSION_ACTIVE;
    }

    public function getId(): ?string
    {
        return $this->sessionId === '' ? null : $this->sessionId;
    }

    public function regenerateId(): void
    {
        if ($this->isActive()) {
            try {
                if (session_regenerate_id(true)) {
                    $this->sessionId = session_id() ?: null;
                }
            } catch (Throwable $e) {
                throw new Exception('Failed to regenerate ID', (int) $e->getCode(), $e);
            }
        }
    }

    public function discard(): void
    {
        if ($this->isActive()) {
            session_abort();
            session_id('');
        }
    }

    public function getName(): string
    {
        // session_name() is valid even when no session is active, so it is the
        // reliable source of the configured name. An explicit option wins.
        $name = $this->options['name'] ?? session_name();
        if (!is_string($name) || $name === '') {
            $name = session_name();
        }
        if (!is_string($name)) {
            throw new InvalidArgumentException('Session name must be a string');
        }
        return $name;
    }

    /**
     * Retrieves and remove a value
     *
     * @param int|bool|string|float|array<mixed>|null $default
     * @return int|bool|string|float|array<mixed>|null
     */
    public function pull(string $key, int|bool|string|float|array|null $default = null): int|bool|string|float|array|null
    {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }

    public function destroy(): void
    {
        if ($this->isActive()) {
            session_destroy();
            session_id('');
            $this->sessionId = null;
        }
    }

    public function setId(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    public function getIdFromRequest(ServerRequestInterface $request): ?string
    {
        $cookies = $request->getCookieParams();
        $param = $cookies[$this->getName()] ?? null;
        if ($param !== null && !is_string($param)) {
            throw new InvalidArgumentException('Session cookie value must be a string');
        }
        return $param;
    }

    public function setIdFromRequest(ServerRequestInterface $request): void
    {
        $id = $this->getIdFromRequest($request);
        if ($id !== null) {
            $this->setId($id);
        }
    }

    /**
     * Configure defaults to better values
     */
    public static function configureDefaults(?string $path = null, ?string $name = null, bool $psr = true): void
    {
        if ($path) {
            session_save_path($path);
        }
        if ($name) {
            session_name($name);
        }

        if ($psr) {
            // We emit the Set-Cookie header ourselves, so php is never told
            // about the cookie params: they live in our own config and are
            // read through getCookieDefaults().
            self::configureForPsr7();
            return;
        }

        // Php emits the session cookie itself, so it needs the params
        session_set_cookie_params([
            'lifetime' => self::$config['lifetime'],
            'httponly' => self::$config['httponly'],
            'samesite' => self::$config['samesite'],
        ]);
    }

    /**
     * The cookie params a session cookie is built from.
     *
     * Php stays the source of the settings it alone knows about (path, domain,
     * secure, partitioned as configured in php.ini), while what kaly exposes
     * through configureExtra() wins over it. Nothing is ever written back to
     * php: mutating global ini state to read it again later made the values
     * order dependent and, since php 8.4, warned when session.use_cookies was
     * disabled for psr-7.
     *
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string,partitioned?:bool}
     */
    public static function getCookieDefaults(): array
    {
        return array_merge(session_get_cookie_params(), [
            'lifetime' => self::$config['lifetime'],
            'httponly' => self::$config['httponly'],
            'samesite' => self::$config['samesite'],
        ]);
    }

    /**
     * @param SessionParams $arr
     * @return void
     */
    public static function configureExtra(array $arr = []): void
    {
        self::$config = array_merge(self::$config, $arr);
    }

    /**
     * @return AllSessionParams
     */
    public static function getExtraConfig(): array
    {
        return self::$config;
    }

    /**
     * @link https://paul-m-jones.com/post/2016/04/12/psr-7-and-session-cookies/
     */
    public static function configureForPsr7(): void
    {
        // No auto-start! You should only use a session when needed
        ini_set('session.auto_start', '0');

        // PSR-7 compatibility
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        // Reject user provided session ids that were not initialized by PHP
        ini_set('session.use_strict_mode', '1');
        // Prevent PHP to send headers
        ini_set('session.cache_limiter', '');
    }

    /**
     * Returns a better set of cookie options based on current request
     * Cookie will be secured on https and scoped to the domain
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string,partitioned?:bool}
     */
    public static function getOptionsForRequest(ServerRequestInterface $request): array
    {
        $options = array_merge(self::getCookieDefaults(), [
            'secure' => $request->getUri()->getScheme() === 'https',
            'domain' => $request->getUri()->getHost(),
        ]);
        if (self::isRememberMe($request)) {
            $options['lifetime'] = self::$config['remember_lifetime'];
        }
        return $options;
    }

    public static function isRememberMe(ServerRequestInterface $request): bool
    {
        //@phpstan-ignore-next-line
        return $request->getMethod() === 'POST' && !empty($request->getParsedBody()[self::$config['remember_key']]);
    }

    /**
     * Regenerate the session ID if it's needed.
     */
    public function runIdRegeneration(): void
    {
        $interval = self::$config['regen_interval'];
        $key = self::$config['expiry_key'];
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
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string,partitioned?:bool}
     */
    public function getCookieParams(): array
    {
        $arr = [];
        foreach ($this->options as $k => $v) {
            if (!str_starts_with($k, 'cookie_')) {
                continue;
            }

            $arr[str_replace('cookie_', '', $k)] = $v;
        }
        //@phpstan-ignore-next-line
        return $arr;
    }

    /**
     * Write a session cookie to the PSR-7 response.
     * Cookie will only be added if necessary
     */
    public function addToResponse(ResponseInterface $response, ServerRequestInterface $request): ResponseInterface
    {
        // Close if still active
        if ($this->isActive()) {
            $this->close();
        }

        // No id...
        $id = $this->getId();
        if ($id === null) {
            return $response;
        }

        if ($this->getIdFromRequest($request) === $id) {
            // SID not changed, no need to send new cookie.
            return $response;
        }

        $name = $this->getName();
        $now = time();
        $params = $this->getCookieParams();

        $cookie = urlencode($name) . '=' . urlencode($id);

        // if omitted, the cookie will expire at end of the session (ie when the browser closes)
        if (!empty($params['lifetime'])) {
            $expires = gmdate('D, d M Y H:i:s T', $now + $params['lifetime']);
            $cookie .= "; Expires={$expires}; Max-Age={$params['lifetime']}";
        }

        if (!empty($params['domain'])) {
            $cookie .= "; Domain={$params['domain']}";
        }

        if (!empty($params['path'])) {
            $cookie .= "; Path={$params['path']}";
        }

        if (!empty($params['samesite']) && in_array($params['samesite'], self::SAMESITE_MODES, true)) {
            $cookie .= '; SameSite=' . $params['samesite'];
        }

        if (!empty($params['secure'])) {
            $cookie .= '; Secure';
        }

        if (!empty($params['httponly'])) {
            $cookie .= '; HttpOnly';
        }

        // CHIPS, php 8.4+. Browsers require it to be paired with Secure.
        if (!empty($params['partitioned'])) {
            $cookie .= '; Partitioned';
        }

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
