<?php

use Kaly\View\AssetsExtension;
use Kaly\View\Engine;

require "../vendor/autoload.php";

$engine = new Engine([
    __DIR__ . '/views',
    'email' => __DIR__ . '/emails',
]);
$engine->addExtension(new AssetsExtension(__DIR__ . '/views/assets', '/assets', true));
$engine->setGlobal('my_global', 'global value');
$engine->setGlobal('my other global', 'other global value');

$user = new class {
    public int $id = 42;
    public string $firstname = "firstname";
    public string $lastname = "surname";

    public function fullname()
    {
        return $this->firstname . ' ' . $this->lastname;
    }
};

$data = [
    'my other global' => 'overwritten global',
    'defined_in_template' => 'from data',
    'safe' => "<script>alert('test')</script>",
    'raw' => "<b>bold</b>",
    'user' => new $user(),
    'arr' => [
        'name' => 'arr name using dot notation',
        'name_html' => '<b>arr name</b>',
    ],
    'items' => [
        ['name' => 'item 1'],
        ['name' => 'item 2'],
    ]
];
$result = $engine->render(
    'demo',
    $data
);
echo $result;


$email = $engine->render(['email', 'welcome']);
d($email);
