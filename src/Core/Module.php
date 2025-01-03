<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Util\Fs;
use Kaly\Di\Definitions;
use Kaly\Util\Str;
use Kaly\View\Engine;
use Closure;

class Module
{
    public const HEADER = '/** @var Kaly\Core\Module $this */';

    protected string $dir;
    protected string $name;
    protected string $namespace;
    protected int $priority = 0;
    protected Definitions $definitions;
    protected ?Closure $definitionsCallback = null;

    public function __construct(string $dir)
    {
        assert(is_dir($dir));
        $this->dir = Fs::dir($dir);
        $this->name = basename($this->dir);
        $this->namespace = $this->buildDefaultNamespace();

        $config = $this->getConfigPath();
        assert($this->addHeader($config), "could not add header to $config");

        $this->definitions = new Definitions();
    }

    public static function fromConfig(string $file): self
    {
        return new self(dirname((string) $file));
    }

    /**
     * Ideal to call in _config.php files for chaining
     * @return Definitions
     */
    public function definitions(): Definitions
    {
        return $this->definitions;
    }

    public function getDir(): string
    {
        return $this->dir;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getConfigPath(): string
    {
        return $this->dir . '/config.php';
    }

    public function getSrcDir(): string
    {
        return $this->dir . '/src';
    }

    public function getTemplatesDir(): string
    {
        return $this->dir . '/templates';
    }

    public function hasTemplates(): bool
    {
        return is_dir($this->getTemplatesDir());
    }

    public function getAssetsDir(): string
    {
        return $this->dir . '/assets';
    }

    public function hasAssets(): bool
    {
        return is_dir($this->getAssetsDir());
    }

    protected function buildDefaultNamespace(): string
    {
        $n = $this->getName();

        // It's already uppercased, and we dont want to convert MyModule to Mymodule
        if (strtoupper($n[0]) === $n[0]) {
            return $n;
        }

        return Str::camelize($n);
    }

    /**
     * Autoloading should really be handled by composer but this helps
     * @return void
     */
    public function autoloadFiles(): void
    {
        spl_autoload_register(function (string $class): void {
            $parts = explode('\\', $class);
            // Namespace are required for modules
            if (count($parts) <= 1) {
                return;
            }

            // Namespace doesn't match (could be A\Multiple\Separator\MyClass)
            $prefix = $this->getNamespace() . '\\';
            if (!str_starts_with($class, $prefix)) {
                return;
            }
            $classWithoutPrefix = substr($class, strlen($prefix));

            // Look for a file in src directory
            $file = $this->getSrcDir() . '/' . str_replace('\\', '/', $classWithoutPrefix) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        });
    }

    /**
     * Modules need a config.php file (even if it's empty). This avoids having is_file checks in the loop
     * @param string $dir
     * @return array<string>
     */
    public static function findModulesInDir(string $dir): array
    {
        // Don't sort results as it is faster - modules can be discovered in any order
        $files = glob($dir . "/*/config.php", GLOB_NOSORT);
        if (!$files) {
            $files = [];
        }
        return $files;
    }

    public function loadConfig(): void
    {
        $file = $this->getConfigPath();
        assert(is_file($file));

        // Set templates dir automatically
        if ($this->hasTemplates()) {
            $this->definitions->callback(Engine::class, function (Engine $engine): void {
                $engine->setPath($this->getName(), $this->getTemplatesDir());
            });
        }

        // Avoid leaking local variables from config files
        $includer = function (string $file): void {
            require $file;
        };
        $includer($file);

        // After including the definitions, it should be locked
        assert($this->definitions->isLocked());
    }

    /**
     * Automatically prepend docblock at start of file to make dx better
     * This should only run in debug mode when assertion are enabled
     * @param string $filename
     * @return bool
     */
    protected function addHeader(string $filename): bool
    {
        $contents = file_get_contents($filename);
        if (!$contents) {
            return false;
        }
        $header = self::HEADER;
        if (!str_contains($contents, $header)) {
            $contents = preg_replace("/^<\?php/", "<?php\n\n" . $header, $contents);
            file_put_contents($filename, $contents);
        }
        return true;
    }


    /**
     * Get the value of priority
     *
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * Set the value of priority
     *
     * @param int $priority
     * @return self
     */
    public function setPriority(int $priority): self
    {
        $this->priority = $priority;
        return $this;
    }

    /**
     * Get the value of definitionsCallback
     *
     * @return ?Closure
     */
    public function getDefinitionsCallback(): ?Closure
    {
        return $this->definitionsCallback;
    }

    /**
     * Set the value of definitionsCallback
     *
     * @param Closure $definitionsCallback
     *
     * @return self
     */
    public function setDefinitionsCallback(Closure $definitionsCallback): self
    {
        $this->definitionsCallback = $definitionsCallback;
        return $this;
    }

    /**
     * Get the value of namespace
     */
    public function getNamespace(): string
    {
        return $this->namespace;
    }

    /**
     * Set the value of namespace
     *
     * @param string $namespace
     */
    public function setNamespace(string $namespace): self
    {
        $this->namespace = $namespace;
        return $this;
    }
}
