<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Core\RenderEnvironment;
use Kaly\Ex;
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\Adapter\LatteRenderer;
use Kaly\View\Adapter\TwigRenderer;
use Kaly\View\RendererInterface;
use Latte\Engine as LatteEngine;
use Latte\Loaders\FileLoader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The behavioural guarantees every adapter must honour, independently of the
 * template syntax each engine exposes.
 */
class RendererContractTest extends TestCase
{
    use RenderEnvironmentFactory;

    /**
     * @return array<string,array{0: callable(): RendererInterface, 1: string}>
     */
    public static function renderers(): array
    {
        return [
            'kaly-tpl' => [
                static fn(): RendererInterface => new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl/contract')),
                'page',
            ],
            'twig' => [
                static fn(): RendererInterface => new TwigRenderer(new Environment(new FilesystemLoader(__DIR__
                . '/adapters/twig/contract'))),
                'page.twig',
            ],
            'latte' => [
                static function (): RendererInterface {
                    $engine = new LatteEngine();
                    $engine->setLoader(new FileLoader(__DIR__ . '/adapters/latte/contract'));

                    return new LatteRenderer($engine);
                },
                'page.latte',
            ],
        ];
    }

    /**
     * @param callable(): RendererInterface $factory
     */
    #[DataProvider('renderers')]
    public function testCapabilitiesReachTheTemplate(callable $factory, string $template): void
    {
        $renderer = $factory();

        $output = $renderer->render($template, ['pageLocal' => 'foo'], $this->renderEnvironment('fr'));

        $this->assertSame('caps:fr:Message de test:fr:home:/assets/app.css:no:csrf:nonce|page:foo', trim($output));
    }

    /**
     * @param callable(): RendererInterface $factory
     */
    #[DataProvider('renderers')]
    public function testEachRenderCarriesItsOwnCapabilities(callable $factory, string $template): void
    {
        $renderer = $factory();

        $this->assertStringContainsString('fr:Message de test', $renderer->render($template, [], $this->renderEnvironment('fr')));
        $this->assertStringContainsString('en:Test message', $renderer->render($template, [], $this->renderEnvironment('en')));
        // Back to fr: no residue of the previous render
        $this->assertStringContainsString('fr:Message de test', $renderer->render($template, [], $this->renderEnvironment('fr')));
    }

    /**
     * @param callable(): RendererInterface $factory
     */
    #[DataProvider('renderers')]
    public function testReservedNamesCollideBeforeTheEngine(callable $factory, string $template): void
    {
        $renderer = $factory();

        foreach (RenderEnvironment::RESERVED as $name) {
            try {
                $renderer->render($template, [$name => null], $this->renderEnvironment('fr'));
                $this->fail("Expected a collision error for '{$name}'");
            } catch (Ex $e) {
                $this->assertSame("View data cannot contain the reserved variable '{$name}'", $e->getMessage());
            }
        }
    }

    /**
     * @param callable(): RendererInterface $factory
     */
    #[DataProvider('renderers')]
    public function testWithoutEnvironmentReservedNamesAreOrdinaryData(callable $factory, string $template): void
    {
        $renderer = $factory();

        $plain = match (true) {
            str_ends_with($template, '.twig') => 'plain.twig',
            str_ends_with($template, '.latte') => 'plain.latte',
            default => 'plain',
        };

        // No environment: 'i18n' is a plain page local and no capability is injected
        $data = ['name' => 'World', 'i18n' => 'allowed', 'url' => 'also allowed'];
        $this->assertSame('World', trim($renderer->render($plain, $data)));
    }

    public function testKalyTplCapabilitiesReachPartialsAndLayouts(): void
    {
        $renderer = new KalyTplRenderer(new ViewEngine(__DIR__ . '/adapters/kaly-tpl/caps'));

        $body = $renderer->render('page', ['pageLocal' => 'foo'], $this->renderEnvironment('fr'));

        $this->assertStringContainsString('caps:Message de test:fr:home:/assets/app.css:no:csrf:ok', $body);
        $this->assertStringContainsString('page:foo', $body);
        $this->assertStringStartsWith('fr|', $body);
        $this->assertStringContainsString('layout-auth:no', $body);
        $this->assertStringContainsString('csp:nonce', $body);
    }
}
