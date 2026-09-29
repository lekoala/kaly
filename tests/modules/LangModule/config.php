<?php

declare(strict_types=1);

use Kaly\Core\Module;

// Mounted on /lang-module/, with the locale prefix of the app locales
return static function (Module $module): void {
    $module->localized();
};
