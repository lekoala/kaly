<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;
use Kaly\Tests\Mocks\SearchInput;
use Psr\Log\LoggerInterface;

class InputController extends AbstractController
{
    public function search(SearchInput $input): string
    {
        return "{$input->q}:{$input->page}";
    }

    public function edit(int $id, SearchInput $input): string
    {
        return "{$id}:{$input->q}";
    }

    // An optional scalar omitted from the url keeps its default before the input
    public function pagedSearch(int $page = 1, ?SearchInput $input = null): string
    {
        return "{$page}:" . ($input->q ?? '');
    }

    // A RequestInput must be the last parameter
    public function badOrder(SearchInput $input, int $id): string
    {
        return "{$input->q}:{$id}";
    }

    // Services belong to the constructor, never to an action
    public function withService(?LoggerInterface $logger = null): string
    {
        return get_debug_type($logger);
    }
}
