<?php

declare(strict_types=1);

/**
 * Loads PHPUnit and the dev dependencies from vendor/, and maps the SDK and its
 * tests to the sources next to this file, ahead of Composer's map.
 *
 * Composer derives its base dir from the real path of vendor/. When vendor/ is
 * a symlink to another checkout (git worktrees share one install), Composer's
 * map points at THAT checkout's src/ and the suite would test the wrong code.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $map = [
        'Lunixi\\Sdk\\Tests\\' => __DIR__ . '/',
        'Lunixi\\Sdk\\' => dirname(__DIR__) . '/src/',
    ];
    foreach ($map as $prefix => $dir) {
        if (strncmp($class, $prefix, strlen($prefix)) === 0) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
            if (is_file($file)) {
                require $file;
            }
            return;
        }
    }
}, true, true);
