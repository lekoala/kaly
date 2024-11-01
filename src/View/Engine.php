<?php

declare(strict_types=1);

namespace Kaly\View;

use Closure;

/**
 * Simply create an instance of the engine and call the "render" method
 *
 * Credits to these awesome engines for inspiration
 * @link https://github.com/thephpleague/plates
 * @link https://github.com/devanych/view-renderer
 * @link https://github.com/qiqphp/qiq
 */
class Engine implements EngineInterface
{
    public const HEADER = '/** @var Kaly\View\Template $this */';

    protected string $dir;
    protected string $ext = 'phtml';
    /**
     * A closure that takes a file name and an array of data
     * @var Closure
     */
    protected Closure $renderer;
    protected EscaperInterface $escaper;
    protected static ?EscaperInterface $globalEscaper = null;
    /**
     * @var array<string,string>
     */
    protected array $blocks = [];
    /**
     * @var array<string,string>
     */
    protected array $paths = [];
    /**
     * @var array<string,mixed>
     */
    protected array $globals = [];
    /**
     * @var array<string,ExtensionInterface>
     */
    protected array $extensions = [];
    protected bool $debug = false;
    protected ?string $customHeader = null;
    protected bool $parsingEnabled = true;

    public function __construct(?string $dir = null, ?string $ext = null, ?string $encoding = null)
    {
        if ($dir) {
            $this->setDir($dir);
        }
        if ($ext) {
            $this->ext = $ext;
        }
        $this->renderer = self::createRenderer();
        $this->escaper = new Escaper($encoding);
        assert($this->setDebug());
        self::$globalEscaper = $this->escaper;
    }

    protected static function createRenderer(): Closure
    {
        require_once __DIR__ . '/_helpers.php';
        return (function (...$args): void {
            // If there is a collision, don't overwrite the existing variable.
            extract($args[1], EXTR_SKIP);
            require $args[0];
        });
    }

    public static function getGlobalEscaper(?string $encoding = null): EscaperInterface
    {
        if (!self::$globalEscaper) {
            self::$globalEscaper = new Escaper($encoding);
        }
        return self::$globalEscaper;
    }

    public function getDir(): string
    {
        return $this->dir;
    }

    public function setDir(string $dir): void
    {
        $dir = rtrim($dir, '\/');
        assert(is_dir($dir), "$dir is not valid");
        $this->dir = $dir;
    }

    public function getCustomHeader(): ?string
    {
        return $this->customHeader;
    }

    public function setCustomHeader(string $v): void
    {
        $this->customHeader = $v;
    }

    public function getDebug(): bool
    {
        return $this->debug;
    }

    public function setDebug(bool $v = true): true
    {
        $this->debug = $v;
        return true;
    }

    public function getPath(string $name): ?string
    {
        return $this->paths[$name] ?? null;
    }

    public function setPath(string $name, string $dir): void
    {
        // Set as default dir if none set
        if (!isset($this->dir)) {
            $this->setDir($dir);
        }

        $dir = rtrim($dir, '\/');
        assert(is_dir($dir));
        $this->paths[$name] = $dir;
    }

    public function getEscaper(): EscaperInterface
    {
        return $this->escaper;
    }

    public function getGlobal(string $k): mixed
    {
        return $this->globals[$k] ?? null;
    }

    public function setGlobal(string $k, mixed $v): void
    {
        $this->globals[$k] = $v;
    }

    public function addExtension(ExtensionInterface $extension): void
    {
        $this->extensions[$extension::class] = $extension;
    }

    public function removeExtension(ExtensionInterface $extension): void
    {
        unset($this->extensions[$extension::class]);
    }

    /**
     * @return array<ExtensionInterface>
     */
    public function getExtensions(): array
    {
        return $this->extensions;
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
        $header = $this->customHeader ?? self::HEADER;
        if (!$header) {
            return true;
        }
        if (!str_contains($contents, $header)) {
            // Replace default header value with the new one
            if ($header != self::HEADER) {
                $contents = str_replace(self::HEADER, $header, $contents);
            }
            // Add to start of template
            $contents = "<?php\n\n" . $header . "\n?>\n" . $contents;
            file_put_contents($filename, $contents);
        }
        return true;
    }

