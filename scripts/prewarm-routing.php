<?php

declare(strict_types=1);

// Kører den samme matrixberegning som API'et uden Simplys korte web-timeout.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
    'action' => 'matrix',
    'origin' => 'baekmarksbro',
];

require __DIR__ . '/../api/routing.php';
