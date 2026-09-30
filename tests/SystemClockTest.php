<?php

declare(strict_types=1);

namespace Kaly\Tests;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Kaly\Clock\FrozenClock;
use Kaly\Clock\SystemClock;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

class SystemClockTest extends TestCase
{
    public function testImplementsThePsrContract(): void
    {
        $this->assertInstanceOf(ClockInterface::class, new SystemClock('UTC'));
    }

    public function testDefaultsToThePhpTimezone(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
        try {
            $this->assertSame('Europe/Brussels', (new SystemClock())->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testExplicitTimezoneWins(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('Europe/Brussels');
        try {
            $this->assertSame('UTC', (new SystemClock('UTC'))->now()->getTimezone()->getName());
            $this->assertSame('Asia/Tokyo', (new SystemClock(new DateTimeZone('Asia/Tokyo')))->now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testEmptyStringFallsBackToUtc(): void
    {
        $this->assertSame('UTC', (new SystemClock(''))->now()->getTimezone()->getName());
    }

    public function testInvalidTimezoneFails(): void
    {
        $this->expectException(DateInvalidTimeZoneException::class);
        new SystemClock('Not/AZone');
    }

    public function testFreezeCapturesAFrozenCopy(): void
    {
        $frozen = (new SystemClock('UTC'))->freeze();

        $this->assertInstanceOf(FrozenClock::class, $frozen);
        $this->assertSame('UTC', $frozen->now()->getTimezone()->getName());
        $this->assertEquals($frozen->now(), $frozen->now(), 'a frozen clock does not advance');
    }

    public function testFrozenClockHoldsTheGivenInstant(): void
    {
        $instant = new DateTimeImmutable('2026-09-30 10:00:00 UTC');
        $clock = new FrozenClock($instant);

        $this->assertSame($instant, $clock->now());
    }

    public function testFrozenClockCanBeMoved(): void
    {
        $clock = new FrozenClock(new DateTimeImmutable('2026-09-30 10:00:00 UTC'));

        $clock->modify('+2 hours');
        $this->assertSame('2026-09-30 12:00:00', $clock->now()->format('Y-m-d H:i:s'));

        $clock->setTo(new DateTimeImmutable('2026-10-01 00:00:00 UTC'));
        $this->assertSame('2026-10-01 00:00:00', $clock->now()->format('Y-m-d H:i:s'));
    }
}
