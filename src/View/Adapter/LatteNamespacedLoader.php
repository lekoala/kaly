<?php

declare(strict_types=1);

namespace Kaly\View\Adapter;

use InvalidArgumentException;
use Latte\Loader;
use Latte\TemplateNotFoundException;

/**
 * A Latte loader resolving Kaly template namespaces ('@Blog/article',
 * 'Blog::article') against registered module directories, delegating
 * everything else to the wrapped loader.
 *
 * Installed automatically by LatteRenderer::setPath(): plain template names
 * keep flowing to the engine loader untouched.
 */
final class LatteNamespacedLoader implements Loader
{
    /**
     * @var array<string,string> Namespace => absolute directory
     */
    private array $paths = [];

    public function __construct(
        private readonly Loader $inner,
    ) {}

    public function setPath(string $namespace, string $path): void
    {
        if ($namespace === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_.-]*$/', $namespace)) {
            throw new InvalidArgumentException("Invalid template namespace '{$namespace}'");
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            throw new InvalidArgumentException("Invalid template path '{$path}'");
        }
        $this->paths[$namespace] = $real;
    }

    public function getContent(string $name): string
    {
        $resolved = $this->resolve($name);
        if ($resolved === null) {
            return $this->inner->getContent($name);
        }
        if (!is_file($resolved)) {
            throw new TemplateNotFoundException("Missing template file '{$resolved}'");
        }
        $content = file_get_contents($resolved);
        if ($content === false) {
            throw new TemplateNotFoundException("Unable to read template file '{$resolved}'");
        }
        return $content;
    }

    public function getReferredName(string $name, string $referringName): string
    {
        // Keep the namespaced form: Latte re-resolves it through
        // getContent()/getUniqueId(), which know the namespaces
        if ($this->isNamespaced($name)) {
            return $name;
        }
        return $this->inner->getReferredName($name, $referringName);
    }

    public function getUniqueId(string $name): string
    {
        return $this->resolve($name) ?? $this->inner->getUniqueId($name);
    }

    private function isNamespaced(string $name): bool
    {
        return str_starts_with($name, '@') || str_contains($name, '::');
    }

    /**
     * Resolve a namespaced template name to an absolute file. Null when the
     * name carries no namespace; unknown namespaces and escapes fail loudly.
     */
    private function resolve(string $name): ?string
    {
        if (str_starts_with($name, '@')) {
            $parts = explode('/', substr($name, 1), 2);
            $namespace = $parts[0];
            $rest = $parts[1] ?? '';
        } elseif (str_contains($name, '::')) {
            [$namespace, $rest] = explode('::', $name, 2);
        } else {
            return null;
        }
        if (!array_key_exists($namespace, $this->paths)) {
            throw new InvalidArgumentException("Unknown template namespace '{$namespace}'");
        }
        $rest = trim(str_replace('\\', '/', $rest), '/');
        if ($rest === '' || str_contains($rest, "\0") || in_array('..', explode('/', $rest), true)) {
            throw new InvalidArgumentException("Invalid template name '{$name}'");
        }
        return $this->paths[$namespace] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rest);
    }
}
