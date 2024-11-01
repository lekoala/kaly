<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * https://developer.mozilla.org/en-US/docs/Web/HTTP/MIME_types/Common_types
 */
class ContentType
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
}
