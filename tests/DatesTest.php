<?php

declare(strict_types=1);

namespace Kaly\Tests;

use DateInvalidTimeZoneException;
use DateTimeZone;
use InvalidArgumentException;
use Kaly\Util\Dates;
use PHPUnit\Framework\TestCase;

class DatesTest extends TestCase
{
    public function testIsDateAcceptsCalendarDatesOnly(): void
    {
        $this->assertTrue(Dates::isDate('2026-02-28'));
        $this->assertTrue(Dates::isDate('2024-02-29'));

        $this->assertFalse(Dates::isDate('2026-02-31'));
        $this->assertFalse(Dates::isDate('2023-02-29'));
        $this->assertFalse(Dates::isDate('2026-13-01'));
        $this->assertFalse(Dates::isDate('2026-00-10'));
        $this->assertFalse(Dates::isDate('2026-2-3'));
        $this->assertFalse(Dates::isDate('2026/10/07'));
        $this->assertFalse(Dates::isDate(''));
        $this->assertFalse(Dates::isDate('2026-10-07 '));
        $this->assertFalse(Dates::isDate('tomorrow'));
    }

    public function testDateIsMidnightInTheDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
        try {
            $date = Dates::date('2026-10-07');

            $this->assertSame('2026-10-07 00:00:00', $date->format('Y-m-d H:i:s'));
            $this->assertSame('Europe/Brussels', $date->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testDateHonoursAnExplicitTimezone(): void
    {
        $date = Dates::date('2026-10-07', 'Europe/Brussels');

        $this->assertSame('2026-10-07 00:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('Europe/Brussels', $date->getTimezone()->getName());
        $this->assertSame('Asia/Tokyo', Dates::date('2026-10-07', new DateTimeZone('Asia/Tokyo'))->getTimezone()->getName());
    }

    public function testDateRejectsImpossibleDates(): void
    {
        $this->assertNull(Dates::tryDate('2026-02-31'));

        $this->expectException(InvalidArgumentException::class);
        Dates::date('2026-02-31');
    }

    public function testTryDateLetsTimezoneErrorsThrough(): void
    {
        $this->expectException(DateInvalidTimeZoneException::class);
        Dates::tryDate('2026-02-28', 'Nope/Zone');
    }

    public function testIsTimeAcceptsStrictHoursOnly(): void
    {
        $this->assertTrue(Dates::isTime('00:00'));
        $this->assertTrue(Dates::isTime('09:30'));
        $this->assertTrue(Dates::isTime('23:59'));

        $this->assertFalse(Dates::isTime('24:00'));
        $this->assertFalse(Dates::isTime('09:60'));
        $this->assertFalse(Dates::isTime('9:30'));
        $this->assertFalse(Dates::isTime('09:30:10'));
        $this->assertFalse(Dates::isTime(''));
    }

    public function testInstantRequiresAnExplicitOffset(): void
    {
        $this->assertTrue(Dates::isInstant('2026-10-07T12:30:00+02:00'));
        $this->assertTrue(Dates::isInstant('2026-10-07T12:30:00Z'));
        $this->assertTrue(Dates::isInstant('2026-10-07T12:30:00.1+02:00'));
        $this->assertTrue(Dates::isInstant('2026-10-07T12:30:00.123456+02:00'));

        $this->assertFalse(Dates::isInstant('2026-10-07T12:30:00'));
        $this->assertFalse(Dates::isInstant('2026-10-07'));
        $this->assertFalse(Dates::isInstant('tomorrow'));
        $this->assertFalse(Dates::isInstant('now'));
        $this->assertFalse(Dates::isInstant('2026-10-07T12:30:00.1234567+02:00'));
        $this->assertFalse(Dates::isInstant('2026-02-31T12:30:00+02:00'));
        $this->assertFalse(Dates::isInstant('2026-10-07T24:00:00+02:00'));
    }

    public function testInstantNormalizesZoneAndKeepsMicroseconds(): void
    {
        $instant = Dates::instant('2026-10-07T12:30:00Z');

        $this->assertSame('+00:00', $instant->format('P'));
        $this->assertSame('2026-10-07T12:30:00.100000+00:00', Dates::instant('2026-10-07T12:30:00.1+00:00')->format('Y-m-d\TH:i:s.uP'));
    }

    public function testInstantRejectsGarbage(): void
    {
        $this->assertNull(Dates::tryInstant('tomorrow'));

        $this->expectException(InvalidArgumentException::class);
        Dates::instant('tomorrow');
    }

    public function testAtCombinesDateAndTime(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
        try {
            $at = Dates::at('2026-10-07', '09:30');

            $this->assertSame('2026-10-07 09:30:00', $at->format('Y-m-d H:i:s'));
            $this->assertSame('Europe/Brussels', $at->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame('09:30', Dates::at('2026-10-07', '09:30', 'UTC')->format('H:i'));
    }

    public function testAtRejectsEitherInvalidPart(): void
    {
        $this->assertNull(Dates::tryAt('2026-02-31', '09:30'));
        $this->assertNull(Dates::tryAt('2026-10-07', '24:00'));
        // Seconds are not a local time in v1, even composed
        $this->assertNull(Dates::tryAt('2026-10-07', '09:30:10'));

        $this->expectException(InvalidArgumentException::class);
        Dates::at('2026-10-07', '24:00');
    }

    public function testTryAtLetsTimezoneErrorsThrough(): void
    {
        $this->expectException(DateInvalidTimeZoneException::class);
        Dates::tryAt('2026-10-07', '09:30', 'Nope/Zone');
    }

    public function testTimezoneResolution(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
        try {
            $this->assertSame('Europe/Brussels', Dates::timezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }

        $this->assertSame('UTC', Dates::timezone('')->getName());
        $this->assertSame('UTC', Dates::timezone('UTC')->getName());

        $zone = new DateTimeZone('Asia/Tokyo');
        $this->assertSame($zone, Dates::timezone($zone));

        $this->expectException(DateInvalidTimeZoneException::class);
        Dates::timezone('Nope/Zone');
    }
}
