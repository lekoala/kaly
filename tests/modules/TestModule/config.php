<?php

/** @var Kaly\Core\Module $this */

use Kaly\Http\InputMapperInterface;
use Kaly\Log\FileLogger;
use Kaly\Router\ClassRouter;
use Kaly\Tests\Mocks\TestInputMapper;
use Kaly\Tests\Mocks\TestInterface;
use Kaly\Tests\Mocks\TestObject;
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

$value_is_not_leaked = 'test';

$this
    ->definitions()
    ->bind(RequestFactoryInterface::class, Psr17Factory::class)
    ->bind(ResponseFactoryInterface::class, Psr17Factory::class)
    ->bind(ServerRequestFactoryInterface::class, Psr17Factory::class)
    ->bind(StreamFactoryInterface::class, Psr17Factory::class)
    ->bind(UploadedFileFactoryInterface::class, Psr17Factory::class)
    ->bind(UriFactoryInterface::class, Psr17Factory::class)
    ->bind(TestInterface::class, TestObject::class)
    ->bind(InputMapperInterface::class, TestInputMapper::class)
    ->set(RendererInterface::class, new KalyTplRenderer(new ViewEngine(__DIR__ . '/templates')))
    ->callback(ClassRouter::class, function (ClassRouter $router): void {
        $router->setAllowedLocales(['en', 'fr'], ['LangModule']);
        $router->addAllowedNamespace('TestModule');
    })
    ->set(Kaly\Core\App::DEBUG_LOGGER, function (): FileLogger {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $basePath = substr((string) $script, 0, strpos((string) $script, 'vendor' . DIRECTORY_SEPARATOR . 'bin'));
        return new FileLogger("{$basePath}/tests.log");
    })
    ->lock();
