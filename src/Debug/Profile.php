<?php

declare(strict_types=1);

namespace Kaly\Debug;

use Kaly\Ex;

/** Elapsed durations for one cycle, accumulated in milliseconds with invocation counts. */
final class Profile
{
    private const MAX_NAME_LENGTH = 64;
    private const MAX_METRICS = 64;

    /**
     * @var array<string,array{duration: float, count: int}>
     */
    private array $metrics = [];

    /**
     * Record elapsed nanoseconds, accumulating the metric's duration and invocation count.
     * Names longer than 64 characters and new names beyond 64 distinct metrics are ignored.
     */
    public function record(string $name, int|float $nanoseconds): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $name) !== 1) {
            throw new Ex('Profile metric names must be lowercase HTTP tokens starting with a letter');
        }
        if ($nanoseconds < 0 || !is_finite((float) $nanoseconds)) {
            throw new Ex('Profile durations must be finite and non-negative');
        }
        if (strlen($name) > self::MAX_NAME_LENGTH || !isset($this->metrics[$name]) && count($this->metrics) >= self::MAX_METRICS) {
            return;
        }
        $metric = $this->metrics[$name] ?? ['duration' => 0.0, 'count' => 0];
        $metric['duration'] += $nanoseconds / 1_000_000;
        $metric['count']++;
        $this->metrics[$name] = $metric;
    }

    /**
     * Accumulated durations in milliseconds, with invocation counts.
     *
     * @return array<string,array{duration: float, count: int}>
     */
    public function metrics(): array
    {
        return $this->metrics;
    }
}
