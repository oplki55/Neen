<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/src/EnvLoader.php';

function assertEnvironmentValue(string $name, string $expected): void
{
    if (getenv($name) !== $expected) {
        throw new RuntimeException('Unexpected environment value for ' . $name . '.');
    }
}

function assertEnvironmentMissing(string $name): void
{
    if (
        getenv($name) !== false
        || array_key_exists($name, $_ENV)
        || array_key_exists($name, $_SERVER)
    ) {
        throw new RuntimeException('Unexpected environment value for ' . $name . '.');
    }
}

function clearEnvironmentValue(string $name): void
{
    putenv($name);
    unset($_ENV[$name], $_SERVER[$name]);
}

$temporaryRoot = sys_get_temp_dir() . '/neen-env-loader-' . bin2hex(random_bytes(8));
$projectRoot = $temporaryRoot . '/public_html/neeng.cc';
$externalDirectory = $temporaryRoot . '/neeng.cc';
$externalEnvironmentFile = $externalDirectory . '/.env';
$localEnvironmentFile = $projectRoot . '/.env';
$productionProjectRoot = $temporaryRoot . '/production-project';
$productionEnvironmentFile = $productionProjectRoot . '/.env';
$developmentProjectRoot = $temporaryRoot . '/development-project';
$developmentEnvironmentFile = $developmentProjectRoot . '/.env';
$testVariables = [
    'APP_ENV',
    'NEEN_ENV_LOADER_SOURCE',
    'NEEN_ENV_LOADER_SERVER',
];

try {
    if (
        !mkdir($projectRoot, 0700, true)
        || !mkdir($externalDirectory, 0700)
        || !mkdir($productionProjectRoot, 0700)
        || !mkdir($developmentProjectRoot, 0700)
    ) {
        throw new RuntimeException('Temporary environment test directories could not be created.');
    }
    if (
        file_put_contents($externalEnvironmentFile, "NEEN_ENV_LOADER_SOURCE=external\nNEEN_ENV_LOADER_SERVER=file\n") === false
        || file_put_contents($localEnvironmentFile, "NEEN_ENV_LOADER_SOURCE=cpanel-local\n") === false
        || file_put_contents($productionEnvironmentFile, "NEEN_ENV_LOADER_SOURCE=production-local\n") === false
        || file_put_contents(
            $developmentEnvironmentFile,
            "NEEN_ENV_LOADER_SOURCE=development-local\nNEEN_ENV_LOADER_SERVER=file\n",
        ) === false
    ) {
        throw new RuntimeException('Temporary environment test files could not be created.');
    }

    putenv('APP_ENV=production');
    putenv('NEEN_ENV_LOADER_SERVER=server');
    EnvLoader::loadForProject($projectRoot);
    assertEnvironmentValue('NEEN_ENV_LOADER_SOURCE', 'external');
    assertEnvironmentValue('NEEN_ENV_LOADER_SERVER', 'server');

    clearEnvironmentValue('NEEN_ENV_LOADER_SOURCE');
    clearEnvironmentValue('APP_ENV');
    if (!unlink($externalEnvironmentFile)) {
        throw new RuntimeException('Temporary external environment file could not be removed.');
    }
    EnvLoader::loadForProject($projectRoot);
    assertEnvironmentMissing('NEEN_ENV_LOADER_SOURCE');

    clearEnvironmentValue('NEEN_ENV_LOADER_SERVER');
    putenv('APP_ENV=production');
    EnvLoader::loadForProject($productionProjectRoot);
    assertEnvironmentMissing('NEEN_ENV_LOADER_SOURCE');

    putenv('APP_ENV=development');
    putenv('NEEN_ENV_LOADER_SERVER=server');
    EnvLoader::loadForProject($developmentProjectRoot);
    assertEnvironmentValue('NEEN_ENV_LOADER_SOURCE', 'development-local');
    assertEnvironmentValue('NEEN_ENV_LOADER_SERVER', 'server');

    fwrite(STDOUT, "Environment loader tests passed.\n");
} finally {
    foreach ($testVariables as $name) {
        clearEnvironmentValue($name);
    }
    foreach ([
        $externalEnvironmentFile,
        $localEnvironmentFile,
        $productionEnvironmentFile,
        $developmentEnvironmentFile,
    ] as $file) {
        if (file_exists($file)) {
            unlink($file);
        }
    }
    foreach ([
        $externalDirectory,
        $projectRoot,
        dirname($projectRoot),
        $productionProjectRoot,
        $developmentProjectRoot,
        $temporaryRoot,
    ] as $directory) {
        if (is_dir($directory)) {
            rmdir($directory);
        }
    }
}
