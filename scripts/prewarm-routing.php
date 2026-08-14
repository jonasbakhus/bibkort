<?php

declare(strict_types=1);

// Kører den samme matrixberegning som API'et uden Simplys korte web-timeout.
$config = require __DIR__ . '/../config/app.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$originId = is_string($argv[1] ?? null) ? $argv[1] : array_key_first($config['origins']);
if (!isset($config['origins'][$originId])) {
    fwrite(STDERR, "Ukendt udgangspunkt: {$originId}\n");
    exit(1);
}
$_GET = [
    'action' => 'matrix',
    'origin' => $originId,
];

require __DIR__ . '/../api/routing.php';
