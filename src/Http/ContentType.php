<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Util\Fs;

/**
 * https://developer.mozilla.org/en-US/docs/Web/HTTP/MIME_types/Common_types
 */
final class ContentType
{
    public const PLAIN = 'text/plain';
    public const HTML = 'text/html';
    public const CSS = 'text/css';
    public const FORM = 'multipart/form-data';
    public const JSON = 'application/json';
    public const JS = 'application/javascript';
    public const STREAM = 'application/octet-stream';
    // images
    public const SVG = 'image/svg+xml';
    public const CSV = 'text/csv';
    public const GIF = 'image/gif';
    public const JPEG = 'image/jpeg';
    // others
    public const PDF = 'application/pdf';
    public const WOFF = 'font/woff2';
    public const XML = 'application/xml';

    /**
     * The Content-Type for a file served over HTTP.
     *
     * Web extensions resolve deterministically, so the type never depends on
     * the platform's fileinfo database (which reports `text/plain` for `.css`
     * and `.js` on Windows). Other files fall back to Fs::contentType().
     */
    public static function forFile(string $filename): string
    {
        return match (strtolower(Fs::extension($filename))) {
            'css' => self::CSS,
            'js', 'mjs' => self::JS,
            'html', 'htm' => self::HTML,
            'json' => self::JSON,
            'svg' => self::SVG,
            'jpg', 'jpeg' => self::JPEG,
            'gif' => self::GIF,
            'pdf' => self::PDF,
            'woff', 'woff2' => self::WOFF,
            'xml' => self::XML,
            'csv' => self::CSV,
            default => Fs::contentType($filename),
        };
    }
}
