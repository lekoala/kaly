<?php

declare(strict_types=1);

namespace Kaly\Clock;

use DateTimeImmutable;
use DateTimeZone;
use DateInvalidTimeZoneException;

/**
 * A clock frozen in time.
 */
final class FrozenClock extends AbstractClock
{
    private DateTimeImmutable $now;

    public function __construct(?DateTimeImmutable $now = null)
    {
        if ($now === null) {
            $now = new DateTimeImmutable();
        }
        $this->now = $now;
    }

    /**
     * {@inheritDoc}
     */
    public function now(): DateTimeImmutable
    {
        return $this->now;
    }

    /**
     * Sets the FrozenClock to a specific time.
     */
    public function setTo(DateTimeImmutable $now): void
    {
        $this->now = $now;
    }

    /**
     * Returns a new *Clock at current system time in UTC.
     *
     * @throws DateInvalidTimeZoneException
     */
    public static function fromUtc(): FrozenClock
    {
        return new FrozenClock(
            new DateTimeImmutable('now', new DateTimeZone('UTC'))
        );
    }
}
