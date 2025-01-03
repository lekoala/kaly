<?php

declare(strict_types=1);

namespace Kaly\Util;

use Exception;
use DateTime;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * @link https://github.com/nette/utils/blob/master/src/Utils/DateTime.php
 */
final class Date
{
    /**
     * Just like strtotime() but you can pass a string as a timestamp
     * @param int|string $datetime
     * @param int|string|null $baseTimestamp
     */
    public static function time($datetime, $baseTimestamp = null): ?int
    {
        if (is_int($datetime)) {
            $datetime = date('Y-m-d H:i:s', $datetime);
        }
        if (is_string($baseTimestamp)) {
            $baseTimestamp = strtotime($baseTimestamp);
            if (!$baseTimestamp) {
                return null;
            }
        }
        $r = strtotime($datetime, $baseTimestamp);
        if ($r === false) {
            return null;
        }
        return $r;
    }

    /**
     * Just like date() but you can pass a string as a timestamp
     * @param string $format
     * @param string|int|false|null $timestamp
     * @return string
     */
    public static function format(string $format = 'Y-m-d H:i:s', $timestamp = null): string
    {
        if ($timestamp === false) {
            throw new Exception("safe_date received an invalid timestamp");
        }
        if (is_string($timestamp)) {
            $timestamp = self::time($timestamp);
        }
        $r = date($format, $timestamp);
        return $r;
    }

    /**
     * Get the number of days between two dates
     *
     * @param string $start
     * @param string $end
     * @return int
     */
    public static function daysDifference(string $start, string $end): int
    {
        $diff = self::diff($start, $end);
        $days = $diff->format('%r%a');
        return intval($days);
    }

    public static function diff(string $start, string $end): DateInterval
    {
        $s = new DateTime($start);
        $e = new DateTime($end);
        return $s->diff($e);
    }

    public static function age(?string $date): string
    {
        if (!$date) {
            return '-';
        }
        try {
            $date = new DateTime($date);
            $now = new DateTime();
            $interval = $now->diff($date);
            if ($interval === false) {
                return '-';
            }
            return (string)$interval->y;
        } catch (Exception) {
            return '-';
        }
    }

    /**
     * Get all days between two dates
     * @return string[]
     */
    public static function range(?string $start, ?string $end, string $format = 'Y-m-d'): array
    {
        if (!$start || !$end) {
            return [];
        }

        $array = [];

        // Variable that store the date interval of period 1 day
        $interval = new DateInterval('P1D');

        $realEnd = new DateTime($end);
        $realEnd->add($interval);
        $period = new DatePeriod(new DateTime($start), $interval, $realEnd);
        foreach ($period as $date) {
            $array[] = $date->format($format);
        }
        return $array;
    }


    public static function convertToDateObject(string|DateTimeInterface|null $v): ?DateTimeImmutable
    {
        if (!$v) {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            if ($v instanceof DateTimeImmutable) {
                return $v;
            }
            $v = $v->format('Y-m-d H:i:s');
        }
        if (str_contains($v, '/')) {
            $v = implode('-', array_reverse(explode('/', $v)));
        }
        if (strlen($v) === 10) {
            $v .= ' 00:00:00';
        }
        $date = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $v);
        return $date ?: null;
    }

    public static function convertToDateString(string|DateTimeInterface|null $v, ?string $format = null): ?string
    {
        $format ??= 'Y-m-d H:i:s';
        if (!$v) {
            return null;
        }
        if (is_string($v)) {
            return $v;
        }
        return $v->format($format);
    }
}
