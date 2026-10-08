<?php

declare(strict_types=1);

namespace Kaly\Test;

use Kaly\Core\App;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UploadedFileInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An in-process HTTP client for functional tests, not a browser emulator.
 *
 * It exercises exactly the boundary Kaly defines — PSR-7 request in,
 * PSR-7 response out — with no socket, no server and no DOM. On top of that
 * narrow boundary it keeps just enough client state to test a Kaly
 * application: response cookies sufficient for session testing, and explicit
 * redirect following.
 *
 * The cookie store is intentionally not a full HTTP cookie jar (RFC 6265):
 * names and values persist across requests, attributes (domain, path, secure,
 * expiry) are neither enforced nor stored. Documented limits, not missing
 * features.
 *
 * Kaly\Test is provided by Kaly but is not part of the production runtime.
 */
final class TestClient
{
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * @var array<string,string> Response cookies, by name
     */
    private array $cookies = [];

    private ?TestResponse $lastResponse = null;

    /**
     * @var array{method:string,uri:string,headers:array<string,string>,body:?string,streamBody:bool,parsedBody:array<array-key,mixed>|object|null,files:array<array-key,mixed>}|null
     */
    private ?array $lastRequest = null;

    private function __construct(
        private RequestHandlerInterface $handler,
        private ServerRequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
    ) {}

    /**
     * A client driving the given application in memory.
     */
    public static function for(App $app): self
    {
        $requests = $app->container()->get(ServerRequestFactoryInterface::class);
        if (!$requests instanceof ServerRequestFactoryInterface) {
            throw new LogicException('The container must provide a ' . ServerRequestFactoryInterface::class);
        }
        $streams = $app->container()->get(StreamFactoryInterface::class);
        if (!$streams instanceof StreamFactoryInterface) {
            throw new LogicException('The container must provide a ' . StreamFactoryInterface::class);
        }
        return new self($app, $requests, $streams);
    }

    /**
     * Send a request and get the response with fluent assertions.
     *
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,files?:array<array-key,mixed>,json?:mixed,body?:string|StreamInterface,cookies?:array<string,string>,maxRedirects?:int} $options
     *   Closed vocabulary: headers to send, query params merged into the uri,
     *   one of json (encoded, JSON content type), form (urlencoded body, parsed
     *   body set as a server would) or a raw body; cookies explicitly sent
     *   (winning over the stored ones); maxRedirects automatically followed
     *   redirects, 0 by default. files is a tree of UploadedFileInterface objects,
     *   optionally alongside form, set directly on the PSR-7 request.
     */
    public function request(string $method, string $uri, array $options = []): TestResponse
    {
        $unknown = array_diff(array_keys($options), ['headers', 'query', 'form', 'files', 'json', 'body', 'cookies', 'maxRedirects']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown request options: ' . implode(', ', $unknown));
        }
        $bodies = array_intersect(['json', 'form', 'body'], array_keys($options));
        if (count($bodies) > 1) {
            throw new \InvalidArgumentException('Only one of json, form or body may be given, got: ' . implode(', ', $bodies));
        }
        if (array_key_exists('files', $options)) {
            if (array_key_exists('json', $options) || array_key_exists('body', $options)) {
                throw new \InvalidArgumentException('files may only be combined with form');
            }
            self::assertUploadedFiles($options['files']);
        }
        $maxRedirects = $options['maxRedirects'] ?? 0;
        if (!is_int($maxRedirects) || $maxRedirects < 0) {
            throw new \InvalidArgumentException('maxRedirects must be an integer >= 0');
        }
        unset($options['maxRedirects']);

        $response = $this->send($method, $uri, $options);

        $hops = 0;
        while ($maxRedirects > 0 && $this->isRedirect($response->response())) {
            if (++$hops > $maxRedirects) {
                throw new LogicException("Too many redirects (over {$maxRedirects})");
            }
            $response = $this->hop($response->response());
        }
        return $response;
    }

    /**
     * Follow the Location of the last response, once.
     *
     * 303 (and 301/302 on POST) become GET; 307/308 replay the method and the
     * body. Cross-origin locations are refused: this client drives one app.
     */
    public function followRedirect(): TestResponse
    {
        if ($this->lastResponse === null) {
            throw new LogicException('No response to follow: send a request first');
        }
        return $this->hop($this->lastResponse->response());
    }

