<?php

declare(strict_types=1);

namespace Kaly\Util;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use Throwable;

/**
 * Strict parsing of the everyday web date representations.
 *
 * A tiny native complement, not a date library: `Y-m-d` dates, `H:i` or
 * `H:i:s` times and RFC 3339 instants with an explicit offset (fractional
 * seconds up to microsecond precision). Anything richer — `LocalDate`, durations,
 * circular intervals, arithmetic, humanization — belongs to
 * `brick/date-time`, `bakame/tokei` or Carbon.
 *
 * `date()` and `at()` resolve a missing timezone from the PHP default
 * timezone, the same convention as `Kaly\Clock\SystemClock`. `instant()`
 * never does: the offset is mandatory in the string. The `try*` family
 * returns `null` for an invalid value only; an unknown timezone string is a
 * configuration error and still throws `DateInvalidTimeZoneException`, even
 * when the value itself is invalid too.
 *
 * The instant subset is deliberately narrow: `T` and `Z` may be lower case,
 * seconds run `00` to `59` (no leap second), fractional seconds hold 1 to 6
 * digits and the offset is `Z` or `±HH:MM` with an `00`–`23` hour. The unknown
 * local offset `-00:00` is not an explicit instant and is rejected.
 */
final class Dates
{
    public const DATE_FORMAT = 'Y-m-d';
    /**
     * The canonical output format: both `H:i` and `H:i:s` are accepted on
     * input, missing seconds default to `00`.
     */
    public const TIME_FORMAT = 'H:i:s';

    /**
     * A pure calendar check, independent of any timezone: a skipped civil
     * day (eg Samoa skipping 2011-12-30) is still a valid `Y-m-d`.
     */
    public static function isDate(string $value): bool
    {
        return self::parseDate($value, 'UTC') !== null;
    }

    public static function isTime(string $value): bool
    {
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/D', $value) === 1;
    }

    public static function isInstant(string $value): bool
    {
        return self::parseInstant($value) !== null;
    }

    /**
     * A `Y-m-d` date at midnight in the given timezone.
     *
     * A midnight skipped by a DST transition has no midnight and is
     * rejected rather than silently normalized to another time.
     *
     * @throws InvalidArgumentException When the value is not a calendar date
     * @throws DateInvalidTimeZoneException When the timezone string is unknown
     */
    public static function date(string $value, DateTimeZone|string|null $timezone = null): DateTimeImmutable
    {
        return self::parseDate($value, $timezone) ?? throw new InvalidArgumentException("Invalid date '{$value}', expected Y-m-d");
    }

    /**
     * @throws DateInvalidTimeZoneException When the timezone string is unknown
     */
    public static function tryDate(string $value, DateTimeZone|string|null $timezone = null): ?DateTimeImmutable
    {
        return self::parseDate($value, $timezone);
    }

    /**
     * An RFC 3339 instant with an explicit offset.
     *
     * @throws InvalidArgumentException When the value is not such an instant
     */
    public static function instant(string $value): DateTimeImmutable
    {
        return (
            self::parseInstant($value) ?? throw new InvalidArgumentException(
                "Invalid instant '{$value}', expected RFC 3339 with an explicit offset",
            )
        );
    }

    public static function tryInstant(string $value): ?DateTimeImmutable
    {
        return self::parseInstant($value);
    }

    /**
     * A `Y-m-d` date plus an `H:i` or `H:i:s` time in the given timezone.
     *
     * @throws InvalidArgumentException When the pair is not a calendar date and time
     * @throws DateInvalidTimeZoneException When the timezone string is unknown
     */
    public static function at(string $date, string $time, DateTimeZone|string|null $timezone = null): DateTimeImmutable
    {
        return (
            self::parseAt($date, $time, $timezone) ?? throw new InvalidArgumentException(
                "Invalid datetime '{$date} {$time}', expected Y-m-d H:i or Y-m-d H:i:s",
            )
        );
    }

