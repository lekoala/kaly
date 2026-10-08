<?php

declare(strict_types=1);

namespace Kaly\View\Adapter;

use Kaly\Ex;
use Kaly\View\Adapter\Twig\KalyExtension;
use Kaly\View\RenderEnvironmentInterface;
use Kaly\View\RendererInterface;
use Kaly\View\TemplateLocatorInterface;
use Kaly\View\TemplatePathRegistryInterface;
use ReflectionException;
use ReflectionProperty;
use Twig\Environment;
use Twig\Extension\ExtensionInterface;
use Twig\Loader\FilesystemLoader;

/**
 * Bridges Twig to Kaly's renderer abstraction.
 *
 * Requires twig/twig (see composer "suggest"). Kaly never abstracts Twig
 * features (extensions, filters, sandbox): configure those on the environment,
 * completely, before wrapping it here. The wrapper installs the small Kaly
 * extension that exposes the render capabilities idiomatically; building the
 * renderer is the last step of the Twig configuration.
 */
final class TwigRenderer implements RendererInterface, TemplateLocatorInterface, TemplatePathRegistryInterface
{
    public function __construct(
        private readonly Environment $twig,
    ) {
        $this->installKalyExtension($twig);
    }

    /**
     * @param array<string,mixed> $data
     */
    public function render(string $template, array $data = [], ?RenderEnvironmentInterface $environment = null): string
    {
        if ($environment !== null) {
            $environment->assertCompatible($data);
        }

        return $this->twig->render($template, [
            ...$data,
            ...($environment?->variables() ?? []),
        ]);
    }

    public function has(string $template): bool
    {
        return $this->twig->getLoader()->exists($template);
    }

    /**
     * Install the Kaly extension once, without ever replacing an existing
     * helper: a conflicting trans/url/asset is a configuration error, not an
     * order to pick silently.
     */
    private function installKalyExtension(Environment $twig): void
    {
        foreach ($twig->getExtensions() as $extension) {
            if ($extension instanceof KalyExtension) {
                return;
            }
        }

        $this->assertNoHelperConflict($twig);

        try {
            $twig->addExtension(new KalyExtension());
        } catch (\LogicException $e) {
            throw new Ex('The Twig environment must be configured before it is wrapped by ' . self::class, 0, $e);
        }
    }

    private function assertNoHelperConflict(Environment $twig): void
    {
        $conflicts = $this->helperNames($twig);

        if ($conflicts !== []) {
            throw new Ex('The Twig environment already defines the reserved Kaly helper(s): ' . implode(', ', array_keys($conflicts)));
        }
    }

    /**
     * The reserved helper names already known to the environment, whether they
     * come from an extension or from a direct addFunction()/addFilter() call.
     *
     * @return array<string,true>
     */
    private function helperNames(Environment $twig): array
    {
        $conflicts = [];

        foreach ($twig->getExtensions() as $extension) {
            $this->collectHelperNames($extension, $conflicts);
        }

        // Twig keeps direct addFunction()/addFilter() calls in a private staging
        // area instead of getExtensions(), and has no public way to read them
        // without initializing the environment (which would forbid adding the
        // extension). Read it so such a helper is never replaced silently.
        $staging = $this->stagingExtension($twig);
        if ($staging !== null) {
            $this->collectHelperNames($staging, $conflicts);
        }

        return $conflicts;
    }

    /**
     * @param array<string,true> $conflicts
     */
    private function collectHelperNames(ExtensionInterface $extension, array &$conflicts): void
    {
        foreach ($extension->getFunctions() as $function) {
            $name = $function->getName();
            if ($name === 'url' || $name === 'asset') {
                $conflicts[$name] = true;
            }
        }
        foreach ($extension->getFilters() as $filter) {
            if ($filter->getName() === 'trans') {
                $conflicts['trans'] = true;
            }
        }
    }

    private function stagingExtension(Environment $twig): ?ExtensionInterface
    {
        $extensionSet = $this->readPrivate($twig, 'extensionSet');
        if (!is_object($extensionSet)) {
            return null;
        }

        $staging = $this->readPrivate($extensionSet, 'staging');

        return $staging instanceof ExtensionInterface ? $staging : null;
    }

    private function readPrivate(object $object, string $property): mixed
    {
        try {
            return (new ReflectionProperty($object, $property))->getValue($object);
        } catch (ReflectionException) {
            return null;
        }
    }

    public function setPath(string $namespace, string $path): void
    {
        $loader = $this->twig->getLoader();
        if (!$loader instanceof FilesystemLoader) {
            throw new Ex('TwigRenderer registers paths on a FilesystemLoader only');
        }
        $loader->addPath($path, $namespace);
    }
}
