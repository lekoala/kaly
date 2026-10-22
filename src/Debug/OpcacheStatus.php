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
     * the cache is healthy. Counters (restarts, hits) are cumulative since
     * opcache started: compare two metrics() readings to see a trend.
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
        $metrics = $this->metrics();
        if ($metrics['restartPending'] || $metrics['restartInProgress']) {
            $problems[] = 'Opcache is restarting, scripts are compiled again until it completes';
        }

        $memory = $metrics['memoryUsedBytes'] + $metrics['memoryFreeBytes'] + $metrics['memoryWastedBytes'];
        $memoryFull = $memory > 0 && ($metrics['memoryFreeBytes'] / $memory) < self::MIN_FREE;
        if ($memoryFull) {
            $problems[] = 'Opcache memory is nearly full, raise opcache.memory_consumption';
        }
        if ($memory > 0 && ($metrics['memoryWastedBytes'] / $memory) > self::MAX_WASTED) {
            $problems[] = sprintf(
                'Opcache wastes %d%% of its memory, restart it after deploys',
                round((100 * $metrics['memoryWastedBytes']) / $memory),
            );
        }

        $strings = $metrics['internedStringsUsedBytes'] + $metrics['internedStringsFreeBytes'];
        if ($strings > 0 && ($metrics['internedStringsFreeBytes'] / $strings) < self::MIN_FREE) {
            $problems[] = 'Opcache interned strings buffer is nearly full, raise opcache.interned_strings_buffer';
        }

        $keysFull = $metrics['keysMax'] > 0 && (1 - ($metrics['keysUsed'] / $metrics['keysMax'])) < self::MIN_FREE;
        if ($keysFull) {
            $problems[] = 'Opcache keys are nearly exhausted, raise opcache.max_accelerated_files';
        }
        if ($metrics['cacheFull'] && !$memoryFull && !$keysFull) {
            $problems[] = 'Opcache is full, raise opcache.memory_consumption or opcache.max_accelerated_files';
        }

        $restarts = $metrics['oomRestarts'] + $metrics['hashRestarts'];
        if ($restarts > 0) {
            $problems[] = sprintf('Opcache was restarted %d time(s) for lack of memory or keys, raise its limits', $restarts);
        }
        if (($metrics['hits'] + $metrics['misses']) >= self::MIN_LOOKUPS && $metrics['hitRatePercent'] < self::MIN_HIT_RATE) {
            $problems[] = sprintf('Opcache hit rate is %.1F%%, check its limits and restarts', $metrics['hitRatePercent']);
        }

        return $problems;
    }

    /**
     * Raw values for a health endpoint or monitoring: sizes in bytes, times as
     * Unix timestamps (0 when it never restarted), counters since opcache
     * started.
     *
     * `validateTimestamps` and the preload metrics describe deployment choices,
     * never problems: checking timestamps is valid when deploys do not reset
     * the cache, and a worker gains little from preloading.
     *
     * @return array{
     *     enabled: bool,
     *     validateTimestamps: bool,
     *     cacheFull: bool,
     *     restartPending: bool,
     *     restartInProgress: bool,
     *     memoryUsedBytes: int,
     *     memoryFreeBytes: int,
     *     memoryWastedBytes: int,
     *     internedStringsUsedBytes: int,
     *     internedStringsFreeBytes: int,
     *     keysUsed: int,
     *     keysMax: int,
     *     scripts: int,
     *     hits: int,
     *     misses: int,
     *     hitRatePercent: float,
     *     oomRestarts: int,
     *     hashRestarts: int,
     *     manualRestarts: int,
     *     startTime: int,
     *     lastRestartTime: int,
     *     preloadedClasses: int,
     *     preloadedMemoryBytes: int,
     * }
     */
    public function metrics(): array
    {
        $memory = $this->section('memory_usage');
        $strings = $this->section('interned_strings_usage');
        $statistics = $this->section('opcache_statistics');
        $hits = $this->int($statistics, 'hits');
        $misses = $this->int($statistics, 'misses');
        $preload = $this->section('preload_statistics');
        $classes = $preload['classes'] ?? [];

        return [
            'enabled' => ($this->status['opcache_enabled'] ?? false) === true,
            'validateTimestamps' => $this->directive('opcache.validate_timestamps'),
            'cacheFull' => ($this->status['cache_full'] ?? false) === true,
            'restartPending' => ($this->status['restart_pending'] ?? false) === true,
            'restartInProgress' => ($this->status['restart_in_progress'] ?? false) === true,
            'memoryUsedBytes' => $this->int($memory, 'used_memory'),
            'memoryFreeBytes' => $this->int($memory, 'free_memory'),
            'memoryWastedBytes' => $this->int($memory, 'wasted_memory'),
            'internedStringsUsedBytes' => $this->int($strings, 'used_memory'),
            'internedStringsFreeBytes' => $this->int($strings, 'free_memory'),
            'keysUsed' => $this->int($statistics, 'num_cached_keys'),
            'keysMax' => $this->int($statistics, 'max_cached_keys'),
            'scripts' => $this->int($statistics, 'num_cached_scripts'),
            'hits' => $hits,
            'misses' => $misses,
            'hitRatePercent' => ($hits + $misses) > 0 ? round((100 * $hits) / ($hits + $misses), 1) : 0.0,
            'oomRestarts' => $this->int($statistics, 'oom_restarts'),
            'hashRestarts' => $this->int($statistics, 'hash_restarts'),
            'manualRestarts' => $this->int($statistics, 'manual_restarts'),
            'startTime' => $this->int($statistics, 'start_time'),
            'lastRestartTime' => $this->int($statistics, 'last_restart_time'),
            'preloadedClasses' => is_array($classes) ? count($classes) : 0,
            'preloadedMemoryBytes' => $this->int($preload, 'memory_consumption'),
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
