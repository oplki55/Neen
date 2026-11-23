<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$documentRoot = realpath(dirname(__DIR__));
$decodedPath = is_string($path) ? rawurldecode($path) : '';
if (
    !is_string($path)
    || $documentRoot === false
    || str_contains($decodedPath, "\0")
) {
    http_response_code(404);
    exit;
}
$segments = explode('/', trim($decodedPath, '/'));
foreach ($segments as $segment) {
    if (str_starts_with($segment, '.') && $segment !== '.well-known') {
        http_response_code(404);
        exit;
    }
}
$decodedPath = $decodedPath === '/' ? '/index.html' : $decodedPath;

$requestedFile = realpath($documentRoot . $decodedPath);
$isInsideDocumentRoot = $requestedFile !== false
    && str_starts_with($requestedFile, $documentRoot . DIRECTORY_SEPARATOR);
if (str_starts_with($decodedPath, '/api/src/')) {
    http_response_code(404);
    exit;
}
if (
    str_starts_with($decodedPath, '/api/')
    && $isInsideDocumentRoot
    && is_file($requestedFile)
    && !str_ends_with($decodedPath, '.php')
) {
    http_response_code(404);
    exit;
}
if ($isInsideDocumentRoot && is_file($requestedFile) && !str_ends_with($decodedPath, '.php')) {
    return false;
}

if (
    in_array($decodedPath, ['/events-admin.php', '/owner-dashboard.php'], true)
    && $isInsideDocumentRoot
    && is_file($requestedFile)
) {
    require $requestedFile;
    exit;
}

if (str_starts_with($decodedPath, '/api/')) {
    require __DIR__ . '/index.php';
    exit;
}

http_response_code(404);
