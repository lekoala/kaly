<?php

declare(strict_types=1);

use Kaly\Core\Module;

return static function (Module $module): void {
    $module->mount('boutique');
};
