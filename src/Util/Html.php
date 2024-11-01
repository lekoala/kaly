<?php

declare(strict_types=1);

namespace Kaly\Util;

use Kaly\Http\Session;

/**
 * @link https://github.com/nette/utils/blob/master/src/Utils/Html.php
 * @link https://github.com/mako-framework/framework/blob/master/src/mako/utility/HTML.php
 */
final class Html
{
    public static function csrfField(): string
    {
        $name = Session::getExtraConfig()['csrf_key'];
        $value = ''; //TODO: get value
        return "<input type=\"hidden\" name=\"$name\" value=\"$value\" />";
    }

    /**
     * @param array<string,string> $arr
     * @return string
     */
    public static function attrs(array $arr): string
    {
        if (empty($arr)) {
            return '';
        }
        $compiled = join('="%s" ', array_keys($arr)) . '="%s"';
        return vsprintf($compiled, array_map('htmlspecialchars', array_values($arr)));
    }

    public static function selected(string $k, string $v): string
    {
        return '';
        // return HTTP::requestString($k) == $v ? ' selected="selected"' : '';
    }

    public static function checked(string $k, string $v): string
    {
        return '';
        // return HTTP::requestString($k) == $v ? ' checked="checked"' : '';
    }
}
