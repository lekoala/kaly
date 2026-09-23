<?php

declare(strict_types=1);

namespace Kaly\Http;

use InvalidArgumentException;
use Kaly\Util\Fs;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Builds file responses for already-authorized paths.
 *
 * It validates *how* a file is served. The application decides *whether* it
 * may be served: resolve user input to a resource, check access, then hand
 * over the storage path. Never pass raw request input here.
 *
 * ```php
 * public function download(Document $document, CurrentUser $user): ResponseInterface
 * {
 *     if (!$this->access->canRead($user, $document)) {
 *         throw new ForbiddenException();
 *     }
 *
 *     return $this->files->create(
 *         $document->storagePath(),
 *         downloadName: $document->originalName(),
 *         attachment: true,
 *     );
 * }
 * ```
 */
final class FileResponseFactory
{
    public function __construct(
        private ResponseFactoryInterface $responses,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * Create a 200 response streaming a regular file from disk.
     *
     * @param string $filename Absolute storage path, already authorized by the caller
     * @param string|null $downloadName Exposed file name for Content-Disposition. Defaults to the file name when $attachment is true
     * @param bool $attachment Force download instead of inline display
     * @param string $method The request method: HEAD returns headers only
     */
    public function create(
        string $filename,
        ?string $downloadName = null,
        bool $attachment = false,
        string $method = 'GET',
    ): ResponseInterface {
        if (!is_file($filename)) {
            throw new InvalidArgumentException("Not a regular file: '{$filename}'");
        }
        $size = filesize($filename);
        if ($size === false) {
            throw new InvalidArgumentException("Unreadable file size: '{$filename}'");
        }

        $response = $this->responses
            ->createResponse(200)
            ->withHeader('Content-Type', Fs::contentType($filename))
            ->withHeader('Content-Length', (string) $size);

        $disposition = $this->contentDisposition($filename, $downloadName, $attachment);
        if ($disposition !== null) {
            $response = $response->withHeader('Content-Disposition', $disposition);
        }

        if ($method === 'HEAD') {
            return $response;
        }

        return $response->withBody($this->streams->createStreamFromFile($filename, 'rb'));
    }

    /**
     * Build a Content-Disposition value with no injectable bytes.
     *
     * The quoted filename stays ASCII (anything else becomes `_`) while the
     * RFC 5987 `filename*` parameter carries the exact UTF-8 name.
     */
    private function contentDisposition(string $filename, ?string $downloadName, bool $attachment): ?string
    {
        if ($downloadName === null && !$attachment) {
            return null;
        }
        $name = $downloadName ?? basename($filename);
        // A filename must never smuggle headers or break quoting
        $name = str_replace(["\r", "\n"], '', $name);
        if ($name === '') {
            $name = 'download';
        }
        $quoted = (string) preg_replace('/["\\\\\x00-\x1F\x7F]/', '_', $name);
        $quoted = (string) preg_replace('/[^\x20-\x7E]/', '_', $quoted);

        $type = $attachment ? 'attachment' : 'inline';
        return sprintf('%s; filename="%s"; filename*=UTF-8\'\'%s', $type, $quoted, rawurlencode($name));
    }
}
