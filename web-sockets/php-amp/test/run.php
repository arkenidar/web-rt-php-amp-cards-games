<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Test files are run as subprocesses to keep their state isolated.
$tests = [
    'InfraTest.php',
    'MemoryGameTest.php',
];

$exitCode = 0;

foreach ($tests as $test) {
    $path = __DIR__ . '/' . $test;
    if (!is_file($path)) {
        echo "SKIP {$test} (not present)\n\n";
        continue;
    }

    echo "=== {$test} ===\n";
    passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($path), $code);
    echo "\n";
    if ($code !== 0) {
        $exitCode = 1;
    }
}

exit($exitCode);