    /**
     * Forget every stored cookie.
     */
    public function clearCookies(): void
    {
        $this->cookies = [];
    }

    /**
     * The stored response cookies, by name.
     *
     * @return array<string,string>
     */
    public function cookies(): array
    {
        return $this->cookies;
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,files?:array<array-key,mixed>,json?:mixed,body?:string|StreamInterface,cookies?:array<string,string>,maxRedirects?:int} $options
     */
    public function get(string $uri, array $options = []): TestResponse
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,files?:array<array-key,mixed>,json?:mixed,body?:string|StreamInterface,cookies?:array<string,string>,maxRedirects?:int} $options
     */
    public function post(string $uri, array $options = []): TestResponse
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,files?:array<array-key,mixed>,json?:mixed,body?:string|StreamInterface,cookies?:array<string,string>,maxRedirects?:int} $options
     */
    public function put(string $uri, array $options = []): TestResponse
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,files?:array<array-key,mixed>,json?:mixed,body?:string|StreamInterface,cookies?:array<string,string>,maxRedirects?:int} $options
     */
    public function patch(string $uri, array $options = []): TestResponse
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,files?:array<array-key,mixed>,json?:mixed,body?:string|StreamInterface,cookies?:array<string,string>,maxRedirects?:int} $options
     */
    public function delete(string $uri, array $options = []): TestResponse
    {
        return $this->request('DELETE', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,files?:array<array-key,mixed>,json?:mixed,body?:string|StreamInterface,cookies?:array<string,string>,parsedBody?:array<array-key,mixed>|object|null} $options
     */
    // A linear request builder: the volume reflects the number of supported
    // option shapes, not intertwined control flow.
    // @mago-expect lint:halstead
    private function send(string $method, string $uri, array $options): TestResponse
    {
        $headers = $options['headers'] ?? [];
        $merged = array_merge($this->cookies, $options['cookies'] ?? []);
        foreach ($merged as $name => $value) {
            if (!is_string($value)) {
                throw new \InvalidArgumentException("Cookie '{$name}' must be a string");
            }
        }

        $request = $this->requests->createServerRequest($method, $uri);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($merged !== [] && !$request->hasHeader('Cookie')) {
            $pairs = [];
            foreach ($merged as $name => $value) {
                $pairs[] = $name . '=' . $value;
            }
            $request = $request->withHeader('Cookie', implode('; ', $pairs));
        }
        $request = $request->withCookieParams($merged);
        if (!empty($options['query'] ?? [])) {
            $current = [];
            parse_str($request->getUri()->getQuery(), $current);
            $mergedQuery = array_merge($current, $options['query']);
            $request = $request->withUri($request->getUri()->withQuery(http_build_query($mergedQuery)))->withQueryParams($mergedQuery);
        }

        $body = null;
        $streamBody = false;
        if (array_key_exists('json', $options)) {
            $body = (string) json_encode($options['json']);
            $request = $request->withHeader('Content-Type', 'application/json')->withBody($this->streams->createStream($body));
        } elseif (array_key_exists('files', $options) && !array_key_exists('body', $options)) {
            // Model the server's parsed request directly, without multipart encoding.
            $request = $request->withParsedBody($options['form'] ?? []);
        } elseif (array_key_exists('form', $options)) {
            $body = http_build_query($options['form']);
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($options['form'])
                ->withBody($this->streams->createStream($body));
        } elseif (array_key_exists('body', $options)) {
            $given = $options['body'];
            if ($given instanceof StreamInterface) {
                $streamBody = true;
                $request = $request->withBody($given);
            } else {
                $body = $given;
                $request = $request->withBody($this->streams->createStream($body));
            }
        }
        if (array_key_exists('parsedBody', $options) && $options['parsedBody'] !== null) {
            // Internal replay (307/308): restore the parsed representation the
            // server would have built, alongside the raw body
            $request = $request->withParsedBody($options['parsedBody']);
        }

        $request = $request->withUploadedFiles($options['files'] ?? []);

        $sentHeaders = [];
        foreach ($request->getHeaders() as $name => $values) {
            $sentHeaders[(string) $name] = implode(', ', $values);
        }
        $this->lastRequest = [
            'method' => $method,
            'uri' => (string) $request->getUri(),
            'headers' => $sentHeaders,
            'body' => $body,
            'streamBody' => $streamBody,
            'parsedBody' => $request->getParsedBody(),
            'files' => $request->getUploadedFiles(),
        ];

        $response = new TestResponse($this->handler->handle($request));
        $this->storeCookies($response->response());
        $this->lastResponse = $response;
        return $response;
    }

    /**
     * @param array<array-key,mixed> $files
     */
    private static function assertUploadedFiles(array $files): void
    {
        foreach ($files as $file) {
            if (is_array($file)) {
                self::assertUploadedFiles($file);
            } elseif (!$file instanceof UploadedFileInterface) {
                throw new \InvalidArgumentException('Every file must be an UploadedFileInterface or a nested array of uploaded files');
            }
        }
    }

    private function hop(ResponseInterface $redirect): TestResponse
    {
        if ($this->lastRequest === null) {
            throw new LogicException('No request to replay: send a request first');
        }
        $location = $redirect->getHeaderLine('Location');
        if (!in_array($redirect->getStatusCode(), self::REDIRECT_STATUSES, true) || $location === '') {
            throw new LogicException('The last response is not a redirect with a Location');
        }

        $method = $this->lastRequest['method'];
        $status = $redirect->getStatusCode();
        $switchToGet = $status === 303 || ($status === 301 || $status === 302) && $method === 'POST';
        $nextMethod = $switchToGet && $method !== 'HEAD' ? 'GET' : $method;

        $headers = self::withoutHeaders($this->lastRequest['headers'], 'Cookie');
        $body = $this->lastRequest['body'];
        if ($nextMethod !== $method) {
            $headers = self::withoutHeaders($headers, 'Content-Type', 'Content-Length', 'Transfer-Encoding');
            $body = null;
        } elseif ($this->lastRequest['streamBody']) {
            throw new LogicException('The request body is a stream and cannot be replayed on redirect');
        }

        $uri = $this->resolveLocation($this->lastRequest['uri'], $location);

        $options = [
            'headers' => $headers,
            'body' => $body ?? '',
        ];
        if ($nextMethod === $method && $this->lastRequest['parsedBody'] !== null) {
            $options['parsedBody'] = $this->lastRequest['parsedBody'];
        }
        if ($nextMethod === $method) {
            $options['files'] = $this->lastRequest['files'];
        }

        return $this->send($nextMethod, $uri, $options);
    }

    private function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1 || str_starts_with($location, '//')) {
            // Absolute or network-path reference: only same-origin targets
            // stay in the app, otherwise '//evil.example/...' would escape it
            $this->assertSameOrigin($base, $location);
            if (str_starts_with($location, '//')) {
                $scheme = (string) parse_url($base, PHP_URL_SCHEME);
                return ($scheme === '' ? 'http' : $scheme) . ':' . $location;
            }
            return $location;
        }

        // A relative reference resolves against the base origin: losing it
        // would make a later absolute redirect look cross-origin
        $origin = self::originOf($base);
        $basePath = parse_url($base, PHP_URL_PATH);
        $basePath = is_string($basePath) && $basePath !== '' ? $basePath : '/';

        // A fragment keeps the base query; a bare query replaces it
        if (str_starts_with($location, '#')) {
            $query = parse_url($base, PHP_URL_QUERY);
            return $origin . $basePath . (is_string($query) ? '?' . $query : '') . $location;
        }
        if (str_starts_with($location, '?')) {
            return $origin . $basePath . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        return $origin . self::mergePaths($basePath, $location);
    }

    /**
     * The scheme and authority of an absolute uri, '' for a relative one.
     */
    private static function originOf(string $uri): string
    {
        $parts = parse_url($uri);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '';
        }
        $scheme = $parts['scheme'] ?? null;
        $origin = (is_string($scheme) && $scheme !== '' ? $scheme : 'http') . '://' . $parts['host'];
        $port = $parts['port'] ?? null;
        if (is_int($port)) {
            $origin .= ':' . $port;
        }
        return $origin;
    }

    /**
     * Drop headers by name, header names being case-insensitive.
     *
     * @param array<string,string> $headers
     * @return array<string,string>
     */
    private static function withoutHeaders(array $headers, string ...$names): array
    {
        $lower = array_map(strtolower(...), $names);
        foreach ($headers as $name => $_) {
            if (in_array(strtolower((string) $name), $lower, true)) {
                unset($headers[$name]);
            }
        }
        return $headers;
    }

    /**
     * The request stays in the app: scheme, host and effective port must match.
     * A network-path reference ('//host/...') inherits the base scheme.
     */
    private function assertSameOrigin(string $base, string $location): void
    {
        $networkPath = str_starts_with($location, '//');
        $baseParts = parse_url($base);
        $targetParts = parse_url($networkPath ? 'http:' . $location : $location);
        if (!is_array($baseParts) || !is_array($targetParts)) {
            throw new LogicException("Refusing redirect to '{$location}': unparseable uri");
        }
        if ($networkPath) {
            $targetParts['scheme'] = $baseParts['scheme'] ?? null;
        }
        $baseHost = strtolower((string) ($baseParts['host'] ?? ''));
        $targetHost = strtolower((string) ($targetParts['host'] ?? ''));
        if ($targetHost === '' || $targetHost !== $baseHost) {
            throw new LogicException("Refusing cross-origin redirect to '{$location}'");
        }
        if (strtolower((string) ($baseParts['scheme'] ?? '')) !== strtolower((string) ($targetParts['scheme'] ?? ''))) {
            throw new LogicException("Refusing cross-origin redirect to '{$location}'");
        }
        if (self::effectivePort($baseParts) !== self::effectivePort($targetParts)) {
            throw new LogicException("Refusing cross-origin redirect to '{$location}'");
        }
    }

    /**
     * @param array<string,mixed> $parts
     */
    private static function effectivePort(array $parts): ?int
    {
        $port = $parts['port'] ?? null;
        if (is_int($port)) {
            return $port;
        }
        $scheme = $parts['scheme'] ?? null;
        return match (is_string($scheme) ? strtolower($scheme) : '') {
            'http' => 80,
            'https' => 443,
            default => null,
        };
    }

    /**
     * Merge a relative reference against a base path (RFC 3986 section 5.2.3)
     * and remove dot segments, so '../target' from '/a/source' is '/target'.
     */
    private static function mergePaths(string $basePath, string $location): string
    {
        $suffix = '';
        $cut = min(($q = strpos($location, '?')) === false ? PHP_INT_MAX : $q, ($h = strpos($location, '#')) === false ? PHP_INT_MAX : $h);
        if ($cut !== PHP_INT_MAX) {
            $suffix = substr($location, $cut);
            $location = substr($location, 0, $cut);
        }
        if ($basePath === '' || $basePath === '/') {
            $merged = '/' . $location;
        } else {
            $merged = substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $location;
        }
        return self::removeDotSegments($merged) . $suffix;
    }

    private static function removeDotSegments(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments !== []) {
                    array_pop($segments);
                    // Removing the last segment leaves its preceding slash
                    if ($segments === []) {
                        $segments[] = '';
                    }
                }
            } else {
                // Empty segments preserve leading and repeated slashes
                $segments[] = $segment;
            }
        }
        if (str_ends_with($path, '/.') || str_ends_with($path, '/..')) {
            $segments[] = '';
        }
        $result = implode('/', $segments);
        return $result === '' ? '/' : $result;
    }

    private function isRedirect(ResponseInterface $response): bool
    {
        return in_array($response->getStatusCode(), self::REDIRECT_STATUSES, true) && $response->getHeaderLine('Location') !== '';
    }

    private function storeCookies(ResponseInterface $response): void
    {
        foreach ($response->getHeader('Set-Cookie') as $header) {
            $pair = explode(';', $header, 2)[0];
            $equals = strpos($pair, '=');
            if ($equals === false) {
                continue;
            }
            $name = trim(substr($pair, 0, $equals));
            $value = trim(substr($pair, $equals + 1));
            if ($name === '') {
                continue;
            }
            if ($value === '') {
                unset($this->cookies[$name]);
                continue;
            }
            $this->cookies[$name] = $value;
        }
    }
}
