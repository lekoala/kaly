<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Request-scoped cookies, read from the PSR-7 request and emitted as
 * Set-Cookie headers on the PSR-7 response. Kaly never emits cookies through
 * PHP globals (no setcookie() call anywhere on this path).
 *
 * @link https://github.com/dflydev/dflydev-fig-cookies
 * @link https://github.com/yiisoft/cookies
 * @link https://github.com/hansott/psr7-cookies
 *
 * @phpstan-type CookieParams array{lifetime?:int,path?:string,domain?:string,secure?:bool,httponly?:bool,samesite?:string,partitioned?:bool}
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

    public function __construct(
        ServerRequestInterface $request,
        protected ?CookiePolicy $policy = null,
    ) {
        foreach ($request->getCookieParams() as $k => $v) {
            // PSR-7 allows array values (eg: foo[]=bar); store a string baseline
            if (is_array($v)) {
                $parts = [];
                foreach ($v as $part) {
                    if (is_scalar($part)) {
                        $parts[] = (string) $part;
                    }
                }
                $this->data[(string) $k] = implode(',', $parts);
            } elseif (is_scalar($v)) {
                $this->data[(string) $k] = (string) $v;
            } else {
                $this->data[(string) $k] = '';
            }
        }
        $this->originalData = $this->data;
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

    /**
     * The application baseline this instance builds its cookies from:
     * the injected policy, or the application default.
     *
     * @return CookieParams
     */
    protected function baselineParams(): array
    {
        /** @var CookieParams $params */
        $params = array_merge(session_get_cookie_params(), ($this->policy ?? CookiePolicy::default())->toArray());
        return $params;
    }

    public function addToResponse(ResponseInterface $response): ResponseInterface
    {
        // Application cookies share the CookiePolicy baseline with the
        // session cookie, rather than whatever php.ini happens to hold.
        $defaultParams = $this->baselineParams();

        foreach ($this->getChanges() as $name => $arr) {
            $value = $arr[1] ?? null;
            /** @var CookieParams $params */
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
     * Thin wrapper over SetCookieHeader: the params travel implicitly so
     * call sites stay intention-revealing.
     *
     * @param CookieParams $params
     */
    protected function buildSetCookieHeader(string $name, string $value, array $params, bool $expire = false): string
    {
        return SetCookieHeader::build($name, $value, $params, $expire);
    }
}
