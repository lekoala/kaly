<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A cookies wrapper that works in psr-7 or using a regular setcookie call
 *
 * @link https://github.com/dflydev/dflydev-fig-cookies
 * @link https://github.com/yiisoft/cookies
 * @link https://github.com/hansott/psr7-cookies
 *
 * @phpstan-type CookieParams array{lifetime?:int,path?:string,domain?:string,secure?:bool,httponly?:bool,samesite?:string}
 */
class Cookies implements ArrayDataInterface
{
    public const SAMESITE_MODES = ['None', 'Lax', 'Strict'];

    /**
     * @var array<string,string>
     */
    protected array $data = [];
    /**
     * @var array<string,string>
     */
    protected array $originalData = [];
    /**
     * @var array<string,CookieParams>
     */
    protected array $params = [];

    public function __construct(ServerRequestInterface $request)
    {
        //@phpstan-ignore-next-line
        $this->data = $this->originalData = $request->getCookieParams();
    }

    #region Interface

    /**
     * {@inheritDoc}
     * @param ?string $default
     * @return ?string
     */
    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    /**
     * {@inheritDoc}
     */
    public function set(string $key, $value): void
    {
        if (!is_string($value)) {
            $value = json_encode($value) ?: '';
        }
        $this->data[$key] = $value;
    }

    /**
     * {@inheritDoc}
     */
    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function clear(): void
    {
        $this->data = [];
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return isset($this->data[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function hasChanged(): bool
    {
        return $this->data !== $this->originalData;
    }

    /**
     * {@inheritDoc}
     */
    public function getChanges(): array
    {
        $arr = [];
        foreach ($this->data as $k => $v) {
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
        return $this->data;
    }

    /**
     * {@inheritDoc}
     */
    public function isEmpty(): bool
    {
        return !count($this->data);
    }

    /**
     * {@inheritDoc}
     */
    public function jsonSerialize(): object
    {
        return (object) $this->data;
    }

    #endregion

    /**
     * @param string $k
     * @param string $v
     * @param CookieParams $params
     * @return void
     */
    public function setWithParams(string $k, string $v, array $params = []): void
    {
        $this->set($k, $v);
        if (!empty($params)) {
            $this->params[$k] = $params;
        }
    }

    /**
     * @param string $k
     * @return CookieParams|null
     */
    public function getParams(string $k): ?array
    {
        return $this->params[$k] ?? null;
    }

    /**
     * @param string $k
     * @param CookieParams $params
     * @return void
     */
    public function setParams(string $k, array $params = []): void
    {
        $this->params[$k] = $params;
    }

    public function write(): bool
    {
        $defaultParams = session_get_cookie_params();
        $now = time();
        $result = false;
        foreach ($this->getChanges() as $name => $arr) {
            $value = $arr[1] ?? null;

            $params = array_merge($defaultParams, $this->getParams($name) ?? []);

            // Force expiry
            if ($value === null || $value === '') {
                $params['lifetime'] =  time() - 3600;
            }

            $expires = isset($params['lifetime']) ? $now + intval($params['lifetime']) : 0;
            $path = $params['path'] ?? '/';
            $domain = $params['domain'] ?? '';
            $secure = $params['secure'] ?? false;
            $httponly = $params['httponly'] ?? true;

            // Assume all will succeed or all will fail
            //@phpstan-ignore-next-line
            $result = setcookie($name, (string) $value, [
                'expires' => $expires,
                'path' => (string) $path,
                'domain' => (string) $domain,
                'secure' => $secure,
                'httponly' => $httponly
            ]);
        }

        return $result;
    }

    public function addToResponse(ResponseInterface $response): ResponseInterface
    {
        $defaultParams = session_get_cookie_params();
        $now = time();

        foreach ($this->getChanges() as $name => $arr) {
            $value = $arr[1] ?? null;

            $params = array_merge($defaultParams, $this->getParams($name) ?? []);

            // Force expiry
            if ($value === null || $value === '') {
                $params['lifetime'] = time() - 3600;
            }

            //@phpstan-ignore-next-line
            $cookie = urlencode($name) . '=' . urlencode((string) $value);

            // if omitted, the cookie will expire at end of the session (ie when the browser closes)
            if (!empty($params['lifetime'])) {
                $expires = gmdate('D, d M Y H:i:s T', $now + intval($params['lifetime']));
                $cookie .= "; Expires={$expires}; Max-Age={$params['lifetime']}";
            }

            if (!empty($params['domain'])) {
                $cookie .= "; Domain={$params['domain']}";
            }

            if (!empty($params['path'])) {
                $cookie .= "; Path={$params['path']}";
            }

            if (!empty($params['samesite'])) {
                $cookie .= "; SameSite={$params['samesite']}";
            }

            if (!empty($params['secure'])) {
                $cookie .= '; Secure';
            }

            if (!empty($params['httponly'])) {
                $cookie .= '; HttpOnly';
            }

            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        return $response;
    }
}
