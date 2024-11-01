# DI

> A strict PSR-11 container

## Usage

The DI container only public methods are `has` and `get` methods. The basic usage is like this:

```php
$di = new Di();

// Get an existing class
$di->get(User::class);
// Check if the class is defined in the container
$di->has(User::class);
```

Any dependency from the User class will be resolved automatically if available in the container.

## Create definitions

In many cases, you will need to define some kind of parameters. In our DI container, this is called a 'definition'.

Definitions can only be added to the constructor as an array like this:

```php
$definitions = [
    // Aliasing or interface binding
    MailingInterface::class => ActualMailer::class,
    // Register instance
    SomeInstance::class => $myClass,
    // Pass positional parameters
    User::class => ['some', 'parameter'],
    PDO::class => ['dsn', 'username', 'password'],
    // Pass named parameters
    OtherClass::class => ['named' => 'some', 'parameter' => 'parameter'],
    // Define as closure
    'closure' => function(Di $di) {
        // do something here...
    },
    // Define as typed closure
    'db' => function (): ?PDO {
        $dsn = $_ENV['PDO_DSN'] ?? null;
        if (!$dsn) {
            return null;
        }
        $username = $_ENV['PDO_USERNAME'] ?? 'root';
        $password = $_ENV['PDO_PASSWORD'] ?? '';
        $options = [
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ];
        return new PDO($dsn, $username, $password, $options);
    }
];
$di = new Di($definitions);
```

There is no way to alter the definitions once the container is created. This is to make sure
that `get` results are predictable and always return the same thing and cannot be changed
by application state. It is recommended to create or load your definitions during the app startup.

Typical definitions are:

-   Aliases: this will allow you to bind interface to actual implementation or to define a shortcut based on a convention (note: the container
    does not check that the class actually implements the interface, since it's considered as a string alias like any other).
-   Arguments: for a given class, define basic arguments that should be passed to the constructor. You can either
    use the actual name of the variables in an associative array, or passed them as a sequential list. Any missing argument will be resolved
    by the DI container if available.
-   Closure: probably the most useful form of definition is the Closure. It allows executing a callback to provide the object
    you want to get. Very useful to read env variable for instance. Closure only get one (optional) argument : the DI container itself.
    Please note that the Closure result will be cached : it is executed only once. The DI container should not be used as some kind
    of factory, please implement them yourself.

Useful tip : when using Closure definitions, you can specify a return type. This will allow to resolve arguments by name
provided that they match the actual type.

For example:

```php
$di = new Di([
    'db' => function(): ?PDO {
        // define stuff here...
        return new PDO($dsn, $username, $password, $options);
    }
]);

class MyController {
    public function __construct(PDO $db) {
        //...
    }
}

$class = $di->get(MyController::class);
```

This behaviour allows to have multiple definitions for the same class.
Aliased definitions are only used over regular classes if a PDO class is not registered.

```php
$di = new Di([
    PDO::class => function(): ?PDO {
        // define stuff here...
        return new PDO($dsn, $username, $password, $options);
    },
    PDO::class . "@adminDb" => function(): ?PDO {
        // define stuff here...
        return new PDO($dsn, $username, $password, $options);
    },
]);

class MyController {
    public function __construct(PDO $db) {
        //...
    }
}
class MyAdminController {
    public function __construct(PDO $adminDb) {
        //...
    }
}

$class = $di->get(MyController::class);
$adminClass = $di->get(MyAdminController::class);
```

Please note that this enforce variable naming convention across your codebase, but we believe it's a good thing.

## This is not a configuration store

Even if you could technically store anything in a PSR container, for instance env variable like this:

```php
// Do not do this, it doesn't work
$di = new Di([
    'db.host' => $_ENV['DB_HOST'] ?? 'localhost',
]);
$host = $di->get('db.host');
```

We do not support this since strings are expected to be class aliases.
This is also why our DI container enforce returning an object as part of the `get` definition.

## Parametrical keys

Maybe you find yourself needing to modify only part of a definition (one specific argument) without
needing knowledge of the full definition.

This is supported using parametrical keys like so.

```php
$def = [
    PDO::class => function () {
        $dsn = getenv("DB_DSN");
        $username =  getenv("DB_USERNAME");
        $password =  getenv("DB_PASSWORD");
        return new PDO($dsn, $username, $password);
    },
    TestObject4::class . ":bar" => function () {
        return 'bar-wrong';
    },
    TestObject4::class . ":baz" => function () {
        return 'baz-right';
    },
];

// Overload
$def[TestObject4::class . ":bar"] = function () {
    return 'bar-right';
};

$container = new Di($def);
$foo = $container->get(TestObject4::class);
```

In this scenario, you can overload a specific argument (in this case `bar`) using the '{id}:{argumentName}' syntax in the container.
Please note that this is not really recommended, but if you do need that level of flexibility, it is possible.

Note: parameters are only looked for actual class implementation, not if the class is called through interface binding.

## Method injection

The Di container support calling methods after the class is created with a specific syntax.

```php
$def = [
    TestObjectInterface::class . '->' => [
        // This is the recommended way to do things
        function(TestObjectInterface $obj, Di $di) {
            $obj->doSomething();
        }
    ],
    TestObject4::class . "->" => [
        'testMethod' => 'one',
        // note:  "val" must match parameter name
        'testMethod2' => ['val' => ['one']],
        // note: regular arrays are merged together
        'testMethod3' => ['one'],
    ],
];
```

By using the '{id}->' syntax, you can define a list of calls that need to be made after creation.

The recommended way to use this feature is to pass an array of closures. The closure will get
the object instance as the first argument, and the Di container as the second argument.

You can also use string based calls to allow overloading configuration, following this convention:

-   The key must be the name of the method that needs to be called
-   The value must be any variable.

Notes about arrays:

-   Associative arrays will be treated as a list of arguments by name
-   Regular arrays ("lists") will be merged together

Calls will be looked for interface and class implementation.

## Queueing method calls

You can queue multiple calls to the same method by wrapping it in an array. The convention
is the same as the one described above. Obviously, you cannot overload a queued call once set.

```php
$def = [
    TestObject4::class . "->" => [
        [
            'testQueue' => 'one',
        ],
        [
            'testQueue' => 'two',
        ],
    ],
];

// But actually, recommended way would be. [] operator works even for undefined indexes
$def[TestObject4::class . '->'][] = ['testQueue' => 'one'];
$def[TestObject4::class . '->'][] = ['testQueue' => 'two'];
```

## Strict definitions

The Di container is very permissive by default, because it will allow you to create
any existing class.

But sometimes, you need actual definitions in order to be able to create useful classes. This
is where strict definitions come into play. You can pass an array of definitions ids that will
need actual definitions instead of simple class_exists calls.

## Exceptions

NotFound and Error exceptions are implemented as anonymous classes. You can still use regular try/catch
blocks as usual based on PSR interface.
