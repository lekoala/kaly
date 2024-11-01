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
    public function cachedData(string $key, $fn = null, $ttl = null)
    {
        $cache = $this->cache;

        // If we don't have a cache, simply execute function
        if (!$cache) {
            if ($fn === null) {
                return $fn;
            }
            return $fn();
        }

        // Get result from cache or compute it
        $result = $cache->get($key);

        if (!$result) {
            if ($fn === null) {
                return null;
            }
            $result = $fn();
            $cache->set($key, $result, $ttl);
        }

        return $result;
    }
}
