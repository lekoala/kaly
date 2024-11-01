<?php

declare(strict_types=1);

namespace Kaly\Clock;

use DateTimeInterface;
use Psr\Clock\ClockInterface;
use Stringable;

/**
 * A base clock
 *
 * Credits to
 * @link https://github.com/ericsizemore/clock
 */
abstract class AbstractClock implements ClockInterface, Stringable
{
    public function __toString(): string
    {
        return sprintf(
            '[%s("%s"): unixtime: %s; iso8601: %s;]',
            static::class,
            $this->now()->getTimezone()->getName(),
            $this->now()->format('U'),
            $this->now()->format(DateTimeInterface::ISO8601_EXPANDED)
        );
    }
}
