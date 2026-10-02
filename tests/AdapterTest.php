<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Tpl\ViewEngine;
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
