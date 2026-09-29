<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;

/**
 * Reached through the route table of the module (see config.php)
 */
class AliasController extends AbstractController
{
    public function hello(): string
    {
        return 'alias-hello';
    }

    public function item(int $id): string
    {
        return 'item-' . $id;
    }

    public function savePost(): string
    {
        return 'alias-saved';
    }

    public function priority(): string
    {
        return 'alias-priority';
    }
}
