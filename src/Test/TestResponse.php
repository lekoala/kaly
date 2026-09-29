<?php

declare(strict_types=1);

namespace Kaly\Test;

use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;

/**
 * The response of TestClient::request(), with fluent assertions.
 *
 * Assertions are plain PHPUnit assertions: failures are real PHPUnit errors
 * with the usual diffs and messages. Kaly\Test is provided by Kaly but is not
 * part of the production runtime: PHPUnit stays a development dependency, and
 * no assertion machinery of ours ever mirrors it.
 */
final class TestResponse
{
    public function __construct(
        private ResponseInterface $response,
    ) {}

    /**
     * Back to plain PSR-7.
     */
    public function response(): ResponseInterface
    {
        return $this->response;
    }

    public function assertStatus(int $status): static
    {
        Assert::assertSame($status, $this->response->getStatusCode());
        return $this;
    }

    public function assertHeader(string $name, string $value): static
    {
        Assert::assertSame($value, $this->response->getHeaderLine($name), "Header '{$name}'");
        return $this;
    }

    public function assertLocation(string $url): static
    {
        return $this->assertHeader('Location', $url);
    }

    public function assertBody(string $body): static
    {
        Assert::assertSame($body, (string) $this->response->getBody());
        return $this;
    }

    /**
     * @param array<string,mixed> $expected The decoded structure (maps compare order-insensitively)
     */
    public function assertJson(array $expected): static
    {
        $decoded = json_decode((string) $this->response->getBody(), true);
        Assert::assertIsArray($decoded, 'The response body is not valid JSON');
        Assert::assertEquals($expected, $decoded);
        return $this;
    }
}
