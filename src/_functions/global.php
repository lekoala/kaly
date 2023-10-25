<?php

// This file is always included by the autoloader

if (!function_exists('array_merge_distinct')) {
    /**
     * This function replaces array_merge_recursive which doesn't preserve datatypes
     * (two strings will be merged into one array, instead of overwriting the value).
     *
     * Arguments are passed as reference for performance reason
     *
     * @param array<mixed> $arr1
     * @param array<mixed> $arr2
     * @param bool $deep
     * @return array<mixed>
     */
    function array_merge_distinct(array &$arr1, array &$arr2, bool $deep = true): array
    {
        foreach ($arr2 as $k => $v) {
            // regular array values are appended
            if (is_int($k)) {
                $arr1[] = $v;
                continue;
            }
            // merge arrays together if possible
            if (isset($arr1[$k]) && is_array($arr1[$k]) && is_array($v)) {
                if ($deep) {
                    $arr1[$k] = array_merge_distinct($arr1[$k], $v, $deep);
                } else {
                    $arr1[$k] = array_merge($arr1[$k], $v);
                }
            } else {
                // simply overwrite value
                $arr1[$k] = $v;
            }
        }
        return $arr1;
    }
}

if (!function_exists('mb_strtotitle')) {
    /**
     * Convert the first character of each word to uppercase
     * and all the other characters to lowercase
     */
    function mb_strtotitle(string $str): string
    {
        return mb_convert_case($str, MB_CASE_TITLE, "UTF-8");
    }
}

if (!function_exists('camelize')) {
    /**
     * Transform a string to camel case
     * Preserves _, it only replaces - because the could be a valid class or method names
     */
    function camelize(string $str, bool $firstChar = true): string
    {
        if ($str === '') {
            return $str;
        }
        $str = str_replace('-', ' ', $str);
        $str = mb_strtotitle($str);
        $str = str_replace(' ', '', $str);
        if (!$firstChar) {
            $str[0] = mb_strtolower($str[0]);
        }
        return $str;
    }
}

if (!function_exists('decamelize')) {
    /**
     * Does the opposite of camelize
     */
    function decamelize(string $str): string
    {
        if ($str === '') {
            return $str;
        }
        $str = preg_replace(['/([a-z\d])([A-Z])/', '/([^-_])([A-Z][a-z])/'], '$1-$2', $str);
        if (!$str) {
            return '';
        }
        $str = mb_strtolower($str);
        return $str;
    }
}

if (!function_exists('esc')) {
    function esc(string $content): string
    {
        return htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true);
    }
}

if (!function_exists('get_class_name')) {
    function get_class_name(string $class): string
    {
        $parts = explode('\\', $class);
        return end($parts);
    }
}

if (!function_exists('stringify')) {
    /**
     * @param mixed $val
     */
    function stringify($val): string
    {
        if (is_array($val)) {
            $val = json_encode($val, JSON_THROW_ON_ERROR);
        } elseif (is_object($val)) {
            if ($val instanceof Stringable) {
                $val = (string)$val;
            } else {
                $val = get_class($val);
            }
        } elseif (is_bool($val)) {
            $val = $val ? "(bool) true" : "(bool) false";
        } elseif (!is_string($val)) {
            $val = get_debug_type($val);
        }
        return $val;
    }
}

if (!function_exists('glob_recursive')) {
    /**
     * @return array<string>
     */
    function glob_recursive(string $pattern, int $flags = 0): array
    {
        $files = glob($pattern, $flags);
        if (!$files) {
            $files = [];
        }
        $dirs = glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT);
        if (!$dirs) {
            $dirs = [];
        }
        foreach ($dirs as $dir) {
            $files = array_merge($files, glob_recursive($dir . '/' . basename($pattern), $flags));
        }
        return $files;
    }
}

if (!function_exists('t')) {
    /**
     * @param array<string, mixed> $parameters
     */
    function t(string $message, array $parameters = [], string $domain = null, string $locale = null): string
    {
        static $translator = null;
        if ($translator === null) {
            /** @var \Kaly\Translator $translator  */
            $translator = \Kaly\App::inst()->getDi()->get(\Kaly\Translator::class);
        }
        return $translator->translate($message, $parameters, $domain, $locale);
    }
}

if (!function_exists('real_sapi_name')) {
    function real_sapi_name(): string
    {
        if (defined('ROADRUNNER')) {
            return 'roadrunner';
        }
        $name = php_sapi_name();
        if ($name) {
            return $name;
        }
        return "undefined";
    }
}

if (!function_exists('d')) {
    /**
     * @param array<mixed> ...$vars
     */
    function d(...$vars): void
    {
        // You can override with your own settings
        $idePlaceholder = $_ENV['DUMP_IDE_PLACEHOLDER'] ?? 'vscode://file/{file}:{line}:0';
        // Get caller info
        $file = '';
        $basefile = "unknown";
        $line = 0;
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);

        // Check origin
        $zeroTrace = $backtrace[0] ?? null;
        if ($zeroTrace && is_array($zeroTrace)) {
            $file = $zeroTrace['file'] ?? '(undefined file)';
            $line = $zeroTrace['line'] ?? '0';
            $basefile = basename($file);
        }

        // Extract arguments
        $arguments = [];
        if ($file) {
            $src = file($file);
            if ($src) {
                $srcLine = $src[$line - 1];
                // Find all arguments, ignore variables within parenthesis
                preg_match("/d\((.+)\)/", $srcLine, $matches);
                if (!empty($matches[1])) {
                    $split = preg_split("/(?![^(]*\)),/", $matches[1]);
                    if ($split) {
                        $arguments = array_map('trim', $split);
                    }
                }
            }
        }
        // Display vars
        $i = 0;
        $body = '';
        foreach ($vars as $v) {
            $name = $arguments[$i] ?? 'debug';
            if (in_array(real_sapi_name(), ['cli', 'phpdbg'])) {
                $body .= "$name in $basefile:$line\n";
                $body .= str_repeat('-', 10) . "\n";
                $body .= print_r($v, true);
                $body .= str_repeat('-', 10) . "\n";
            } else {
                $ideLink = str_replace(['{file}', '{line}'], [$file, $line], $idePlaceholder);
                $body .= "<pre>$name in <a href=\"$ideLink\">$basefile:$line</a><hr/>";
                $body .= print_r($v, true);
                $body .= "<hr/></pre>";
            }
            $i++;
        }
        if (in_array(real_sapi_name(), ['roadrunner'])) {
            throw new \Kaly\Exceptions\ResponseException($body);
        } else {
            echo $body;
            exit(1);
        }
    }
}

if (!function_exists('l')) {
    /**
     * @param mixed $message
     * @param array<string, mixed> $context
     */
    function l($message, array $context = []): void
    {
        if (!is_string($message)) {
            $message = stringify($message);
        }
        /** @var \Psr\Log\LoggerInterface $logger  */
        $logger = \Kaly\App::inst()->getDi()->get(\Kaly\App::DEBUG_LOGGER);
        $logger->log(\Psr\Log\LogLevel::DEBUG, $message, $context);
    }
}
