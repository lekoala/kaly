<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

use Kaly\Core\AbstractController;
use Kaly\View\View;

class LocaleEchoFixture extends AbstractController
{
    public function hello(string $locale): string
    {
        return $this->ctx()->locale() . ':' . $locale;
    }

    public function greet(string $locale): View
    {
        return View::of('hello');
    }
}
