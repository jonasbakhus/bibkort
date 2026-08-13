<?php

declare(strict_types=1);

// Kører den samme matrixberegning som API'et uden Simplys korte web-timeout.
$config = require __DIR__ . '/../config/app.php';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_GET = [
    'action' => 'matrix',
    'origin' => array_key_first($config['origins']),
];

require __DIR__ . '/../api/routing.php';
