<?php

declare(strict_types=1);

namespace Kaly\Tests\Mocks;

enum PatientStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
}
