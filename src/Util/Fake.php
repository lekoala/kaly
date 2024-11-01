<?php

declare(strict_types=1);

namespace LeKoala;

/**
 * Generate fake stuff
 */
class Fake
{
    private const WORDS = [
        'cupiditate',
        'praesentium',
        'voluptas',
        'pariatur',
        'cum',
        'lorem',
        'ipsum',
        'loquor',
        'sic',
        'amet'
    ];
    private const FN = ['Julia', 'Lucius', 'Julius', 'Anna'];
    private const SN = ['Maximus', 'Corneli', 'Postumius', 'Servilius'];
    private const COUNTRIES = ['US', 'NZ', 'FR', 'BE', 'NL', 'IT', 'UK'];
    private const CITIES = ['Roma', 'Caesera', 'Florentia', 'Lutetia'];
    private const LOCALES = ['fr_FR', 'fr_BE', 'nl_BE', 'nl_NL', 'en_US', 'en_NZ', 'en_UK', 'it_IT'];

    /**
     * Pick randomly between two strings
     * @param string $a
     * @param string $b
     * @return string
     */
    public static function pick(string $a, string $b): string
    {
        return random_int(0, 1) === 1 ? $a : $b;
    }

    /**
     * Pick randomly a set of items in an array
     * @param array<mixed> $arr
     * @param int $c
     * @return array<mixed>
     */
    public static function picka(array $arr, int $c = 1): array
    {
        $r = [];
        while ($c > 0) {
            $c--;
            $r[] = $arr[array_rand($arr)];
        }
        return $r;
    }

    /**
     * Pick a random date
     * @param int $count
     * @param string|null $sign
     * @return string
     */
    public static function date(int $count = 365, ?string $sign = null): string
    {
        $sign = $sign ?? self::pick('+', '-');
        return date('Y-m-d', strtotime($sign . random_int(1, $count) . ' days') ?: null);
    }

    /**
     * Pick a random time
     * @return string
     */
    public static function time(): string
    {
        return sprintf('%02d:%02d:%02d', random_int(0, 23), random_int(0, 59), random_int(0, 59));
    }

    public static function datetime(): string
    {
        return self::date() . ' ' . self::time();
    }

    public static function datetimez(): string
    {
        return self::date() . 'T' . self::time() . 'Z';
    }

    /**
     * A random int
     * @param int $a
     * @param int $b
     * @return int
     */
    public static function i(int $a = -100, int $b = 100): int
    {
        return random_int($a, $b);
    }

    public static function ctry(): string
    {
        return self::COUNTRIES[array_rand(self::COUNTRIES)];
    }

    public static function fn(): string
    {
        return self::FN[array_rand(self::FN)];
    }

    public static function sn(): string
    {
        return self::SN[array_rand(self::SN)];
    }

    public static function domain(): string
    {
        return self::WORDS[array_rand(self::WORDS)] . '.dev';
    }

    public static function words(int $a = 5, int $b = 10): string
    {
        return implode(' ', self::picka(self::WORDS, random_int($a, $b)));
    }

    public static function ucWords(int $a = 5, int $b = 10): string
    {
        return ucfirst(self::words($a, $b));
    }

    public static function b(): bool
    {
        return (bool)random_int(0, 1);
    }

    public static function city(): string
    {
        return self::CITIES[array_rand(self::CITIES)];
    }

    public static function addr(): string
    {
        return 'via ' . self::words(1, 1) . ', ' . self::i(1, 20) . ' - ' . self::i(1000, 9999) . ' ' . self::city();
    }

    public static function locale(?string $ctry = null): string
    {
        do {
            $l = self::LOCALES[array_rand(self::LOCALES)];
        } while ($ctry !== null && !str_contains($l, $ctry));
        return $l;
    }

    public static function lg(?string $ctry = null): string
    {
        return explode('_', self::locale($ctry))[0];
    }

    public static function money(int $a = 10_000, int $b = 100_000, ?string $currency = null): string
    {
        $currency = $currency ?? self::pick('€', '$');
        return number_format(self::i($a, $b)) . ' ' . $currency;
    }

    public static function email(?string $user = null): string
    {
        if ($user === null) {
            $user = self::fn();
        }
        return strtolower($user) . '@' . self::domain();
    }
}
