<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;
use Kaly\Router\RouteAttribute;

class AttributeDemoController extends AbstractController
{
    #[RouteAttribute('/attr/hello', methods: ['GET'], name: 'attr.hello')]
    #[RouteAttribute('/legacy/hello', methods: ['GET'], name: 'attr.hello-legacy')]
    public function hello(): string
    {
        return 'attr-hello';
    }

    #[RouteAttribute('/attr/item/{id}', methods: ['GET'], name: 'attr.item', requirements: ['id' => '\d+'])]
    public function item(int $id): string
    {
        return 'item-' . $id;
    }

    #[RouteAttribute('/attr/save', methods: ['POST'], name: 'attr.save')]
    public function savePost(): string
    {
        return 'attr-saved';
    }

    #[RouteAttribute('/attr/priority', methods: ['GET'], name: 'attr.priority-low', priority: 0)]
    #[RouteAttribute('/attr/priority', methods: ['GET'], name: 'attr.priority-high', priority: 100)]
    public function priority(): string
    {
        return 'attr-priority';
    }
}
