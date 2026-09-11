<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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

    // region Interface

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
        // Compare the union of old and new keys so that removed cookies are
        // represented (with a null value) and get their Set-Cookie emitted.
        $keys = array_unique(array_merge(array_keys($this->originalData), array_keys($this->data)));
        foreach ($keys as $k) {
            $old = $this->originalData[$k] ?? null;
            $new = $this->data[$k] ?? null;
            if ($old !== $new) {
                $arr[$k] = [$old, $new];
            }
        }
        return $arr;
    }

    /**
     * A cookie is deleted when it is removed or set to an empty value.
     */
    protected static function isRemoval(mixed $value): bool
    {
        return $value === null || $value === '';
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

    // endregion

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
        $result = false;
        foreach ($this->getChanges() as $name => $arr) {
            $value = $arr[1] ?? null;
            $params = array_merge($defaultParams, $this->getParams($name) ?? []);

            $options = [
                'path' => (string) ($params['path'] ?? '/'),
                'domain' => (string) ($params['domain'] ?? ''),
                'secure' => (bool) ($params['secure'] ?? false),
                'httponly' => (bool) ($params['httponly'] ?? true),
            ];
            if (!empty($params['samesite'])) {
                $options['samesite'] = (string) $params['samesite'];
            }

            if (self::isRemoval($value)) {
                // Expire the cookie in the past
                $options['expires'] = 1;
            } elseif (!empty($params['lifetime'])) {
                $options['expires'] = time() + intval($params['lifetime']);
            } else {
                // Expire at end of the session (when the browser closes)
                $options['expires'] = 0;
            }

            // Assume all will succeed or all will fail
            //@phpstan-ignore-next-line
            $result = setcookie($name, is_scalar($value) ? (string) $value : '', $options);
        }

        return $result;
    }

    public function addToResponse(ResponseInterface $response): ResponseInterface
    {
        $defaultParams = session_get_cookie_params();

        foreach ($this->getChanges() as $name => $arr) {
            $value = $arr[1] ?? null;
            $params = array_merge($defaultParams, $this->getParams($name) ?? []);

            $isRemoval = self::isRemoval($value);
            $stringValue = is_scalar($value) ? (string) $value : '';
            $cookie = $this->buildSetCookieHeader($name, $isRemoval ? '' : $stringValue, $params, $isRemoval);

            $response = $response->withAddedHeader('Set-Cookie', $cookie);
        }

        return $response;
    }

    /**
     * Build a raw Set-Cookie header value.
     *
     * @param CookieParams $params
     */
    protected function buildSetCookieHeader(string $name, string $value, array $params, bool $expire = false): string
    {
        $cookie = urlencode($name) . '=' . urlencode($value);

        if ($expire) {
            $cookie .= '; Expires=Thu, 01 Jan 1970 00:00:01 GMT; Max-Age=0';
        } elseif (!empty($params['lifetime'])) {
            // lifetime is a duration, not an absolute timestamp
            $lifetime = intval($params['lifetime']);
            $expires = gmdate('D, d M Y H:i:s T', time() + $lifetime);
            $cookie .= "; Expires={$expires}; Max-Age={$lifetime}";
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

        return $cookie;
    }
}
