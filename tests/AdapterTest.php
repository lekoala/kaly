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

    public function testLatteRenderer(): void
    {
        $engine = new LatteEngine();
        $engine->setLoader(new FileLoader(__DIR__ . '/adapters/latte'));

        $renderer = new LatteRenderer($engine);

        $this->assertTrue($renderer->has('hello.latte'));
        $this->assertSame('Hello World', $renderer->render('hello.latte', ['name' => 'World']));
    }

    public function testTwigRenderer(): void
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/adapters/twig'));

        $renderer = new TwigRenderer($twig);

        $this->assertTrue($renderer->has('hello.twig'));
        $this->assertSame('Hello World', $renderer->render('hello.twig', ['name' => 'World']));
    }
}
