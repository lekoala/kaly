<?php

declare(strict_types=1);

namespace Kaly\Core;

use Psr\SimpleCache\CacheInterface;

/**
 * Implement easy to use cache providers
 */
trait HasCache
{
    protected ?CacheInterface $cache = null;

    /**
     * Retrieve cached data or compute it using $fn callable
     * @param string $key
     * @param ?callable $fn
     * @param null|int|\DateInterval $ttl In seconds. 60 * 60 * 24 = 1 day
     * @return mixed
     */
    public function cachedData(string $key, ?callable $fn = null, int|\DateInterval|null $ttl = null): mixed
    {
        $cache = $this->cache;

        // If we don't have a cache, simply execute function
        if (!$cache) {
            return $fn === null ? null : $fn();
        }

        // A falsy value (0, [], false...) is a legit cached value: only a
        // real miss triggers the computation
        $miss = new \stdClass();
        $result = $cache->get($key, $miss);

        if ($result === $miss) {
            if ($fn === null) {
                return null;
            }
            $result = $fn();
            $cache->set($key, $result, $ttl);
        }

        return $result;
    }
}
