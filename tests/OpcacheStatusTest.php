<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Debug\OpcacheStatus;
use PHPUnit\Framework\TestCase;

class OpcacheStatusTest extends TestCase
{
    public function testHealthyCacheHasNoProblemAndExposesRawMetrics(): void
    {
        $status = new OpcacheStatus(true, self::opcacheStatus(), self::configuration());

        $this->assertSame([], $status->problems());
        $this->assertSame(
            [
                'enabled' => true,
                'validateTimestamps' => false,
                'cacheFull' => false,
                'restartPending' => false,
                'restartInProgress' => false,
                'memoryUsedBytes' => 25,
                'memoryFreeBytes' => 75,
                'memoryWastedBytes' => 0,
                'internedStringsUsedBytes' => 40,
                'internedStringsFreeBytes' => 60,
                'keysUsed' => 300,
                'keysMax' => 1000,
                'scripts' => 300,
                'hits' => 9900,
                'misses' => 100,
                'hitRatePercent' => 99.0,
                'oomRestarts' => 0,
                'hashRestarts' => 0,
                'manualRestarts' => 1,
                'startTime' => 1_800_000_000,
                'lastRestartTime' => 1_800_003_600,
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

    public function testTimestampValidationIsADeploymentChoiceNotAProblem(): void
    {
        $status = new OpcacheStatus(true, self::opcacheStatus(), self::configuration(validateTimestamps: true));

        $this->assertSame([], $status->problems());
        $this->assertTrue($status->metrics()['validateTimestamps']);
    }

    public function testExhaustedLimitsNameTheDirectiveToRaise(): void
    {
        $status = self::opcacheStatus();
        $status['memory_usage'] = ['used_memory' => 80, 'free_memory' => 2, 'wasted_memory' => 18];
        $status['interned_strings_usage'] = ['used_memory' => 99, 'free_memory' => 1];
        $status['opcache_statistics']['num_cached_keys'] = 960;
        $status['opcache_statistics']['oom_restarts'] = 2;
        $status['opcache_statistics']['hash_restarts'] = 1;
        $status['cache_full'] = true;

        $this->assertSame(
            [
                'Opcache memory is nearly full, raise opcache.memory_consumption',
                'Opcache wastes 18% of its memory, restart it after deploys',
                'Opcache interned strings buffer is nearly full, raise opcache.interned_strings_buffer',
                'Opcache keys are nearly exhausted, raise opcache.max_accelerated_files',
                'Opcache was restarted 3 time(s) for lack of memory or keys, raise its limits',
            ],
            (new OpcacheStatus(true, $status, self::configuration()))->problems(),
        );
    }

    public function testNativeFullAndRestartStatesAreReported(): void
    {
        $status = self::opcacheStatus();
        $status['cache_full'] = true;
        $status['restart_pending'] = true;

        $this->assertSame(
            [
                'Opcache is restarting, scripts are compiled again until it completes',
                'Opcache is full, raise opcache.memory_consumption or opcache.max_accelerated_files',
            ],
            (new OpcacheStatus(true, $status, self::configuration()))->problems(),
        );

        $status = self::opcacheStatus();
        $status['restart_in_progress'] = true;
        $this->assertSame(
            ['Opcache is restarting, scripts are compiled again until it completes'],
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
            ['Opcache hit rate is 80.0%, check its limits and restarts'],
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
        $metrics = OpcacheStatus::current()->metrics();

        // The test SAPI may run with or without opcache: only the shape is stable
        $this->assertArrayHasKey('enabled', $metrics);
        $this->assertArrayHasKey('preloadedClasses', $metrics);
    }

    /**
     * @return array{opcache_enabled: bool, cache_full: bool, restart_pending: bool, restart_in_progress: bool, memory_usage: array<string,int>, interned_strings_usage: array<string,int>, opcache_statistics: array<string,int>, preload_statistics?: array<string,mixed>}
     */
    private static function opcacheStatus(): array
    {
        return [
            'opcache_enabled' => true,
            'cache_full' => false,
            'restart_pending' => false,
            'restart_in_progress' => false,
            'memory_usage' => ['used_memory' => 25, 'free_memory' => 75, 'wasted_memory' => 0],
            'interned_strings_usage' => ['buffer_size' => 100, 'used_memory' => 40, 'free_memory' => 60],
            'opcache_statistics' => [
                'num_cached_scripts' => 300,
                'num_cached_keys' => 300,
                'max_cached_keys' => 1000,
                'hits' => 9900,
                'misses' => 100,
                'start_time' => 1_800_000_000,
                'last_restart_time' => 1_800_003_600,
                'oom_restarts' => 0,
                'hash_restarts' => 0,
                'manual_restarts' => 1,
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
