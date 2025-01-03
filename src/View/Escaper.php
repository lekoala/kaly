<?php

declare(strict_types=1);

namespace Kaly\View;

use Exception;
use Laminas\Escaper\Escaper as LaminasEscaper;
use Stringable;

/**
 * A more flexible escaper that accepts mixed values and don't run escape
 * methods on empty values
 *
 * Alternatively, this could be based on
 * @link https://github.com/twigphp/Twig/blob/3.x/src/Runtime/EscaperRuntime.php
 * @link https://docs.laminas.dev/laminas-escaper/theory-of-operation/
 */
class Escaper extends LaminasEscaper implements EscaperInterface
{
    /**
     * @param mixed $value
     * @param ?string $escapeMode h|a|u|c|j
     * @return string
     */
    public function escape(mixed $value, ?string $escapeMode = null): string
    {
        return match ($escapeMode) {
            'h' => $this->escHtml($value),
            'a' => $this->escHtmlAttr($value),
            'u' => $this->escUrl($value),
            'c' => $this->escCss($value),
            'j' => $this->escJs($value),
            default => self::convertMixedToString($value), // no mode = no escape
        };
    }

    /**
     * Convert string|int|float|bool|null|array|object|callable|resource to strings
     * @return string
     */
    protected static function convertMixedToString(mixed $string): string
    {
        $string ??= '';
        if (is_string($string)) {
            return $string;
        }
        if (is_callable($string)) {
            return self::convertMixedToString($string());
        }
        if (is_bool($string)) {
            return $string ? '✓' : '⨯';
        }
        if (is_array($string)) {
            return implode(', ', $string);
        }
        if (is_object($string) && $string instanceof Stringable) {
            return (string)$string;
        }
        if (is_numeric($string)) {
            return (string)$string;
        }
        $type = get_debug_type($string);
        throw new Exception("Cannot convert $type to string");
    }

    public function escHtml(mixed $string): string
    {
        $string = self::convertMixedToString($string);
        if (!$string) {
            return '';
        }
        return parent::escapeHtml($string);
    }

    public function escHtmlAttr(mixed $string): string
    {
        $string = self::convertMixedToString($string);
        if (!$string) {
            return '';
        }
        return parent::escapeHtmlAttr($string);
    }

    public function escJs(mixed $string): string
    {
        $string = self::convertMixedToString($string);
        if (!$string) {
            return '';
        }
        return parent::escapeJs($string);
    }

    public function escUrl(mixed $string): string
    {
        $string = self::convertMixedToString($string);
        if (!$string) {
            return '';
        }
        return parent::escapeUrl($string);
    }

    public function escCss(mixed $string): string
    {
        $string = self::convertMixedToString($string);
        if (!$string) {
            return '';
        }
        return parent::escapeCss($string);
    }
}
