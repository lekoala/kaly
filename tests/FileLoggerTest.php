<?php

declare(strict_types=1);

namespace Kaly\Tests;

use DateTimeImmutable;
use Kaly\Clock\FrozenClock;
use Kaly\Log\FileLogger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\Stream;
use PHPUnit\Framework\TestCase;

class FileLoggerTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'kaly-log-');
        $this->assertNotFalse($file);
        $this->file = $file;
    }

    protected function tearDown(): void
    {
        if (is_file($this->file)) {
            unlink($this->file);
        }
    }

    private function logger(): FileLogger
    {
        return new FileLogger($this->file, clock: new FrozenClock(new DateTimeImmutable('2026-01-01T12:00:00Z')));
    }

    public function testAResponseBodyIsNeverConsumedWhenTheMessagePrintsIt(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if ($pair === false) {
            $this->markTestSkipped('A non-seekable socket stream is not available');
        }
        fwrite($pair[0], 'quatre');
        stream_socket_shutdown($pair[0], STREAM_SHUT_WR);
        $body = new Stream($pair[1]);
        $response = new Response(200, ['Content-Type' => 'text/plain'], $body);

        try {
            $this->logger()->info('download {response}', ['response' => $response]);

            // The logger only reads status and headers: the stream is intact
            $this->assertSame('quatre', $body->getContents());
            $log = (string) file_get_contents($this->file);
            $this->assertStringContainsString('download Response: 200 OK (text/plain)', $log);
        } finally {
            $body->close();
            fclose($pair[0]);
        }
    }

    public function testAStringableMessageIsAccepted(): void
    {
        $message = new class implements \Stringable {
            public function __toString(): string
            {
                return 'test {value}';
            }
        };

        $this->logger()->log('info', $message, ['value' => 'ok']);

        $this->assertSame("[2026-01-01 12:00:00] [info] test ok\n", (string) file_get_contents($this->file));
    }

    public function testAnUnusedResponseIsNeverTouched(): void
    {
        $body = Stream::create('quatre');
        $response = new Response(200, [], $body);

        $this->logger()->info('download', ['response' => $response]);

        // Eager interpolation used to read the body even without a placeholder
        $this->assertSame(0, $body->tell());
        $this->assertSame('quatre', $body->getContents());
        $this->assertSame("[2026-01-01 12:00:00] [info] download\n", (string) file_get_contents($this->file));
    }
}