    /**
     * @throws DateInvalidTimeZoneException When the timezone string is unknown
     */
    public static function tryAt(string $date, string $time, DateTimeZone|string|null $timezone = null): ?DateTimeImmutable
    {
        return self::parseAt($date, $time, $timezone);
    }

    /**
     * Resolve a timezone parameter the way `SystemClock` does: an instance
     * passes through, `null` means the PHP default timezone and an empty
     * string falls back to UTC.
     *
     * @throws DateInvalidTimeZoneException When the timezone string is unknown
     */
    public static function timezone(DateTimeZone|string|null $timezone = null): DateTimeZone
    {
        $timezone ??= date_default_timezone_get();

        if ($timezone instanceof DateTimeZone) {
            return $timezone;
        }

        // \Exception < PHP 8.3, \DateInvalidTimeZoneException >= PHP 8.3
        try {
            return new DateTimeZone($timezone === '' ? 'UTC' : $timezone);
        } catch (Throwable $throwable) {
            throw new DateInvalidTimeZoneException($throwable->getMessage(), intval($throwable->getCode()), $throwable);
        }
    }

    /**
     * @throws DateInvalidTimeZoneException When the timezone string is unknown
     */
    private static function parseDate(string $value, DateTimeZone|string|null $timezone): ?DateTimeImmutable
    {
        // Resolve first: an unknown timezone throws even for an invalid value
        $timezone = self::timezone($timezone);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!' . self::DATE_FORMAT, $value, $timezone);

        // The round trip rejects impossible dates; the midnight check rejects
        // days whose midnight never happened (DST gaps normalized by PHP)
        return $date !== false && $date->format(self::DATE_FORMAT) === $value && $date->format('H:i:s') === '00:00:00' ? $date : null;
    }

    /**
     * `YYYY-MM-DD[Tt]HH:MM:SS` with 1 to 6 fractional digits and `[Zz]` or
     * `±HH:MM`. Seconds run `00`–`59` and the offset hour `00`–`23`; anything
     * outside (leap seconds, unknown `-00:00` offset) is rejected.
     */
    private static function parseInstant(string $value): ?DateTimeImmutable
    {
        if (
            preg_match(
                '/^(\d{4})-(\d{2})-(\d{2})[tT](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,6}))?([zZ]|[+-](?:[01]\d|2[0-3]):[0-5]\d)$/D',
                $value,
                $m,
            ) !== 1
        ) {
            return null;
        }

        $offset = strtoupper($m[8]) === 'Z' ? '+00:00' : $m[8];
        $head = "{$m[1]}-{$m[2]}-{$m[3]}T{$m[4]}:{$m[5]}:{$m[6]}";
        $fraction = $m[7];

        if ($fraction === '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', "{$head}{$offset}");

            return $date !== false && $date->format('Y-m-d\TH:i:sP') === "{$head}{$offset}" ? $date : null;
        }

        $fraction = str_pad($fraction, 6, '0');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s.uP', "{$head}.{$fraction}{$offset}");

        return $date !== false && $date->format('Y-m-d\TH:i:s.uP') === "{$head}.{$fraction}{$offset}" ? $date : null;
    }

    /**
     * @throws DateInvalidTimeZoneException When the timezone string is unknown
     */
    private static function parseAt(string $date, string $time, DateTimeZone|string|null $timezone): ?DateTimeImmutable
    {
        // Resolve first: an unknown timezone throws even for an invalid pair
        $timezone = self::timezone($timezone);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) !== 1 || !self::isTime($time)) {
            return null;
        }

        $time = strlen($time) === 5 ? $time . ':00' : $time;
        $candidate = "{$date} {$time}";

        $format = '!' . self::DATE_FORMAT . ' ' . self::TIME_FORMAT;
        $at = DateTimeImmutable::createFromFormat($format, $candidate, $timezone);

        return $at !== false && $at->format(self::DATE_FORMAT . ' ' . self::TIME_FORMAT) === $candidate ? $at : null;
    }
}
