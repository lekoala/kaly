<?php

declare(strict_types=1);

namespace Kaly\Tests;

use Kaly\Ex;
use Kaly\View\Adapter\TwigRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Error\RuntimeError;
use Twig\Extension\AbstractExtension;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The Twig-side sugar: idiomatic helpers, explicit failures when a capability
 * is absent, idempotent installation and conflict detection.
 */
class TwigExtensionTest extends TestCase
{
    use RenderEnvironmentFactory;

    private function twig(): Environment
    {
        return new Environment(new FilesystemLoader(__DIR__ . '/adapters/twig/extension'));
    }

    public function testIdiomaticHelpersRender(): void
    {
        $renderer = new TwigRenderer($this->twig());

        $this->assertSame(
            'Message de test:fr:home:/assets/app.css',
            trim($renderer->render('uses.twig', [], $this->renderEnvironment('fr'))),
        );
    }

    public function testMissingCapabilityFailsExplicitly(): void
    {
        $renderer = new TwigRenderer($this->twig());

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage("The trans filter requires the 'i18n' render capability");
        $renderer->render('uses.twig');
    }

    public function testIncludeOnlyWithoutCapabilitiesFailsExplicitly(): void
    {
        $renderer = new TwigRenderer($this->twig());

        $this->expectException(RuntimeError::class);
        $this->expectExceptionMessage("The url() function requires the 'url' render capability");
        $renderer->render('include.twig', [], $this->renderEnvironment('fr'));
    }

    public function testIncludeOnlyWithCapabilitiesWorks(): void
    {
        $renderer = new TwigRenderer($this->twig());

        $this->assertSame('fr:home', trim($renderer->render('include_with.twig', [], $this->renderEnvironment('fr'))));
    }

    public function testInstallingTwiceIsIdempotent(): void
    {
        $twig = $this->twig();
        new TwigRenderer($twig);
        $renderer = new TwigRenderer($twig);

        $this->assertSame(
            'Message de test:fr:home:/assets/app.css',
            trim($renderer->render('uses.twig', [], $this->renderEnvironment('fr'))),
        );
    }

    public function testConflictingFilterIsRejected(): void
    {
        $twig = $this->twig();
        $twig->addExtension(new class extends AbstractExtension {
            /**
             * @return list<TwigFilter>
             */
            public function getFilters(): array
            {
                return [new TwigFilter('trans', static fn(string $s): string => $s)];
            }
        });

        $this->expectException(Ex::class);
        $this->expectExceptionMessage('trans');
        new TwigRenderer($twig);
    }

    public function testConflictingFunctionIsRejected(): void
    {
        $twig = $this->twig();
        $twig->addExtension(new class extends AbstractExtension {
            /**
             * @return list<TwigFunction>
             */
            public function getFunctions(): array
            {
                return [new TwigFunction('url', static fn(string $s): string => $s)];
            }
        });

        $this->expectException(Ex::class);
        $this->expectExceptionMessage('url');
        new TwigRenderer($twig);
    }

    /**
     * @return array<string,array{0: TwigFunction|TwigFilter, 1: string}>
     */
    public static function directlyAddedHelpers(): array
    {
        return [
            'url function' => [new TwigFunction('url', static fn(string $s): string => $s), 'url'],
            'asset function' => [new TwigFunction('asset', static fn(string $s): string => $s), 'asset'],
            'trans filter' => [new TwigFilter('trans', static fn(string $s): string => $s), 'trans'],
        ];
    }

    #[DataProvider('directlyAddedHelpers')]
    public function testDirectlyAddedHelperIsRejected(TwigFunction|TwigFilter $helper, string $name): void
    {
        $twig = $this->twig();
        if ($helper instanceof TwigFunction) {
            $twig->addFunction($helper);
        } else {
            $twig->addFilter($helper);
        }

        $this->expectException(Ex::class);
        $this->expectExceptionMessage($name);
        new TwigRenderer($twig);
    }

    public function testUnrelatedDirectCallableDoesNotBlockInstallation(): void
    {
        $twig = $this->twig();
        $twig->addFunction(new TwigFunction('greet', static fn(): string => 'hi'));
        $renderer = new TwigRenderer($twig);

        $this->assertSame(
            'Message de test:fr:home:/assets/app.css',
            trim($renderer->render('uses.twig', [], $this->renderEnvironment('fr'))),
        );
    }

    public function testAlreadyInitializedEnvironmentIsRejected(): void
    {
        $twig = $this->twig();
        // Rendering a template initializes the Twig ExtensionSet
        $twig->render('hello.twig');

        $this->expectException(Ex::class);
        new TwigRenderer($twig);
    }
}
