<?php

declare(strict_types=1);

return [
    'compat' => [
        'flat' => 'Flat message',
        'nested' => [
            'deep' => 'Nested message',
        ],
        'percent' => 'Hello %name%',
        'braces' => 'Hello {name}',
        'hash' => 'Hello #name#',
        'mixed' => 'Hello %name% aka {name}',
    ],
    'standalone.flat' => 'Already flat message',
    'empty_translation' => '',
    'zero_translation' => '0',
    'null_translation' => null,
];
