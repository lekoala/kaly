<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Core\Ex;
use Kaly\Util\Json;

class ResponseException extends Ex implements HttpExceptionInterface
{
    protected ?string $dataType = null;
    protected ?string $contentType = null;

    public function getResponseHeaders(): array
    {
        return match ($this->dataType) {
            'svg' => ['Content-Type' => ContentType::SVG],
            'json' => ['Content-Type' => ContentType::JSON],
            'html' => ['Content-Type' => ContentType::HTML],
            default => [],
        };
    }

    public function getResponseBody(): string
    {
        $message = $this->getMessage();
        if ($this->dataType === 'json') {
            return $message === '' ? '[]' : Json::encode(['message' => $message]);
        }
        return $message;
    }

    public static function svg(string $message, int $code = 200): self
    {
        $inst = new self($message, $code);
        $inst->setDataType('svg');
        return $inst;
    }

    public static function html(string $message, int $code = 200): self
    {
        $inst = new self($message, $code);
        $inst->setDataType('html');
        return $inst;
    }

    /**
     * @param string|array<mixed> $message
     * @param int $code
     * @return self
     */
    public static function json(string|array $message, int $code = 200): self
    {
        if (is_array($message)) {
            $message = Json::encode($message);
        }
        $inst = new self($message, $code);
        $inst->setDataType('json');
        return $inst;
    }

    public function getDataType(): ?string
    {
        return $this->dataType;
    }

    public function setDataType(string $dataType): void
    {
        $this->dataType = $dataType;
    }
}
