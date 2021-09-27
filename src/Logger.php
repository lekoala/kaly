<?php

declare(strict_types=1);

namespace Kaly;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;
use Stringable;

/**
 * A dead simple logger
 */
class Logger extends AbstractLogger
{
    protected string $destination;
    protected int $level;

    public function __construct(string $destination, string $level = LogLevel::DEBUG)
    {
        $this->destination = $destination;
        $this->level = self::getNumericLevel($level);
    }

    protected static function getNumericLevel(string $level): int
    {
        switch ($level) {
            case LogLevel::EMERGENCY:
                return 7;
            case LogLevel::ALERT:
                return 6;
            case LogLevel::CRITICAL:
                return 5;
            case LogLevel::ERROR:
                return 4;
            case LogLevel::WARNING:
                return 3;
            case LogLevel::NOTICE:
                return 2;
            case LogLevel::INFO:
                return 1;
            case LogLevel::DEBUG:
                return 0;
        }
        throw new RuntimeException("Invalid log level: '$level'");
    }

    /**
     * @param array<string, mixed> $context
     */
    protected static function interpolate(string $message, array $context = []): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            if (is_array($val)) {
                $val = json_encode($val, JSON_THROW_ON_ERROR);
            } elseif (is_object($val)) {
                if ($val instanceof Stringable) {
                    $val = (string)$val;
                } else {
                    $val = get_class($val);
                }
            } elseif (!is_string($val)) {
                $val = get_debug_type($val);
            }
            $replace['{' . $key . '}'] = $val;
        }
        return strtr($message, $replace);
    }

    public function log($level, $message, array $context = [])
    {
        if (!$this->destination) {
            return;
        }
        if (self::getNumericLevel($level) < $this->level) {
            return;
        }
        $date = date('Y-m-d H:i:s');
        $message = self::interpolate($message, $context);
        $message = "[$date] [$level] $message";
        file_put_contents($this->destination, $message . "\n", FILE_APPEND);
    }
}
