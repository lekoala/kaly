<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;
use Kaly\Core\Ex;
use Exception;

/**
 * A session that can work in workers even if we use $_SESSION under the hood
 * It can be used with a middleware, or without
 * Also work for non psr-7 contexts
 *
 * @phpstan-type SessionParams array{'regen_interval'?:int,'expiry_key'?:string,'remember_lifetime'?:int,'remember_key'?:string,'lifetime'?:int,'httponly'?:bool,'samesite'?:string,'csrf_key'?:string}
 * @phpstan-type AllSessionParams array{'regen_interval':int,'expiry_key':string,'remember_lifetime':int,'remember_key':string,'lifetime':int,'httponly':bool,'samesite':string,'csrf_key':string}
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
        'remember_lifetime' => 31536000,
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
            $cookiesParameters = session_get_cookie_params();
        }
        $cookiesParameters = array_combine(
            array_map(fn($v): string => "cookie_$v", array_keys($cookiesParameters)),
            $cookiesParameters
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

    #region Interface

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
            assert(is_string($k));
            $old = $this->originalData[$k] ?? null;
            if ($old != $v) {
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

    #endregion

    /**
     * @return bool True if session was closed by this call
     */
    public function close(): bool
    {
        if ($this->isActive()) {
            return session_write_close();
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

        // We got a session id from the request
        if ($this->sessionId !== null) {
            session_id($this->sessionId);
        }

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
                throw new Exception('Failed to regenerate ID', (int)$e->getCode(), $e);
            }
        }
    }

    public function discard(): void
    {
        if ($this->isActive()) {
            session_abort();
        }
    }

    public function getName(): string
    {
        $name = $this->isActive() ? session_name() : '';
        if (!$name) {
            $name = $this->options['name'];
        }
        assert(is_string($name));
        return $name;
    }

    /**
     * Retrieves and remove a value
     *
     * @param int|bool|string|float|array<mixed>|null $default
     * @return int|bool|string|float|array<mixed>|null
     */
    public function pull(
        string $key,
        int|bool|string|float|array|null $default = null
    ): int|bool|string|float|array|null {
        $value = $this->get($key, $default);
        $this->remove($key);
        return $value;
    }

    public function destroy(): void
    {
        if ($this->isActive()) {
            session_destroy();
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
        $param =  $cookies[$this->getName()] ?? null;
        assert(is_null($param) || is_string($param));
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
        session_set_cookie_params([
            'lifetime' => self::$config['lifetime'],
            'httponly' => self::$config['httponly'],
            'samesite' => self::$config['samesite'],
        ]);
        if ($path) {
            session_save_path($path);
        }
        if ($name) {
            session_name($name);
        }
        if ($psr) {
            self::configureForPsr7();
        }
    }

    /**
     * @param SessionParams $arr
     * @return void
     */
    public static function configureExtra(array $arr = []): void
    {
        self::$config = array_merge($arr, self::$config);
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
        ini_set('session.auto_start ', '0');

        // PSR-7 compatibility
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        // Prevent PHP to send headers
        ini_set('session.cache_limiter', '');
    }

    /**
     * Returns a better set of cookie options based on current request
     * Cookie will be secured on https and scoped to the domain
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     */
    public static function getOptionsForRequest(ServerRequestInterface $request): array
    {
        $options = array_merge(session_get_cookie_params(), [
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
        return $request->getMethod() === "POST" && !empty($request->getParsedBody()[self::$config['remember_key']]);
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
     * @return array{lifetime:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string}
     */
    public function getCookieParams(): array
    {
        $arr = [];
        foreach ($this->options as $k => $v) {
            if (str_starts_with($k, 'cookie_')) {
                $arr[str_replace('cookie_', '', $k)] = $v;
            }
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

        if (!empty($params['samesite']) && in_array($params['samesite'], self::SAMESITE_MODES)) {
            $cookie .= '; SameSite=' . $params['samesite'];
        }

        if (!empty($params['secure'])) {
            $cookie .= '; Secure';
        }

        if (!empty($params['httponly'])) {
            $cookie .= '; HttpOnly';
        }

        return $response->withAddedHeader('Set-Cookie', $cookie);
    }
}
