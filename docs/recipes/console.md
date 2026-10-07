---
layout: default
title: Console recipe
nav_order: 4
---
# Console recipe

There is no `Kaly\Console`: commands reuse the same composition as HTTP
through one shared bootstrap. The container, the modules and the use cases are
identical — only the entry point differs:

```php
// bootstrap.php
use Kaly\Core\App;

return App::create(__DIR__);
```

```php
// public/index.php (HTTP)
$app = require dirname(__DIR__) . '/bootstrap.php';
$app->run();
```

```php
// bin/cleanup.php (CLI)
$app = require dirname(__DIR__) . '/bootstrap.php';
$app->boot();

$app->container()->get(Cleanup::class)();
```

Create and close resources inside the script; never simulate an HTTP request
to run a job. Symfony Console, when wanted, is just a CLI UI around the same
use cases.
