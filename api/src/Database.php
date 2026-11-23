<?php
declare(strict_types=1);

final class Database
{
    public static function connect(): PDO
    {
        $dsn = getenv('NEEN_DB_DSN');
        if ($dsn === false || $dsn === '') {
            $host = getenv('NEEN_DB_HOST');
            $name = getenv('NEEN_DB_NAME');
            if ($host === false || $host === '' || $name === false || $name === '') {
                throw new RuntimeException('Database environment is not configured.');
            }

            $dsn = 'mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4';
        }

        if (getenv('APP_ENV') !== 'test' && !str_starts_with($dsn, 'mysql:')) {
            throw new RuntimeException('Only MySQL is supported outside the test environment.');
        }

        $user = getenv('NEEN_DB_USER');
        $password = getenv('NEEN_DB_PASSWORD');
        $pdo = new PDO(
            $dsn,
            $user === false ? null : $user,
            $password === false ? null : $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ],
        );

        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $pdo->exec("SET time_zone = '+00:00'");
        }

        return $pdo;
    }
}
