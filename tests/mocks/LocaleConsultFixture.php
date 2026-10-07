<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

class LocaleConsultFixture
{
    public function consult(string $locale): string
    {
        return $locale;
    }
}
