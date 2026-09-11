<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Text\Adapter\SymfonyTranslator;
use Kaly\Text\LocalizedTranslator;
use Kaly\Text\Translator;
use Kaly\Text\TranslatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator as SymfonyEngine;

class TranslatorAdapterTest extends TestCase
{
    private function symfony(): TranslatorInterface
    {
        $engine = new SymfonyEngine('en');
        $engine->addLoader('php', new PhpFileLoader());
        $engine->setFallbackLocales(['en']);
        foreach (['en', 'fr'] as $locale) {
            $engine->addResource('php', __DIR__ . "/data/lang/messages.{$locale}.php", $locale);
        }
        $engine->addResource('php', __DIR__ . '/data/lang/test.en.php', 'en', 'test');

        return new SymfonyTranslator($engine);
    }

    private function native(): TranslatorInterface
    {
        $translator = new Translator('en');
        $translator->addPath(__DIR__ . '/data/lang');
        return $translator;
    }

    /**
     * @return array<string,array{0:string}>
     */
    public static function implementations(): array
    {
        return [
            'native' => ['native'],
            'symfony' => ['symfony'],
        ];
    }

    /**
     * The portable baseline: ids, domains, parameters and explicit locales must
     * behave the same whichever engine is configured.
     */
    #[DataProvider('implementations')]
    public function testPortableBaseline(string $implementation): void
    {
        $translator = $implementation === 'native' ? $this->native() : $this->symfony();

        // Nested ids
        $this->assertSame('Test message', $translator->translate('global.test'));
        $this->assertSame('Message de test', $translator->translate('global.test', [], null, 'fr'));

        // Parameters
        $this->assertSame('Welcome to this app Test', $translator->translate('Welcome', ['{name}' => 'Test'], null, 'en'));

        // Domains
        $this->assertSame('Welcome to this domain test', $translator->translate('Welcome', [], 'test', 'en'));

        // A null locale means the default locale of the service, not a per request one
        $this->assertSame('Test message', $translator->translate('global.test'));
    }

    #[DataProvider('implementations')]
    public function testLocalizedTranslatorWorksWithBothEngines(string $implementation): void
    {
        $translator = $implementation === 'native' ? $this->native() : $this->symfony();

        $fr = new LocalizedTranslator($translator, 'fr');
        $en = new LocalizedTranslator($translator, 'en');

        // Two successive renders fr then en must not influence each other
        $this->assertSame('Message de test', $fr->translate('global.test'));
        $this->assertSame('Test message', $en->translate('global.test'));
        $this->assertSame('Message de test', $fr->translate('global.test'));

        // And the shared engine is untouched
        $this->assertSame('Test message', $translator->translate('global.test'));
    }

    /**
     * Engine specific behaviour that the contract deliberately does not unify.
     */
    public function testMissingKeysAreEngineSpecific(): void
    {
        $this->assertSame('{{not_found}}', $this->native()->translate('not_found'));
        $this->assertSame('not_found', $this->symfony()->translate('not_found'));
    }
}
