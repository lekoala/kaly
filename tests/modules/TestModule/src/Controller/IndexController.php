<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Exception;
use Kaly\Core\AbstractController;
use Kaly\Http\RedirectException;
use Kaly\Http\ValidationException;
use Kaly\View\View;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

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

    protected function isinvalid(): string
    {
        return 'never returns because protected';
    }

    public function foo(): string
    {
        return 'foo';
    }

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

    public function changePost(array $body = []): string
    {
        return 'mutation-called';
    }

    public function requiredPost(array $body): array
    {
        return $body;
    }

    public function middleware(): string
    {
        $attr = $this->request->getAttribute('test-attribute');
        return (string) $attr;
    }

    public function middlewareException(): never
    {
        throw new Exception($this->request->getAttribute('test-attribute'));
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

    public function noop(): void {}

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
        throw new ValidationException('This is invalid');
    }
}
