<?php

use Kaly\Text\Ollama;

require dirname(__DIR__) . '/vendor/autoload.php';

$ollama = new Ollama();

$res = $ollama->generate("What is the capital of France?");

print($res['response']);
