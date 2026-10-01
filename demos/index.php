<?php

declare(strict_types=1);

$files = glob(__DIR__ . '/*.php') ?: [];

foreach ($files as $f) {
    $n = basename($f);
    if ($n === 'index.php') {
        continue;
    }
    echo "<a href='$n'>$n</a><br>";
}
