<?php

declare(strict_types=1);

/**
 * Reproducible boot and request benchmark.
 *
 * ```bash
 * php benchmarks/boot.php                              # the demo app
 * php benchmarks/boot.php demos/app /simple-module/    # an app and a path
 * php benchmarks/boot.php --synthetic=100 /site/thing3/action2/
 * ```
 *
 * Two execution models are measured:
 *
 * - fpm: one process per request (autoload, boot, handle), like PHP-FPM.
 *   Opcache is warm through its file cache, with timestamps validated, so an
 *   edited file is never served stale.
 * - worker: boot once, then handle many requests, like FrankenPHP or
 *   RoadRunner workers.
 *
 * Results are medians in milliseconds. Only relative values are meaningful:
 * compare two commits on the same machine.
 *
 * Internal: `--child=<app> <path>` runs one fpm-like request and prints its
 * timings.
 */

$root = dirname(__DIR__);

if (isset($argv[1]) && str_starts_with($argv[1], '--child=')) {
    $t0 = hrtime(true);
    require $root . '/vendor/autoload.php';
    $t1 = hrtime(true);
    $app = Kaly\Core\App::create(substr($argv[1], 8), false);
    $app->boot();
    $t2 = hrtime(true);
    $response = $app->handle(new Nyholm\Psr7\ServerRequest('GET', $argv[2] ?? '/'));
    $t3 = hrtime(true);
    echo json_encode([
        'autoload' => ($t1 - $t0) / 1e6,
        'boot' => ($t2 - $t1) / 1e6,
        'request' => ($t3 - $t2) / 1e6,
        'status' => $response->getStatusCode(),
    ]), "\n";
    exit(0);
}

$options = getopt('', ['synthetic::', 'runs::', 'requests::'], $rest);
$args = array_slice($argv, $rest);
$runs = (int) ($options['runs'] ?? 25);
$requests = (int) ($options['requests'] ?? 1000);

if (isset($options['synthetic'])) {
    $app = synthetic((int) $options['synthetic']);
    $path = $args[0] ?? '/site/thing0/action0/';
} else {
    $app = realpath($args[0] ?? $root . '/demos/app') ?: throw new RuntimeException('Unknown app directory');
    $path = $args[1] ?? '/';
}

$opcacheDir = sys_get_temp_dir() . '/kaly-bench-opcache';
@mkdir($opcacheDir);
$child = [
    PHP_BINARY,
    '-d', 'opcache.enable_cli=1',
    '-d', 'opcache.file_cache=' . $opcacheDir,
    '-d', 'opcache.file_cache_only=1',
    '-d', 'opcache.validate_timestamps=1',
    __FILE__,
    '--child=' . $app,
    $path,
];

// Warm up the opcache file cache
for ($i = 0; $i < 3; $i++) {
    runChild($child);
}
$samples = [];
for ($i = 0; $i < $runs; $i++) {
    $samples[] = runChild($child);
}

printf("app      %s\npath     %s\n\n", $app, $path);
printf("fpm      median of %d processes\n", $runs);
foreach (['autoload', 'boot', 'request'] as $key) {
    printf("  %-8s %6.2f ms\n", $key, median(array_column($samples, $key)));
}
printf("  status   %d\n\n", $samples[0]['status']);

// Worker: boot once, then many requests in the same process
require $root . '/vendor/autoload.php';
$t = hrtime(true);
$worker = Kaly\Core\App::create($app, false)->boot();
$boot = (hrtime(true) - $t) / 1e6;
$timings = [];
for ($i = 0; $i < $requests; $i++) {
    $t = hrtime(true);
    $worker->handle(new Nyholm\Psr7\ServerRequest('GET', $path));
    $timings[] = (hrtime(true) - $t) / 1e6;
}
printf("worker   boot once, then %d requests\n", $requests);
printf("  boot     %6.2f ms\n", $boot);
printf("  first    %6.2f ms\n", $timings[0]);
printf("  request  %6.3f ms (median)\n", median(array_slice($timings, 1)));

/**
 * @param list<string> $command
 * @return array{autoload:float,boot:float,request:float,status:int}
 */
function runChild(array $command): array
{
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Cannot start a child process');
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    proc_close($process);
    $data = json_decode(trim($out), true);
    if (!is_array($data)) {
        throw new RuntimeException("Child failed: {$out}{$err}");
    }
    /** @var array{autoload:float,boot:float,request:float,status:int} $data */
    return $data;
}

/**
 * @param list<float> $values
 */
function median(array $values): float
{
    sort($values);
    return $values[intdiv(count($values), 2)];
}

/**
 * A synthetic app: 5 modules of $controllers / 5 controllers of 6 actions
 */
function synthetic(int $controllers): string
{
    $base = sys_get_temp_dir() . "/kaly-bench-app-{$controllers}";
    foreach (['Site', 'Admin', 'Api', 'Shop', 'Blog'] as $module) {
        $dir = "{$base}/modules/{$module}/src/Controller";
        @mkdir($dir, 0o777, true);
        file_put_contents("{$base}/modules/{$module}/config.php", "<?php\n");
        for ($i = 0; $i < intdiv($controllers, 5); $i++) {
            $methods = '';
            for ($j = 0; $j < 6; $j++) {
                $methods .= "    public function action{$j}(int \$id = 0): string { return '{$module} {$i} {$j}'; }\n";
            }
            file_put_contents("{$dir}/Thing{$i}Controller.php", "<?php\nnamespace {$module}\\Controller;\nclass Thing{$i}Controller\n{\n{$methods}}\n");
        }
    }
    return $base;
}
