<?php

// This file need to be included before vendor/autoload.php

if (!function_exists('dump')) {
    /**
     * @param array<mixed> ...$vars
     */
    function dump(...$vars): void
    {
        // You can override with your own settings
        if (!defined('DUMP_IDE_PLACEHOLDER')) {
            define('DUMP_IDE_PLACEHOLDER', 'vscode://file/{file}:{line}:0');
        }
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
        foreach ($vars as $v) {
            $name = $arguments[$i] ?? 'debug';
            if (in_array(\PHP_SAPI, ['cli', 'phpdbg'])) {
                echo "$name in $basefile:$line\n";
            } else {
                $ideLink = str_replace(['{file}', '{line}'], [$file, $line], DUMP_IDE_PLACEHOLDER);
                echo "<pre>$name in <a href=\"$ideLink\">$basefile:$line</a></pre>";
            }
            \Symfony\Component\VarDumper\VarDumper::dump($v);
            $i++;
        }
    }
}

if (!function_exists('dd')) {
    /**
     * @param array<mixed> ...$vars
     */
    function dd(...$vars): void
    {
        call_user_func_array("dump", $vars);
        exit(1);
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
