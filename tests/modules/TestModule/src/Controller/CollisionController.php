<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;

/**
 * show() and showGet() would both answer GET /collision/show/, index() and
 * indexGet() GET /collision/.
 */
final class CollisionController extends AbstractController
{
    public function index(): string
    {
        return 'bare index';
    }

    public function indexGet(): string
    {
        return 'suffixed index';
    }

    public function show(): string
    {
        return 'bare';
    }

    public function showGet(): string
    {
        return 'suffixed';
    }
}
