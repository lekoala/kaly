<?php

declare(strict_types=1);

namespace Kaly\Clock;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Throwable;

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
        $timezone ??= date_default_timezone_get();

        if (is_string($timezone)) {
            // \Exception < PHP 8.3, \DateInvalidTimeZoneException >= PHP 8.3
            try {
                $timezone = new DateTimeZone($timezone === '' ? 'UTC' : $timezone);
            } catch (Throwable $throwable) {
                throw new DateInvalidTimeZoneException($throwable->getMessage(), intval($throwable->getCode()), $throwable);
            }
        }

        $this->timezone = $timezone;
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
