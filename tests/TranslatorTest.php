<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translator;
use PHPUnit\Framework\TestCase;

class TranslatorTest extends TestCase
{
    public function testTranslate(): void
    {
        $translator = new Translator('en');
        $translator->addPath(__DIR__ . '/data/lang');
        $result = $translator->translate('global.test');
        $this->assertEquals('Test message', $result);
        $result = $translator->translate('Welcome', ['{name}' => 'Test']);
        $this->assertEquals('Welcome to this app Test', $result);

        // Bare keys are replaced as is, even inside delimiters: pass exact
        // placeholders instead, exactly like Symfony
        $result = $translator->translate('Welcome', ['name' => 'Test']);
        $this->assertEquals('Welcome to this app {Test}', $result);

        $result = $translator->translate('global.test', [], null, 'fr');
        $this->assertEquals('Message de test', $result);

        // Check that we fallback to lang if locale is not found
        $result = $translator->translate('global.test', [], null, 'fr_FR');
        $this->assertEquals('Message de test', $result);

        // Check that we fallback to default if not found
        $result = $translator->translate('NotTranslated', ['{str}' => 'test'], null, 'fr_FR');
        $this->assertEquals('This is not translated for test', $result);

        // A missing key returns the id itself, formatted with the parameters
        $result = $translator->translate('not_found');
        $this->assertEquals('not_found', $result);
        $result = $translator->translate('Hello %name%', ['%name%' => 'Thomas']);
        $this->assertEquals('Hello Thomas', $result);

        // An empty id returns an empty string, like Symfony
        $this->assertSame('', $translator->translate(''));

        // Empty and zero translations are valid, null means absent
        $this->assertSame('', $translator->translate('empty_translation'));
        $this->assertSame('0', $translator->translate('zero_translation'));
        $this->assertSame('null_translation', $translator->translate('null_translation'));

        $result = $translator->translate('Welcome', [], 'test');
        $this->assertEquals('Welcome to this domain test', $result);
    }

    public function testFlatAndNestedCatalogs(): void
    {
        $translator = new Translator('en');
        $translator->addToCatalog('messages', 'en', [
            'hello.world' => 'Hello %name%',
            'hello' => ['other' => 'Nested other'],
            'flat.only' => 'Flat only',
        ]);
        $translator->addToCatalog('messages', 'fr', ['hello.world' => 'Bonjour %name%']);

        $this->assertSame('Hello Alice', $translator->translate('hello.world', ['%name%' => 'Alice']));
        $this->assertSame('Nested other', $translator->translate('hello.other'));
        $this->assertSame('Bonjour Alice', $translator->translate('hello.world', ['%name%' => 'Alice'], locale: 'fr_FR'));
        $this->assertSame('Flat only', $translator->translate('flat.only', locale: 'fr'));
        $this->assertSame('flat.missing', $translator->translate('flat.missing'));
    }

    public function testAmbiguousFlatAndNestedIdsAreRejected(): void
    {
        $translator = new Translator('en');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Ambiguous translation id 'hello.world' in 'added catalog'");
        $translator->addToCatalog('messages', 'en', [
            'hello.world' => 'Hello %name%',
            'hello' => ['world' => 'Nested hello'],
        ]);
    }

    public function testLaterPathsMergeKeyByKey(): void
    {
        $translator = new Translator('en');
        $translator->addPath(__DIR__ . '/data/lang-overrides/first');
        $translator->addPath(__DIR__ . '/data/lang-overrides/second');

        // The second path overrides 'shared' without dropping 'first.only'
        $this->assertSame('Second wins', $translator->translate('shared'));
        $this->assertSame('First only', $translator->translate('first.only'));
        $this->assertSame('Second only', $translator->translate('second.only'));
    }

    public function testLocalizedTranslatorDoesNotLeakBetweenRenders(): void
    {
        $translator = new Translator('en');
        $translator->addPath(__DIR__ . '/data/lang');

        $fr = new LocalizedTranslator($translator, 'fr');
        $en = new LocalizedTranslator($translator, 'en');

        $this->assertSame('fr', $fr->locale());
        $this->assertEquals('Message de test', $fr->translate('global.test'));
        $this->assertEquals('Test message', $en->translate('global.test'));
        // The shared engine still answers with its own default locale
        $this->assertEquals('Test message', $translator->translate('global.test'));

        // An explicit locale always wins over the bound one
        $this->assertEquals('Test message', $fr->translate('global.test', [], null, 'en'));

        // withLocale is immutable
        $this->assertSame($fr, $fr->withLocale('fr'));
        $this->assertNotSame($fr, $fr->withLocale('en'));
        $this->assertEquals('Test message', $fr->withLocale('en')->translate('global.test'));
    }
}
