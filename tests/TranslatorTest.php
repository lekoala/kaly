<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Translator;
use PHPUnit\Framework\TestCase;

class TranslatorTest extends TestCase
{
    public function testTranslate()
    {
        $translator = new Translator('en', 'en');
        $translator->addPath(__DIR__ . "/data/lang");
        $result = $translator->translate("global.test");
        $this->assertEquals("Test message", $result);
        $result = $translator->translate("Welcome", ["name" => "Test"]);
        $this->assertEquals("Welcome to this app Test", $result);

        $translator->setCurrentLocale("fr");
        $result = $translator->translate("global.test");
        $this->assertEquals("Message de test", $result);

        // Check that we fallback to lang if locale is not found
        $translator->setCurrentLocale("fr_FR");
        $result = $translator->translate("global.test");
        $this->assertEquals("Message de test", $result);

        // Check that we fallback to default if not found
        $result = $translator->translate("NotTranslated", ['str' => "test"]);
        $this->assertEquals("This is not translated for test", $result);
    }

    public function testPlural()
    {
        $translator = new Translator('en', 'en');
        $translator->addPath(__DIR__ . "/data/lang");
        $result = $translator->translate("apples", ["%count%" => 0]);
        $this->assertEquals("%name% has no apples", $result);
        $result = $translator->translate("apples", ["%count%" => 1]);
        $this->assertEquals("%name% has one apple", $result);
        $result = $translator->translate("apples", ["%count%" => 2]);
        $this->assertEquals("%name% has 2 apples", $result);

        $result = $translator->translate("easy_apples", ["%count%" => 1]);
        $this->assertEquals("an apple", $result);
        $result = $translator->translate("easy_apples", ["%count%" => 2]);
        $this->assertEquals("2 apples", $result);

        $result = $translator->translate("utf8plural", ["%count%" => 0]);
        $this->assertEquals("öëBC", $result);
        $result = $translator->translate("utf8plural", ["%count%" => 1]);
        $this->assertEquals("öëBC", $result);
        $result = $translator->translate("utf8plural", ["%count%" => 2]);
        $this->assertEquals("öëBC", $result);
    }

    public function testParse()
    {
        $arr = [
            'en' => [
                'language' => 'en',
                'script' => '',
                'country' => '',
                'private' => '',
            ],
            'en-US' =>  [
                'language' => 'en',
                'script' => '',
                'country' => 'US',
                'private' => '',
            ],
            'en_US' =>  [
                'language' => 'en',
                'script' => '',
                'country' => 'US',
                'private' => '',
            ],
            'zh-Hant-TW' =>  [
                'language' => 'zh',
                'script' => 'Hant',
                'country' => 'TW',
                'private' => '',
            ],
            'de-DE-x-goethe' =>  [
                'language' => 'de',
                'script' => '',
                'country' => 'DE',
                'private' => 'goethe',
            ],
            'agq_CM' =>  [
                'language' => 'agq',
                'script' => '',
                'country' => 'CM',
                'private' => '',
            ],
        ];
        foreach ($arr as $input => $output) {
            $this->assertEquals($output, Translator::parseLocale($input), "Failed for $input");
        }
    }

    public function testLangFromLocale()
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
            $this->assertEquals($output, Translator::getLangFromLocale($input), "Failed for $input");
        }
    }
}
