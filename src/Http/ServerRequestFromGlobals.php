<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Builds a PSR-7 server request from the PHP globals with any PSR-17
 * implementation. This is the only place where Kaly reads the request
 * globals, and only for SAPI entry points (`App::run()`): runtimes hand
 * their own PSR-7 request to `App::handle()`.
 *
 * It reports what the server received, nothing more: resolving proxy headers
 * (X-Forwarded-*) is the job of a trusted proxy middleware.
 */
final class ServerRequestFromGlobals
{
    public function __construct(
        private ServerRequestFactoryInterface $requests,
        private UriFactoryInterface $uris,
        private UploadedFileFactoryInterface $files,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * Every argument defaults to its global
     *
     * @param array<string,mixed>|null $server
     * @param array<array-key,mixed>|null $query
     * @param array<array-key,mixed>|null $post
     * @param array<array-key,mixed>|null $cookies
     * @param array<array-key,mixed>|null $files
     */
    public function create(
        ?array $server = null,
        ?array $query = null,
        ?array $post = null,
        ?array $cookies = null,
        ?array $files = null,
        string $body = 'php://input',
    ): ServerRequestInterface {
        /** @var array<string,mixed> $server */
        $server ??= $_SERVER;
        $method = self::string($server, 'REQUEST_METHOD') ?? 'GET';

        $request = $this->requests->createServerRequest($method, $this->uris->createUri(self::uri($server)), $server);

        $protocol = self::string($server, 'SERVER_PROTOCOL');
        if ($protocol !== null && str_starts_with($protocol, 'HTTP/')) {
            $request = $request->withProtocolVersion(substr($protocol, 5));
        }

        foreach (self::headers($server) as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        $request = $request
            ->withCookieParams($cookies ?? $_COOKIE)
            ->withQueryParams($query ?? $_GET)
            ->withUploadedFiles($this->normalizeFiles($files ?? $_FILES))
            ->withBody($this->streams->createStreamFromFile($body, 'r'));

        // PHP only parses form bodies, and only for POST
        $mediaType = strtolower(trim(explode(';', $request->getHeaderLine('Content-Type'))[0]));
        if ($method === 'POST' && in_array($mediaType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)) {
            $request = $request->withParsedBody($post ?? $_POST);
        }

        return $request;
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function uri(array $server): string
    {
        $https = self::string($server, 'HTTPS');
        $scheme = $https !== null && $https !== '' && strtolower($https) !== 'off' ? 'https' : 'http';

        $host = self::string($server, 'HTTP_HOST') ?? self::string($server, 'SERVER_NAME') ?? 'localhost';
        $port = self::string($server, 'SERVER_PORT');
        if (!str_contains($host, ':') && !str_starts_with($host, '[') && $port !== null && !in_array($port, ['80', '443'], true)) {
            $host .= ':' . $port;
        }

        $target = self::string($server, 'REQUEST_URI') ?? '/';
        // Absolute-form request target (eg: through a forward proxy)
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $target) === 1) {
            return $target;
        }
        if (!str_contains($target, '?')) {
            $query = self::string($server, 'QUERY_STRING');
            if ($query !== null && $query !== '') {
                $target .= '?' . $query;
            }
        }

        return $scheme . '://' . $host . '/' . ltrim($target, '/');
    }

    /**
     * @param array<string,mixed> $server
     * @return array<string,string>
     */
    private static function headers(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = substr($key, 5);
            } elseif (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $name = $key;
            } else {
                continue;
            }
            $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', $name))))] = $value;
        }

        // Apache hides the Authorization header unless told otherwise
        if (!isset($headers['Authorization'])) {
            $redirected = self::string($server, 'REDIRECT_HTTP_AUTHORIZATION');
            $user = self::string($server, 'PHP_AUTH_USER');
            if ($redirected !== null) {
                $headers['Authorization'] = $redirected;
            } elseif ($user !== null) {
                $headers['Authorization'] = 'Basic ' . base64_encode($user . ':' . (self::string($server, 'PHP_AUTH_PW') ?? ''));
            }
        }

        return $headers;
    }

    /**
     * Turn the $_FILES layout (name/type/tmp_name/error/size at every leaf)
     * into a tree of UploadedFileInterface.
     *
     * @param array<array-key,mixed> $files
     * @return array<array-key,mixed>
     */
    private function normalizeFiles(array $files): array
    {
        $normalized = [];
        foreach ($files as $key => $value) {
            if ($value instanceof UploadedFileInterface) {
                $normalized[$key] = $value;
            } elseif (is_array($value) && array_key_exists('tmp_name', $value)) {
                $normalized[$key] = $this->fileSpec($value);
            } elseif (is_array($value)) {
                $normalized[$key] = $this->normalizeFiles($value);
            }
        }
        return $normalized;
    }

    /**
     * @param array<array-key,mixed> $spec
     * @return UploadedFileInterface|array<array-key,mixed>
     */
    private function fileSpec(array $spec): UploadedFileInterface|array
    {
        // A multiple upload (name="docs[]") nests the index under each field
        if (is_array($spec['tmp_name'])) {
            $nested = [];
            foreach (array_keys($spec['tmp_name']) as $index) {
                $nested[$index] = $this->fileSpec([
                    'tmp_name' => $spec['tmp_name'][$index],
                    'size' => is_array($spec['size'] ?? null) ? $spec['size'][$index] ?? null : null,
                    'error' => is_array($spec['error'] ?? null) ? $spec['error'][$index] ?? UPLOAD_ERR_NO_FILE : UPLOAD_ERR_NO_FILE,
                    'name' => is_array($spec['name'] ?? null) ? $spec['name'][$index] ?? null : null,
                    'type' => is_array($spec['type'] ?? null) ? $spec['type'][$index] ?? null : null,
                ]);
            }
            return $nested;
        }

        $error = is_int($spec['error'] ?? null) ? $spec['error'] : UPLOAD_ERR_NO_FILE;
        $tmp = is_string($spec['tmp_name']) ? $spec['tmp_name'] : '';
        $stream = $error === UPLOAD_ERR_OK && $tmp !== '' && is_file($tmp)
            ? $this->streams->createStreamFromFile($tmp, 'r')
            : $this->streams->createStream('');

        return $this->files->createUploadedFile(
            $stream,
            is_int($spec['size'] ?? null) ? $spec['size'] : null,
            $error,
            is_string($spec['name'] ?? null) ? $spec['name'] : null,
            is_string($spec['type'] ?? null) ? $spec['type'] : null,
        );
    }

    /**
     * @param array<string,mixed> $server
     */
    private static function string(array $server, string $key): ?string
    {
        $value = $server[$key] ?? null;
        if (is_int($value)) {
            return (string) $value;
        }
        return is_string($value) ? $value : null;
    }
}
