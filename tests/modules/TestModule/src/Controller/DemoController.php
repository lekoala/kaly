<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;

class DemoController extends AbstractController
{
    public function index(string $param = ''): string
    {
        if ($param) {
            return "hello {$param}";
        }
        return 'hello demo';
    }

    public function indexPost(): string
    {
        return 'demo posted';
    }

    public function lastPostGet(): string
    {
        return 'last post';
    }

    public function probeGet(): ResponseInterface
    {
        return new Response(200, ['X-Action' => 'get'], 'probe');
    }

    public function probeHead(): ResponseInterface
    {
        return new Response(200, ['X-Action' => 'head']);
    }

    public function moveItemPost(int $id): string
    {
        return "moved {$id}";
    }

    public function methodGet(): string
    {
        return 'get';
    }

    public function methodPost(): string
    {
        return 'post';
    }

    public function func(): string
    {
        return 'hello func';
    }

    //@codingStandardsIgnoreLine
    public function hello_func(): string
    {
        return 'hello underscore';
    }

    public function arr(string ...$args): string
    {
        return 'hello ' . implode(',', $args);
    }

    public function arrplus(string $test, string ...$args): string
    {
        return 'hello ' . $test . ',' . implode(',', $args);
    }
}
