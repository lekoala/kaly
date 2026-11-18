<?php

declare(strict_types=1);

namespace Kaly\Log;

use Kaly\Clock\SystemClock;
use Kaly\Util\Str;
use Psr\Clock\ClockInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;
use RuntimeException;

/**
 * A dead simple logger. You can set the base log level. Any message below this level will be ignored.
 */
final class FileLogger extends AbstractLogger
{
    protected string $destination;
    protected int $level;

    public function __construct(
        string $destination,
        string $level = LogLevel::DEBUG,
        private readonly ClockInterface $clock = new SystemClock(),
    ) {
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
            default => throw new RuntimeException("Invalid log level: '{$level}'"),
        };
    }

    /**
     * @param array<string,mixed> $context
     */
    protected static function interpolate(string $message, array $context = []): string
    {
        $replace = [];
        foreach ($context as $key => $val) {
            $placeholder = '{' . $key . '}';
            // Resolve only what the message prints: converting a response body
            // eagerly consumes a stream the message may never mention
            if (!str_contains($message, $placeholder)) {
                continue;
            }
            $replace[$placeholder] = $val instanceof ResponseInterface ? self::describeResponse($val) : Str::stringify($val);
        }
        return strtr($message, $replace);
    }

    /**
     * Status and headers only: the body belongs to the client, it may be a
     * large or unseekable stream, and logging must never consume it.
     */
    private static function describeResponse(ResponseInterface $response): string
    {
        $description = 'Response: ' . $response->getStatusCode();
        $reason = $response->getReasonPhrase();
        if ($reason !== '') {
            $description .= ' ' . $reason;
        }
        $type = $response->getHeaderLine('Content-Type');
        if ($type !== '') {
            $description .= ' (' . $type . ')';
        }
        return $description;
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
        $date = $this->clock->now()->format('Y-m-d H:i:s');
        // The interface accepts string|Stringable: convert after the level
        // filter, before interpolation expects a string
        $message = str_replace(["\r", "\n"], ['\\r', '\\n'], self::interpolate((string) $message, $context));
        $message = "[{$date}] [{$level}] {$message}";
        file_put_contents($this->destination, $message . "\n", FILE_APPEND | LOCK_EX);
    }
}
