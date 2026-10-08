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

    public function testIsDateIgnoresTheGlobalTimezone(): void
    {
        // Samoa skipped 2011-12-30 entirely; it stays a valid calendar date
        $previous = date_default_timezone_get();
        date_default_timezone_set('Pacific/Apia');
        try {
            $this->assertTrue(Dates::isDate('2011-12-30'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testDateRejectsAMidnightSkippedByDst(): void
    {
        // America/Sao_Paulo sprang forward at midnight on 2018-11-04
        $this->assertNull(Dates::tryDate('2018-11-04', 'America/Sao_Paulo'));

        $this->expectException(InvalidArgumentException::class);
        Dates::date('2018-11-04', 'America/Sao_Paulo');
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

    public function testUnknownTimezoneThrowsEvenForAnInvalidValue(): void
    {
        // An unknown timezone is a configuration error, never a null
        $thrown = 0;
        try {
            Dates::tryDate('bad', 'Nope/Zone');
        } catch (DateInvalidTimeZoneException) {
            $thrown++;
        }
        try {
            Dates::tryAt('bad', 'bad', 'Nope/Zone');
        } catch (DateInvalidTimeZoneException) {
            $thrown++;
        }

        $this->assertSame(2, $thrown);
    }

    public function testIsTimeAcceptsStrictLocalTimes(): void
    {
        $this->assertTrue(Dates::isTime('00:00'));
        $this->assertTrue(Dates::isTime('09:30'));
        $this->assertTrue(Dates::isTime('23:59'));
        $this->assertTrue(Dates::isTime('09:30:10'));

        $this->assertFalse(Dates::isTime('24:00'));
        $this->assertFalse(Dates::isTime('09:60'));
        $this->assertFalse(Dates::isTime('09:30:60'));
        $this->assertFalse(Dates::isTime('9:30'));
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

    public function testInstantAcceptsLowercaseAnnotations(): void
    {
        $this->assertTrue(Dates::isInstant('2026-10-07t12:30:00z'));
        $this->assertSame('+00:00', Dates::instant('2026-10-07t12:30:00z')->format('P'));
    }

    public function testInstantRejectsOutsideTheSupportedSubset(): void
    {
        // Leap seconds are not representable and the unknown local offset
        // is not an explicit instant
        $this->assertFalse(Dates::isInstant('2016-12-31T23:59:60Z'));
        $this->assertFalse(Dates::isInstant('2026-10-07T12:30:00-00:00'));
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

    public function testInstantPreservesInputOffsetRegardlessOfTheDefaultTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Asia/Tokyo');
        try {
            $value = '2026-10-08T12:30:00.123456-03:30';
            $this->assertSame($value, Dates::instant($value)->format('Y-m-d\TH:i:s.uP'));
            $this->assertSame($value, Dates::tryInstant($value)?->format('Y-m-d\TH:i:s.uP'));
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testTryInstantRejectsMissingOffsetsAndImpossibleDates(): void
    {
        $this->assertNull(Dates::tryInstant('2026-10-08T12:30:00'));
        $this->assertNull(Dates::tryInstant('2026-02-31T12:30:00Z'));

        $this->expectException(InvalidArgumentException::class);
        Dates::instant('2026-10-08T12:30:00');
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

    public function testAtKeepsSecondsWhenGiven(): void
    {
        $this->assertSame('2026-10-07 09:30:15', Dates::at('2026-10-07', '09:30:15', 'UTC')->format('Y-m-d H:i:s'));
        $this->assertSame('09:30:00', Dates::at('2026-10-07', '09:30', 'UTC')->format(Dates::TIME_FORMAT));
        $this->assertSame('09:30:15', Dates::at('2026-10-07', '09:30:15', 'UTC')->format(Dates::TIME_FORMAT));
    }

    public function testAtRejectsEitherInvalidPart(): void
    {
        $this->assertNull(Dates::tryAt('2026-02-31', '09:30'));
        $this->assertNull(Dates::tryAt('2026-10-07', '24:00'));
        $this->assertNull(Dates::tryAt('2026-10-07', '09:30:60'));
        // A local time skipped by a DST transition is rejected with seconds too
        $this->assertNull(Dates::tryAt('2018-11-04', '00:30:15', 'America/Sao_Paulo'));

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
