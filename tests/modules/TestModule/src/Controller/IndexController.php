<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Exception;
use JsonSerializable;
use Kaly\Core\AbstractController;
use Kaly\Http\Exception\RedirectException;
use Kaly\Http\Input\ValidationException;
use Kaly\Http\JsonResult;
use Kaly\Tests\Mocks\SaveInput;
use Kaly\Validation\Validator;
use Kaly\View\View;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class IndexController extends AbstractController
{
    public function index(): string
    {
        return 'hello';
    }

    public function raw(): ResponseInterface
    {
        return new Response(201, ['X-Raw' => 'yes'], 'raw');
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonArray(): array
    {
        return ['value' => new class($this->request->hasHeader('X-Serialization-Failure')) implements JsonSerializable {
            public function __construct(
                private bool $fail,
            ) {}

            public function jsonSerialize(): string
            {
                if ($this->fail) {
                    throw new RuntimeException('Serialization failed');
                }
                return 'serialized';
            }
        }];
    }

    public function jsonResult(): JsonResult
    {
        return new JsonResult($this->jsonArray(), 201, ['X-Json' => 'yes']);
    }

    protected function isinvalid(): string
    {
        return 'never returns because protected';
    }

    public static function staticaction(): string
    {
        return 'never reached because static';
    }

    public function foo(): string
    {
        return 'foo';
    }

    /**
     * @param array<mixed> $arr
     * @return array<mixed>
     */
    public function arr(array $arr): array
    {
        return $arr;
    }

    public function methodGet(): string
    {
        return 'get';
    }

    public function methodPost(): string
    {
        return 'post';
    }

    public function changePost(): string
    {
        return 'mutation-called';
    }

    /**
     * @return array<string,string>
     */
    public function requiredPost(SaveInput $input): array
    {
        return ['test' => $input->test];
    }

    public function middleware(): string
    {
        $attr = $this->request->getAttribute('test-attribute');
        return is_scalar($attr) ? (string) $attr : '';
    }

    public function middlewareException(): never
    {
        $attr = $this->request->getAttribute('test-attribute');
        throw new Exception(is_scalar($attr) ? (string) $attr : '');
    }

    public function getip(): string
    {
        return $this->ctx()->clientIp();
    }

    public function typedInt(int $value): string
    {
        return (string) $value;
    }

    public function typedFloat(float $value): string
    {
        return (string) $value;
    }

    public function typedBool(bool $value): string
    {
        return $value ? 'true' : 'false';
    }

    public function view(): View
    {
        return new View('@TestModule/view', ['title' => 'View test']);
    }

    public function noop(): string
    {
        return '';
    }

    public function getipstate(): string
    {
        return $this->ctx()->clientIp();
    }

    public function redirect(): never
    {
        throw new RedirectException('/test-module');
    }

    public function validation(): never
    {
        $validator = new Validator();
        $validator->notBlank('name', '');
        throw new ValidationException($validator->result());
    }
}
