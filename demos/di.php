<?php

use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Psr\Log\LoggerInterface;
use Kaly\Di\CircularReferenceException;
use Kaly\Log\FileLogger;

require "../vendor/autoload.php";
require "di/demo-classes.php";

$app = new App();

function doSomething(SomeClass $class, LoggerInterface $logger)
{
    return $class;
}

$inlineFn = function (SomeClass $class, int $c) {
    return $c;
};

$logFile = __DIR__ . '/demo2.log';
$definitions = Definitions::create()
    // You can store objects directly
    ->set(App::class, $app)
    // Usage of closure is recommended to avoid instantiating class when they are not used
    ->set('db', fn() => new ExtendedPDO('sqlite::memory:'))
    ->set('backup_db', fn() => new ExtendedPDO('sqlite::memory:'))
    // This will map backupDb to backup_db entry when resolving instances of PDO
    ->resolve(PDO::class, 'backupDb', 'backup_db')
    // If you pass a wrong type, it will throw an exception
    // ->set('backup_db', fn () => new WrongClass('sqlite::memory:'))
    ->set('backup_db', fn() => new BackupPDO('sqlite::memory:'))
    // This callback will be applied to both named pdo instances
    ->callback(PDO::class, fn(PDO $pdo) => $pdo->exec('PRAGMA stats;'))
    ->parameter(PDO::class, 'dsn', 'sqlite::memory:')
    ->set('customLogger', fn() => new FileLogger($logFile))
    ->callback('customLogger', fn(LoggerInterface $inst) => $inst->log('debug', "I'm initialized from the container"))
    ->bind(FileLogger::class, destination: __DIR__ . '/demo.log')
    // Add all interfaces
    ->add(new IterableAndCountable())
    ->unlock();

$container = new Container($definitions);
$injector = new Injector($container);

// SomeClass is provided by container (even if there is no definition for it)
$injectorResultInline = $injector->invoke($inlineFn, c: 42);
$injectorResultCallable = $injector->invoke('doSomething');

// you can also add new definitions after the container as been instantiated
// you probably shouldn't be doing this but i'm not here to judge :-)
$definitions->set('someAlias', SomeClass::class);

assert($container->has(LoggerInterface::class));
assert($container->has(SomeClass::class));
assert($container->has('customLogger'));
assert($container->has('someAlias'));

$UnionClass = $container->get(UnionClass::class);
assert($UnionClass instanceof UnionClass);

$IntersectionClass = $container->get(IntersectionClass::class);
assert($IntersectionClass instanceof IntersectionClass);

// has fail if not here
// assert($container->has('somethingNotHere'));

// get() always expect a class-string argument
// see https://phpstan.org/blog/generics-by-examples#function-accepts-any-string%2C-but-returns-object-of-the-same-type-if-it%E2%80%99s-a-class-string
$logger = $container->get(LoggerInterface::class);
$customLogger = $container->get('customLogger');
// even if we didn't define it, it can be built
$someclass = $container->get(SomeClass::class);
// and it is cached
$someclass2 = $container->get(SomeClass::class);
assert($someclass === $someclass2);
$appFromContainer = $container->get(App::class);
assert($app === $appFromContainer);
//
$backupService = $container->get(BackupService::class);

// circular references throw errors
// they can be prevented by defining parameters behorehand
try {
    $circular = $container->get(CircularA::class);
} catch (CircularReferenceException $e) {
    echo $e->getMessage();
}

// Validate definitions using assert
// see https://www.php.net/manual/en/function.assert.php
// $definitions = (new Definitions())
//     ->bind(SomeClassThatDoesntExist::class);

// We got the idea...
if (is_file($logFile)) {
    unlink($logFile);
}

d($container, $logger, $someclass, $backupService, $injectorResultInline, $injectorResultCallable);
