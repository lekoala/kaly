<?php

declare(strict_types=1);

namespace Kaly\Http;

/**
 * An explicit controller result: data rendered as JSON, with its status code
 * and headers.
 *
 * The dispatcher turns it into a response. A plain array result stays the
 * shorthand for a 200 JSON response.
 */
final readonly class JsonResponse
{
    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $headers
     */
    public function __construct(
        public array $data = [],
        public int $status = 200,
        public array $headers = [],
    ) {}

    /**
     * @param array<string,mixed> $data
     * @param array<string,string> $headers
     */
    public static function of(array $data, int $status = 200, array $headers = []): self
    {
        return new self($data, $status, $headers);
    }
}
