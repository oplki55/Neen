<?php
declare(strict_types=1);

if (PHP_VERSION_ID < 80300) {
    throw new RuntimeException('NEEN requires PHP 8.3 or later.');
}

require_once __DIR__ . '/src/EnvLoader.php';
EnvLoader::loadForProject(dirname(__DIR__));
