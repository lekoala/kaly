<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Http\Exception\NotFoundException;
use Kaly\I18n\LocalizedTranslator;
use Kaly\I18n\Translator;
use Kaly\Tpl\ValueFormatter;
use Kaly\Tpl\ViewEngine;
use Kaly\Tpl\ViewException;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\Adapter\LatteRenderer;
use Kaly\View\Adapter\TwigRenderer;
use Latte\Engine as LatteEngine;
use Latte\Loaders\FileLoader;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class AdapterTest extends TestCase
{
    public function testKalyTplRenderer(): void
    {
        $renderer = new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl'));

        $this->assertTrue($renderer->has('hello'));
        $this->assertSame('Hello World', $renderer->render('hello', ['name' => 'World']));
    }

    public function testKalyTplSharedDataWinsOverPageLocals(): void
    {
        $renderer = new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl'));

        $this->assertSame('Hello Shared', $renderer->render('hello', ['name' => 'Page'], ['name' => 'Shared']));
        $this->assertSame('Hello Page', $renderer->render('hello', ['name' => 'Page']));
    }

    public function testKalyTplFormatterForDerivesTheFormatterFromTheRenderLocale(): void
    {
        $engine = new ViewEngine(__DIR__ . '/adapters/kaly-tpl');
        $engine->formatter(new TaggedFormatter('engine'));
        $formatters = ['fr' => new TaggedFormatter('fr')];

        $bridged = new KalyTplRenderer($engine, formatterFor: fn(string $locale): ?ValueFormatter => $formatters[$locale] ?? null);
        $fr = ['i18n' => new LocalizedTranslator(new Translator('en'), 'fr')];
        $de = ['i18n' => new LocalizedTranslator(new Translator('en'), 'de')];

        // The locale of the i18n capability selects the formatter of this render
        $this->assertSame('fr:date', trim($bridged->render('date', ['when' => '2026-11-18'], $fr)));
        // Without the callback, the engine default formats the render
        $this->assertSame('engine:date', trim((new KalyTplRenderer($engine))->render('date', ['when' => '2026-11-18'], $fr)));
        // An unknown locale falls back to the engine default
        $this->assertSame('engine:date', trim($bridged->render('date', ['when' => '2026-11-18'], $de)));
        // A shared bag without a localized translator keeps the engine default
        $this->assertSame('engine:date', trim($bridged->render('date', ['when' => '2026-11-18'], ['i18n' => 'plain'])));
    }

    public function testKalyTplSurfacesHttpFailuresThrownByTemplates(): void
    {
        $renderer = new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl'));

        // kaly-tpl wraps every application exception; a deliberate
        // client-facing failure still answers with the status it chose
        try {
            $renderer->render('throws404');
            $this->fail('A 404 was expected');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->status());
        }
    }

    public function testKalyTplKeepsOtherTemplateFailuresWrapped(): void
    {
        $renderer = new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl'));

        try {
            $renderer->render('throws');
            $this->fail('A render failure was expected');
        } catch (ViewException $e) {
            $this->assertInstanceOf(\DomainException::class, $e->getPrevious());
            $this->assertStringContainsString('template failure', $e->getMessage());
        }
    }

    public function testLatteRendererResolvesModuleNamespaces(): void
    {
        $engine = new LatteEngine();
        $engine->setLoader(new FileLoader(__DIR__ . '/adapters/latte'));

        $renderer = new LatteRenderer($engine);
        $renderer->setPath('Mail', __DIR__ . '/adapters/latte-ns');

        // Plain names still flow to the engine loader
        $this->assertTrue($renderer->has('hello.latte'));
        $this->assertSame('Hello World', $renderer->render('hello.latte', ['name' => 'World']));

        $this->assertTrue($renderer->has('@Mail/hello.latte'));
        $this->assertSame('Hello World!', $renderer->render('@Mail/hello.latte', ['name' => 'World']));
        $this->assertFalse($renderer->has('@Mail/missing.latte'));
        $this->assertFalse($renderer->has('@Unknown/hello.latte'));
    }

    public function testTwigRendererResolvesModuleNamespaces(): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/adapters/twig'));

        $renderer = new TwigRenderer($twig);
        $renderer->setPath('Mail', __DIR__ . '/adapters/twig-ns');

        $this->assertTrue($renderer->has('hello.twig'));
        $this->assertSame('Hello World', $renderer->render('hello.twig', ['name' => 'World']));

        $this->assertTrue($renderer->has('@Mail/hello.twig'));
        $this->assertSame('Hello World!', $renderer->render('@Mail/hello.twig', ['name' => 'World']));
        $this->assertFalse($renderer->has('@Mail/missing.twig'));
    }
}

/** A formatter whose every value names its tag, so a test can tell formatters apart. */
final class TaggedFormatter implements ValueFormatter
{
    public function __construct(
        private readonly string $tag,
    ) {}

    public function date(\DateTimeInterface|string|null $value, string $style = 'medium'): string
    {
        return $this->tag . ':date';
    }

    public function time(\DateTimeInterface|string|null $value, string $style = 'short'): string
    {
        return $this->tag . ':time';
    }

    public function datetime(\DateTimeInterface|string|null $value, string $style = 'medium'): string
    {
        return $this->tag . ':datetime';
    }

    public function money(int|float|string|null $amount, ?string $currency = null): string
    {
        return $this->tag . ':money';
    }

    public function number(int|float|string|null $value, int $decimals = 0): string
    {
        return $this->tag . ':number';
    }

    public function percent(int|float|string|null $value, int $decimals = 0): string
    {
        return $this->tag . ':percent';
    }

    public function duration(\DateInterval|int|float|string|null $value, string $style = 'short'): string
    {
        return $this->tag . ':duration';
    }
}
