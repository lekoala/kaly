<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Debug\OpcacheStatus;
use PHPUnit\Framework\TestCase;

class OpcacheStatusTest extends TestCase
{
    public function testHealthyProductionCacheHasNoProblem(): void
    {
        $status = new OpcacheStatus(true, self::opcacheStatus(), self::configuration());

        $this->assertSame([], $status->problems());
        $this->assertSame(
            [
                'enabled' => true,
                'memoryUsed' => 25.0,
                'hitRate' => 99.0,
                'scripts' => 300,
                'preloadedClasses' => 0,
            ],
            $status->metrics(),
        );
    }

    public function testUnavailableCacheIsTheOnlyProblem(): void
    {
        $this->assertSame(['Opcache is not loaded, enable the Zend OPcache extension'], (new OpcacheStatus(false, null, null))->problems());
        $this->assertSame(
            ['Opcache status is not readable, check opcache.restrict_api'],
            (new OpcacheStatus(true, null, null))->problems(),
        );
        $this->assertSame(
            ['Opcache is disabled for this SAPI, set opcache.enable=1 (opcache.enable_cli=1 for the CLI)'],
            (new OpcacheStatus(true, null, self::configuration()))->problems(),
        );
        $this->assertFalse((new OpcacheStatus(true, null, self::configuration()))->metrics()['enabled']);
    }

    public function testTimestampValidationIsAProductionProblem(): void
    {
        $status = new OpcacheStatus(true, self::opcacheStatus(), self::configuration(validateTimestamps: true));

        $this->assertSame(
            ['Opcache checks file timestamps, set opcache.validate_timestamps=0 and reset the cache on deploy'],
            $status->problems(),
        );
    }

    public function testFullCacheNamesTheDirectiveToRaise(): void
    {
        $status = self::opcacheStatus();
        $status['memory_usage'] = ['used_memory' => 80, 'free_memory' => 2, 'wasted_memory' => 18];
        $status['interned_strings_usage'] = ['buffer_size' => 100, 'free_memory' => 1];
        $status['opcache_statistics']['num_cached_keys'] = 1000;
        $status['opcache_statistics']['oom_restarts'] = 2;
        $status['opcache_statistics']['hash_restarts'] = 1;

        $this->assertSame(
            [
                'Opcache memory is nearly full, raise opcache.memory_consumption',
                'Opcache wastes 18% of its memory, restart it after deploys',
                'Opcache interned strings buffer is nearly full, raise opcache.interned_strings_buffer',
                'Opcache has no free key left, raise opcache.max_accelerated_files',
                'Opcache restarted 3 time(s) because it was full, raise its limits',
            ],
            (new OpcacheStatus(true, $status, self::configuration()))->problems(),
        );
    }

    public function testHitRateIsJudgedOnlyAfterEnoughLookups(): void
    {
        $status = self::opcacheStatus();
        $status['opcache_statistics']['hits'] = 10;
        $status['opcache_statistics']['misses'] = 90;
        $this->assertSame([], (new OpcacheStatus(true, $status, self::configuration()))->problems());

        $status['opcache_statistics']['hits'] = 800;
        $status['opcache_statistics']['misses'] = 200;
        $this->assertSame(
            ['Opcache hit rate is 80.0%, the cache is too small or keeps restarting'],
            (new OpcacheStatus(true, $status, self::configuration()))->problems(),
        );
    }

    public function testPreloadedClassesAreCounted(): void
    {
        $status = self::opcacheStatus();
        $status['preload_statistics'] = ['memory_consumption' => 1, 'classes' => ['A', 'B'], 'scripts' => ['a.php']];

        $this->assertSame(2, (new OpcacheStatus(true, $status, self::configuration()))->metrics()['preloadedClasses']);
    }

    public function testCurrentReadsThisProcess(): void
    {
        $status = OpcacheStatus::current();

        // The test SAPI may run with or without opcache: only the shape is stable
        $this->assertSame(['enabled', 'memoryUsed', 'hitRate', 'scripts', 'preloadedClasses'], array_keys($status->metrics()));
    }

    /**
     * @return array{opcache_enabled: bool, cache_full: bool, memory_usage: array<string,int>, interned_strings_usage: array<string,int>, opcache_statistics: array<string,int>, preload_statistics?: array<string,mixed>}
     */
    private static function opcacheStatus(): array
    {
        return [
            'opcache_enabled' => true,
            'cache_full' => false,
            'memory_usage' => ['used_memory' => 25, 'free_memory' => 75, 'wasted_memory' => 0],
            'interned_strings_usage' => ['buffer_size' => 100, 'used_memory' => 40, 'free_memory' => 60],
            'opcache_statistics' => [
                'num_cached_scripts' => 300,
                'num_cached_keys' => 300,
                'max_cached_keys' => 1000,
                'hits' => 9900,
                'misses' => 100,
                'oom_restarts' => 0,
                'hash_restarts' => 0,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private static function configuration(bool $validateTimestamps = false): array
    {
        return ['directives' => ['opcache.enable' => true, 'opcache.validate_timestamps' => $validateTimestamps]];
    }
}
