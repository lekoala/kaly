<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\I18n\Locale;
use PHPUnit\Framework\TestCase;

class LocaleTest extends TestCase
{
    public function testParse(): void
    {
        $arr = [
            'en' => [
                'language' => 'en',
                'script' => '',
                'country' => '',
                'private' => '',
            ],
            'en-US' => [
                'language' => 'en',
                'script' => '',
                'country' => 'US',
                'private' => '',
            ],
            'en_US' => [
                'language' => 'en',
                'script' => '',
                'country' => 'US',
                'private' => '',
            ],
            'zh-Hant-TW' => [
                'language' => 'zh',
                'script' => 'Hant',
                'country' => 'TW',
                'private' => '',
            ],
            'de-DE-x-goethe' => [
                'language' => 'de',
                'script' => '',
                'country' => 'DE',
                'private' => 'goethe',
            ],
            'agq_CM' => [
                'language' => 'agq',
                'script' => '',
                'country' => 'CM',
                'private' => '',
            ],
        ];
        foreach ($arr as $input => $output) {
            $this->assertEquals($output, Locale::parse($input), "Failed for {$input}");
        }
    }

    public function testLanguage(): void
    {
        $arr = [
            'en' => 'en',
            'en-US' => 'en',
            'en_US' => 'en',
            'zh-Hant-TW' => 'zh',
            'de-DE-x-goethe' => 'de',
            'DE' => 'de',
        ];
        foreach ($arr as $input => $output) {
            $this->assertEquals($output, Locale::language($input), "Failed for {$input}");
        }
    }

    public function testIsValid(): void
    {
        $this->assertTrue(Locale::isValid('en'));
        $this->assertTrue(Locale::isValid('zh-Hant-TW'));
        $this->assertFalse(Locale::isValid('!!'));
        $this->assertFalse(Locale::isValid(''));
    }
}
