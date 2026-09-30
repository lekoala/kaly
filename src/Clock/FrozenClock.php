<?php

declare(strict_types=1);

namespace Kaly\Clock;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;

/**
 * A clock frozen in time, for tests.
 *
 * Application code should only depend on `Psr\Clock\ClockInterface::now()`,
 * which keeps it portable across implementations.
 */
final class FrozenClock implements ClockInterface
{
    public function __construct(
        private DateTimeImmutable $now,
    ) {}

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Sets the clock to a specific time.
     */
    public function setTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    /**
     * Moves the clock, eg: `$clock->modify('+2 hours')`.
     *
     * @throws \DateMalformedStringException If the modifier is invalid.
     */
    public function modify(string $modifier): void
    {
        $this->now = $this->now->modify($modifier);
    }
}
