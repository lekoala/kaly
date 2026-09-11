<?php

/** @var Kaly\Core\Module $this */

use Kaly\Log\FileLogger;
use Kaly\Tpl\ViewEngine;
use Kaly\View\Adapter\KalyTplRenderer;
use Kaly\View\RendererInterface;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

// Main module
$this->definitions()
    ->bind(RequestFactoryInterface::class, Psr17Factory::class)
    ->bind(ResponseFactoryInterface::class, Psr17Factory::class)
    ->bind(ServerRequestFactoryInterface::class, Psr17Factory::class)
    ->bind(StreamFactoryInterface::class, Psr17Factory::class)
    ->bind(UploadedFileFactoryInterface::class, Psr17Factory::class)
    ->bind(UriFactoryInterface::class, Psr17Factory::class)
    ->set(RendererInterface::class, new KalyTplRenderer(new ViewEngine(__DIR__ . '/templates')))
    ->set('debugLogger', fn() => new FileLogger(dirname(dirname(__DIR__)) . '/temp/debug.log'))
    ->lock();
