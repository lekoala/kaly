<?php

declare(strict_types=1);

namespace Kaly\View;

/**
 * @link https://wiki.php.net/rfc/escaper
 */
interface EscaperInterface
{
    public function escape(mixed $value, ?string $escapeMode = null): string;
    public function escHtml(mixed $string): string;
    public function escHtmlAttr(mixed $string): string;
    public function escJs(mixed $string): string;
    public function escUrl(mixed $string): string;
    public function escCss(mixed $string): string;
}
