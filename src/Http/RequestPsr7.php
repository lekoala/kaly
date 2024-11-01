<?php

declare(strict_types=1);

namespace Kaly\Http;

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\UploadedFileInterface;

/**
 * Implements everything from server request interface
 */
trait RequestPsr7
{
    /**
     * {@inheritdoc}
     */
    public function getAttribute($name, $default = null)
    {
        return $this->request->getAttribute($name, $default);
    }

    /**
     * {@inheritdoc}
     * @return array<string,mixed>
     */
    public function getAttributes(): array
    {
        return $this->request->getAttributes();
    }

    /**
     * {@inheritdoc}
     */
    public function getBody(): StreamInterface
    {
        return $this->request->getBody();
    }

    /**
     * {@inheritdoc}
     * @return mixed
     */
    public function getParsedBody()
    {
        return $this->request->getParsedBody();
    }

    /**
     * {@inheritdoc}
     * @return array<string,string>
     */
    public function getCookieParams(): array
    {
        return $this->request->getCookieParams();
    }

    /**
     * {@inheritdoc}
     */
    public function getHeader($name): array
    {
        return $this->request->getHeader($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaderLine($name): string
    {
        return $this->request->getHeaderLine($name);
    }

    /**
     * {@inheritdoc}
     */
    public function getHeaders(): array
    {
        return $this->request->getHeaders();
    }

    /**
     * {@inheritdoc}
     */
    public function getMethod(): string
    {
        return $this->request->getMethod();
    }

    /**
     * {@inheritdoc}
     */
    public function getProtocolVersion(): string
    {
        return $this->request->getProtocolVersion();
    }

    /**
     * {@inheritdoc}
     * @return array<string,string>
     */
    public function getQueryParams(): array
    {
        return $this->request->getQueryParams();
    }

    /**
     * {@inheritdoc}
     */
    public function getRequestTarget(): string
    {
        return $this->request->getRequestTarget();
    }

    /**
     * {@inheritdoc}
     * @return array<string,string>
     */
    public function getServerParams(): array
    {
        return $this->request->getServerParams();
    }

    /**
     * {@inheritdoc}
     * @return array<UploadedFileInterface>
     */
    public function getUploadedFiles(): array
    {
        return $this->request->getUploadedFiles();
    }

    /**
     * {@inheritdoc}
     */
    public function getUri(): UriInterface
    {
        return $this->request->getUri();
    }

    /**
     * {@inheritdoc}
     */
    public function hasHeader($name): bool
    {
        return $this->request->hasHeader($name);
    }

    /**
     * {@inheritdoc}
     */
    public function withAddedHeader($name, $value): MessageInterface
    {
        $serverRequest = $this->request->withAddedHeader($name, $value);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withAttribute($name, $value): ServerRequestInterface
    {
        $serverRequest = $this->request->withAttribute($name, $value);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     * @param array<string,mixed> $attributes
     */
    public function withAttributes(array $attributes): ServerRequestInterface
    {
        $serverRequest = $this->request;

        foreach ($attributes as $attribute => $value) {
            $serverRequest = $serverRequest->withAttribute($attribute, $value);
        }

        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withoutAttribute($name): ServerRequestInterface
    {
        $serverRequest = $this->request->withoutAttribute($name);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withBody(StreamInterface $body): MessageInterface
    {
        $serverRequest = $this->request->withBody($body);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     * @param array<string,string> $cookies
     */
    public function withCookieParams(array $cookies): ServerRequestInterface
    {
        $serverRequest = $this->request->withCookieParams($cookies);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withHeader($name, $value): MessageInterface
    {
        $serverRequest = $this->request->withHeader($name, $value);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withoutHeader($name): MessageInterface
    {
        $serverRequest = $this->request->withoutHeader($name);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withMethod($method): RequestInterface
    {
        $serverRequest = $this->request->withMethod($method);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     * @param array<mixed> $data
     */
    public function withParsedBody($data): ServerRequestInterface
    {
        $serverRequest = $this->request->withParsedBody($data);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withProtocolVersion($version): MessageInterface
    {
        $serverRequest = $this->request->withProtocolVersion($version);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     * @param array<string,string> $query
     */
    public function withQueryParams(array $query): ServerRequestInterface
    {
        $serverRequest = $this->request->withQueryParams($query);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withRequestTarget($requestTarget): RequestInterface
    {
        $serverRequest = $this->request->withRequestTarget($requestTarget);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     * @param array<UploadedFileInterface> $uploadedFiles
     */
    public function withUploadedFiles(array $uploadedFiles): ServerRequestInterface
    {
        $serverRequest = $this->request->withUploadedFiles($uploadedFiles);
        return new static($serverRequest);
    }

    /**
     * {@inheritdoc}
     */
    public function withUri(UriInterface $uri, $preserveHost = false): RequestInterface
    {
        $serverRequest = $this->request->withUri($uri, $preserveHost);
        return new static($serverRequest);
    }
}
