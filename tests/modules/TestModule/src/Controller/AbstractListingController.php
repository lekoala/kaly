<?php

declare(strict_types=1);

namespace TestModule\Controller;

use Kaly\Core\AbstractController;

/**
 * A shared parent: abstract, so the convention never resolves it directly.
 */
abstract class AbstractListingController extends AbstractController
{
    public function list(): string
    {
        return 'listed ' . $this->label();
    }

    protected function label(): string
    {
        return 'items';
    }
}
