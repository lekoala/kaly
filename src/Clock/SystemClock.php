<?php

declare(strict_types=1);

namespace Kaly\Clock;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Kaly\Util\Dates;
use Psr\Clock\ClockInterface;

/**
 * A clock that relies on system time.
 *
 * The timezone defaults to the PHP default timezone (see APP_TIMEZONE):
 * forcing UTC is the application's decision, `new SystemClock('UTC')`.
 */
final class SystemClock implements ClockInterface
{
    private DateTimeZone $timezone;

    /**
     * @throws DateInvalidTimeZoneException If $timezone is passed as string and is invalid.
     */
    public function __construct(DateTimeZone|string|null $timezone = null)
    {
        $this->timezone = Dates::timezone($timezone);
    }

    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }

    /**
     * Get a frozen copy of this clock, for tests.
     */
    public function freeze(): FrozenClock
    {
        return new FrozenClock($this->now());
    }
}
