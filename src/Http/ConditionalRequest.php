<?php

declare(strict_types=1);

namespace Kaly\Http;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Cache revalidation of an existing, selected and authorized representation.
 *
 * The representation must otherwise produce a 200 response. This primitive
 * neither generates validators nor evaluates writing preconditions.
 *
 * @link https://www.rfc-editor.org/rfc/rfc9110.html#section-13
 */
final readonly class ConditionalRequest
{
    private const ETAG = '(?:W\/)?"[\x21\x23-\x7E\x80-\xFF]*"';
    private const SHORT_DAY = '(Mon|Tue|Wed|Thu|Fri|Sat|Sun)';
    private const LONG_DAY = '(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)';
    private const MONTH = '(Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)';
    private const TIME = '((?:[01][0-9]|2[0-3]):[0-5][0-9]:(?:[0-5][0-9]|60))';

    public function __construct(
        private ClockInterface $clock,
    ) {}

    /**
     * Whether GET/HEAD may return 304 for a representation that otherwise returns 200.
     *
     * $etag is a complete HTTP entity tag, including quotes and optional W/.
     * Invalid request validators never match. If-None-Match's presence suppresses
     * date revalidation even when malformed or no current ETag is supplied.
     * If-Match and If-Unmodified-Since must be evaluated separately and removed
     * from a request copy after success before invoking this helper.
     */
    public function isNotModified(RequestInterface $request, ?string $etag = null, ?DateTimeInterface $lastModified = null): bool
    {
        if (
            !in_array($request->getMethod(), ['GET', 'HEAD'], true)
            || $request->hasHeader('If-Match')
            || $request->hasHeader('If-Unmodified-Since')
        ) {
            return false;
        }
        if ($request->hasHeader('If-None-Match')) {
            return self::matchesEtag($request->getHeaderLine('If-None-Match'), $etag);
        }
        if ($lastModified === null || !$request->hasHeader('If-Modified-Since')) {
            return false;
        }
        $values = $request->getHeader('If-Modified-Since');
        if (count($values) !== 1) {
            return false;
        }
        $date = $this->parseDate($values[0]);

        return $date !== null && $lastModified->getTimestamp() <= $date->getTimestamp();
    }

    /**
     * Validate the entire list before accepting a match. Quotes delimit opaque
     * tags, not escaped strings: commas and backslashes inside are ordinary bytes.
     */
    private static function matchesEtag(string $value, ?string $etag): bool
    {
        $value = trim($value, " \t");
        if ($value === '*') {
            return true;
        }
        if ($etag === null || preg_match('/\A' . self::ETAG . '\z/', $etag) !== 1) {
            return false;
        }

        $matched = false;
        $offset = 0;
        $length = strlen($value);
        $opaque = str_starts_with($etag, 'W/') ? substr($etag, 2) : $etag;
        while ($offset < $length) {
            // RFC list recipients tolerate empty members.
            if (str_contains(" \t,", $value[$offset])) {
                ++$offset;
                continue;
            }
            if (preg_match('/\G(' . self::ETAG . ')/', $value, $parts, 0, $offset) !== 1) {
                return false;
            }
            $tag = $parts[1];
            $matched = $matched || (str_starts_with($tag, 'W/') ? substr($tag, 2) : $tag) === $opaque;
            $offset += strlen($tag);
            while ($offset < $length && str_contains(" \t", $value[$offset])) {
                ++$offset;
            }
            if ($offset < $length && $value[$offset] !== ',') {
                return false;
            }
        }

        return $matched;
    }

    /**
     * Parse only HTTP's three date grammars, with calendar and weekday checks.
     * The injected clock controls RFC 850's rolling 50-year window.
     */
    private function parseDate(string $value): ?DateTimeImmutable
    {
        $value = trim($value, " \t");
        $reference = null;

        if (
            preg_match('/\A' . self::SHORT_DAY . ', ([0-9]{2}) ' . self::MONTH . ' ([0-9]{4}) ' . self::TIME . ' GMT\z/', $value, $m) === 1
        ) {
            [, $weekday, $day, $monthName, $year, $timeOfDay] = $m;
        } elseif (
            preg_match('/\A' . self::LONG_DAY . ', ([0-9]{2})-' . self::MONTH . '-([0-9]{2}) ' . self::TIME . ' GMT\z/', $value, $m) === 1
        ) {
            [, $weekday, $day, $monthName, $year, $timeOfDay] = $m;
            $reference = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
            $year = (string) ((intdiv((int) $reference->format('Y'), 100) * 100) + (int) $year);
        } elseif (
            preg_match('/\A' . self::SHORT_DAY . ' ' . self::MONTH . ' ([0-9]{2}| [1-9]) ' . self::TIME . ' ([0-9]{4})\z/', $value, $m)
            === 1
        ) {
            [, $weekday, $monthName, $day, $timeOfDay, $year] = $m;
        } else {
            return null;
        }

        $leapSecond = str_ends_with($timeOfDay, ':60');
        $calendarTime = $leapSecond ? substr($timeOfDay, 0, 6) . '59' : $timeOfDay;
        $date = self::calendarDate((int) $year, $monthName, (int) $day, $calendarTime);
        if ($date === null) {
            return null;
        }
        $instant = $leapSecond ? $date->modify('+1 second') : $date;
        if ($reference !== null && $instant > $reference->modify('+50 years')) {
            $date = self::calendarDate((int) $year - 100, $monthName, (int) $day, $calendarTime);
            if ($date === null) {
                return null;
            }
        }
        if ($date->format(strlen($weekday) === 3 ? 'D' : 'l') !== $weekday) {
            return null;
        }

        return $leapSecond ? $date->modify('+1 second') : $date;
    }

    private static function calendarDate(int $year, string $month, int $day, string $time): ?DateTimeImmutable
    {
        if ($year < 1) {
            return null;
        }
        $calendar = sprintf('%04d-%s-%02d %s', $year, $month, $day, $time);
        $date = DateTimeImmutable::createFromFormat('!Y-M-d H:i:s', $calendar, new DateTimeZone('UTC'));

        return $date !== false && $date->format('Y-M-d H:i:s') === $calendar ? $date : null;
    }
}
