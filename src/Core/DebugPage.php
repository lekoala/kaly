<?php

declare(strict_types=1);

namespace Kaly\Core;

use Kaly\Http\DebugPageInterface;
use Kaly\Util\Env;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * The error page shown in debug mode, never in production.
 *
 * Everything needed to understand a failure on one screen: the exception
 * chain, the code around each location (with an IDE link), the trace, and
 * what the cycle had established (route, locale, middlewares that ran).
 * Everything coming from the exception or the request is escaped.
 */
final class DebugPage implements DebugPageInterface
{
    private const EXCERPT_LINES = 5;

    public function html(Throwable $exception, ?ServerRequestInterface $request = null, int $status = 500): string
    {
        $sections = '';
        $depth = 0;
        for ($ex = $exception; $ex !== null; $ex = $ex->getPrevious()) {
            $sections .= $this->exceptionSection($ex, $depth++);
        }
        if ($request !== null) {
            $sections .= $this->requestSection($request);
        }

        $title = self::e($exception::class . ': ' . $exception->getMessage());

        return <<<HTML
            <!doctype html>
            <html lang="en">
            <head>
            <meta charset="utf-8">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title>{$status} · {$title}</title>
            <style>{$this->css()}</style>
            </head>
            <body>
            <header><span class="status">{$status}</span> Kaly debug</header>
            <main>{$sections}</main>
            </body>
            </html>
            HTML;
    }

    /**
     * The same information for a terminal
     */
    public function text(Throwable $exception): string
    {
        $out = '';
        for ($ex = $exception; $ex !== null; $ex = $ex->getPrevious()) {
            $out .= sprintf(
                "%s[%s] %s (%s:%d)\n%s\n",
                $out === '' ? '' : "\nPrevious: ",
                $ex::class,
                $ex->getMessage(),
                $ex->getFile(),
                $ex->getLine(),
                $ex->getTraceAsString(),
            );
        }
        return $out;
    }

    private function exceptionSection(Throwable $ex, int $depth): string
    {
        $class = self::e($ex::class);
        $message = self::e($ex->getMessage());
        $location = self::e($ex->getFile() . ':' . $ex->getLine());
        $link = self::e(self::ideLink($ex->getFile(), $ex->getLine()));
        $label = $depth === 0 ? '' : '<p class="label">Previous</p>';
        $excerpt = $this->excerpt($ex->getFile(), $ex->getLine());
        $trace = self::e($ex->getTraceAsString());

        return <<<HTML
            <section>
            {$label}<p class="class">{$class}</p>
            <h1>{$message}</h1>
            <p><a href="{$link}">{$location}</a></p>
            {$excerpt}
            <details><summary>Trace</summary><pre>{$trace}</pre></details>
            </section>
            HTML;
    }

    private function excerpt(string $file, int $line): string
    {
        if (!is_file($file) || !is_readable($file)) {
            return '';
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return '';
        }
        $from = max(1, $line - self::EXCERPT_LINES);
        $to = min(count($lines), $line + self::EXCERPT_LINES);
        $out = '';
        for ($i = $from; $i <= $to; $i++) {
            $class = $i === $line ? ' class="hit"' : '';
            $out .= "<span{$class}><i>{$i}</i>" . self::e($lines[$i - 1]) . "</span>\n";
        }
        return "<pre class=\"code\">{$out}</pre>";
    }

    private function requestSection(ServerRequestInterface $request): string
    {
        $rows = [
            'Request' => $request->getMethod() . ' ' . $request->getUri(),
        ];
        $ctx = HttpContext::tryFrom($request);
        if ($ctx !== null) {
            if ($ctx->hasRoute()) {
                $route = $ctx->route();
                $rows['Route'] = $route->controller . '::' . $route->action;
                $rows['Module'] = $route->module ?? '';
                if ($route->name !== null) {
                    $rows['Name'] = $route->name;
                }
                if ($route->middlewares !== []) {
                    $rows['Route middlewares'] = implode(', ', $route->middlewares);
                }
            } else {
                $rows['Route'] = '(not routed)';
            }
            if ($ctx->hasLocale()) {
                $rows['Locale'] = $ctx->locale();
            }
            $rows['Middlewares that ran'] = $ctx->middlewares() === [] ? '(none)' : implode(', ', $ctx->middlewares());
        }
        foreach (['Accept', 'Content-Type', 'User-Agent'] as $header) {
            if ($request->hasHeader($header)) {
                $rows[$header] = $request->getHeaderLine($header);
            }
        }

        $html = '';
        foreach ($rows as $key => $value) {
            $html .= '<tr><th>' . self::e($key) . '</th><td>' . self::e($value) . '</td></tr>';
        }
        return "<section><p class=\"label\">Context</p><table>{$html}</table></section>";
    }

    private static function ideLink(string $file, int $line): string
    {
        $placeholder = Env::getString(App::ENV_IDE_PLACEHOLDER, 'vscode://file/{file}:{line}:0');
        return str_replace(['{file}', '{line}'], [$file, (string) $line], $placeholder);
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function css(): string
    {
        return <<<'CSS'
            :root { --bg: #fafafa; --fg: #1f2328; --muted: #6a737d; --card: #fff; --line: #e1e4e8; --hit: #fff3bf; --accent: #cf222e; }
            @media (prefers-color-scheme: dark) { :root { --bg: #0d1117; --fg: #e6edf3; --muted: #8b949e; --card: #161b22; --line: #30363d; --hit: #3d3000; --accent: #ff7b72; } }
            * { box-sizing: border-box; }
            body { margin: 0; background: var(--bg); color: var(--fg); font: 15px/1.5 system-ui, sans-serif; }
            header { padding: 12px 16px; border-bottom: 1px solid var(--line); color: var(--muted); }
            .status { color: var(--accent); font-weight: 700; margin-right: 8px; }
            main { max-width: 1100px; margin: 0 auto; padding: 16px; }
            section { background: var(--card); border: 1px solid var(--line); border-radius: 8px; padding: 16px; margin-bottom: 16px; overflow: hidden; }
            h1 { font-size: 20px; margin: 4px 0 8px; overflow-wrap: anywhere; }
            .class, .label { margin: 0; color: var(--muted); font-family: ui-monospace, monospace; font-size: 13px; }
            .label { text-transform: uppercase; letter-spacing: .05em; margin-bottom: 8px; }
            a { color: inherit; font-family: ui-monospace, monospace; font-size: 13px; overflow-wrap: anywhere; }
            pre { margin: 12px 0 0; overflow-x: auto; font: 13px/1.5 ui-monospace, monospace; }
            .code span { display: block; white-space: pre; }
            .code .hit { background: var(--hit); }
            .code i { display: inline-block; width: 4em; color: var(--muted); font-style: normal; user-select: none; }
            summary { cursor: pointer; margin-top: 12px; color: var(--muted); }
            table { border-collapse: collapse; width: 100%; font-size: 14px; }
            th { text-align: left; color: var(--muted); font-weight: 500; padding: 4px 16px 4px 0; vertical-align: top; white-space: nowrap; }
            td { padding: 4px 0; font-family: ui-monospace, monospace; font-size: 13px; overflow-wrap: anywhere; }
            CSS;
    }
}
