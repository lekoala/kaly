<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class RouteHandlerFixture
{
    public function show(int $id): string
    {
        return (string) $id;
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
