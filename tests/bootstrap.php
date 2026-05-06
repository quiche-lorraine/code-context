<?php

declare(strict_types=1);

$candidates = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$loaded = false;
foreach ($candidates as $candidate) {
    if (is_file($candidate)) {
        require $candidate;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    fwrite(STDERR, "Unable to load Composer autoloader for tests.\n");
    exit(1);
}
