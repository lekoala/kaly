<?php

declare(strict_types=1);

namespace Kaly\Test;

use Kaly\Core\App;
use LogicException;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * An in-process HTTP client for functional tests, not a browser emulator.
 *
 * It exercises exactly the boundary Kaly defines — PSR-7 request in,
 * PSR-7 response out — with no socket, no server and no browser state:
 * no redirect following, no history, no DOM.
 *
 * Kaly\Test is provided by Kaly but is not part of the production runtime.
 */
final class TestClient
{
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
        $requests = $app->getContainer()->get(ServerRequestFactoryInterface::class);
        if (!$requests instanceof ServerRequestFactoryInterface) {
            throw new LogicException('The container must provide a ' . ServerRequestFactoryInterface::class);
        }
        $streams = $app->getContainer()->get(StreamFactoryInterface::class);
        if (!$streams instanceof StreamFactoryInterface) {
            throw new LogicException('The container must provide a ' . StreamFactoryInterface::class);
        }
        return new self($app, $requests, $streams);
    }

    /**
     * Send a request and get the response with fluent assertions.
     *
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,json?:mixed,body?:string|StreamInterface} $options
     *   Closed vocabulary: headers to send, query params merged into the uri,
     *   one of json (encoded, JSON content type), form (urlencoded body, parsed
     *   body set as a server would) or a raw body.
     */
    public function request(string $method, string $uri, array $options = []): TestResponse
    {
        $unknown = array_diff(array_keys($options), ['headers', 'query', 'form', 'json', 'body']);
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown request options: ' . implode(', ', $unknown));
        }
        $bodies = array_intersect(['json', 'form', 'body'], array_keys($options));
        if (count($bodies) > 1) {
            throw new \InvalidArgumentException('Only one of json, form or body may be given, got: ' . implode(', ', $bodies));
        }

        $request = $this->requests->createServerRequest($method, $uri);
        foreach ($options['headers'] ?? [] as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if (!empty($options['query'] ?? [])) {
            $current = [];
            parse_str($request->getUri()->getQuery(), $current);
            $merged = array_merge($current, $options['query']);
            $request = $request->withUri($request->getUri()->withQuery(http_build_query($merged)))->withQueryParams($merged);
        }
        if (array_key_exists('json', $options)) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streams->createStream((string) json_encode($options['json'])));
        } elseif (array_key_exists('form', $options)) {
            $request = $request
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
                ->withParsedBody($options['form'])
                ->withBody($this->streams->createStream(http_build_query($options['form'])));
        } elseif (array_key_exists('body', $options)) {
            $body = $options['body'];
            $request = $request->withBody($body instanceof StreamInterface ? $body : $this->streams->createStream($body));
        }

        return new TestResponse($this->handler->handle($request));
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,json?:mixed,body?:string|StreamInterface} $options
     */
    public function get(string $uri, array $options = []): TestResponse
    {
        return $this->request('GET', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,json?:mixed,body?:string|StreamInterface} $options
     */
    public function post(string $uri, array $options = []): TestResponse
    {
        return $this->request('POST', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,json?:mixed,body?:string|StreamInterface} $options
     */
    public function put(string $uri, array $options = []): TestResponse
    {
        return $this->request('PUT', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,json?:mixed,body?:string|StreamInterface} $options
     */
    public function patch(string $uri, array $options = []): TestResponse
    {
        return $this->request('PATCH', $uri, $options);
    }

    /**
     * @param array{headers?:array<string,string>,query?:array<string,mixed>,form?:array<string,mixed>,json?:mixed,body?:string|StreamInterface} $options
     */
    public function delete(string $uri, array $options = []): TestResponse
    {
        return $this->request('DELETE', $uri, $options);
    }
}
