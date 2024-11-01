<?php

declare(strict_types=1);

namespace Kaly\Http;

use Kaly\Http\HttpFactory;
use Psr\Http\Message\ResponseInterface;
use Kaly\Core\Ex;
use Kaly\Http\ResponseProviderInterface;
use Kaly\Util\Json;

class ResponseException extends Ex implements ResponseProviderInterface
{
    protected ?string $dataType = null;
    protected ?string $contentType = null;

    public function getResponse(): ResponseInterface
    {
        $code = $this->getIntCode();
        if ($code === 0) {
            $code = 200;
        }
        return match ($this->dataType) {
            'svg' => HttpFactory::createResponse($this->getMessage(), $code, [
                'Content-Type' => ContentType::SVG
            ]),
            'json' => HttpFactory::createJsonResponse($this->getMessage(), $code),
            'html' => HttpFactory::createHtmlResponse($this->getMessage(), $code),
            default => HttpFactory::createResponse($this->getMessage(), $code),
        };
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
