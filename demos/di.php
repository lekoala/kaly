<?php

use Kaly\Di\Container;
use Kaly\Di\Definitions;
use Kaly\Di\Injector;
use Kaly\Logger;
use Psr\Log\LoggerInterface;
use Kaly\Di\CircularReferenceException;
use Kaly\Di\Reference;

require "../vendor/autoload.php";

// A basic class taking a parameter
class SomeClass
{
    protected float $v;
    public function __construct(
        public LoggerInterface $logger
    ) {
        // provide an unique value
        $this->v = microtime(true);
    }
}

// A circular scenario
class CircularA
{
    public function __construct(
        public CircularB $inst
    ) {}
}

class CircularB
{
    public function __construct(
        public CircularA $inst
    ) {}
}

class ExtendedPDO extends PDO {}

class WrongClass
{
    public function __construct(
        public string $dsn
    ) {}
}

// A class that takes two pdo instances
// Named service can be found based on variable name or using the reference definition
class BackupService
{
    public function __construct(
        public PDO $db,
        public PDO $backupDb
    ) {}
}

class App {}
$app = new App();

function doSomething(SomeClass $class, LoggerInterface $logger)
{
    return $class;
}

$inlineFn = function (SomeClass $class, int $c) {
    return $c;
};

$logFile = __DIR__ . '/demo2.log';
$definitions = (new Definitions())
    // You can store objects directly
    ->set(App::class, $app)
    // Usage of closure is recommended to avoid instantiating class when they are not used
    ->set('db', fn() => new ExtendedPDO('sqlite::memory:'))
    ->set('backup_db', fn() => new ExtendedPDO('sqlite::memory:'))
    // This will map backupDb to backup_db entry when resolving instances of PDO
    ->resolve(PDO::class, 'backupDb', 'backup_db')
    // If you pass a wrong type, it will throw an exception
    // ->set('backup_db', fn () => new WrongClass('sqlite::memory:'))
    // This callback will be applied to both named pdo instances
    ->callback(PDO::class, fn(PDO $pdo) => $pdo->exec('PRAGMA stats;'))
    // ->parameter(PDO::class, 'dsn', 'sqlite::memory:')
    ->set('customLogger', fn() => new Logger($logFile))
    ->callback('customLogger', fn(LoggerInterface $inst) => $inst->log('debug', "I'm initialized from the container"))
    ->bind(Logger::class, destination: __DIR__ . '/demo.log');
$container = new Container($definitions);

$injector = new Injector($container);
$injectorResultInline = $injector->invoke($inlineFn, c: 42);
$injectorResultCallable = $injector->invoke('doSomething');

// you can also add new definitions after the container as been instantiated
// you probably shouldn't be doing this but i'm not here to judge :-)
$definitions->set('someAlias', SomeClass::class);

assert($container->has(LoggerInterface::class));
assert($container->has(SomeClass::class));
assert($container->has('customLogger'));
assert($container->has('someAlias'));

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

// cloning will clear the instance cache of the cloned container
$containerClone = clone $container;
$appFromClonedContainer = $containerClone->get(App::class);
$someclassCloned = $containerClone->get(SomeClass::class);

// still the same, because it is set from the definitions
assert($appFromClonedContainer === $appFromContainer);
// not the same, because we cleared the instance cache
assert($someclassCloned !== $someclass);

// Validate definitions using assert
// see https://www.php.net/manual/en/function.assert.php
// $definitions = (new Definitions())
//     ->bind(SomeClassThatDoesntExist::class);

// We got the idea...
if (is_file($logFile)) {
    unlink($logFile);
}

d($container, $logger, $someclass, $backupService, $injectorResultInline, $injectorResultCallable, $containerClone);
