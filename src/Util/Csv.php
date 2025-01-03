<?php

declare(strict_types=1);

namespace Kaly\Util;

use RuntimeException;
use Generator;
use Kaly\Http\HttpFactory;
use Psr\Http\Message\ResponseInterface;

/**
 * @link https://wiki.php.net/rfc/kill-csv-escaping
 * @link https://nyamsprod.com/blog/csv-and-php8-4/
 * @link https://github.com/lekoala/spread-compat/blob/master/src/Csv/Native.php
 */
final class Csv
{
    public const BOM = "\xef\xbb\xbf";

    /**
     * @param string $filename
     * @return resource
     */
    protected static function getInputStream(string $filename)
    {
        // Open for reading only; place the file pointer at the beginning of the file.
        $stream = fopen($filename, 'r');
        if (!$stream) {
            throw new RuntimeException("Failed to open stream");
        }
        return $stream;
    }

    /**
     * The memory limit of php://temp can be controlled by appending /maxmemory:NN,
     * where NN is the maximum amount of data to keep in memory before using a temporary file, in bytes.
     *
     * @return resource
     */
    protected static function getMaxMemTempStream()
    {
        $mb = 4;
        // Open for reading and writing; place the file pointer at the beginning of the file.
        $stream = fopen('php://temp/maxmemory:' . ($mb * 1024 * 1024), 'r+');
        if (!$stream) {
            throw new RuntimeException("Failed to open stream");
        }
        return $stream;
    }


    /**
     * Don't forget fclose afterwards if you don't need the stream anymore
     *
     * @param resource $stream
     */
    protected static function getStreamContents($stream): string
    {
        // Rewind to 0 before getting content from the start
        rewind($stream);
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            $contents = "";
        }
        return $contents;
    }

    /**
     * @return resource
     */
    protected static function getOutputStream(string $filename = 'php://output')
    {
        // Open for writing only; place the file pointer at the beginning of the file
        // and truncate the file to zero length. If the file does not exist, attempt to create it.
        $stream = fopen($filename, 'w');
        if (!$stream) {
            throw new RuntimeException("Failed to open stream");
        }
        return $stream;
    }

    public static function detectDelimiter(string $filename, string $default = ','): string
    {
        $delimiters = [
            ';' => 0,
            ',' => 0,
            "\t" => 0,
            "|" => 0
        ];

        $handle = fopen($filename, "r");
        if (!$handle) {
            return $default;
        }
        $firstLine = fgets($handle);
        fclose($handle);
        if (!$firstLine) {
            return $default;
        }
        foreach ($delimiters as $delimiter => &$count) {
            $count = count(str_getcsv($firstLine, $delimiter));
        }
        return array_search(max($delimiters), $delimiters) ?: $default;
    }

    public static function readString(
        string $contents,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '',
        bool $assoc = false
    ): Generator {
        $temp = self::getMaxMemTempStream();
        fwrite($temp, $contents);
        rewind($temp);
        return self::readStream($temp, $separator, $enclosure, $escape, $assoc);
    }

    /**
     * @param resource $stream
     */
    public static function readStream(
        $stream,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '',
        bool $assoc = false
    ): Generator {
        if (fgets($stream, 4) !== self::BOM) {
            // bom not found - rewind pointer to start of file.
            rewind($stream);
        }
        $headers = null;

        while (
            !feof($stream)
            &&
            ($line = fgetcsv($stream, null, $separator, $enclosure, $escape)) !== false
        ) {
            if ($assoc) {
                if ($headers === null) {
                    $headers = $line;
                    continue;
                }
                //@phpstan-ignore-next-line
                $line = array_combine($headers, $line);
            }
            yield $line;
        }
    }

    public static function readFile(
        string $filename,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '',
        bool $assoc = false
    ): \Generator {
        $stream = self::getInputStream($filename);
        yield from self::readStream($stream, $separator, $enclosure, $escape, $assoc);
    }

    /**
     * @param resource $stream
     * @param iterable<int,array<int|string,bool|float|int|string|null>> $data
     */
    protected static function write(
        $stream,
        iterable $data,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '',
        string $eol = "\n",
        bool $bom = true
    ): void {
        if ($bom) {
            fputs($stream, self::BOM);
        }

        foreach ($data as $row) {
            $result = fputcsv($stream, $row, $separator, $enclosure, $escape, $eol);
            if ($result === false) {
                throw new RuntimeException("Failed to write line");
            }
        }
    }

    /**
     * @param iterable<int,array<int|string,bool|float|int|string|null>> $data
     */
    public static function writeString(
        iterable $data,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '',
        string $eol = "\n",
        bool $bom = true
    ): string {
        $stream = self::getMaxMemTempStream();
        self::write($stream, $data, $separator, $enclosure, $escape, $eol, $bom);
        $contents = self::getStreamContents($stream);
        fclose($stream);
        return $contents;
    }

    /**
     * @param iterable<int,array<int|string,bool|float|int|string|null>> $data
     */
    public static function writeFile(
        iterable $data,
        string $filename,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '',
        string $eol = "\n",
        bool $bom = true
    ): bool {
        $stream = self::getOutputStream($filename);
        self::write($stream, $data, $separator, $enclosure, $escape, $eol, $bom);
        fclose($stream);
        return true;
    }

    /**
     * @param iterable<int,array<int|string,bool|float|int|string|null>> $data
     */
    public static function output(
        iterable $data,
        string $filename,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '',
        string $eol = "\n",
        bool $bom = true
    ): void {
        if (headers_sent()) {
            throw new RuntimeException("Headers already sent");
        }
        foreach (self::getHeaders($filename) as $name => $value) {
            header("$name: $value");
        }
        $stream = self::getOutputStream();
        self::write($stream, $data, $separator, $enclosure, $escape, $eol, $bom);
        fclose($stream);
    }

    /**
     * @return array<string,string>
     */
    public static function getHeaders(string $filename): array
    {
        $headers = [];
        $headers['Content-Type'] = 'text/csv';
        $headers['Content-Disposition'] = 'attachment; ' .
            'filename="' . rawurlencode($filename) . '"; ' .
            'filename*=UTF-8\'\'' . rawurlencode($filename);
        $headers['Cache-Control'] = 'max-age=0';
        $headers['Pragma'] = 'public';
        return $headers;
    }

    /**
     * @param iterable<int,array<int|string,bool|float|int|string|null>> $data
     */
    public static function toResponse(
        iterable $data,
        string $filename,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '',
        string $eol = "\n",
        bool $bom = true
    ): ResponseInterface {
        $body = self::writeString($data, $separator, $enclosure, $escape, $eol, $bom);
        $response = HttpFactory::createResponse($body);
        foreach (self::getHeaders($filename) as $name => $value) {
            $response = $response->withHeader($name, $value);
        }
        return $response;
    }
}
