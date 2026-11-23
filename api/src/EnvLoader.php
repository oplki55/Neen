<?php
declare(strict_types=1);

final class EnvLoader
{
    public static function loadForProject(string $projectRoot): void
    {
        $resolvedProjectRoot = realpath($projectRoot);
        if ($resolvedProjectRoot === false) {
            throw new RuntimeException('Application directory could not be resolved.');
        }

        $publicDirectory = dirname($resolvedProjectRoot);
        $isCpanelDocumentRoot = strtolower(basename($publicDirectory)) === 'public_html';
        if ($isCpanelDocumentRoot) {
            $externalEnvironmentFile = dirname($publicDirectory)
                . DIRECTORY_SEPARATOR
                . basename($resolvedProjectRoot)
                . DIRECTORY_SEPARATOR
                . '.env';
            if (file_exists($externalEnvironmentFile) || is_link($externalEnvironmentFile)) {
                $resolvedEnvironmentFile = realpath($externalEnvironmentFile);
                if (
                    $resolvedEnvironmentFile !== false
                    && str_starts_with(
                        $resolvedEnvironmentFile,
                        $resolvedProjectRoot . DIRECTORY_SEPARATOR,
                    )
                ) {
                    throw new RuntimeException('External environment file must be outside the public directory.');
                }

                self::load($externalEnvironmentFile);
                return;
            }
        }

        if ($isCpanelDocumentRoot || self::isProductionEnvironment()) {
            return;
        }

        self::load($resolvedProjectRoot . DIRECTORY_SEPARATOR . '.env');
    }

    private static function isProductionEnvironment(): bool
    {
        $environment = getenv('APP_ENV');
        if ($environment === false) {
            $environment = $_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? null;
        }

        return is_string($environment) && strtolower(trim($environment)) === 'production';
    }

    public static function load(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('Environment file is not a readable regular file.');
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new RuntimeException('Environment file could not be read.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if (
                !preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $name)
                || getenv($name) !== false
                || array_key_exists($name, $_ENV)
                || array_key_exists($name, $_SERVER)
            ) {
                continue;
            }

            $value = trim($value);
            if (
                strlen($value) >= 2
                && (($value[0] === '"' && str_ends_with($value, '"'))
                    || ($value[0] === "'" && str_ends_with($value, "'")))
            ) {
                $quoted = $value[0] === '"';
                $value = substr($value, 1, -1);
                if ($quoted && str_contains($value, '\\')) {
                    $value = stripcslashes($value);
                }
            } else {
                $value = preg_replace('/\s+#.*$/', '', $value) ?? $value;
            }

            $_ENV[$name] = $value;
            putenv($name . '=' . $value);
        }
    }
}
