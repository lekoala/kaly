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
to run a job.

## Arguments

`Kaly\Util\Args` parses `$argv` against the long options the script declares,
so `--dry-run export.zip` is never read as `--dry-run=export.zip`:

```php
// bin/import.php
use Kaly\Ex;
use Kaly\Util\Args;

try {
    $args = Args::parse($argv, flags: ['dry-run'], values: ['format']);
    $export = $args->positional(0);
    $format = $args->value('format') ?? 'html';
} catch (Ex $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Usage: php bin/import.php [--dry-run] [--format=html|csv] <export.zip>' . PHP_EOL);
    exit(2);
}

$app = require dirname(__DIR__) . '/bootstrap.php';
$app->boot();

$app->container()->get(ImportExport::class)($export, $format, $args->flag('dry-run'));
```

Flags are booleans, values are strings, positionals keep their order.
`--name=value`, `--name value`, options before or after positionals and the
`--` separator are accepted. A value starting with `-` needs `=`
(`--offset=-5`), except a lone `-`. Unknown, repeated or malformed options and
missing positionals throw `Kaly\Ex`; `Args` never writes to `STDERR` or exits,
so the script keeps its exit codes. Read every argument before running the use
case: a usage error then never interrupts work halfway.

Short options, `--no-*` negation, repeated values, typed values, generated help
and subcommands are deliberately out of scope. When a script needs them, use
`Symfony\Component\Console\Input\ArgvInput` with an `InputDefinition` — it works
without an `Application` — or Symfony Console as a full CLI UI around the same
use cases.