    /**
     * parse strings into the template
     * Escaping must be made contextually. We borrow qiq syntax since it's really nice, but without the $
     * For escaping, we can use \{{ var }} to display raw {{ var }}
     *
     * @link https://docs.laminas.dev/laminas-escaper/theory-of-operation/#why-contextual-escaping
     * @link https://stackoverflow.com/questions/41873763/how-do-i-use-preg-replace-in-php-with-mustache
     * @link https://qiqphp.com/3.x/syntax.html
     * @param array<string,mixed> $data
     */
    protected function parse(string $contents, array $data = []): string
    {
        // Don't bother
        if (!$this->parsingEnabled || !str_contains($contents, '{{')) {
            return $contents;
        }
        // Replace any {{content}} or {{x content}} or {{arr.content.value}}.
        return preg_replace_callback('/\\\\?{{(?:(=|h|a|u|c|j) )?([\w\. -]+?)}}/', function ($match) use ($data) {
            $fullMatch = $match[0]; // {{h mystring}}
            $escapeMode = $match[1]; // h
            $key = $match[2]; // mystring

            // \{{escape}} will be displayed as is
            if (str_starts_with($fullMatch, '\\')) {
                return ltrim($fullMatch, '\\');
            }
            if (!$key) {
                return $fullMatch;
            }
            $key = trim($key);

            // dot notation support
            if (str_contains($key, '.')) {
                $loc = &$data;
                foreach (explode('.', $key) as $step) {
                    if (is_array($loc)) {
                        $loc = &$loc[$step] ?? '';
                    } elseif (is_object($loc)) {
                        $loc = method_exists($loc, $step) ? $loc->$step() : $loc->$step;
                    } else {
                        break;
                    }
                }
                $value = $loc;
            } else {
                if (!array_key_exists($key, $data)) {
                    return $fullMatch;
                }
                $value = $data[$key] ?? '';
            }

            if (!$value) {
                return '';
            }

            // Strings need to be escaped
            // Stringable object take care of themselves
            if (!is_object($value)) {
                $value = $this->escaper->escape($value, $escapeMode);
            }
            return $value;
        }, $contents) ?? '';
    }


    public function getBlock(string $name): string
    {
        return $this->blocks[$name] ?? '';
    }

    public function setBlock(string $name, string $contents): void
    {
        // If there is a block already defined in a child template, inject {{parent}}
        if (isset($this->blocks[$name])) {
            $contents = $this->parse($this->blocks[$name], [
                'parent' => new ViewData($contents)
            ]);
        }
        $this->blocks[$name] = $contents;
    }

    /**
     * @param string $name
     * @return string|array{string,string}
     */
    public static function resolveName(string $name): string|array
    {
        if (str_contains($name, '::')) {
            // Support module::path
            $name = explode('::', $name, 2);
        } elseif (str_starts_with($name, '@')) {
            // Support @module/path
            $name = explode('/', $name, 2);
            $name[0] = ltrim($name[0], '@');
        }
        assert(is_string($name) || count($name) == 2);
        return $name;
    }

    /**
     * @param string|array<string> $name
     * @return string
     */
    protected function resolveFile(string|array $name): string
    {
        if (is_string($name)) {
            $name = self::resolveName($name);
        }
        if (is_array($name)) {
            $path = $name[0];
            $name = $name[1];
            assert(isset($this->paths[$path]), "Invalid path $path");
            $dir = $this->paths[$path] ?? null;
        } else {
            assert(isset($this->dir));
            $dir = $this->dir;
        }

        $filename = $dir . '/' . trim((string) $name, '\/');
        if (pathinfo($filename, PATHINFO_EXTENSION) === '') {
            $filename .= '.' . $this->ext;
        }

        assert(is_file($filename), "$filename not found");

        return $filename;
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $name): bool
    {
        if (!isset($this->dir)) {
            return false;
        }
        $filename = $this->resolveFile($name);
        return is_file($filename);
    }

    /**
     * {@inheritDoc}
     */
    public function render(string $name, array $data = []): string
    {
        $st = microtime(true);

        // The + operator returns the right-hand array appended to the left-hand array;
        // for keys that exist in both arrays, the elements from the left-hand array will be used,
        // and the matching elements from the right-hand array will be ignored.
        $contents = $this->renderFile($name, $data + $this->globals);
        $this->blocks = []; // reset blocks at the end of render

        $et = microtime(true);
        $tt = sprintf('%0.6f', $et - $st);
        if ($this->debug) {
            $contents .= '<!-- rendered in ' . $tt . ' seconds -->';
        }

        return $contents ?: "";
    }

    /**
     * @param string|array<string> $file
     * @param array<string,mixed> $data
     * @return string
     */
    public function renderFile(string|array $file, array $data = []): string
    {
        $filename = $this->resolveFile($file);
        assert($this->addHeader($filename), "could not add header to $filename");

        $tpl = new Template($this, $filename);
        // Try/catch block is required in case of errors in templates
        try {
            $level = ob_get_level();
            ob_start();

            // Include in an isolated scope
            $bound = $this->renderer->bindTo($tpl);
            if ($bound) {
                $bound($filename, $data);
            }

            $contents = ob_get_clean() ?: "";
            $contents = $this->parse($contents, $data);
            if ($this->debug) {
                $contents = "<!-- start $filename -->\n$contents\n<!-- end $filename -->\n";
            }
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }

        // Layouts can be nested
        if ($tpl->layout) {
            $data['content'] = $contents;
            $contents = $this->renderFile($tpl->layout, $data);
        }
        return $contents;
    }

    /**
     */
    public function isParsingEnabled(): bool
    {
        return $this->parsingEnabled;
    }

    /**
     * @param bool $parsingEnabled
     */
    public function setParsingEnabled(bool $parsingEnabled): void
    {
        $this->parsingEnabled = $parsingEnabled;
    }
}
