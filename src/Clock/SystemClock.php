<?php

declare(strict_types=1);

namespace Kaly\Clock;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use DateInvalidTimeZoneException;

/**
 * A clock that relies on system time.
 */
final class SystemClock extends AbstractClock
{
    private DateTimeZone $timezone;

    /**
     * @throws DateInvalidTimeZoneException If $timezone is passed as string and is invalid.
     */
    public function __construct(null|DateTimeZone|string $timezone = null)
    {
        if (!$timezone instanceof DateTimeZone) {
            $timezone ??= 'UTC';

            try {
                $this->timezone = new DateTimeZone($timezone === '' ? 'UTC' : $timezone);
                // \Exception < PHP 8.3, \DateInvalidTimeZoneException >= PHP 8.3
                // DateInvalidTimeZoneException is polyfilled via symfony/polyfill-php83
            } catch (Throwable $throwable) {
                throw new DateInvalidTimeZoneException(
                    $throwable->getMessage(),
                    intval($throwable->getCode()),
                    $throwable
                );
            }
            return;
        }

        $this->timezone = $timezone;
    }

    /**
     * Get a frozen copy of this clock
     */
    public function freeze(): FrozenClock
    {
        return new FrozenClock($this->now());
    }

    /**
     * {@inheritDoc}
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', $this->timezone);
    }

    /**
     * Returns a new SystemClock at current system time using the system's timezone.
     *
     * @throws DateInvalidTimeZoneException
     */
    public static function fromSystemTimezone(): SystemClock
    {
        return new SystemClock(new DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Returns a new *Clock at current system time in UTC.
     *
     * @throws DateInvalidTimeZoneException
     */
    public static function fromUtc(): SystemClock
    {
        return new SystemClock(new DateTimeZone('UTC'));
    }
}
