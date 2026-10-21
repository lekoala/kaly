<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Psr\Http\Message\ServerRequestInterface;

class RouteHandlerFixture
{
    public function show(int $id): string
    {
        return (string) $id;
    }

    public function optional(int $id = 7): string
    {
        return (string) $id;
    }

    public function slug(string $slug): string
    {
        return $slug;
    }

    public function withInput(SearchInput $input): string
    {
        return $input->q;
    }

    public function objectParam(ServerRequestInterface $request): string
    {
        return 'request';
    }

    public function __invoke(): string
    {
        return 'invoked';
    }

    protected function hidden(): string
    {
        return 'hidden';
    }

    public static function staticAction(): string
    {
        return 'static';
    }
}
