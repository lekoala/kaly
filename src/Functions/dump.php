<?php

// This file need to be included before vendor/autoload.php

if (!function_exists('dump')) {
    function dump(...$vars)
    {
        $placeholder = "vscode://file/{file}:{line}:0";
        // Get caller info
        $file = '';
        $basefile = "unknown";
        $line = 0;
        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3);
        // Remove one step if called through dd
        if (!empty($backtrace[1]) && is_array($backtrace[1]) && $backtrace[1]['function'] === "dd") {
            array_shift($backtrace);
        }
        // Check origin
        if (!empty($backtrace[0]) && is_array($backtrace[0])) {
            $file = $backtrace[0]['file'];
            $line = $backtrace[0]['line'];
            $basefile = basename($file);
        }
        // Extract arguments
        if ($file) {
            $src = file($file);
            $srcLine = $src[$line - 1];
            // Find all arguments, ignore variables within parenthesis
            preg_match("/d\((.+)\)/", $srcLine, $matches);
            $arguments = [];
            if (!empty($matches[1])) {
                $arguments = array_map('trim', preg_split("/(?![^(]*\)),/", $matches[1]));
            }
        }
        // Display vars
        $i = 0;
        foreach ($vars as $v) {
            $name = $arguments[$i] ?? 'debug';
            if (in_array(\PHP_SAPI, ['cli', 'phpdbg'])) {
                echo "$name in $basefile:$line\n";
            } else {
                $ideLink = str_replace(['{file}', '{line}'], [$file, $line], $placeholder);
                echo "<pre>$name in <a href=\"$ideLink\">$basefile:$line</a></pre>";
            }
            \Symfony\Component\VarDumper\VarDumper::dump($v);
            $i++;
        }
        return $vars;
    }
}
if (!function_exists('dd')) {
    function dd(...$vars)
    {
        call_user_func_array("dump", $vars);
        exit(1);
    }
}
