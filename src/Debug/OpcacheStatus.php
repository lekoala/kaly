<?php

declare(strict_types=1);

namespace Kaly\Debug;

/**
 * Production problems of the opcache serving this process.
 *
 * Opcache memory belongs to the SAPI: the CLI never sees the FPM pool. Read
 * the status from a request served by that pool (eg a protected health
 * route), never from a console command.
 */
final class OpcacheStatus
{
    /** Below this share of free memory or interned strings, the cache is about to fill up */
    private const MIN_FREE = 0.05;
    /** Wasted memory is only reclaimed by a restart */
    private const MAX_WASTED = 0.10;
    /** The hit rate is meaningless before this many lookups */
    private const MIN_LOOKUPS = 1000;
    private const MIN_HIT_RATE = 90.0;

    /**
     * @param array<mixed>|null $status opcache_get_status(false), null when disabled or unreadable
     * @param array<mixed>|null $configuration opcache_get_configuration(), null when unreadable
     */
    public function __construct(
        private readonly bool $loaded,
        private readonly ?array $status,
        private readonly ?array $configuration,
    ) {}

    public static function current(): self
    {
        if (!function_exists('opcache_get_status')) {
            return new self(false, null, null);
        }
        // opcache.restrict_api makes both calls warn and return false
        $status = @opcache_get_status(false);
        $configuration = @opcache_get_configuration();
        return new self(true, is_array($status) ? $status : null, is_array($configuration) ? $configuration : null);
    }

    /**
     * Each problem is a sentence naming the directive to change. Empty when
     * the cache is healthy.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        if (!$this->loaded) {
            return ['Opcache is not loaded, enable the Zend OPcache extension'];
        }
        // opcache.restrict_api hides both; a disabled cache still has a configuration
        if ($this->configuration === null) {
            return ['Opcache status is not readable, check opcache.restrict_api'];
        }
        if ($this->status === null || ($this->status['opcache_enabled'] ?? false) !== true) {
            return ['Opcache is disabled for this SAPI, set opcache.enable=1 (opcache.enable_cli=1 for the CLI)'];
        }

        $problems = [];
        if ($this->directive('opcache.validate_timestamps')) {
            $problems[] = 'Opcache checks file timestamps, set opcache.validate_timestamps=0 and reset the cache on deploy';
        }

        $memory = $this->section('memory_usage');
        $used = $this->int($memory, 'used_memory');
        $free = $this->int($memory, 'free_memory');
        $wasted = $this->int($memory, 'wasted_memory');
        $total = $used + $free + $wasted;
        if ($total > 0 && ($free / $total) < self::MIN_FREE) {
            $problems[] = 'Opcache memory is nearly full, raise opcache.memory_consumption';
        }
        if ($total > 0 && ($wasted / $total) > self::MAX_WASTED) {
            $problems[] = sprintf('Opcache wastes %d%% of its memory, restart it after deploys', round((100 * $wasted) / $total));
        }

        $strings = $this->section('interned_strings_usage');
        $buffer = $this->int($strings, 'buffer_size');
        if ($buffer > 0 && ($this->int($strings, 'free_memory') / $buffer) < self::MIN_FREE) {
            $problems[] = 'Opcache interned strings buffer is nearly full, raise opcache.interned_strings_buffer';
        }

        $statistics = $this->section('opcache_statistics');
        $maxKeys = $this->int($statistics, 'max_cached_keys');
        if ($maxKeys > 0 && $this->int($statistics, 'num_cached_keys') >= $maxKeys) {
            $problems[] = 'Opcache has no free key left, raise opcache.max_accelerated_files';
        }
        $restarts = $this->int($statistics, 'oom_restarts') + $this->int($statistics, 'hash_restarts');
        if ($restarts > 0) {
            $problems[] = sprintf('Opcache restarted %d time(s) because it was full, raise its limits', $restarts);
        }
        $hits = $this->int($statistics, 'hits');
        $lookups = $hits + $this->int($statistics, 'misses');
        if ($lookups >= self::MIN_LOOKUPS && ((100 * $hits) / $lookups) < self::MIN_HIT_RATE) {
            $problems[] = sprintf('Opcache hit rate is %.1F%%, the cache is too small or keeps restarting', (100 * $hits) / $lookups);
        }

        return $problems;
    }

    /**
     * The numbers worth watching, for a health endpoint or a log line.
     * Preloading is a deployment choice, never a problem: `preloadedClasses`
     * is 0 without it, and a worker gains little from it.
     *
     * @return array{enabled: bool, memoryUsed: float, hitRate: float, scripts: int, preloadedClasses: int}
     */
    public function metrics(): array
    {
        $memory = $this->section('memory_usage');
        $total = $this->int($memory, 'used_memory') + $this->int($memory, 'free_memory') + $this->int($memory, 'wasted_memory');
        $statistics = $this->section('opcache_statistics');
        $hits = $this->int($statistics, 'hits');
        $lookups = $hits + $this->int($statistics, 'misses');
        $preload = $this->section('preload_statistics');
        $classes = $preload['classes'] ?? [];

        return [
            'enabled' => ($this->status['opcache_enabled'] ?? false) === true,
            'memoryUsed' => $total > 0 ? round((100 * $this->int($memory, 'used_memory')) / $total, 1) : 0.0,
            'hitRate' => $lookups > 0 ? round((100 * $hits) / $lookups, 1) : 0.0,
            'scripts' => $this->int($statistics, 'num_cached_scripts'),
            'preloadedClasses' => is_array($classes) ? count($classes) : 0,
        ];
    }

    private function directive(string $name): bool
    {
        $directives = $this->configuration['directives'] ?? [];
        return is_array($directives) && (bool) ($directives[$name] ?? false);
    }

    /**
     * @return array<mixed>
     */
    private function section(string $name): array
    {
        $section = $this->status[$name] ?? [];
        return is_array($section) ? $section : [];
    }

    /**
     * @param array<mixed> $section
     */
    private function int(array $section, string $key): int
    {
        $value = $section[$key] ?? 0;
        return is_int($value) ? $value : 0;
    }
}
