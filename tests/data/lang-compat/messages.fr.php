<?php

declare(strict_types=1);

return [
    'compat' => [
        'flat' => 'Message plat',
        'nested' => [
            'deep' => 'Message imbriqué',
        ],
        'percent' => 'Bonjour %name%',
        'braces' => 'Bonjour {name}',
        'hash' => 'Bonjour #name#',
        'mixed' => 'Bonjour %name% alias {name}',
    ],
    'standalone.flat' => 'Message déjà plat',
    'empty_translation' => '',
    'zero_translation' => '0',
    'null_translation' => null,
];
