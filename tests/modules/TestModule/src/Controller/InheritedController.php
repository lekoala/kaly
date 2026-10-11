<?php

declare(strict_types=1);

namespace TestModule\Controller;

final class InheritedController extends AbstractListingController
{
    public function show(): string
    {
        return 'shown';
    }
}
