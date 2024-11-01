<?php

declare(strict_types=1);

namespace Kaly\View;

interface EscaperInterface
{
    public function escape(mixed $value, ?string $escapeMode = null): string;
    public function escHtml(mixed $string): string;
    public function escHtmlAttr(mixed $string): string;
    public function escJs(mixed $string): string;
    public function escUrl(mixed $string): string;
    public function escCss(mixed $string): string;
}
