# Views

> Displaying stuff

## Using Twig

Kaly doesn't provide a view component out of the box (after all, maybe you don't need one!),
but supports Twig.

Simply require Twig in your project

```
composer require "twig/twig:^3.0"
```

## Configuring Di for Twig

In the app router, we check if there is a definition for the `\Twig\Loader\LoaderInterface`.

Example configuration below

```php
return [
    \Twig\Loader\FilesystemLoader::class . ':paths' => [
        __DIR__ . '/views'
    ],
    \Twig\Loader\FilesystemLoader::class . '->' => [
        [
            'addPath' => [
                'path' => __DIR__ . '/views',
                'namespace' => 'app',
            ]
        ]
    ],
    \Twig\Loader\FilesystemLoader::class . ':rootPath' => function () {
        return getcwd() . '/..';
    },
    \Twig\Loader\LoaderInterface::class => function (\Kaly\Di $di) {
        return $di->get(\Twig\Loader\FilesystemLoader::class);
    }
];
```

And you can of course add additional paths for your modules under another namespace like so

```php
return [
    \Twig\Loader\FilesystemLoader::class . '->' => [
        [
            'addPath' => [
                'path' => __DIR__ . '/views',
                'namespace' => 'admin',
            ]
        ],
    ],
];
```

## Automatically match the template

Based on request attributes, views are automatically matched.
