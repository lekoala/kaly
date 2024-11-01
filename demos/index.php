<?php

$files = glob('./*.php');

foreach ($files as $f) {
    $n = basename($f);
    if ($n === 'index.php') {
        continue;
    }
    echo "<a href='$n'>$n</a><br>";
}
