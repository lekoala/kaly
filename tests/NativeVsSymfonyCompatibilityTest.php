<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\I18n\Adapter\SymfonyTranslator;
use Kaly\I18n\Translator;
use Kaly\I18n\TranslatorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Translation\Loader\PhpFileLoader;
use Symfony\Component\Translation\Translator as SymfonyEngine;

/**
 * The portable contract, as an executable test: for every declared portable
 * behaviour both engines produce the same result from the same files.
 *
 * Deliberately excluded as engine-specific: plural and ICU syntax, global
 * parameters, TranslatableInterface parameters and fallback chains.
 */
class NativeVsSymfonyCompatibilityTest extends TestCase
{
    private function native(): TranslatorInterface
    {
        $translator = new Translator('en');
        $translator->addPath(__DIR__ . '/data/lang-compat');
        return $translator;
    }

    private function symfony(): TranslatorInterface
    {
        $engine = new SymfonyEngine('en');
        $engine->addLoader('php', new PhpFileLoader());
        $engine->setFallbackLocales(['en']);
        $dir = __DIR__ . '/data/lang-compat';
        foreach (['en', 'fr'] as $locale) {
            $engine->addResource('php', "{$dir}/messages.{$locale}.php", $locale);
            $engine->addResource('php', "{$dir}/notifications.{$locale}.php", $locale, 'notifications');
        }
        return new SymfonyTranslator($engine);
    }

    /**
     * @return iterable<string,array{0:string,1:array<string,mixed>,2:?string,3:string}>
     */
    public static function portableCases(): iterable
    {
        yield 'flat id' => ['standalone.flat', [], null, 'en'];
        yield 'flat id fr' => ['standalone.flat', [], null, 'fr'];
        yield 'nested id' => ['compat.nested.deep', [], null, 'en'];
        yield 'nested id fr' => ['compat.nested.deep', [], null, 'fr'];
        yield 'nested branch' => ['compat.flat', [], null, 'fr'];
        yield 'explicit domain' => ['welcome', ['%name%' => 'Thomas', '{count}' => '3'], 'notifications', 'en'];
        yield 'explicit domain fr' => ['welcome', ['%name%' => 'Thomas', '{count}' => '3'], 'notifications', 'fr'];
        yield 'percent parameter' => ['compat.percent', ['%name%' => 'Thomas'], null, 'en'];
        yield 'braces parameter' => ['compat.braces', ['{name}' => 'Thomas'], null, 'fr'];
        yield 'hash parameter' => ['compat.hash', ['#name#' => 'Thomas'], null, 'en'];
        yield 'mixed parameters' => ['compat.mixed', ['%name%' => 'Thomas', '{name}' => 'Tom'], null, 'fr'];
        yield 'empty id' => ['', [], null, 'en'];
        yield 'empty translation' => ['empty_translation', [], null, 'en'];
        yield 'zero translation' => ['zero_translation', [], null, 'fr'];
        yield 'null translation means absent' => ['null_translation', [], null, 'en'];
        yield 'missing id' => ['compat.unknown', [], null, 'fr'];
        yield 'missing id with parameters' => ['Hello %name%', ['%name%' => 'Thomas'], null, 'fr'];
        yield 'missing id in explicit domain' => ['unknown', [], 'notifications', 'en'];
    }

    /**
     * @param array<string,mixed> $parameters
     */
    #[DataProvider('portableCases')]
    public function testNativeMatchesSymfony(string $id, array $parameters, ?string $domain, string $locale): void
    {
        $this->assertSame(
            $this->symfony()->translate($id, $parameters, $domain, $locale),
            $this->native()->translate($id, $parameters, $domain, $locale),
        );
    }
}
