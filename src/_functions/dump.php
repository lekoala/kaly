<?php

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
