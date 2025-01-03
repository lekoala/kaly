<?php

namespace Kaly\Tests\Mocks;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TestMiddleware implements MiddlewareInterface
{
    public const DEFAULT_ATTR = "test-attribute";
    public const DEFAULT_VALUE = "test-value";
    protected string $attribute = "test-attribute";
    protected string $value = "test-value";

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $updatedRequest = $request->withAttribute($this->attribute, $this->value);
        return $handler->handle($updatedRequest);
    }

    public function getAttribute(): string
    {
        return $this->attribute;
    }

    public function setAttribute(string $attribute): self
    {
        $this->attribute = $attribute;
        return $this;
    }

    public function getValue(): string
    {
        return $this->value;
    }

    public function setValue(string $value): self
    {
        $this->value = $value;
        return $this;
    }
}
