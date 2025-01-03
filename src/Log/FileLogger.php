<?php

declare(strict_types=1);

namespace Kaly\Log;

use Kaly\Util\Str;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * A dead simple logger. You can set the base log level. Any message below this level will be ignored.
 */
class FileLogger extends AbstractLogger
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
        return match ($level) {
            LogLevel::EMERGENCY => 7,
            LogLevel::ALERT => 6,
            LogLevel::CRITICAL => 5,
            LogLevel::ERROR => 4,
            LogLevel::WARNING => 3,
            LogLevel::NOTICE => 2,
            LogLevel::INFO => 1,
            LogLevel::DEBUG => 0,
            default => throw new RuntimeException("Invalid log level: '$level'")
        };
    }

    /**
     * @param array<string,mixed> $context
     */
    protected static function interpolate(string $message, array $context = []): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            $replace['{' . $key . '}'] = Str::stringify($val);
        }
        return strtr($message, $replace);
    }

    /**
     * @param string $level
     * @param string $message
     * @param array<string,mixed> $context
     * @return void
     */
    public function log($level, $message, array $context = []): void
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
