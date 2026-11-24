#!/usr/bin/env php
<?php

declare(strict_types=1);

foreach ([
    __DIR__ . '/src/Keys.php',
    __DIR__ . '/src/Literal.php',
    __DIR__ . '/src/Scanner.php',
    __DIR__ . '/src/Cli.php',
] as $file) {
    require_once $file;
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath((string) $argv[0]) === realpath(__FILE__)) {
    exit(Keyslip\Cli::main($argv));
}
