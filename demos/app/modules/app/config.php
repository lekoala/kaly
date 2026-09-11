<?php

/** @var Kaly\Core\Module $this */

use Kaly\Log\FileLogger;
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;

// Main module
$this->definitions()
    ->set(RendererInterface::class, new KalyTplRenderer(new ViewEngine(__DIR__ . '/templates')))
    ->set('debugLogger', fn() => new FileLogger(dirname(dirname(__DIR__)) . '/temp/debug.log'))
    ->lock();
