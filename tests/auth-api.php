<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/src/ApiException.php';
require_once dirname(__DIR__) . '/api/src/CoinsLedger.php';
require_once dirname(__DIR__) . '/api/src/Attendance.php';

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function request(
    string $baseUrl,
    string $path,
    array &$cookies,
    ?array $payload = null,
    ?string $csrf = null,
    string $method = 'GET',
): array {
    $headers = ['Accept: application/json'];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $payload === null
                ? ''
                : json_encode($payload === [] ? (object) [] : $payload, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'timeout' => 3,
            'follow_location' => 0,
        ],
    ]);
    $body = file_get_contents($baseUrl . $path, false, $context);
    if ($body === false) {
        throw new RuntimeException('Request failed: ' . $path . ' (' . (error_get_last()['message'] ?? 'unknown transport error') . ')');
    }
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    $status = isset($match[1]) ? (int) $match[1] : 0;

    foreach ($responseHeaders as $header) {
        if (stripos($header, 'Set-Cookie: ') !== 0) {
            continue;
        }
        $cookie = explode(';', substr($header, 12), 2)[0];
        [$name, $value] = array_pad(explode('=', $cookie, 2), 2, '');
        $cookies[$name] = $value;
    }

    return [$status, json_decode($body, true, 32, JSON_THROW_ON_ERROR), $responseHeaders];
}

function responseHeader(array $headers, string $name): ?string
{
    foreach ($headers as $header) {
        if (stripos($header, $name . ':') === 0) {
            return trim(substr($header, strlen($name) + 1));
        }
    }
    return null;
}

function uploadRequest(
    string $baseUrl,
    string $path,
    array &$cookies,
    array $fields,
    string $filePath,
    string $fileName,
    string $csrf,
): array {
    $fileContents = file_get_contents($filePath);
    if ($fileContents === false) {
        throw new RuntimeException('Unable to read site media upload fixture.');
    }
    $boundary = 'neen-test-' . bin2hex(random_bytes(12));
    $body = '';
    foreach ($fields as $name => $value) {
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n";
        $body .= (string) $value . "\r\n";
    }
    $body .= '--' . $boundary . "\r\n";
    $body .= 'Content-Disposition: form-data; name="image"; filename="' . $fileName . '"' . "\r\n";
    $body .= "Content-Type: image/webp\r\n\r\n" . $fileContents . "\r\n";
    $body .= '--' . $boundary . "--\r\n";

    $headers = [
        'Accept: application/json',
        'Content-Type: multipart/form-data; boundary=' . $boundary,
        'X-CSRF-Token: ' . $csrf,
    ];
    if ($cookies !== []) {
        $headers[] = 'Cookie: ' . implode('; ', array_map(
            static fn (string $name, string $value): string => $name . '=' . $value,
            array_keys($cookies),
            array_values($cookies),
        ));
    }
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'ignore_errors' => true,
            'timeout' => 10,
            'follow_location' => 0,
        ],
    ]);
    $responseBody = file_get_contents($baseUrl . $path, false, $context);
    if ($responseBody === false) {
        throw new RuntimeException('Site media upload request failed.');
    }
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    return [
        isset($match[1]) ? (int) $match[1] : 0,
        json_decode($responseBody, true, 32, JSON_THROW_ON_ERROR),
    ];
}

function requestPage(string $baseUrl, string $path, array $cookies): array
{
    $headers = ['Accept: text/html'];
    if ($cookies !== []) {
        $pairs = [];
        foreach ($cookies as $name => $value) {
            $pairs[] = $name . '=' . $value;
        }
        $headers[] = 'Cookie: ' . implode('; ', $pairs);
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => 3,
            'follow_location' => 0,
        ],
    ]);
    $body = file_get_contents($baseUrl . $path, false, $context);
    if ($body === false) {
        throw new RuntimeException('Page request failed: ' . $path . ' (' . (error_get_last()['message'] ?? 'unknown transport error') . ')');
    }
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    $status = isset($match[1]) ? (int) $match[1] : 0;
    return [$status, $body, $responseHeaders];
}

function concurrentRequests(
    string $baseUrl,
    string $path,
    array $cookies,
    array $payload,
    string $csrf,
): array {
    $cookieHeader = implode('; ', array_map(
        static fn (string $name, string $value): string => $name . '=' . $value,
        array_keys($cookies),
        array_values($cookies),
    ));
    $clientCode = <<<'PHP'
$payload = json_decode($argv[5], true, 32, JSON_THROW_ON_ERROR);
$context = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Accept: application/json\r\nContent-Type: application/json\r\nX-CSRF-Token: {$argv[4]}\r\nCookie: {$argv[3]}",
    'content' => json_encode($payload === [] ? (object) [] : $payload, JSON_THROW_ON_ERROR),
    'ignore_errors' => true,
    'timeout' => 10,
]]);
$body = file_get_contents($argv[1] . $argv[2], false, $context);
if ($body === false) {
    exit(2);
}
preg_match('/\s(\d{3})\s/', $http_response_header[0] ?? '', $match);
echo json_encode([
    'status' => isset($match[1]) ? (int) $match[1] : 0,
    'response' => json_decode($body, true, 32, JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR);
PHP;
    $workers = [];
    for ($index = 0; $index < 2; $index++) {
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $clientCode,
                $baseUrl,
                $path,
                $cookieHeader,
                $csrf,
                json_encode($payload, JSON_THROW_ON_ERROR),
            ],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to launch a concurrent game request.');
        }
        fclose($pipes[0]);
        $workers[] = [$process, $pipes[1], $pipes[2]];
    }

    $results = [];
    foreach ($workers as [$process, $stdout, $stderr]) {
        $output = stream_get_contents($stdout);
        $error = stream_get_contents($stderr);
        fclose($stdout);
        fclose($stderr);
        $exitCode = proc_close($process);
        if ($exitCode !== 0 || !is_string($output) || $output === '') {
            throw new RuntimeException('A concurrent game request failed: ' . (string) $error);
        }
        $result = json_decode($output, true, 32, JSON_THROW_ON_ERROR);
        $results[] = [(int) $result['status'], $result['response']];
    }
    return $results;
}

function checkResponse(array $response, bool $success, string $message): void
{
    assertTrue(($response['success'] ?? null) === $success, $message . ': response success flag');
}

$root = dirname(__DIR__);
$uploadsDirectory = $root . '/uploads';
$siteMediaDirectory = $uploadsDirectory . '/site';
$uploadsDirectoryExisted = is_dir($uploadsDirectory);
$siteMediaDirectoryExisted = is_dir($siteMediaDirectory);
$siteMediaProtectionFile = $siteMediaDirectory . '/.htaccess';
$siteMediaProtectionFileExisted = is_file($siteMediaProtectionFile);
$siteMediaFilesBefore = glob($siteMediaDirectory . '/*') ?: [];
$temporaryDirectory = sys_get_temp_dir() . '/neen-auth-' . bin2hex(random_bytes(6));
if (!mkdir($temporaryDirectory, 0700)) {
    throw new RuntimeException('Unable to create test directory.');
}
$databasePath = $temporaryDirectory . '/accounts.sqlite';
$mailPath = $temporaryDirectory . '/mail.jsonl';
$database = new PDO('sqlite:' . $databasePath);
$database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$schema = file_get_contents(__DIR__ . '/fixtures/account-schema.sqlite.sql');
if ($schema === false) {
    throw new RuntimeException('Unable to read the SQLite test schema.');
}
$database->exec($schema);
unset($database);

$serverSocket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($serverSocket === false) {
    throw new RuntimeException('Unable to reserve a local test port: ' . $error);
}
$address = stream_socket_get_name($serverSocket, false);
fclose($serverSocket);
$port = (int) substr(strrchr((string) $address, ':'), 1);
$baseUrl = 'http://127.0.0.1:' . $port;
$environment = array_merge($_ENV, [
    'APP_ENV' => 'test',
    'NEEN_APP_KEY' => bin2hex(random_bytes(32)),
    'NEEN_APP_URL' => $baseUrl,
    'NEEN_DB_DSN' => 'sqlite:' . $databasePath,
    'NEEN_TEST_MAIL_FILE' => $mailPath,
    'SUNNY_ISLAND_MIN_DURATION_SECONDS' => '2',
    'XDEBUG_MODE' => 'off',
]);
$process = proc_open(
    [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/api/router.php'],
    [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
    $pipes,
    $root,
    $environment,
);
if (!is_resource($process)) {
    throw new RuntimeException('Unable to launch the API test server.');
}
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

try {
    $ready = false;
    for ($attempt = 0; $attempt < 50; $attempt++) {
        $socket = @fsockopen('127.0.0.1', $port, $socketError, $socketMessage, 0.1);
        if ($socket !== false) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(50_000);
    }
    assertTrue($ready, 'API test server starts');

    $cookies = [];
    [$publicSettingsStatus, , $publicSettingsHeaders] = request($baseUrl, '/api/site-settings', $cookies);
    assertTrue(
        $publicSettingsStatus === 200
        && responseHeader($publicSettingsHeaders, 'Cache-Control') === 'public, max-age=60, stale-while-revalidate=30',
        'Public site settings use short-lived shared caching',
    );
    [$publicTickerStatus, , $publicTickerHeaders] = request($baseUrl, '/api/news-ticker', $cookies);
    assertTrue(
        $publicTickerStatus === 200
        && responseHeader($publicTickerHeaders, 'Cache-Control') === 'public, max-age=60, stale-while-revalidate=30',
        'Public ticker data uses short-lived shared caching',
    );
    foreach ([
        '/api/auth/me',
        '/api/homepage-rewards',
        '/api/owner/news-ticker',
        '/api/admin/audit-logs',
    ] as $privatePath) {
        [, , $privateHeaders] = request($baseUrl, $privatePath, $cookies);
        assertTrue(
            responseHeader($privateHeaders, 'Cache-Control') === 'no-store',
            $privatePath . ' responses remain non-cacheable',
        );
    }

    [$status, $response] = request($baseUrl, '/api/auth/csrf', $cookies);
    assertTrue($status === 200, 'Anonymous CSRF endpoint responds');
    $csrf = $response['data']['csrf_token'];
    assertTrue(isset($cookies['neen_csrf']), 'Anonymous CSRF cookie is issued');

    [$status] = request(
        $baseUrl,
        '/api/auth/register',
        $cookies,
        ['username' => 'NeenPlayer', 'email' => 'player@example.com', 'password' => 'Correct-Horse-123'],
        null,
        'POST',
    );
    assertTrue($status === 403, 'Registration rejects a missing CSRF header');

    [$status, $response] = request(
        $baseUrl,
        '/api/auth/register',
        $cookies,
        [
            'username' => 'NeenPlayer',
            'email' => 'player@example.com',
            'password' => 'Correct-Horse-123',
            'display_name' => 'Neen Player',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'Valid registration creates a pending account');
    checkResponse($response, true, 'Valid open registration');
    assertTrue($response['data']['wallet_created'] === true, 'Registration creates a wallet');
    assertTrue(
        $response['data']['email_verification_required'] === true
        && $response['data']['verification_email_sent'] === true,
        'Local/test registration issues a verification requirement and records the verification email',
    );
    $userId = (int) $response['data']['user_id'];
    $walletId = (int) $response['data']['wallet_id'];
    [$verificationStatus] = request(
        $baseUrl,
        '/api/auth/email/verify',
        $cookies,
        ['token' => str_repeat('a', 43)],
        $csrf,
        'POST',
    );
    assertTrue($verificationStatus === 422, 'Invalid email verification tokens cannot change account state');

    $expiredTokenDb = new PDO('sqlite:' . $databasePath);
    $expiredToken = str_repeat('b', 43);
    $expiredTokenStatement = $expiredTokenDb->prepare(
        'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
         VALUES (:user_id, :token_hash, :expires_at, :created_at)',
    );
    $expiredTokenStatement->execute([
        'user_id' => $userId,
        'token_hash' => hash('sha256', $expiredToken),
        'expires_at' => gmdate('Y-m-d H:i:s', time() - 3600),
        'created_at' => gmdate('Y-m-d H:i:s', time() - 7200),
    ]);
    unset($expiredTokenStatement, $expiredTokenDb);
    [$expiredStatus] = request(
        $baseUrl,
        '/api/auth/email/verify',
        $cookies,
        ['token' => $expiredToken],
        $csrf,
        'POST',
    );
    assertTrue($expiredStatus === 422, 'Expired email verification tokens are rejected');

    [$preVerificationLoginStatus, $preVerificationLoginResponse] = request(
        $baseUrl,
        '/api/auth/login',
        $cookies,
        ['identifier' => 'NEENPLAYER', 'password' => 'Correct-Horse-123'],
        $csrf,
        'POST',
    );
    assertTrue($preVerificationLoginStatus === 200, 'Unverified accounts can log in normally');
    $csrf = $preVerificationLoginResponse['data']['csrf_token'];

    $existingUnverified = new PDO('sqlite:' . $databasePath);
    $existingUnverifiedStatement = $existingUnverified->prepare(
        "INSERT INTO users (username, email, password_hash, status, email_verified, created_at, updated_at)
         VALUES ('existingunverified', 'existing-unverified@example.com', :password_hash, 'active', 0, :now, :now)",
    );
    $existingNow = gmdate('Y-m-d H:i:s');
    $existingUnverifiedStatement->execute([
        'password_hash' => password_hash('Correct-Horse-123', PASSWORD_DEFAULT),
        'now' => $existingNow,
    ]);
    unset($existingUnverifiedStatement, $existingUnverified);

    [$existingUnverifiedLoginStatus, $existingUnverifiedLoginResponse] = request(
        $baseUrl,
        '/api/auth/login',
        $cookies,
        ['identifier' => 'existingunverified', 'password' => 'Correct-Horse-123'],
        $csrf,
        'POST',
    );
    assertTrue($existingUnverifiedLoginStatus === 200, 'Active accounts without verified email can sign in normally');
    $csrf = $existingUnverifiedLoginResponse['data']['csrf_token'];
    [$legacyResendStatus] = request(
        $baseUrl,
        '/api/auth/email/resend',
        $cookies,
        ['email' => 'existing-unverified@example.com'],
        $csrf,
        'POST',
    );
    assertTrue($legacyResendStatus === 202, 'Legacy active unverified accounts can request safe verification delivery');

    $mailLines = file($mailPath, FILE_IGNORE_NEW_LINES);
    assertTrue(is_array($mailLines) && $mailLines !== [], 'Verification email is recorded by the test mail sink');
    $verificationMail = json_decode((string) $mailLines[0], true, 16, JSON_THROW_ON_ERROR);
    assertTrue(
        ($verificationMail['to'] ?? null) === 'player@example.com'
        && ($verificationMail['subject'] ?? null) === 'Verify your NEEN email address',
        'The verification email is addressed to the registered account',
    );
    preg_match('/verify_token=([A-Za-z0-9_-]{43})/', (string) $verificationMail['message'], $verificationMatch);
    assertTrue(isset($verificationMatch[1]), 'The verification email contains a valid verification token');
    $verificationToken = $verificationMatch[1];

    [$resendStatus] = request(
        $baseUrl,
        '/api/auth/email/resend',
        $cookies,
        ['email' => 'player@example.com'],
        $csrf,
        'POST',
    );
    assertTrue($resendStatus === 202, 'Pending account verification can be resent');
    $mailLines = file($mailPath, FILE_IGNORE_NEW_LINES);
    assertTrue(is_array($mailLines) && count($mailLines) >= 2, 'Repeated verification requests record a new email');
    $resendMail = json_decode((string) $mailLines[count($mailLines) - 1], true, 16, JSON_THROW_ON_ERROR);
    preg_match('/verify_token=([A-Za-z0-9_-]{43})/', (string) $resendMail['message'], $resendMatch);
    assertTrue(isset($resendMatch[1]), 'The resent verification email contains a valid token');
    $resendToken = $resendMatch[1];

    [$oldTokenStatus] = request(
        $baseUrl,
        '/api/auth/email/verify',
        $cookies,
        ['token' => $verificationToken],
        $csrf,
        'POST',
    );
    assertTrue($oldTokenStatus === 422, 'A repeated verification request invalidates the previous token');

    [$verificationStatus] = request(
        $baseUrl,
        '/api/auth/email/verify',
        $cookies,
        ['token' => $resendToken],
        $csrf,
        'POST',
    );
    assertTrue($verificationStatus === 200, 'The newest verification token activates the pending account');

    [$reuseStatus] = request(
        $baseUrl,
        '/api/auth/email/verify',
        $cookies,
        ['token' => $resendToken],
        $csrf,
        'POST',
    );
    assertTrue($reuseStatus === 422, 'A verification token cannot be reused');

    [$status] = request(
        $baseUrl,
        '/api/auth/register',
        $cookies,
        [
            'username' => 'neenplayer',
            'email' => 'other@example.com',
            'password' => 'Correct-Horse-123',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 409, 'Duplicate username registration is rejected');
    [$status] = request(
        $baseUrl,
        '/api/auth/register',
        $cookies,
        [
            'username' => 'differentname',
            'email' => 'PLAYER@example.com',
            'password' => 'Correct-Horse-123',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 409, 'Duplicate email registration is rejected');
    foreach ([
        ['username' => 'bad name', 'email' => 'valid@example.com', 'password' => 'Correct-Horse-123'],
        ['username' => 'validname', 'email' => 'not-an-email', 'password' => 'Correct-Horse-123'],
        ['username' => 'validname', 'email' => 'valid@example.com', 'password' => 'short'],
    ] as $invalidRegistration) {
        [$invalidStatus] = request(
            $baseUrl,
            '/api/auth/register',
            $cookies,
            $invalidRegistration,
            $csrf,
            'POST',
        );
        assertTrue($invalidStatus === 422, 'Invalid registration data is rejected with a validation response');
        $rateLimitDb = new PDO('sqlite:' . $databasePath);
        $rateLimitDb->exec('DELETE FROM auth_rate_limits');
        unset($rateLimitDb);
    }

    $accountPage = file_get_contents($baseUrl . '/account.html');
    assertTrue(
        is_string($accountPage)
        && str_contains($accountPage, 'Create account')
        && str_contains($accountPage, 'Email or username'),
        'The account page exposes login and registration entry points',
    );
    $siteScript = file_get_contents($root . '/script.js');
    assertTrue(
        is_string($siteScript)
        && str_contains($siteScript, '/api/auth/me')
        && str_contains($siteScript, 'dataset.accountLink')
        && str_contains($siteScript, 'neen:authchange'),
        'Shared site navigation reads server-side sign-in status and responds to account changes',
    );
    $gamePage = file_get_contents($baseUrl . '/memory-match.html');
    assertTrue(is_string($gamePage) && str_contains($gamePage, 'data-match-board'),
        'Memory Match page is served with the existing site');
    [$status, $gameCatalogResponse] = request($baseUrl, '/api/games/memory-match', $cookies);
    $gameCatalog = $gameCatalogResponse['data']['game'] ?? [];
    assertTrue(
        $status === 200
        && $gameCatalog['pairs_total'] === 8
        && $gameCatalog['cards_total'] === 16
        && $gameCatalog['free_attempts_per_day'] === 3
        && $gameCatalog['reset_timezone'] === 'Africa/Cairo',
        'Memory Match exposes the eight-image catalog and approved daily policy',
    );
    assertTrue(count($gameCatalog['images']) === 8, 'The public catalog returns every supported source image');
    foreach ($gameCatalog['images'] as $image) {
        $imageBytes = file_get_contents($baseUrl . $image['src']);
        $servedImage = $imageBytes === false ? false : getimagesizefromstring($imageBytes);
        assertTrue(
            $servedImage !== false
            && $servedImage[0] === 1600
            && $servedImage[1] === 738,
            'The original catalog image is served successfully with its full dimensions',
        );
    }
    $gameStyles = file_get_contents($root . '/memory-match.css');
    assertTrue(
        is_string($gameStyles)
        && str_contains($gameStyles, 'aspect-ratio: 1;')
        && str_contains($gameStyles, 'object-fit: contain;')
        && str_contains($gameStyles, '@media (max-width: 560px)'),
        'Memory Match uses square, uncropped cards and includes a small-screen layout',
    );
    $sourceImages = glob($root . '/assets/hero-loot/Memory Match pic/*') ?: [];
    assertTrue(count($sourceImages) === 8, 'All eight source images in the approved folder were discovered');
    $imageManifest = json_decode(
        (string) file_get_contents($root . '/assets/hero-loot/memory-match-images.json'),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    assertTrue(count($imageManifest) === 8, 'The organized game manifest uses all eight discovered images');
    foreach ($imageManifest as $image) {
        $imageInfo = getimagesize($root . '/' . $image['src']);
        assertTrue(
            $imageInfo !== false
            && ($imageInfo['mime'] ?? '') === 'image/webp'
            && $imageInfo[0] === 1600
            && $imageInfo[1] === 738,
            'Memory Match images are valid WebP files at their original dimensions',
        );
    }

    $anonymousCookies = [];
    [$status] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $anonymousCookies,
        ['idempotency_key' => 'memory-anonymous-start-001'],
        null,
        'POST',
    );
    assertTrue($status === 401, 'An anonymous visitor cannot start a user-linked game session');

    [$status] = request(
        $baseUrl,
        '/api/auth/login',
        $cookies,
        ['identifier' => 'bad identifier!', 'password' => 'Correct-Horse-123'],
        $csrf,
        'POST',
    );
    assertTrue($status === 422, 'Invalid email/username login identifiers are rejected before authentication');
    [$status, $response] = request(
        $baseUrl,
        '/api/auth/login',
        $cookies,
        ['email' => 'player@example.com', 'password' => 'Wrong-Password-123'],
        $csrf,
        'POST',
    );
    assertTrue($status === 401, 'Incorrect login is rejected');
    checkResponse($response, false, 'Incorrect login');

    [$status, $response, $loginHeaders] = request(
        $baseUrl,
        '/api/auth/login',
        $cookies,
        ['identifier' => 'NEENPLAYER', 'password' => 'Correct-Horse-123'],
        $csrf,
        'POST',
    );
    assertTrue($status === 200, 'Correct login succeeds by case-insensitive username');
    checkResponse($response, true, 'Correct login');
    $csrf = $response['data']['csrf_token'];
    assertTrue(isset($cookies['neen_session']), 'Login issues an opaque session cookie');
    $sessionCookieHeaders = array_values(array_filter(
        $loginHeaders,
        static fn (string $header): bool => stripos($header, 'Set-Cookie: neen_session=') === 0,
    ));
    assertTrue(
        $sessionCookieHeaders !== []
        && stripos(implode(';', $sessionCookieHeaders), 'httponly') !== false
        && stripos(implode(';', $sessionCookieHeaders), 'samesite=lax') !== false,
        'Session cookie has HttpOnly and SameSite protections',
    );
    $database = new PDO('sqlite:' . $databasePath);
    $storedSessionHash = $database->query(
        'SELECT session_token_hash FROM user_sessions WHERE user_id = ' . $userId . ' ORDER BY id DESC LIMIT 1',
    )->fetchColumn();
    $storedPasswordHash = $database->query(
        'SELECT password_hash FROM users WHERE id = ' . $userId,
    )->fetchColumn();
    assertTrue(
        $storedSessionHash === hash('sha256', $cookies['neen_session'])
        && $storedSessionHash !== $cookies['neen_session'],
        'The latest session token is stored only as a hash',
    );
    assertTrue(
        is_string($storedPasswordHash)
        && $storedPasswordHash !== 'Correct-Horse-123'
        && password_verify('Correct-Horse-123', $storedPasswordHash),
        'Registration stores a verifiable one-way password hash instead of plaintext',
    );
    unset($database);

    [$status, $response] = request($baseUrl, '/api/auth/me', $cookies);
    assertTrue($status === 200 && $response['data']['user']['id'] === $userId, 'Authenticated user data is returned');
    assertTrue($response['data']['user']['roles'] === ['user'], 'New accounts receive only the user role');
    assertTrue(
        $response['data']['user']['permissions'] === ['view_wallet', 'view_wallet_transactions'],
        'New accounts receive only read-only wallet permissions',
    );

    $sessionDb = new PDO('sqlite:' . $databasePath);
    $sessionQuery = $sessionDb->prepare(
        'SELECT expires_at, last_activity_at FROM user_sessions WHERE session_token_hash = :token_hash',
    );
    $sessionQuery->execute(['token_hash' => hash('sha256', $cookies['neen_session'])]);
    $sessionStateBefore = $sessionQuery->fetch(PDO::FETCH_ASSOC);
    $sessionQuery->closeCursor();
    [$status, , $sessionHeaders] = request($baseUrl, '/api/auth/me', $cookies);
    $sessionQuery->execute(['token_hash' => hash('sha256', $cookies['neen_session'])]);
    $sessionStateAfter = $sessionQuery->fetch(PDO::FETCH_ASSOC);
    $sessionQuery->closeCursor();
    assertTrue(
        $status === 200
        && is_array($sessionStateBefore)
        && $sessionStateAfter === $sessionStateBefore
        && responseHeader($sessionHeaders, 'Set-Cookie') === null,
        'Recent authenticated activity skips the session update and cookie refresh',
    );

    $staleActivity = gmdate('Y-m-d H:i:s', time() - 121);
    $sessionDb->prepare(
        'UPDATE user_sessions SET last_activity_at = :last_activity_at WHERE session_token_hash = :token_hash',
    )->execute([
        'last_activity_at' => $staleActivity,
        'token_hash' => hash('sha256', $cookies['neen_session']),
    ]);
    [$status, , $sessionHeaders] = request($baseUrl, '/api/auth/me', $cookies);
    $sessionQuery->execute(['token_hash' => hash('sha256', $cookies['neen_session'])]);
    $sessionStateAfter = $sessionQuery->fetch(PDO::FETCH_ASSOC);
    $sessionQuery->closeCursor();
    assertTrue(
        $status === 200
        && is_array($sessionStateAfter)
        && $sessionStateAfter['last_activity_at'] !== $staleActivity
        && responseHeader($sessionHeaders, 'Set-Cookie') !== null,
        'Stale authenticated activity refreshes the session and cookie',
    );
    unset($sessionQuery, $sessionDb);

    [$status, $response] = request($baseUrl, '/api/users/me', $cookies);
    assertTrue(
        $status === 200 && $response['data']['profile']['email_verified'] === true,
        'Verified authenticated profile reports email verification correctly',
    );
    assertTrue($response['data']['profile']['display_name'] === 'Neen Player', 'Registration creates a user profile');
    assertTrue($response['data']['profile']['free_fire_id'] === null, 'New profiles have no Free Fire ID by default');

    [$status] = request(
        $baseUrl,
        '/api/users/me',
        $cookies,
        ['role' => 'super_admin'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 422, 'Profile updates reject role tampering');

    [$status, $response] = request(
        $baseUrl,
        '/api/users/me',
        $cookies,
        ['display_name' => 'Updated Player', 'country' => 'Egypt'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 200, 'Authenticated profile can be updated');
    assertTrue($response['data']['profile']['display_name'] === 'Updated Player', 'Profile update is persisted');

    [$status, $response] = request(
        $baseUrl,
        '/api/users/me',
        $cookies,
        ['free_fire_id' => '12345678901', 'avatar_url' => 'assets/hero-loot/snoop-sticker-01.webp'],
        $csrf,
        'POST',
    );
    assertTrue($status === 200, 'Profile accepts a Free Fire ID and allowlisted preset avatar through POST');
    assertTrue(
        $response['data']['profile']['free_fire_id'] === '12345678901'
        && $response['data']['profile']['avatar_url'] === 'assets/hero-loot/snoop-sticker-01.webp',
        'Free Fire ID and preset avatar are returned after saving',
    );
    [$status, $response] = request($baseUrl, '/api/users/me', $cookies);
    assertTrue(
        $status === 200
        && $response['data']['profile']['free_fire_id'] === '12345678901'
        && $response['data']['profile']['avatar_url'] === 'assets/hero-loot/snoop-sticker-01.webp',
        'Free Fire ID and preset avatar persist across profile reads',
    );
    [$status] = request(
        $baseUrl,
        '/api/users/me',
        $cookies,
        ['free_fire_id' => '1234'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 422, 'Profile rejects Free Fire IDs outside the 5-to-15 digit range');
    [$status] = request(
        $baseUrl,
        '/api/users/me',
        $cookies,
        ['avatar_url' => '../icone/unsafe.webp'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 422, 'Profile rejects arbitrary local avatar paths');
    [$status] = request(
        $baseUrl,
        '/api/users/me',
        $cookies,
        ['avatar_url' => 'javascript:alert(1)'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 422, 'Profile rejects unsafe avatar URL schemes');
    [$status, $response] = request(
        $baseUrl,
        '/api/users/me',
        $cookies,
        ['avatar_url' => 'https://example.com/player-avatar.png'],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200 && $response['data']['profile']['avatar_url'] === 'https://example.com/player-avatar.png',
        'Profile continues to accept a valid HTTPS avatar URL',
    );
    [$status, $response] = request(
        $baseUrl,
        '/api/users/me',
        $cookies,
        ['free_fire_id' => ''],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200 && $response['data']['profile']['free_fire_id'] === null,
        'An empty Free Fire ID clears the saved identifier',
    );

    [$status, $response] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue($status === 200, 'Authenticated user can read their wallet');
    assertTrue(
        $response['data']['id'] === $walletId
        && $response['data']['coin_balance'] === 0
        && $response['data']['purchased_coin_balance'] === 0
        && $response['data']['earned_coin_balance'] === 0
        && $response['data']['diamond_balance'] === 0,
        'The account wallet is created once with zero Coins and Diamond balances',
    );
    $database = new PDO('sqlite:' . $databasePath);
    assertTrue((int) $database->query('SELECT COUNT(*) FROM wallets WHERE user_id = ' . $userId)->fetchColumn() === 1,
        'Only one wallet exists for the registered user');
    [$status, $response] = request($baseUrl, '/api/coins/packages', $cookies);
    assertTrue($status === 200 && $response['data']['packages'] === [], 'No unapproved Coins packages are seeded');
    [$status, $response] = request($baseUrl, '/api/coins/payment-methods', $cookies);
    assertTrue($status === 200 && $response['data']['payment_methods'] === [], 'No payment methods are assumed');
    [$status] = request($baseUrl, '/api/admin/coin-packages', $cookies);
    assertTrue($status === 403, 'Ordinary users cannot manage Coins packages');
    [$status] = request($baseUrl, '/api/admin/coin-orders', $cookies);
    assertTrue($status === 403, 'Ordinary users cannot review Coins orders');
    [$status] = request($baseUrl, '/api/wallet/debit', $cookies, [], $csrf, 'POST');
    assertTrue($status === 404, 'There is no public wallet debit endpoint');

    $database->prepare(
        "INSERT INTO user_roles (user_id, role_id, granted_at)
         SELECT :user_id, id, CURRENT_TIMESTAMP FROM roles WHERE name = 'super_admin'",
    )->execute(['user_id' => $userId]);

    [$status, $response] = request(
        $baseUrl,
        '/api/admin/coin-packages',
        $cookies,
        [
            'name' => 'Test Coins',
            'coin_amount' => 100,
            'price' => '2.50',
            'currency_code' => 'USD',
            'is_active' => true,
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'Authorized admin can configure a Coins package');
    $packageId = $response['data']['package']['id'];
    [$status, $response] = request(
        $baseUrl,
        '/api/admin/coin-payment-methods',
        $cookies,
        [
            'code' => 'test-transfer',
            'display_name' => 'Test transfer',
            'instructions' => 'Test environment only.',
            'proof_required' => true,
            'is_active' => true,
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'Authorized admin can configure a payment method');
    $paymentMethodId = $response['data']['payment_method']['id'];

    [$status, $response] = request(
        $baseUrl,
        '/api/coins/orders',
        $cookies,
        [
            'package_id' => $packageId,
            'payment_method_id' => $paymentMethodId,
            'idempotency_key' => 'coins-order-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 201 && $response['data']['order']['status'] === 'awaiting_payment',
        'Creating a purchase request does not credit Coins');
    $orderId = $response['data']['order']['id'];
    [$status, $replay] = request(
        $baseUrl,
        '/api/coins/orders',
        $cookies,
        [
            'package_id' => $packageId,
            'payment_method_id' => $paymentMethodId,
            'idempotency_key' => 'coins-order-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 200 && $replay['data']['order']['id'] === $orderId,
        'Repeated purchase requests return the same order');
    $database->query('PRAGMA foreign_keys = ON');
    $walletRow = $database->query(
        'SELECT coin_balance, purchased_coin_balance, earned_coin_balance FROM wallets WHERE user_id = ' . $userId,
    )->fetch(PDO::FETCH_ASSOC);
    assertTrue((int) $walletRow['coin_balance'] === 0, 'Unreviewed purchase does not add Coins');
    unset($database);

    [$status, $response] = request(
        $baseUrl,
        '/api/coins/orders/' . $orderId . '/evidence',
        $cookies,
        [
            'payment_reference' => 'TEST-REFERENCE-001',
            'proof_details' => 'Test proof record.',
            'idempotency_key' => 'coins-evidence-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 200 && $response['data']['order']['status'] === 'pending_review',
        'Payment evidence moves an order to review without crediting Coins');

    [$status, $response] = request(
        $baseUrl,
        '/api/admin/coin-orders/' . $orderId . '/review',
        $cookies,
        [
            'decision' => 'approved',
            'reason' => 'Test approval after evidence review.',
            'idempotency_key' => 'coins-review-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 200 && $response['data']['review']['ledger_entry_id'] > 0,
        'Payment approval creates a Purchased Coins ledger entry');
    [$status, $replay] = request(
        $baseUrl,
        '/api/admin/coin-orders/' . $orderId . '/review',
        $cookies,
        [
            'decision' => 'approved',
            'reason' => 'Test approval after evidence review.',
            'idempotency_key' => 'coins-review-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 200 && $replay['data']['review']['ledger_entry_id'] === $response['data']['review']['ledger_entry_id'],
        'Repeated approval cannot credit the order twice');

    [$status, $wallet] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $status === 200
        && $wallet['data']['coin_balance'] === 100
        && $wallet['data']['purchased_coin_balance'] === 100
        && $wallet['data']['earned_coin_balance'] === 0
        && $wallet['data']['diamond_balance'] === 0,
        'Purchase approval changes only Purchased Coins, not Earned Coins or Diamonds',
    );

    $ledger = new CoinsLedger($database = new PDO('sqlite:' . $databasePath));
    $earnedEntry = $ledger->credit(
        $userId,
        'earned',
        25,
        'earned_reward',
        'coins-earned-test-001',
        'test_reward',
        1,
        'Test-only earned reward.',
    );
    $usageEntry = $ledger->debitAfterConfirmedUsage(
        $userId,
        'purchased',
        30,
        555,
        'coins-usage-test-001',
        'Test-only confirmed service usage.',
    );
    assertTrue($ledger->debitAfterConfirmedUsage(
        $userId,
        'purchased',
        30,
        555,
        'coins-usage-test-001',
        'Test-only confirmed service usage.',
    ) === $usageEntry, 'Repeated confirmed usage is idempotent');
    try {
        $ledger->debitAfterConfirmedUsage(
            $userId,
            'purchased',
            1000,
            556,
            'coins-overdraw-test-001',
            'Test-only overdraw attempt.',
        );
        throw new RuntimeException('Overspending must be rejected.');
    } catch (ApiException $exception) {
        assertTrue($exception->errorCode === 'INSUFFICIENT_BALANCE', 'Overspending is rejected safely');
    }

    $reversalEntry = $ledger->reverse(
        $userId,
        $usageEntry,
        'Test-only usage reversal.',
        'coins-reverse-test-001',
    );
    assertTrue($ledger->reverse(
        $userId,
        $usageEntry,
        'Test-only usage reversal.',
        'coins-reverse-test-001',
    ) === $reversalEntry, 'A repeated reversal request is idempotent');
    try {
        $ledger->reverse($userId, $usageEntry, 'Second reversal must fail.', 'coins-reverse-test-002');
        throw new RuntimeException('A ledger entry must not be reversed twice.');
    } catch (ApiException $exception) {
        assertTrue($exception->errorCode === 'DUPLICATE_REQUEST', 'A ledger entry cannot be reversed twice');
    }
    $ledger->reverse($userId, $earnedEntry, 'Test-only earned adjustment reversal.', 'coins-reverse-test-003');

    [$status, $wallet] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $status === 200
        && $wallet['data']['coin_balance'] === 100
        && $wallet['data']['purchased_coin_balance'] === 100
        && $wallet['data']['earned_coin_balance'] === 0,
        'Debits, earned credits, and reversals keep wallet buckets reconciled',
    );
    $ledgerHistory = request($baseUrl, '/api/wallet/transactions?limit=100', $cookies);
    assertTrue(
        $ledgerHistory[0] === 200 && count($ledgerHistory[1]['data']['transactions']) === 5,
        'Users can view the immutable Coins transaction history',
    );
    try {
        $database->exec('UPDATE coin_ledger SET note = \'tampered\' WHERE id = ' . $usageEntry);
        throw new RuntimeException('Coin ledger entries must not be editable.');
    } catch (PDOException) {
        assertTrue(true, 'Coin ledger entries reject updates');
    }
    try {
        $database->exec('DELETE FROM coin_ledger WHERE id = ' . $usageEntry);
        throw new RuntimeException('Coin ledger entries must not be deletable.');
    } catch (PDOException) {
        assertTrue(true, 'Coin ledger entries reject deletes');
    }

    [$status, $adjustment] = request(
        $baseUrl,
        '/api/admin/wallets/' . $userId . '/adjustments',
        $cookies,
        [
            'direction' => 'credit',
            'balance_type' => 'earned',
            'amount' => 10,
            'reason' => 'Test-only admin adjustment.',
            'idempotency_key' => 'coins-admin-adjust-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'Explicitly authorized admin can make an audited balance adjustment');
    [$status, $adjustmentReplay] = request(
        $baseUrl,
        '/api/admin/wallets/' . $userId . '/adjustments',
        $cookies,
        [
            'direction' => 'credit',
            'balance_type' => 'earned',
            'amount' => 10,
            'reason' => 'Test-only admin adjustment.',
            'idempotency_key' => 'coins-admin-adjust-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201 && $adjustmentReplay['data']['ledger_entry_id'] === $adjustment['data']['ledger_entry_id'],
        'Admin wallet adjustments are idempotent',
    );
    [$status] = request(
        $baseUrl,
        '/api/admin/coin-ledger/' . $adjustment['data']['ledger_entry_id'] . '/reverse',
        $cookies,
        [
            'reason' => 'Test-only admin correction.',
            'idempotency_key' => 'coins-admin-reverse-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'Administrative correction creates a reversal entry');
    [$status, $wallet] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $wallet['data']['coin_balance'] === 100
        && $wallet['data']['earned_coin_balance'] === 0,
        'Administrative reversal restores the prior wallet balances',
    );

    [$status, $response] = request(
        $baseUrl,
        '/api/coins/orders',
        $cookies,
        [
            'package_id' => $packageId,
            'payment_method_id' => $paymentMethodId,
            'idempotency_key' => 'coins-order-test-002',
        ],
        $csrf,
        'POST',
    );
    $rejectedOrderId = $response['data']['order']['id'];
    [$status] = request(
        $baseUrl,
        '/api/coins/orders/' . $rejectedOrderId . '/evidence',
        $cookies,
        [
            'payment_reference' => 'TEST-REFERENCE-002',
            'proof_details' => 'Test proof record for rejection.',
            'idempotency_key' => 'coins-evidence-test-002',
        ],
        $csrf,
        'POST',
    );
    [$status] = request(
        $baseUrl,
        '/api/admin/coin-orders/' . $rejectedOrderId . '/review',
        $cookies,
        [
            'decision' => 'rejected',
            'reason' => 'Test rejection.',
            'idempotency_key' => 'coins-review-test-002',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 200, 'Admin can reject a Coins order');
    [$status, $wallet] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue($wallet['data']['coin_balance'] === 100, 'Rejected payment adds no Coins');

    [$status, $gameStart] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $cookies,
        ['idempotency_key' => 'memory-match-start-001'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201
        && $gameStart['data']['created'] === true
        && $gameStart['data']['attempts_remaining_today'] === 2,
        'An authenticated user can start one server-tracked free game',
    );
    $gameSessionId = $gameStart['data']['session']['id'];
    $gameState = $gameStart['data']['session'];
    $gameReward = null;
    [$status, $gameReplay] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $cookies,
        ['idempotency_key' => 'memory-match-start-001'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $gameReplay['data']['session']['id'] === $gameSessionId
        && $gameReplay['data']['attempts_remaining_today'] === 2,
        'Retrying session creation is idempotent and consumes no extra daily attempt',
    );
    [$status, $gameCurrent] = request($baseUrl, '/api/games/memory-match/sessions/current', $cookies);
    assertTrue(
        $status === 200
        && $gameCurrent['data']['session']['id'] === $gameSessionId
        && $gameCurrent['data']['attempts_remaining_today'] === 2,
        'An active game can be recovered from server state',
    );
    assertTrue(
        $gameState['matched_cards'] === []
        && $gameState['revealed_cards'] === [],
        'The shuffled unrevealed board identities are not sent to the browser',
    );

    $flipGameCard = static function (int $index, array $extra = []) use (
        $baseUrl,
        &$cookies,
        &$csrf,
        $gameSessionId,
        &$gameState,
        &$gameReward,
    ): array {
        [$status, $response] = request(
            $baseUrl,
            '/api/games/memory-match/sessions/' . $gameSessionId . '/flip',
            $cookies,
            ['card_index' => $index, ...$extra],
            $csrf,
            'POST',
        );
        if ($status === 200) {
            $gameState = $response['data']['session'];
            $gameReward = $response['data']['reward'] ?? null;
        }
        return [$status, $response];
    };

    [$status, $firstFlip] = $flipGameCard(0);
    assertTrue(
        $status === 200
        && count($firstFlip['data']['session']['revealed_cards']) === 1,
        'A legal first card flip reveals one server-selected image',
    );
    assertTrue(!isset($firstFlip['data']['reward']), 'An incomplete Memory Match turn returns no reward');
    [$status] = $flipGameCard(0);
    assertTrue($status === 409, 'A card cannot be flipped twice while already revealed');
    [$status] = $flipGameCard(1, ['won' => true, 'matches' => 8]);
    assertTrue($status === 422, 'Client-supplied win or match results are rejected');
    [$status] = $flipGameCard(255);
    assertTrue($status === 422, 'Card indices outside the server board are rejected');
    [$status] = request(
        $baseUrl,
        '/api/games/memory-match/sessions/' . $gameSessionId . '/reward',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue($status === 404, 'There is no client-callable reward claim endpoint before a win');

    $seenImages = [];
    $matchedIndexes = [];
    $mismatchWasChecked = false;
    $matchedCardWasChecked = false;
    $ledgerFailureChecked = false;
    $learnGameState = static function () use (&$gameState, &$seenImages, &$matchedIndexes): void {
        foreach ($gameState['matched_cards'] as $card) {
            $seenImages[$card['index']] = $card['image']['id'];
            $matchedIndexes[$card['index']] = true;
        }
        foreach ($gameState['revealed_cards'] as $card) {
            $seenImages[$card['index']] = $card['image']['id'];
        }
    };
    $learnGameState();

    for ($turn = 0; $gameState['status'] !== 'completed' && $turn < 100; $turn++) {
        $learnGameState();
        $revealed = $gameState['revealed_cards'];
        if (count($revealed) === 2) {
            if (!$mismatchWasChecked) {
                [$earlyHideStatus, $earlyHideResponse] = request(
                    $baseUrl,
                    '/api/games/memory-match/sessions/' . $gameSessionId . '/hide-mismatch',
                    $cookies,
                    [],
                    $csrf,
                    'POST',
                );
                assertTrue(
                    $earlyHideStatus === 409
                    && ($earlyHideResponse['errors'][0]['code'] ?? '') === 'PAIR_REVEAL_PENDING',
                    'The server rejects hiding a mismatch before its reveal delay',
                );
                $blockedIndex = 0;
                while (
                    isset($matchedIndexes[$blockedIndex])
                    || in_array($blockedIndex, array_column($revealed, 'index'), true)
                ) {
                    $blockedIndex++;
                }
                [$status, $blockedResponse] = $flipGameCard($blockedIndex);
                assertTrue(
                    $status === 409
                    && ($blockedResponse['errors'][0]['code'] ?? '') === 'PAIR_REVEAL_PENDING',
                    'A third card is rejected while a mismatched pair is still revealed',
                );
                $mismatchWasChecked = true;
                usleep(1_100_000);
                [$status, $hiddenResponse] = request(
                    $baseUrl,
                    '/api/games/memory-match/sessions/' . $gameSessionId . '/hide-mismatch',
                    $cookies,
                    [],
                    $csrf,
                    'POST',
                );
                assertTrue(
                    $status === 200
                    && $hiddenResponse['data']['session']['revealed_cards'] === [],
                    'Mismatched cards are concealed by the server after the reveal delay',
                );
                $gameState = $hiddenResponse['data']['session'];
            } else {
                usleep(1_100_000);
                [$status, $hiddenResponse] = request(
                    $baseUrl,
                    '/api/games/memory-match/sessions/' . $gameSessionId . '/hide-mismatch',
                    $cookies,
                    [],
                    $csrf,
                    'POST',
                );
                assertTrue($status === 200, 'A later mismatch can be concealed after its delay');
                $gameState = $hiddenResponse['data']['session'];
            }
            continue;
        }

        $candidate = null;
        if (count($revealed) === 1) {
            $first = $revealed[0];
            foreach ($seenImages as $index => $imageId) {
                if (
                    $imageId === $first['image']['id']
                    && $index !== $first['index']
                    && !isset($matchedIndexes[$index])
                ) {
                    $candidate = (int) $index;
                    break;
                }
            }
        }
        if ($candidate === null) {
            for ($index = 0; $index < $gameState['cards_total']; $index++) {
                if (!isset($matchedIndexes[$index]) && !isset($seenImages[$index])) {
                    $candidate = $index;
                    break;
                }
            }
        }
        if ($candidate === null) {
            for ($index = 0; $index < $gameState['cards_total']; $index++) {
                if (!isset($matchedIndexes[$index]) && !in_array($index, array_column($revealed, 'index'), true)) {
                    $candidate = $index;
                    break;
                }
            }
        }
        assertTrue($candidate !== null, 'A remaining unmatched card can be selected');
        $finalPair = $gameState['matched_pairs'] === 7
            && count($revealed) === 1
            && ($seenImages[$candidate] ?? null) === $revealed[0]['image']['id'];
        if ($finalPair && !$ledgerFailureChecked) {
            $database->exec(
                "CREATE TRIGGER test_fail_game_reward BEFORE INSERT ON coin_ledger
                 WHEN NEW.source = 'game_reward'
                 BEGIN SELECT RAISE(ABORT, 'simulated ledger failure'); END",
            );
            [$failedStatus] = $flipGameCard($candidate);
            assertTrue($failedStatus === 500, 'A ledger write failure prevents a Memory Match win response');
            $database->exec('DROP TRIGGER test_fail_game_reward');
            $storedStatus = $database->prepare('SELECT status FROM game_sessions WHERE id = :id');
            $storedStatus->execute(['id' => $gameSessionId]);
            $rewardCount = $database->prepare(
                'SELECT COUNT(*) FROM coin_ledger WHERE reference_type = \'game_session\' AND reference_id = :id',
            );
            $rewardCount->execute(['id' => $gameSessionId]);
            $rolledBackStatus = $storedStatus->fetchColumn();
            $storedStatus->closeCursor();
            $rolledBackRewardCount = (int) $rewardCount->fetchColumn();
            $rewardCount->closeCursor();
            $walletAfterFailure = request($baseUrl, '/api/wallet', $cookies);
            assertTrue(
                $rolledBackStatus === 'in_progress'
                && $rolledBackRewardCount === 0
                && $walletAfterFailure[1]['data']['coin_balance'] === 100,
                'A failed reward ledger write rolls back both game completion and wallet credit',
            );
            $ledgerFailureChecked = true;
        }
        [$status] = $flipGameCard($candidate);
        assertTrue($status === 200, 'The server accepts a legal card flip');
        $learnGameState();

        if (!$matchedCardWasChecked && $gameState['matched_cards'] !== []) {
            $matchedIndex = $gameState['matched_cards'][0]['index'];
            [$status] = $flipGameCard($matchedIndex);
            assertTrue($status === 409, 'A card already matched cannot be flipped again');
            $matchedCardWasChecked = true;
        }
    }
    assertTrue(
        $gameState['status'] === 'completed'
        && $gameState['matched_pairs'] === 8
        && $gameState['completed_at'] !== null,
        'The server completes the game only after all image pairs are matched',
    );
    assertTrue(
        $mismatchWasChecked && $matchedCardWasChecked && $ledgerFailureChecked,
        'Mismatch, matched-card, and atomic reward protections were exercised',
    );
    assertTrue(
        $gameReward !== null
        && $gameReward['amount'] === 50
        && $gameReward['balance_type'] === 'earned'
        && $gameReward['earned_coin_balance'] === 50
        && $gameReward['coin_balance'] === 150,
        'A verified Memory Match win credits exactly 50 Earned Coins',
    );
    $firstRewardEntry = $database->prepare(
        'SELECT source, amount, balance_type, reference_type, reference_id, idempotency_key,
                balance_before, balance_after, total_balance_before, total_balance_after
         FROM coin_ledger WHERE user_id = :user_id AND reference_type = \'game_session\' AND reference_id = :session_id',
    );
    $firstRewardEntry->execute(['user_id' => $userId, 'session_id' => $gameSessionId]);
    $firstReward = $firstRewardEntry->fetch();
    $firstRewardEntry->closeCursor();
    assertTrue(
        $firstReward !== false
        && $firstReward['source'] === 'game_reward'
        && (int) $firstReward['amount'] === 50
        && $firstReward['balance_type'] === 'earned'
        && $firstReward['idempotency_key'] === 'game-win-memory-match-' . $gameSessionId
        && (int) $firstReward['balance_before'] === 0
        && (int) $firstReward['balance_after'] === 50
        && (int) $firstReward['total_balance_before'] === 100
        && (int) $firstReward['total_balance_after'] === 150,
        'The immutable ledger entry records the exact game, session, Earned bucket, and before/after balances',
    );
    [$status, $completedReplay] = $flipGameCard(0);
    assertTrue(
        $status === 409
        && ($completedReplay['errors'][0]['code'] ?? '') === 'GAME_SESSION_CLOSED',
        'A completed game cannot be submitted or completed a second time',
    );
    [$status, $completedRefresh] = request($baseUrl, '/api/games/memory-match/sessions/current', $cookies);
    assertTrue(
        $status === 200
        && $completedRefresh['data']['session']['status'] === 'completed'
        && !isset($completedRefresh['data']['reward']),
        'Refreshing a completed Memory Match session never returns a new reward grant',
    );
    $walletAfterGame = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $walletAfterGame[1]['data']['coin_balance'] === 150
        && $walletAfterGame[1]['data']['earned_coin_balance'] === 50
        && $walletAfterGame[1]['data']['purchased_coin_balance'] === 100,
        'Memory Match rewards change Earned Coins only and preserve Purchased Coins',
    );
    [$status, $secondGame] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $cookies,
        ['idempotency_key' => 'memory-match-start-002'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201 && $secondGame['data']['attempts_remaining_today'] === 1,
        'A second free start consumes exactly one additional daily attempt',
    );
    $secondGameId = $secondGame['data']['session']['id'];
    $otherUser = $database->prepare(
        "INSERT INTO users (username, email, password_hash, status, email_verified, created_at, updated_at)
         VALUES ('RewardOther', 'reward-other@example.com', 'test-hash', 'active', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
    );
    $otherUser->execute();
    $otherUserId = (int) $database->lastInsertId();
    $otherSessionToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $otherCsrfToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $database->prepare(
        'INSERT INTO user_sessions
            (user_id, session_token_hash, csrf_token_hash, expires_at, last_activity_at, created_at)
         VALUES (:user_id, :session_hash, :csrf_hash, :expires_at, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
    )->execute([
        'user_id' => $otherUserId,
        'session_hash' => hash('sha256', $otherSessionToken),
        'csrf_hash' => hash('sha256', $otherCsrfToken),
        'expires_at' => (new DateTimeImmutable('+30 minutes', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
    ]);
    $otherCookies = ['neen_session' => $otherSessionToken];
    [$status] = request(
        $baseUrl,
        '/api/games/memory-match/sessions/' . $secondGameId . '/flip',
        $otherCookies,
        ['card_index' => 0],
        $otherCsrfToken,
        'POST',
    );
    assertTrue($status === 404, 'Another user cannot flip a card or claim a reward for the session');
    [$status, $activeReplay] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $cookies,
        ['idempotency_key' => 'memory-match-start-other-key'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $activeReplay['data']['session']['id'] === $secondGameId
        && $activeReplay['data']['attempts_remaining_today'] === 1,
        'A second start cannot replace an already active game session',
    );
    $secondDeckStatement = $database->prepare(
        'SELECT deck_json FROM memory_match_states WHERE session_id = :session_id',
    );
    $secondDeckStatement->execute(['session_id' => $secondGameId]);
    $secondDeckJson = $secondDeckStatement->fetchColumn();
    $secondDeckStatement->closeCursor();
    $secondDeck = json_decode((string) $secondDeckJson, true, 16, JSON_THROW_ON_ERROR);
    $secondPairs = [];
    foreach ($secondDeck as $cardIndex => $imageId) {
        $secondPairs[$imageId][] = $cardIndex;
    }
    $secondGameState = $secondGame['data']['session'];
    $secondGameReward = null;
    foreach ($secondPairs as $pair) {
        foreach ($pair as $cardIndex) {
            [$status, $matchedMove] = request(
                $baseUrl,
                '/api/games/memory-match/sessions/' . $secondGameId . '/flip',
                $cookies,
                ['card_index' => $cardIndex],
                $csrf,
                'POST',
            );
            assertTrue($status === 200, 'A server-valid pair can be completed in a new Memory Match session');
            $secondGameState = $matchedMove['data']['session'];
            $secondGameReward = $matchedMove['data']['reward'] ?? $secondGameReward;
        }
    }
    assertTrue(
        $secondGameState['status'] === 'completed'
        && $secondGameReward !== null
        && $secondGameReward['amount'] === 50
        && $secondGameReward['earned_coin_balance'] === 100
        && $secondGameReward['coin_balance'] === 200,
        'A second Memory Match win grants a separate 50 Earned Coins reward',
    );
    [$status, $secondGameReplay] = request(
        $baseUrl,
        '/api/games/memory-match/sessions/' . $secondGameId . '/flip',
        $cookies,
        ['card_index' => $secondPairs[array_key_first($secondPairs)][0]],
        $csrf,
        'POST',
    );
    $secondRewardRows = $database->prepare(
        "SELECT COUNT(*) FROM coin_ledger
         WHERE user_id = :user_id AND source = 'game_reward'
           AND reference_type = 'game_session' AND reference_id = :session_id",
    );
    $secondRewardRows->execute(['user_id' => $userId, 'session_id' => $secondGameId]);
    $secondRewardCount = (int) $secondRewardRows->fetchColumn();
    $secondRewardRows->closeCursor();
    assertTrue(
        $status === 409
        && $secondRewardCount === 1,
        'Replaying a completed session cannot grant its game reward twice',
    );
    $walletAfterSecondWin = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $walletAfterSecondWin[1]['data']['coin_balance'] === 200
        && $walletAfterSecondWin[1]['data']['earned_coin_balance'] === 100
        && $walletAfterSecondWin[1]['data']['purchased_coin_balance'] === 100,
        'A second win updates only the Earned Coins bucket',
    );
    [$status, $thirdGame] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $cookies,
        ['idempotency_key' => 'memory-match-start-003'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201
        && $thirdGame['data']['attempts_remaining_today'] === 0,
        'The third free start is allowed and the expired session is closed',
    );
    [$status, $activeFourthStart] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $cookies,
        ['idempotency_key' => 'memory-match-start-004'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $activeFourthStart['data']['session']['id'] === $thirdGame['data']['session']['id']
        && $activeFourthStart['data']['attempts_remaining_today'] === 0,
        'An additional start request resumes the third active session without consuming another attempt',
    );
    $database->prepare('UPDATE game_sessions SET expires_at = :expired WHERE id = :id')
        ->execute([
            'expired' => (new DateTimeImmutable('-1 minute', new DateTimeZone('UTC')))->format('Y-m-d H:i:s'),
            'id' => $thirdGame['data']['session']['id'],
        ]);
    [$status] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $cookies,
        ['idempotency_key' => 'memory-match-start-005'],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'A fourth Memory Match start is available as a paid 100-Coin attempt');

    [$status] = request($baseUrl, '/api/games/picture-jigsaw/sessions/current', $anonymousCookies);
    assertTrue($status === 401, 'Anonymous visitors cannot load a user-linked Jigsaw session');
    [$status, $jigsawCatalogResponse] = request($baseUrl, '/api/games/picture-jigsaw', $cookies);
    $jigsawCatalog = $jigsawCatalogResponse['data']['game'] ?? [];
    assertTrue(
        $status === 200
        && $jigsawCatalog['board_size'] === 3
        && $jigsawCatalog['piece_count'] === 9
        && count($jigsawCatalog['images']) === 3
        && $jigsawCatalog['free_attempts_per_day'] === 3,
        'Jigsaw exposes the approved 3-by-3 board and all three images',
    );
    $jigsawManifest = json_decode(
        (string) file_get_contents($root . '/assets/hero-loot/picture-jigsaw-images.json'),
        true,
        16,
        JSON_THROW_ON_ERROR,
    );
    assertTrue(count($jigsawManifest) === 3, 'The Jigsaw manifest contains the three approved images');
    foreach ($jigsawCatalog['images'] as $image) {
        $bytes = file_get_contents($baseUrl . $image['src']);
        $info = $bytes === false ? false : getimagesizefromstring($bytes);
        assertTrue(
            $info !== false
            && ($info['mime'] ?? '') === 'image/webp'
            && $info[0] === $image['width']
            && $info[1] === $image['height'],
            'Jigsaw images are served as WebP without resizing or changing their proportions',
        );
    }
    $jigsawPage = file_get_contents($baseUrl . '/picture-jigsaw.html');
    $jigsawStyles = file_get_contents($root . '/picture-jigsaw.css');
    assertTrue(
        is_string($jigsawPage)
        && str_contains($jigsawPage, 'data-jigsaw-options')
        && is_string($jigsawStyles)
        && str_contains($jigsawStyles, 'touch-action: none;')
        && str_contains($jigsawStyles, '@media (max-width: 600px)'),
        'The Jigsaw page includes image selection and responsive pointer/touch styling',
    );

    [$status] = request(
        $baseUrl,
        '/api/games/picture-jigsaw/sessions',
        $anonymousCookies,
        [
            'image_id' => $jigsawCatalog['images'][0]['id'],
            'idempotency_key' => 'jigsaw-anonymous-start-001',
        ],
        null,
        'POST',
    );
    assertTrue($status === 401, 'An anonymous visitor cannot start a Jigsaw game');

    foreach ($jigsawCatalog['images'] as $imageNumber => $image) {
        [$status, $started] = request(
            $baseUrl,
            '/api/games/picture-jigsaw/sessions',
            $cookies,
            ['image_id' => $image['id'], 'idempotency_key' => 'jigsaw-start-' . ($imageNumber + 1) . '-0001'],
            $csrf,
            'POST',
        );
        assertTrue(
            $status === 201
            && $started['data']['session']['image']['id'] === $image['id']
            && $started['data']['session']['piece_count'] === 9,
            'A registered user can start Jigsaw with each selected original image',
        );
        $jigsawSessionId = $started['data']['session']['id'];
        $jigsawState = $started['data']['session'];
        assertTrue(
            count($jigsawState['permutation']) === 9
            && count(array_unique($jigsawState['permutation'])) === 9
            && $jigsawState['permutation'] !== range(0, 8),
            'The server creates a unique shuffled nine-piece board',
        );
        assertTrue(
            $started['data']['attempts_remaining_today'] === 2 - $imageNumber,
            'Jigsaw starts are counted per user and per Cairo day',
        );

        [$status] = request(
            $baseUrl,
            '/api/games/picture-jigsaw/sessions',
            $cookies,
            [
                'image_id' => $image['id'],
                'idempotency_key' => 'jigsaw-start-' . ($imageNumber + 1) . '-0001',
                'permutation' => range(0, 8),
                'completed' => true,
            ],
            $csrf,
            'POST',
        );
        assertTrue($status === 422, 'Client-supplied piece positions and completion flags are rejected');
        [$status] = request(
            $baseUrl,
            '/api/games/picture-jigsaw/sessions/' . $jigsawSessionId,
            $otherCookies,
        );
        assertTrue($status === 404, 'A different authenticated user cannot read another Jigsaw session');
        [$status] = request(
            $baseUrl,
            '/api/games/picture-jigsaw/sessions/' . $jigsawSessionId . '/swap',
            $otherCookies,
            ['from_index' => 0, 'to_index' => 1],
            $otherCsrfToken,
            'POST',
        );
        assertTrue($status === 404, 'Another user cannot complete or claim a reward for a Jigsaw session');
        [$status] = request(
            $baseUrl,
            '/api/games/picture-jigsaw/sessions/' . $jigsawSessionId . '/reward',
            $cookies,
            [],
            $csrf,
            'POST',
        );
        assertTrue($status === 404, 'There is no client-callable reward claim endpoint before a Jigsaw win');
        [$status, $refresh] = request(
            $baseUrl,
            '/api/games/picture-jigsaw/sessions/current',
            $cookies,
        );
        assertTrue(
            $status === 200
            && $refresh['data']['session']['id'] === $jigsawSessionId,
            'An interrupted Jigsaw board is recovered from server state after refresh',
        );

        $jigsawReward = null;
        $swapJigsaw = static function (int $from, int $to, array $extra = []) use (
            $baseUrl,
            &$cookies,
            &$csrf,
            $jigsawSessionId,
            &$jigsawState,
            &$jigsawReward,
        ): array {
            [$status, $response] = request(
                $baseUrl,
                '/api/games/picture-jigsaw/sessions/' . $jigsawSessionId . '/swap',
                $cookies,
                ['from_index' => $from, 'to_index' => $to, ...$extra],
                $csrf,
                'POST',
            );
            if ($status === 200) {
                $jigsawState = $response['data']['session'];
                $jigsawReward = $response['data']['reward'] ?? null;
            }
            return [$status, $response];
        };

        [$status] = $swapJigsaw(0, 0);
        assertTrue($status === 422, 'The server rejects a no-op piece placement');
        [$status] = $swapJigsaw(0, 1, ['completed' => true, 'permutation' => range(0, 8)]);
        assertTrue($status === 422, 'The server rejects forged completion and arrangement data');

        $firstWrong = null;
        foreach ($jigsawState['permutation'] as $position => $pieceIndex) {
            if ($position !== $pieceIndex) {
                $firstWrong = $position;
                break;
            }
        }
        $safeSwap = null;
        for ($otherPosition = 0; $otherPosition < 9; $otherPosition++) {
            if ($otherPosition === $firstWrong) {
                continue;
            }
            $candidate = $jigsawState['permutation'];
            [$candidate[$firstWrong], $candidate[$otherPosition]] = [$candidate[$otherPosition], $candidate[$firstWrong]];
            if ($candidate !== range(0, 8)) {
                $safeSwap = $otherPosition;
                break;
            }
        }
        assertTrue($safeSwap !== null, 'A deliberately incorrect piece placement can be exercised');
        [$status, $wrongPlacement] = $swapJigsaw($firstWrong, $safeSwap);
        assertTrue(
            $status === 200
            && isset($wrongPlacement['data']['session']['status'], $wrongPlacement['data']['session']['permutation'])
            && $wrongPlacement['data']['session']['status'] !== 'completed'
            && $wrongPlacement['data']['session']['permutation'] !== range(0, 8),
            'A piece in the wrong position does not complete the Jigsaw',
        );
        assertTrue(!isset($wrongPlacement['data']['reward']), 'An incomplete Jigsaw board returns no reward');

        $solveMoves = 0;
        while ($jigsawState['status'] !== 'completed' && $solveMoves < 20) {
            $wrongPosition = null;
            foreach ($jigsawState['permutation'] as $position => $pieceIndex) {
                if ($position !== $pieceIndex) {
                    $wrongPosition = $position;
                    break;
                }
            }
            assertTrue($wrongPosition !== null, 'The board still has an incorrect piece before completion');
            $correctPosition = array_search($wrongPosition, $jigsawState['permutation'], true);
            assertTrue($correctPosition !== false, 'Every Jigsaw piece has a server-stored position');
            $candidatePermutation = $jigsawState['permutation'];
            [$candidatePermutation[$wrongPosition], $candidatePermutation[$correctPosition]] = [
                $candidatePermutation[$correctPosition],
                $candidatePermutation[$wrongPosition],
            ];
            if ($imageNumber === 2 && $candidatePermutation === range(0, 8)) {
                $concurrentResults = concurrentRequests(
                    $baseUrl,
                    '/api/games/picture-jigsaw/sessions/' . $jigsawSessionId . '/swap',
                    $cookies,
                    ['from_index' => $wrongPosition, 'to_index' => $correctPosition],
                    $csrf,
                );
                $concurrentStatuses = array_column($concurrentResults, 0);
                sort($concurrentStatuses);
                assertTrue(
                    $concurrentStatuses === [200, 409],
                    'Concurrent final Jigsaw moves serialize to one completion and one rejected replay',
                );
                foreach ($concurrentResults as [$concurrentStatus, $concurrentResponse]) {
                    if ($concurrentStatus === 200) {
                        $jigsawState = $concurrentResponse['data']['session'];
                        $jigsawReward = $concurrentResponse['data']['reward'] ?? null;
                    }
                }
                $status = 200;
            } else {
                [$status] = $swapJigsaw($wrongPosition, $correctPosition);
            }
            assertTrue($status === 200, 'The server accepts a legal Jigsaw piece swap');
            $solveMoves++;
        }
        assertTrue(
            $jigsawState['status'] === 'completed'
            && $jigsawState['permutation'] === range(0, 8)
            && $jigsawState['completed_at'] !== null,
            'The server records completion only after all nine pieces are correctly positioned',
        );
        assertTrue(
            $jigsawReward !== null
            && $jigsawReward['amount'] === 50
            && $jigsawReward['balance_type'] === 'earned'
            && $jigsawReward['earned_coin_balance'] === 100 + 50 * ($imageNumber + 1)
            && $jigsawReward['coin_balance'] === 200 + 50 * ($imageNumber + 1),
            'A verified Picture Jigsaw win credits exactly 50 additional Earned Coins',
        );
        [$status, $duplicateFinish] = $swapJigsaw(0, 1);
        assertTrue(
            $status === 409
            && ($duplicateFinish['errors'][0]['code'] ?? '') === 'GAME_SESSION_CLOSED',
            'A completed Jigsaw session cannot be completed a second time',
        );
        $jigsawRewardRows = $database->prepare(
            "SELECT COUNT(*) FROM coin_ledger
             WHERE user_id = :user_id AND source = 'game_reward'
               AND reference_type = 'game_session' AND reference_id = :session_id",
        );
        $jigsawRewardRows->execute(['user_id' => $userId, 'session_id' => $jigsawSessionId]);
        $jigsawRewardCount = (int) $jigsawRewardRows->fetchColumn();
        $jigsawRewardRows->closeCursor();
        assertTrue($jigsawRewardCount === 1, 'A Picture Jigsaw session has exactly one immutable reward ledger entry');
        [$status, $recoveredFinish] = request(
            $baseUrl,
            '/api/games/picture-jigsaw/sessions/current',
            $cookies,
        );
        assertTrue(
            $status === 200
            && $recoveredFinish['data']['session']['id'] === $jigsawSessionId
            && $recoveredFinish['data']['session']['status'] === 'completed',
            'A completed Jigsaw result remains available after refresh',
        );
        [$status, $refreshedWallet] = request($baseUrl, '/api/wallet', $cookies);
        assertTrue(
            $status === 200
            && $refreshedWallet['data']['earned_coin_balance'] === 100 + 50 * ($imageNumber + 1)
            && $refreshedWallet['data']['purchased_coin_balance'] === 100
            && $refreshedWallet['data']['coin_balance'] === 200 + 50 * ($imageNumber + 1),
            'Refreshing a completed Jigsaw session shows server wallet balances without another grant',
        );
    }
    [$status] = request(
        $baseUrl,
        '/api/games/picture-jigsaw/sessions',
        $cookies,
        [
            'image_id' => $jigsawCatalog['images'][0]['id'],
            'idempotency_key' => 'jigsaw-start-limit-0001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'A fourth Jigsaw start is available as a paid 100-Coin attempt');

    [$status, $walletAfterJigsaw] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $status === 200
        && $walletAfterJigsaw['data']['coin_balance'] === 350
        && $walletAfterJigsaw['data']['earned_coin_balance'] === 250
        && $walletAfterJigsaw['data']['purchased_coin_balance'] === 100,
        'The combined game rewards remain in Earned Coins and preserve the Purchased Coins balance',
    );

    [$status, $targetCatalogResponse] = request($baseUrl, '/api/games/target-rush', $cookies);
    $targetCatalog = $targetCatalogResponse['data']['game'] ?? [];
    assertTrue(
        $status === 200
        && $targetCatalog['slug'] === 'target-rush'
        && $targetCatalog['round_duration_seconds'] === 30
        && $targetCatalog['score_to_win'] === 200
        && $targetCatalog['free_attempts_per_day'] === 3
        && $targetCatalog['reward_amount_coins'] === 50,
        'Target Rush exposes its 30-second round and current server-configured daily and reward settings',
    );
    [$status] = request($baseUrl, '/api/games/target-rush/sessions/current', $anonymousCookies);
    assertTrue($status === 401, 'Anonymous visitors cannot load a user-linked Target Rush session');
    $targetPage = file_get_contents($baseUrl . '/target-rush.html');
    assertTrue(
        is_string($targetPage)
        && str_contains($targetPage, 'NEEN_Target_Rush_01_Start_Screen.png')
        && str_contains($targetPage, 'NEEN_Target_Rush_02_Gameplay.png')
        && str_contains($targetPage, 'NEEN_Target_Rush_03_Victory_Results.png')
        && str_contains($targetPage, 'NEEN_Target_Rush_04_Game_Over.png')
        && str_contains($targetPage, 'data-rush-target'),
        'Target Rush serves all four approved screen images with interactive target controls',
    );
    foreach ([
        'NEEN_Target_Rush_01_Start_Screen.png',
        'NEEN_Target_Rush_02_Gameplay.png',
        'NEEN_Target_Rush_03_Victory_Results.png',
        'NEEN_Target_Rush_04_Game_Over.png',
    ] as $targetImage) {
        $imageBytes = file_get_contents($baseUrl . '/Target%20Rush/' . $targetImage);
        assertTrue(
            $imageBytes !== false && getimagesizefromstring($imageBytes) !== false,
            'Target Rush serves each approved screen image as an actual image asset',
        );
    }
    [$status, $targetStarted] = request(
        $baseUrl,
        '/api/games/target-rush/sessions',
        $cookies,
        ['idempotency_key' => 'target-rush-start-001'],
        $csrf,
        'POST',
    );
    $targetSession = $targetStarted['data']['session'] ?? [];
    assertTrue(
        $status === 201
        && $targetStarted['data']['attempts_remaining_today'] === 2
        && $targetSession['status'] === 'started'
        && $targetSession['time_remaining_ms'] <= 30_000
        && $targetSession['time_remaining_ms'] > 0
        && $targetSession['score'] === 0
        && is_array($targetSession['active_target'])
        && count($targetSession['lookahead_targets'] ?? []) >= 5
        && $targetSession['lookahead_targets'][0]['index'] === 0
        && $targetSession['lookahead_targets'][0]['starts_at_ms'] === 0
        && $targetSession['lookahead_targets'][0]['ends_at_ms'] === 1_100
        && is_int($targetSession['server_elapsed_ms'])
        && !array_key_exists('targets', $targetSession),
        'Target Rush starts with a private server-generated target stream, a bounded lookahead, and one Cairo-day attempt',
    );
    $targetSessionId = $targetSession['id'];
    [$futureTargetStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/hit',
        $cookies,
        ['token' => $targetSession['lookahead_targets'][1]['token']],
        $csrf,
        'POST',
    );
    assertTrue($futureTargetStatus === 409, 'Target Rush rejects a lookahead target before its server-defined start time');
    [$tamperedHitStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/hit',
        $cookies,
        ['token' => $targetSession['active_target']['token'], 'score' => 999999, 'time' => 0],
        $csrf,
        'POST',
    );
    assertTrue($tamperedHitStatus === 422, 'Target Rush rejects client-supplied scores and times');
    [$invalidTokenStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/hit',
        $cookies,
        ['token' => str_repeat('0', 32)],
        $csrf,
        'POST',
    );
    assertTrue($invalidTokenStatus === 409, 'Target Rush rejects targets that are not active in the server time slot');
    [$earlyFinishStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/finish',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue($earlyFinishStatus === 409, 'Target Rush cannot finish or earn a reward before its full 30-second round');

    $targetDb = new PDO('sqlite:' . $databasePath);
    $targetStateQuery = $targetDb->prepare(
        'SELECT secret_json, guesses_json FROM vault_cracker_states WHERE session_id = :session_id',
    );
    $targetStateQuery->execute(['session_id' => $targetSessionId]);
    $targetState = $targetStateQuery->fetch(PDO::FETCH_ASSOC);
    $targetList = json_decode((string) $targetState['secret_json'], true, 16, JSON_THROW_ON_ERROR);
    $runState = json_decode((string) $targetState['guesses_json'], true, 16, JSON_THROW_ON_ERROR);
    unset($targetStateQuery);
    $safeIndexes = [];
    foreach ($targetList as $index => $target) {
        if ($target['type'] !== 'danger') {
            $safeIndexes[] = $index;
        }
    }
    $runState['started_at_ms'] = (int) floor(microtime(true) * 1000) - 1_100 - 150;
    $targetDb->prepare(
        'UPDATE vault_cracker_states SET guesses_json = :state WHERE session_id = :session_id',
    )->execute([
        'state' => json_encode($runState, JSON_THROW_ON_ERROR),
        'session_id' => $targetSessionId,
    ]);
    [$graceHitStatus, $graceHitResponse] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/hit',
        $cookies,
        ['token' => $targetList[0]['token']],
        $csrf,
        'POST',
    );
    assertTrue(
        $graceHitStatus === 200
        && is_int($graceHitResponse['data']['session']['server_elapsed_ms'] ?? null)
        && ($graceHitResponse['data']['session']['lookahead_targets'][0]['index'] ?? null) === 1,
        'Target Rush accepts a hit within the 300ms grace period and refreshes its server-timed lookahead',
    );
    $runState = json_decode((string) $targetDb->query(
        'SELECT guesses_json FROM vault_cracker_states WHERE session_id = ' . (int) $targetSessionId,
    )->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
    $runState['started_at_ms'] = (int) floor(microtime(true) * 1000) - 1_100 - 100;
    $runState['hits'][0]['received_at_ms'] = $runState['started_at_ms'] + 100;
    $targetDb->prepare(
        'UPDATE vault_cracker_states SET guesses_json = :state WHERE session_id = :session_id',
    )->execute([
        'state' => json_encode($runState, JSON_THROW_ON_ERROR),
        'session_id' => $targetSessionId,
    ]);
    [$duplicateHitStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/hit',
        $cookies,
        ['token' => $targetList[0]['token']],
        $csrf,
        'POST',
    );
    assertTrue($duplicateHitStatus === 409, 'Target Rush rejects duplicate hits for a previously accepted target token');
    $runState['started_at_ms'] = (int) floor(microtime(true) * 1000) - (2 * 1_100) - 1_100 - 301;
    $runState['hits'][0]['received_at_ms'] = $runState['started_at_ms'] + 100;
    $targetDb->prepare(
        'UPDATE vault_cracker_states SET guesses_json = :state WHERE session_id = :session_id',
    )->execute([
        'state' => json_encode($runState, JSON_THROW_ON_ERROR),
        'session_id' => $targetSessionId,
    ]);
    [$expiredTargetStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/hit',
        $cookies,
        ['token' => $targetList[1]['token']],
        $csrf,
        'POST',
    );
    assertTrue($expiredTargetStatus === 409, 'Target Rush rejects a target hit after the 300ms grace period');
    $targetStartMs = (int) floor(microtime(true) * 1000) - 31_000;
    foreach ($safeIndexes as $index) {
        if ($index === 0) {
            continue;
        }
        $runState['started_at_ms'] = (int) floor(microtime(true) * 1000) - ($index * 1_100) - 100;
        foreach ($runState['hits'] as &$existingHit) {
            $existingHit['received_at_ms'] = $runState['started_at_ms'] + ($existingHit['index'] * 1_100) + 100;
        }
        unset($existingHit);
        $targetDb->prepare(
            'UPDATE vault_cracker_states SET guesses_json = :state WHERE session_id = :session_id',
        )->execute([
            'state' => json_encode($runState, JSON_THROW_ON_ERROR),
            'session_id' => $targetSessionId,
        ]);
        [$hitStatus, $hitResponse] = request(
            $baseUrl,
            '/api/games/target-rush/sessions/' . $targetSessionId . '/hit',
            $cookies,
            ['token' => $targetList[$index]['token']],
            $csrf,
            'POST',
        );
        assertTrue(
            $hitStatus === 200
            && $hitResponse['data']['session']['hits'] === count($runState['hits']) + 1,
            'Target Rush records each active hit using server-owned target type and timing',
        );
        $runState = json_decode((string) $targetDb->query(
            'SELECT guesses_json FROM vault_cracker_states WHERE session_id = ' . (int) $targetSessionId,
        )->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
        $runState['started_at_ms'] = $targetStartMs;
        foreach ($runState['hits'] as &$hit) {
            $hit['received_at_ms'] = $targetStartMs + ($hit['index'] * 1_100) + 100;
        }
        unset($hit);
        $targetDb->prepare(
            'UPDATE vault_cracker_states SET guesses_json = :state WHERE session_id = :session_id',
        )->execute([
            'state' => json_encode($runState, JSON_THROW_ON_ERROR),
            'session_id' => $targetSessionId,
        ]);
        $targetDb->prepare('UPDATE game_sessions SET started_at = :started_at WHERE id = :id')->execute([
            'started_at' => gmdate('Y-m-d H:i:s', intdiv($targetStartMs, 1000)),
            'id' => $targetSessionId,
        ]);
    }
    unset($targetDb);
    [$status, $targetWin] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/finish',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $targetWin['data']['session']['status'] === 'completed'
        && $targetWin['data']['session']['score'] >= $targetCatalog['score_to_win']
        && $targetWin['data']['reward']['amount'] === 50,
        'A full server-timed Target Rush round credits the configured reward only after its verified score goal',
    );
    [$targetReplayStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/finish',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    [$targetClaimStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetSessionId . '/reward',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    $targetLedgerDb = new PDO('sqlite:' . $databasePath);
    $targetLedgerCount = $targetLedgerDb->prepare(
        "SELECT COUNT(*) FROM coin_ledger
         WHERE user_id = :user_id AND source = 'game_reward' AND reference_type = 'game_session'
           AND reference_id = :session_id",
    );
    $targetLedgerCount->execute(['user_id' => $userId, 'session_id' => $targetSessionId]);
    assertTrue(
        $targetReplayStatus === 409
        && $targetClaimStatus === 404
        && (int) $targetLedgerCount->fetchColumn() === 1,
        'Target Rush blocks repeated reward issuance and leaves exactly one immutable ledger entry',
    );
    unset($targetLedgerCount, $targetLedgerDb);

    [$targetFailStartStatus, $targetFailStart] = request(
        $baseUrl,
        '/api/games/target-rush/sessions',
        $cookies,
        ['idempotency_key' => 'target-rush-start-002'],
        $csrf,
        'POST',
    );
    $targetFailId = $targetFailStart['data']['session']['id'];
    $targetFailDb = new PDO('sqlite:' . $databasePath);
    $targetFailStateQuery = $targetFailDb->prepare(
        'SELECT guesses_json FROM vault_cracker_states WHERE session_id = :session_id',
    );
    $targetFailStateQuery->execute(['session_id' => $targetFailId]);
    $targetFailState = json_decode((string) $targetFailStateQuery->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
    $targetFailState['started_at_ms'] = (int) floor(microtime(true) * 1000) - 31_000;
    $targetFailDb->prepare(
        'UPDATE vault_cracker_states SET guesses_json = :state WHERE session_id = :session_id',
    )->execute([
        'state' => json_encode($targetFailState, JSON_THROW_ON_ERROR),
        'session_id' => $targetFailId,
    ]);
    unset($targetFailStateQuery);
    unset($targetFailDb);
    [$targetFailStatus, $targetFail] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $targetFailId . '/finish',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue(
        $targetFailStartStatus === 201
        && $targetFailStatus === 200
        && $targetFail['data']['session']['status'] === 'failed'
        && $targetFail['data']['reward'] === null,
        'An expired round below the score goal closes without issuing a reward',
    );
    [$thirdTargetStartStatus, $thirdTargetStart] = request(
        $baseUrl,
        '/api/games/target-rush/sessions',
        $cookies,
        ['idempotency_key' => 'target-rush-start-003'],
        $csrf,
        'POST',
    );
    $thirdTargetId = $thirdTargetStart['data']['session']['id'];
    $thirdTargetDb = new PDO('sqlite:' . $databasePath);
    $thirdTargetStateQuery = $thirdTargetDb->prepare(
        'SELECT guesses_json FROM vault_cracker_states WHERE session_id = :session_id',
    );
    $thirdTargetStateQuery->execute(['session_id' => $thirdTargetId]);
    $thirdTargetState = json_decode((string) $thirdTargetStateQuery->fetchColumn(), true, 16, JSON_THROW_ON_ERROR);
    $thirdTargetState['started_at_ms'] = (int) floor(microtime(true) * 1000) - 31_000;
    $thirdTargetDb->prepare(
        'UPDATE vault_cracker_states SET guesses_json = :state WHERE session_id = :session_id',
    )->execute([
        'state' => json_encode($thirdTargetState, JSON_THROW_ON_ERROR),
        'session_id' => $thirdTargetId,
    ]);
    unset($thirdTargetStateQuery, $thirdTargetDb);
    [$thirdTargetFinishStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions/' . $thirdTargetId . '/finish',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue(
        $thirdTargetStartStatus === 201 && $thirdTargetFinishStatus === 200,
        'The third Target Rush attempt is available and can end without a reward',
    );
    [$targetLimitStatus] = request(
        $baseUrl,
        '/api/games/target-rush/sessions',
        $cookies,
        ['idempotency_key' => 'target-rush-start-004'],
        $csrf,
        'POST',
    );
    assertTrue(
        $targetLimitStatus === 201,
        'Target Rush allows an extra paid attempt after three free Cairo-day starts',
    );

    [$status, $sequenceCatalogResponse] = request($baseUrl, '/api/games/loot-sequence', $cookies);
    $sequenceCatalog = $sequenceCatalogResponse['data']['game'] ?? [];
    assertTrue(
        $status === 200
        && $sequenceCatalog['slug'] === 'loot_sequence'
        && count($sequenceCatalog['stickers']) === 6
        && $sequenceCatalog['free_attempts_per_day'] === 3
        && $sequenceCatalog['reward_amount_coins'] === 50,
        'Loot Sequence exposes its six stickers and server-configured reward limits',
    );
    [$status] = request($baseUrl, '/api/games/loot-sequence/sessions/current', $anonymousCookies);
    assertTrue($status === 401, 'Anonymous visitors cannot load a user-linked Loot Sequence session');
    $sequencePage = file_get_contents($baseUrl . '/loot-sequence.html');
    assertTrue(
        is_string($sequencePage) && str_contains($sequencePage, 'data-sequence-submit'),
        'The Loot Sequence page is served with playable controls',
    );
    [$status, $sequenceStarted] = request(
        $baseUrl,
        '/api/games/loot-sequence/sessions',
        $cookies,
        ['idempotency_key' => 'loot-sequence-start-001'],
        $csrf,
        'POST',
    );
    $sequenceSession = $sequenceStarted['data']['session'] ?? [];
    assertTrue(
        $status === 201
        && $sequenceStarted['data']['attempts_remaining_today'] === 2
        && $sequenceSession['round_number'] === 1
        && count($sequenceSession['sequence']) === 3,
        'Loot Sequence stores its server pattern and begins with a three-step round',
    );
    $sequenceSessionId = $sequenceSession['id'];
    $sequenceReward = null;
    for ($round = 1; $round <= 4; $round++) {
        [$status, $roundResult] = request(
            $baseUrl,
            '/api/games/loot-sequence/sessions/' . $sequenceSessionId . '/submit',
            $cookies,
            ['pattern' => $sequenceSession['sequence']],
            $csrf,
            'POST',
        );
        assertTrue($status === 200 && $roundResult['data']['correct'] === true, 'Loot Sequence accepts a completed server-matching round');
        if ($round < 4) {
            $sequenceSession = $roundResult['data']['session'];
            assertTrue(
                $sequenceSession['round_number'] === $round + 1
                && count($sequenceSession['sequence']) === $round + 3,
                'Loot Sequence advances through progressively longer server-controlled rounds',
            );
        } else {
            $sequenceReward = $roundResult['data']['reward'] ?? null;
            assertTrue(
                $roundResult['data']['session']['status'] === 'completed'
                && is_array($sequenceReward)
                && $sequenceReward['amount'] === 50,
                'Completing all four Loot Sequence rounds credits Earned Coins',
            );
        }
    }

    $failSequenceSession = static function (string $key) use (
        $baseUrl,
        $sequenceCatalog,
        &$cookies,
        &$csrf,
    ): void {
        [$startStatus, $started] = request(
            $baseUrl,
            '/api/games/loot-sequence/sessions',
            $cookies,
            ['idempotency_key' => $key],
            $csrf,
            'POST',
        );
        assertTrue($startStatus === 201, 'A remaining Loot Sequence daily attempt starts');
        $session = $started['data']['session'];
        $wrongPattern = $session['sequence'];
        $replacement = $sequenceCatalog['stickers'][0]['id'];
        if ($wrongPattern[0] === $replacement) {
            $replacement = $sequenceCatalog['stickers'][1]['id'];
        }
        $wrongPattern[0] = $replacement;
        [$status, $response] = request(
            $baseUrl,
            '/api/games/loot-sequence/sessions/' . $session['id'] . '/submit',
            $cookies,
            ['pattern' => $wrongPattern],
            $csrf,
            'POST',
        );
        assertTrue(
            $status === 200
            && $response['data']['correct'] === false
            && $response['data']['session']['status'] === 'failed',
            'Loot Sequence rejects an incorrect pattern without awarding Coins',
        );
    };
    $failSequenceSession('loot-sequence-start-002');
    $failSequenceSession('loot-sequence-start-003');
    [$sequenceLimitStatus] = request(
        $baseUrl,
        '/api/games/loot-sequence/sessions',
        $cookies,
        ['idempotency_key' => 'loot-sequence-start-004'],
        $csrf,
        'POST',
    );
    assertTrue($sequenceLimitStatus === 201, 'Loot Sequence allows a paid 100-Coin attempt after three free starts');

    [$sunnyAnonymousStatus] = request($baseUrl, '/api/games/sunny-island/state', $anonymousCookies);
    assertTrue($sunnyAnonymousStatus === 401, 'Sunny Island state requires an authenticated player');
    [$status, $sunnyInitialResponse] = request($baseUrl, '/api/games/sunny-island/state', $cookies);
    $sunnyInitial = $sunnyInitialResponse['data']['state'] ?? [];
    assertTrue(
        $status === 200
        && $sunnyInitial['progress']['xp'] === 0
        && $sunnyInitial['progress']['level'] === 1
        && $sunnyInitial['progress']['unlocked_collectibles'] === []
        && $sunnyInitial['progress']['discovered_zones'] === []
        && $sunnyInitial['progress']['total_adventures_completed'] === 0
        && $sunnyInitial['progress']['last_position'] === null
        && count($sunnyInitial['codex']) === 9
        && $sunnyInitial['adventure']['attempts_remaining_today'] === 3
        && $sunnyInitial['adventure']['reward_amount_coins'] === 50
        && $sunnyInitial['adventure']['active_session'] === null,
        'Sunny Island returns initial permanent progress, the Codex, and daily adventure settings',
    );
    [$sunnyProgressStatus] = request(
        $baseUrl,
        '/api/games/sunny-island/progress',
        $cookies,
        [
            'collectibles' => ['golden_compass'],
            'discovered_zones' => ['sunset_ridge'],
        ],
        $csrf,
        'POST',
    );
    assertTrue(
        $sunnyProgressStatus === 410,
        'Sunny Island rejects the deprecated client-progress endpoint',
    );
    [$sunnyProgressReadStatus, $sunnyProgressRead] = request(
        $baseUrl,
        '/api/games/sunny-island/state',
        $cookies,
    );
    assertTrue(
        $sunnyProgressReadStatus === 200
        && $sunnyProgressRead['data']['state']['progress']['unlocked_collectibles'] === []
        && $sunnyProgressRead['data']['state']['progress']['discovered_zones'] === [],
        'Client-only Sunny Island progress fields do not change server state',
    );
    [$sunnyStartStatus, $sunnyStarted] = request(
        $baseUrl,
        '/api/games/sunny-island/start',
        $cookies,
        ['idempotency_key' => 'sunny-island-start-001'],
        $csrf,
        'POST',
    );
    $sunnySession = $sunnyStarted['data']['session'] ?? [];
    $sunnyObjectives = $sunnySession['objectives'] ?? [];
    assertTrue(
        $sunnyStartStatus === 201
        && $sunnyStarted['data']['attempts_remaining_today'] === 2
        && $sunnyStarted['data']['reward_amount_coins'] === 50
        && count($sunnyObjectives) === 3
        && count(array_unique(array_column($sunnyObjectives, 'id'))) === 3
        && count(array_filter(
            $sunnyObjectives,
            static fn (array $objective): bool => !array_key_exists('token', $objective),
        )) === count($sunnyObjectives)
        && !array_key_exists('contract', $sunnySession),
        'Sunny Island starts a server-backed adventure and keeps private objective tokens off the client',
    );
    $sunnySessionId = $sunnySession['id'];
    $sunnyObjectiveReports = array_column($sunnyObjectives, 'id');
    [$sunnyTooFastStatus] = request(
        $baseUrl,
        '/api/games/sunny-island/complete',
        $cookies,
        [
            'session_id' => $sunnySessionId,
            'request_key' => 'sunny-complete-too-fast',
            'completion_nonce' => $sunnySession['action_nonce'],
        ],
        $csrf,
        'POST',
    );
    assertTrue($sunnyTooFastStatus === 409, 'Sunny Island rejects completion before the minimum adventure duration');
    [$sunnyInvalidObjectiveStatus] = request(
        $baseUrl,
        '/api/games/sunny-island/action',
        $cookies,
        [
            'session_id' => $sunnySessionId,
            'request_key' => 'sunny-invalid-action',
            'action_nonce' => $sunnySession['action_nonce'],
            'action' => 'unknown',
            'target_id' => 'not-a-contract-target',
        ],
        $csrf,
        'POST',
    );
    assertTrue($sunnyInvalidObjectiveStatus === 422, 'Sunny Island rejects unknown actions outside the server-held contract');
    $sunnyTestDb = new PDO('sqlite:' . $databasePath);
    $sunnyStartedAt = $sunnyTestDb->prepare(
        "UPDATE game_sessions SET started_at = :started_at WHERE id = :session_id",
    );
    $sunnyStartedAt->execute([
        'started_at' => gmdate('Y-m-d H:i:s', time() - 3),
        'session_id' => $sunnySessionId,
    ]);
    unset($sunnyStartedAt, $sunnyTestDb);
    [$sunnyIncompleteStatus] = request(
        $baseUrl,
        '/api/games/sunny-island/complete',
        $cookies,
        [
            'session_id' => $sunnySessionId,
            'request_key' => 'sunny-complete-incomplete',
            'completion_nonce' => $sunnySession['action_nonce'],
        ],
        $csrf,
        'POST',
    );
    assertTrue($sunnyIncompleteStatus === 409, 'Sunny Island rejects completion when required objectives are missing');
    [$neonTravelStatus, $neonTravelResponse] = request(
        $baseUrl,
        '/api/games/sunny-island/action',
        $cookies,
        [
            'session_id' => $sunnySessionId,
            'request_key' => 'sunny-travel-neon',
            'action_nonce' => $sunnySession['action_nonce'],
            'action' => 'enter_zone',
            'target_id' => 'neon_pier',
        ],
        $csrf,
        'POST',
    );
    assertTrue($neonTravelStatus === 200, 'Sunny Island accepts a server-authorized adjacent zone transition');
    $sunnySession['action_nonce'] = $neonTravelResponse['data']['action_nonce'];
    foreach (['pier-beacon-a', 'pier-beacon-b'] as $beaconIndex => $beaconId) {
        [$beaconStatus, $beaconResponse] = request(
            $baseUrl,
            '/api/games/sunny-island/action',
            $cookies,
            [
                'session_id' => $sunnySessionId,
                'request_key' => 'sunny-beacon-' . ($beaconIndex + 1),
                'action_nonce' => $sunnySession['action_nonce'],
                'action' => 'activate_beacon',
                'target_id' => $beaconId,
            ],
            $csrf,
            'POST',
        );
        assertTrue($beaconStatus === 200, 'Sunny Island accepts a server-location beacon event');
        $sunnySession['action_nonce'] = $beaconResponse['data']['action_nonce'];
    }
    $sunnyObjectiveStatus = 200;
    $sunnyObjectiveResponse = [];
    $sunnyZoneNeighbors = [
        'neon_pier' => ['palm_grove'],
        'palm_grove' => ['neon_pier', 'loot_canyon'],
        'loot_canyon' => ['palm_grove', 'sunset_ridge'],
        'sunset_ridge' => ['loot_canyon'],
    ];
    $sunnyEntrances = [
        'neon_pier' => ['zone' => 'neon_pier', 'x' => 240, 'y' => 565],
        'palm_grove' => ['zone' => 'palm_grove', 'x' => 260, 'y' => 785],
        'loot_canyon' => ['zone' => 'loot_canyon', 'x' => 260, 'y' => 815],
        'sunset_ridge' => ['zone' => 'sunset_ridge', 'x' => 780, 'y' => 825],
    ];
    $sunnyCurrentZone = 'neon_pier';
    foreach ($sunnyObjectives as $objective) {
        $queue = [[$sunnyCurrentZone]];
        $visited = [$sunnyCurrentZone => true];
        $zonePath = [];
        while ($queue !== []) {
            $path = array_shift($queue);
            $zone = $path[count($path) - 1];
            if ($zone === $objective['zone']) {
                $zonePath = $path;
                break;
            }
            foreach ($sunnyZoneNeighbors[$zone] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $queue[] = [...$path, $neighbor];
                }
            }
        }
        foreach (array_slice($zonePath, 1) as $zone) {
            [$zoneStatus, $zoneResponse] = request(
                $baseUrl,
                '/api/games/sunny-island/action',
                $cookies,
                [
                    'session_id' => $sunnySessionId,
                    'request_key' => 'sunny-objective-zone-' . $zone,
                    'action_nonce' => $sunnySession['action_nonce'],
                    'action' => 'enter_zone',
                    'target_id' => $zone,
                ],
                $csrf,
                'POST',
            );
            if ($zoneStatus !== 200) {
                $sunnyObjectiveStatus = $zoneStatus;
                break 2;
            }
            $sunnySession['action_nonce'] = $zoneResponse['data']['action_nonce'];
        }
        $sunnyCurrentZone = $objective['zone'];
        if ($objective['id'] === 'collect_drops') {
            foreach (['sun_crate', 'palm_pack', 'reef_sticker'] as $collectIndex => $collectibleId) {
                [$collectStatus, $collectResponse] = request(
                    $baseUrl,
                    '/api/games/sunny-island/action',
                    $cookies,
                    [
                        'session_id' => $sunnySessionId,
                        'request_key' => 'sunny-objective-collect-' . $collectIndex,
                        'action_nonce' => $sunnySession['action_nonce'],
                        'action' => 'collect',
                        'target_id' => $collectibleId,
                    ],
                    $csrf,
                    'POST',
                );
                if ($collectStatus !== 200) {
                    $sunnyObjectiveStatus = $collectStatus;
                    break 2;
                }
                $sunnySession['action_nonce'] = $collectResponse['data']['action_nonce'];
            }
        }
        if ($objective['id'] === 'find_relic') {
            [$collectStatus, $collectResponse] = request(
                $baseUrl,
                '/api/games/sunny-island/action',
                $cookies,
                [
                    'session_id' => $sunnySessionId,
                    'request_key' => 'sunny-objective-golden-compass',
                    'action_nonce' => $sunnySession['action_nonce'],
                    'action' => 'collect',
                    'target_id' => 'golden_compass',
                ],
                $csrf,
                'POST',
            );
            if ($collectStatus !== 200) {
                $sunnyObjectiveStatus = $collectStatus;
                break;
            }
            $sunnySession['action_nonce'] = $collectResponse['data']['action_nonce'];
        }
        [$sunnyObjectiveStatus, $sunnyObjectiveResponse] = request(
            $baseUrl,
            '/api/games/sunny-island/state',
            $cookies,
        );
        if ($sunnyObjectiveStatus !== 200) {
            break;
        }
    }
    [$lootZoneStatus, $lootZoneResponse] = request(
        $baseUrl,
        '/api/games/sunny-island/action',
        $cookies,
        [
            'session_id' => $sunnySessionId,
            'request_key' => 'sunny-finish-route-loot',
            'action_nonce' => $sunnySession['action_nonce'],
            'action' => 'enter_zone',
            'target_id' => 'loot_canyon',
        ],
        $csrf,
        'POST',
    );
    if ($lootZoneStatus === 200) {
        $sunnySession['action_nonce'] = $lootZoneResponse['data']['action_nonce'];
        [$relicStatus, $relicResponse] = request(
            $baseUrl,
            '/api/games/sunny-island/action',
            $cookies,
            [
                'session_id' => $sunnySessionId,
                'request_key' => 'sunny-finish-golden-compass',
                'action_nonce' => $sunnySession['action_nonce'],
                'action' => 'collect',
                'target_id' => 'golden_compass',
            ],
            $csrf,
            'POST',
        );
        if ($relicStatus === 200) {
            $sunnySession['action_nonce'] = $relicResponse['data']['action_nonce'];
        }
    }
    [$sunnyObjectiveStatus, $sunnyObjectiveResponse] = request(
        $baseUrl,
        '/api/games/sunny-island/state',
        $cookies,
    );
    assertTrue(
        $sunnyObjectiveStatus === 200
        && $sunnyObjectiveResponse['data']['state']['adventure']['active_session']['objectives_completed'] === 3,
        'Sunny Island verifies objective reports against the server contract and saved zone progress',
    );
    [$sunnyCompleteStatus, $sunnyComplete] = request(
        $baseUrl,
        '/api/games/sunny-island/complete',
        $cookies,
        [
            'session_id' => $sunnySessionId,
            'request_key' => 'sunny-complete-success',
            'completion_nonce' => $sunnySession['action_nonce'],
        ],
        $csrf,
        'POST',
    );
    assertTrue(
        $sunnyCompleteStatus === 200
        && $sunnyComplete['data']['session']['status'] === 'completed'
        && $sunnyComplete['data']['xp_awarded'] === 100
        && $sunnyComplete['data']['progress']['xp'] === 100
        && $sunnyComplete['data']['progress']['level'] === 1
        && $sunnyComplete['data']['progress']['total_adventures_completed'] === 1
        && $sunnyComplete['data']['reward']['amount'] === 50
        && $sunnyComplete['data']['reward']['balance_type'] === 'earned',
        'Sunny Island awards XP and Earned Coins only after server-verifying all objectives',
    );
    [$sunnyFinalStateStatus, $sunnyFinalState] = request(
        $baseUrl,
        '/api/games/sunny-island/state',
        $cookies,
    );
    assertTrue(
        $sunnyFinalStateStatus === 200
        && $sunnyFinalState['data']['state']['progress']['unlocked_collectibles']
            === ['sun_crate', 'palm_pack', 'reef_sticker', 'golden_compass']
        && count($sunnyFinalState['data']['state']['progress']['discovered_zones']) === 4
        && in_array(
            $sunnyFinalState['data']['state']['progress']['last_position']['zone'],
            ['neon_pier', 'palm_grove', 'loot_canyon', 'sunset_ridge'],
            true,
        ),
        'Adventure completion preserves permanent Sunny Island discoveries',
    );
    $sunnyLedgerDb = new PDO('sqlite:' . $databasePath);
    $sunnyLedgerCount = $sunnyLedgerDb->prepare(
        "SELECT COUNT(*) FROM coin_ledger
         WHERE user_id = :user_id AND source = 'game_reward'
           AND reference_type = 'game_session' AND reference_id = :session_id",
    );
    $sunnyLedgerCount->execute(['user_id' => $userId, 'session_id' => $sunnySessionId]);
    assertTrue((int) $sunnyLedgerCount->fetchColumn() === 1, 'Sunny Island credits exactly one immutable ledger reward');
    unset($sunnyLedgerCount, $sunnyLedgerDb);
    [$sunnyReplayStatus] = request(
        $baseUrl,
        '/api/games/sunny-island/complete',
        $cookies,
        [
            'session_id' => $sunnySessionId,
            'request_key' => 'sunny-complete-success',
            'completion_nonce' => $sunnySession['action_nonce'],
        ],
        $csrf,
        'POST',
    );
    assertTrue($sunnyReplayStatus === 409, 'Sunny Island completion cannot be replayed for additional XP or Coins');
    $completeSunnyAdventure = static function (
        string $attemptKey,
        int $expectedXp,
        int $expectedLevel,
        int $expectedCompleted,
    ) use ($baseUrl, $databasePath, &$cookies, &$csrf): void {
        [$startStatus, $started] = request(
            $baseUrl,
            '/api/games/sunny-island/start',
            $cookies,
            ['idempotency_key' => $attemptKey],
            $csrf,
            'POST',
        );
        assertTrue($startStatus === 201, 'A remaining Sunny Island daily adventure can start');
        $session = $started['data']['session'];
        $testDb = new PDO('sqlite:' . $databasePath);
        $setStartedAt = $testDb->prepare(
            'UPDATE game_sessions SET started_at = :started_at WHERE id = :session_id',
        );
        $setStartedAt->execute([
            'started_at' => gmdate('Y-m-d H:i:s', time() - 3),
            'session_id' => $session['id'],
        ]);
        unset($setStartedAt, $testDb);
        [$sunnyStateStatus, $sunnyStateResponse] = request(
            $baseUrl,
            '/api/games/sunny-island/state',
            $cookies,
        );
        $multiCurrentZone = $sunnyStateStatus === 200
            ? (string) ($sunnyStateResponse['data']['state']['progress']['last_position']['zone'] ?? 'neon_pier')
            : 'neon_pier';
        $multiNeighbors = [
            'neon_pier' => ['palm_grove'],
            'palm_grove' => ['neon_pier', 'loot_canyon'],
            'loot_canyon' => ['palm_grove', 'sunset_ridge'],
            'sunset_ridge' => ['loot_canyon'],
        ];
        $queue = [[$multiCurrentZone]];
        $visited = [$multiCurrentZone => true];
        $pathToNeon = [];
        while ($queue !== []) {
            $candidatePath = array_shift($queue);
            $candidateZone = $candidatePath[count($candidatePath) - 1];
            if ($candidateZone === 'neon_pier') {
                $pathToNeon = $candidatePath;
                break;
            }
            foreach ($multiNeighbors[$candidateZone] as $neighbor) {
                if (!isset($visited[$neighbor])) {
                    $visited[$neighbor] = true;
                    $queue[] = [...$candidatePath, $neighbor];
                }
            }
        }
        $multiEntrances = [
            'neon_pier' => ['zone' => 'neon_pier', 'x' => 240, 'y' => 565],
            'palm_grove' => ['zone' => 'palm_grove', 'x' => 260, 'y' => 785],
            'loot_canyon' => ['zone' => 'loot_canyon', 'x' => 260, 'y' => 815],
            'sunset_ridge' => ['zone' => 'sunset_ridge', 'x' => 780, 'y' => 825],
        ];
        foreach (array_slice($pathToNeon, 1) as $zone) {
            request(
                $baseUrl,
                '/api/games/sunny-island/progress',
                $cookies,
                [
                    'session_id' => $session['id'],
                    'progress_event' => [
                        'type' => 'zone',
                        'id' => $zone,
                        'position' => $multiEntrances[$zone],
                    ],
                ],
                $csrf,
                'POST',
            );
        }
        request(
            $baseUrl,
            '/api/games/sunny-island/progress',
            $cookies,
            [
                'session_id' => $session['id'],
                'progress_event' => [
                    'type' => 'zone',
                    'id' => 'neon_pier',
                    'position' => ['zone' => 'neon_pier', 'x' => 240, 'y' => 565],
                ],
            ],
            $csrf,
            'POST',
        );
        foreach ([
            ['type' => 'beacon', 'id' => 'pier-beacon-a', 'position' => ['zone' => 'neon_pier', 'x' => 800, 'y' => 440]],
            ['type' => 'beacon', 'id' => 'pier-beacon-b', 'position' => ['zone' => 'neon_pier', 'x' => 1110, 'y' => 600]],
        ] as $beaconEvent) {
            request(
                $baseUrl,
                '/api/games/sunny-island/progress',
                $cookies,
                [
                    'session_id' => $session['id'],
                    'progress_event' => $beaconEvent,
                ],
                $csrf,
                'POST',
            );
        }
        $completeStatus = 200;
        $multiCurrentZone = 'neon_pier';
        $multiNeighbors = [
            'neon_pier' => ['palm_grove'],
            'palm_grove' => ['neon_pier', 'loot_canyon'],
            'loot_canyon' => ['palm_grove', 'sunset_ridge'],
            'sunset_ridge' => ['loot_canyon'],
        ];
        $multiEntrances = [
            'neon_pier' => ['zone' => 'neon_pier', 'x' => 240, 'y' => 565],
            'palm_grove' => ['zone' => 'palm_grove', 'x' => 260, 'y' => 785],
            'loot_canyon' => ['zone' => 'loot_canyon', 'x' => 260, 'y' => 815],
            'sunset_ridge' => ['zone' => 'sunset_ridge', 'x' => 780, 'y' => 825],
        ];
        foreach ($session['objectives'] as $objective) {
            $queue = [[$multiCurrentZone]];
            $visited = [$multiCurrentZone => true];
            $path = [];
            while ($queue !== []) {
                $candidatePath = array_shift($queue);
                $candidateZone = $candidatePath[count($candidatePath) - 1];
                if ($candidateZone === $objective['zone']) {
                    $path = $candidatePath;
                    break;
                }
                foreach ($multiNeighbors[$candidateZone] as $neighbor) {
                    if (!isset($visited[$neighbor])) {
                        $visited[$neighbor] = true;
                        $queue[] = [...$candidatePath, $neighbor];
                    }
            }
            }
            foreach (array_slice($path, 1) as $zone) {
                request(
                    $baseUrl,
                    '/api/games/sunny-island/progress',
                    $cookies,
                    [
                        'session_id' => $session['id'],
                        'progress_event' => [
                            'type' => 'zone',
                            'id' => $zone,
                            'position' => $multiEntrances[$zone],
                        ],
                    ],
                    $csrf,
                    'POST',
                );
            }
            $multiCurrentZone = $objective['zone'];
            [$completeStatus] = request(
                $baseUrl,
                '/api/games/sunny-island/progress',
                $cookies,
                [
                    'session_id' => $session['id'],
                    'completed_objectives' => [$objective['id']],
                ],
                $csrf,
                'POST',
            );
            if ($completeStatus !== 200) {
                break;
            }
        }
        [$completeStatus, $completed] = request(
            $baseUrl,
            '/api/games/sunny-island/complete',
            $cookies,
            ['session_id' => $session['id']],
            $csrf,
            'POST',
        );
        assertTrue(
            $completeStatus === 200
            && $completed['data']['progress']['xp'] === $expectedXp
            && $completed['data']['progress']['level'] === $expectedLevel
            && $completed['data']['progress']['total_adventures_completed'] === $expectedCompleted
            && $completed['data']['reward']['amount'] === 50,
            'Sunny Island awards XP across adventures and advances levels every 300 XP',
        );
    };
    $completeSunnyAdventure('sunny-island-start-002', 200, 1, 2);
    $completeSunnyAdventure('sunny-island-start-003', 300, 2, 3);
    [$sunnyDailyLimitStatus] = request(
        $baseUrl,
        '/api/games/sunny-island/start',
        $cookies,
        ['idempotency_key' => 'sunny-island-start-004'],
        $csrf,
        'POST',
    );
    assertTrue($sunnyDailyLimitStatus === 201, 'Sunny Island allows a paid 100-Coin adventure after three free adventures');

    [$status, $publicLeaderboard] = request($baseUrl, '/api/events/leaderboard', $anonymousCookies);
    $leaderboards = $publicLeaderboard['data']['leaderboards'] ?? [];
    $earnedLeader = array_values(array_filter(
        $leaderboards['earned_coins'] ?? [],
        static fn (array $row): bool => $row['username'] === 'neenplayer',
    ))[0] ?? null;
    $gameWinner = array_values(array_filter(
        $leaderboards['game_wins'] ?? [],
        static fn (array $row): bool => $row['username'] === 'neenplayer',
    ))[0] ?? null;
    assertTrue(
        $status === 200
        && is_array($earnedLeader)
        && $earnedLeader['score'] >= 350
        && is_array($gameWinner)
        && $gameWinner['score'] >= 2
        && array_key_exists('attendance_streaks', $leaderboards)
        && !array_key_exists('email', $earnedLeader)
        && !array_key_exists('email', $gameWinner),
        'The public leaderboard returns current Coins, verified wins, attendance data, and no email fields',
    );
    [$sequenceClaimStatus] = request(
        $baseUrl,
        '/api/games/loot-sequence/sessions/' . $sequenceSessionId . '/reward',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue(
        $targetClaimStatus === 404 && $sequenceClaimStatus === 404,
        'Completed game rewards are credited only by server-verified game completion, never a client claim request',
    );
    $challengesPage = file_get_contents($baseUrl . '/events-challenges.html');
    assertTrue(
        is_string($challengesPage)
        && str_contains($challengesPage, 'target-rush.html')
        && str_contains($challengesPage, 'data-challenge="target-rush"')
        && !str_contains($challengesPage, 'vault-cracker.html')
        && !str_contains($challengesPage, 'Vault Cracker')
        && str_contains($challengesPage, 'loot-sequence.html')
        && str_contains($challengesPage, 'sunny-island.html')
        && str_contains($challengesPage, 'data-challenge="sunny_island"')
        && str_contains($challengesPage, 'data-challenge-status'),
        'The Challenges Hub links the new games and Sunny Island and renders live daily challenge state',
    );
    [$sunnyPageStatus, $sunnyPage] = requestPage($baseUrl, '/sunny-island.html', []);
    assertTrue(
        $sunnyPageStatus === 200
        && str_contains($sunnyPage, 'data-island-canvas')
        && str_contains($sunnyPage, 'data-codex-dialog')
        && str_contains($sunnyPage, 'data-avatar-select')
        && str_contains($sunnyPage, 'data-avatar-portrait')
        && substr_count($sunnyPage, '<option value="snoop-sticker-') === 9
        && str_contains($sunnyPage, 'sunny-island.js'),
        'Sunny Island serves the playable canvas, Codex, and all nine NEEN avatar styles',
    );

    [$status] = request($baseUrl, '/api/attendance/daily', $anonymousCookies);
    assertTrue($status === 401, 'Anonymous visitors cannot inspect account attendance');
    [$status] = request(
        $baseUrl,
        '/api/attendance/check-in',
        $anonymousCookies,
        [],
        null,
        'POST',
    );
    assertTrue($status === 401, 'Anonymous visitors cannot claim attendance Coins');
    [$status, $attendanceBefore] = request(
        $baseUrl,
        '/api/attendance/daily?date=2000-01-01',
        $cookies,
    );
    $serverAttendanceDate = (new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d');
    assertTrue(
        $status === 200
        && $attendanceBefore['data']['attendance']['today'] === $serverAttendanceDate
        && $attendanceBefore['data']['attendance']['timezone'] === 'Africa/Cairo'
        && $attendanceBefore['data']['attendance']['today_reward'] === 100
        && $attendanceBefore['data']['attendance']['cycle_reward_total'] === 700,
        'Attendance dates and reward values come from the server and use Cairo time',
    );
    [$status] = request(
        $baseUrl,
        '/api/attendance/check-in',
        $cookies,
        ['date' => '2000-01-01', 'user_id' => 999],
        $csrf,
        'POST',
    );
    assertTrue($status === 422, 'Client-supplied dates and user IDs are rejected');
    $database->exec(
        "CREATE TRIGGER test_fail_attendance_reward BEFORE INSERT ON coin_ledger
         WHEN NEW.source = 'earned_reward' AND NEW.reference_type = 'daily_attendance'
         BEGIN SELECT RAISE(ABORT, 'simulated attendance ledger failure'); END",
    );
    [$status] = request(
        $baseUrl,
        '/api/attendance/check-in',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue($status === 500, 'Attendance ledger failure is reported rather than accepted');
    $database->exec('DROP TRIGGER test_fail_attendance_reward');
    $attendanceCount = (int) $database->query('SELECT COUNT(*) FROM daily_attendance')->fetchColumn();
    $attendanceFailureWallet = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $attendanceCount === 0
        && $attendanceFailureWallet[1]['data']['coin_balance'] === 600
        && $attendanceFailureWallet[1]['data']['earned_coin_balance'] === 500,
        'A failed check-in ledger write rolls back attendance and leaves the wallet unchanged',
    );
    $concurrentCheckins = concurrentRequests(
        $baseUrl,
        '/api/attendance/check-in',
        $cookies,
        [],
        $csrf,
    );
    $checkinStatuses = array_column($concurrentCheckins, 0);
    sort($checkinStatuses);
    $createdCheckins = array_values(array_filter(
        $concurrentCheckins,
        static fn (array $result): bool => ($result[1]['data']['created'] ?? false) === true,
    ));
    assertTrue(
        $checkinStatuses === [200, 201]
        && count($createdCheckins) === 1
        && $createdCheckins[0][1]['data']['attendance']['checked_in_today'] === true,
        'Concurrent same-day requests create exactly one credited attendance record: '
            . json_encode($concurrentCheckins, JSON_THROW_ON_ERROR),
    );
    [$status, $attendanceReplay] = request(
        $baseUrl,
        '/api/attendance/check-in',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $attendanceReplay['data']['created'] === false
        && $attendanceReplay['data']['attendance']['checked_in_today'] === true,
        'Repeating attendance on the same Cairo day returns the existing check-in without re-crediting',
    );
    $attendanceLedger = $database->prepare(
        "SELECT a.id AS attendance_id, a.attendance_date, a.cycle_number, a.cycle_day, a.reward_amount,
                a.ledger_entry_id, l.source, l.balance_type, l.amount, l.reference_type, l.reference_id,
                l.idempotency_key
         FROM daily_attendance a
         JOIN coin_ledger l ON l.id = a.ledger_entry_id
         WHERE a.user_id = :user_id",
    );
    $attendanceLedger->execute(['user_id' => $userId]);
    $attendanceEntry = $attendanceLedger->fetch();
    $attendanceLedger->closeCursor();
    assertTrue(
        $attendanceEntry !== false
        && $attendanceEntry['attendance_date'] === $serverAttendanceDate
        && (int) $attendanceEntry['cycle_day'] === 1
        && (int) $attendanceEntry['reward_amount'] === 100
        && $attendanceEntry['source'] === 'earned_reward'
        && $attendanceEntry['balance_type'] === 'earned'
        && (int) $attendanceEntry['amount'] === 100
        && $attendanceEntry['reference_type'] === 'daily_attendance'
        && (int) $attendanceEntry['reference_id'] === (int) $attendanceEntry['attendance_id']
        && $attendanceEntry['idempotency_key'] === 'daily-attendance-' . $serverAttendanceDate,
        'Attendance and ledger entries are linked to the Cairo day, cycle, and unique daily key',
    );
    [$status, $attendanceWallet] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $status === 200
        && $attendanceWallet['data']['coin_balance'] === 700
        && $attendanceWallet['data']['earned_coin_balance'] === 600
        && $attendanceWallet['data']['purchased_coin_balance'] === 100,
        'Daily attendance credits 100 Earned Coins without changing Purchased Coins or game rewards',
    );

    [$status] = request($baseUrl, '/api/homepage-rewards', $anonymousCookies);
    assertTrue($status === 401, 'Anonymous visitors cannot inspect homepage rewards');
    [$status, $homepageRewardBefore] = request($baseUrl, '/api/homepage-rewards', $cookies);
    assertTrue(
        $status === 200
        && $homepageRewardBefore['data']['reward']['configured'] === false
        && $homepageRewardBefore['data']['reward']['can_claim'] === false
        && $homepageRewardBefore['data']['reward']['wallet']['coin_balance'] === 700,
        'Homepage rewards start unavailable until the server configuration is explicitly set',
    );
    $database->prepare(
        "DELETE FROM user_roles
         WHERE user_id = :user_id AND role_id = (SELECT id FROM roles WHERE name = 'super_admin')",
    )->execute(['user_id' => $userId]);
    [$status] = request(
        $baseUrl,
        '/api/admin/homepage-rewards',
        $cookies,
        null,
        null,
        'GET',
    );
    assertTrue($status === 403, 'Ordinary users cannot read administrative homepage reward configuration: HTTP ' . $status);
    [$status] = request(
        $baseUrl,
        '/api/homepage-rewards',
        $cookies,
        ['reward_amount' => 999],
        $csrf,
        'POST',
    );
    assertTrue($status === 422, 'Homepage reward claims reject client-supplied reward amounts');

    $superAdminRoleId = (int) $database->query(
        "SELECT id FROM roles WHERE name = 'super_admin'",
    )->fetchColumn();
    $grantAdmin = $database->prepare(
        'INSERT INTO user_roles (user_id, role_id, granted_at) VALUES (:user_id, :role_id, :granted_at)',
    );
    $grantAdmin->execute([
        'user_id' => $userId,
        'role_id' => $superAdminRoleId,
        'granted_at' => gmdate('Y-m-d H:i:s'),
    ]);
    [$status, $configuredReward] = request(
        $baseUrl,
        '/api/admin/homepage-rewards',
        $cookies,
        ['reward_amount' => '7', 'window_seconds' => '3600', 'is_active' => true],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200
        && $configuredReward['data']['configuration']['reward_amount'] === 7
        && $configuredReward['data']['configuration']['window_seconds'] === 3600
        && $configuredReward['data']['configuration']['is_active'] === true,
        'Authorized administrators configure the server reward and window',
    );

    $database->exec(
        "CREATE TRIGGER test_fail_homepage_reward BEFORE INSERT ON coin_ledger
         WHEN NEW.source = 'earned_reward' AND NEW.reference_type = 'homepage_reward'
         BEGIN SELECT RAISE(ABORT, 'simulated homepage reward ledger failure'); END",
    );
    [$status] = request(
        $baseUrl,
        '/api/homepage-rewards',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    $failedHomepageClaims = (int) $database->query('SELECT COUNT(*) FROM homepage_reward_claims')->fetchColumn();
    $failedHomepageWallet = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $status === 500
        && $failedHomepageClaims === 0
        && $failedHomepageWallet[1]['data']['coin_balance'] === 700,
        'A failed homepage reward ledger write rolls back its claim and wallet credit',
    );
    $database->exec('DROP TRIGGER test_fail_homepage_reward');

    $concurrentHomepageClaims = concurrentRequests(
        $baseUrl,
        '/api/homepage-rewards',
        $cookies,
        [],
        $csrf,
    );
    $homepageClaimStatuses = array_column($concurrentHomepageClaims, 0);
    sort($homepageClaimStatuses);
    $createdHomepageClaims = array_values(array_filter(
        $concurrentHomepageClaims,
        static fn (array $result): bool => ($result[1]['data']['created'] ?? false) === true,
    ));
    assertTrue(
        $homepageClaimStatuses === [200, 201]
        && count($createdHomepageClaims) === 1
        && $createdHomepageClaims[0][1]['data']['amount'] === 7
        && $createdHomepageClaims[0][1]['data']['wallet']['coin_balance'] === 707,
        'Concurrent homepage claims create one earned-credit ledger entry and return the actual wallet balance',
    );
    [$status, $homepageReplay] = request(
        $baseUrl,
        '/api/homepage-rewards',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    $homepageLedgerCount = (int) $database->query(
        "SELECT COUNT(*) FROM coin_ledger WHERE source = 'earned_reward' AND reference_type = 'homepage_reward'",
    )->fetchColumn();
    assertTrue(
        $status === 200
        && $homepageReplay['data']['created'] === false
        && $homepageReplay['data']['wallet']['coin_balance'] === 707
        && $homepageLedgerCount === 1,
        'Repeated homepage reward claims do not credit the wallet twice',
    );

    [$status] = request($baseUrl, '/api/events', $anonymousCookies);
    assertTrue($status === 200, 'Anonymous visitors can browse published community events');
    [$status] = request($baseUrl, '/api/events/suggestions', $anonymousCookies);
    assertTrue($status === 401, 'Anonymous visitors cannot submit or view event suggestions');
    [$status, $suggestionResponse] = request(
        $baseUrl,
        '/api/events/suggestions',
        $cookies,
        ['title' => 'Community puzzle night', 'description' => 'A friendly community event idea.'],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'Authenticated users can submit an event suggestion');
    $suggestionId = $suggestionResponse['data']['suggestion_id'];
    [$status, $mySuggestions] = request($baseUrl, '/api/events/suggestions', $cookies);
    assertTrue(
        $status === 200
        && $mySuggestions['data']['suggestions'][0]['id'] === $suggestionId
        && $mySuggestions['data']['suggestions'][0]['status'] === 'pending',
        'Users can track the status of their own event suggestions',
    );
    [$status, $adminSuggestions] = request($baseUrl, '/api/admin/event-suggestions', $cookies);
    assertTrue(
        $status === 200
        && $adminSuggestions['data']['suggestions'][0]['id'] === $suggestionId,
        'Authorized administrators can review pending event suggestions',
    );
    [$status, $suggestionReview] = request(
        $baseUrl,
        '/api/admin/event-suggestions/' . $suggestionId . '/review',
        $cookies,
        ['decision' => 'accepted', 'note' => 'Approved for planning.'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $suggestionReview['data']['review']['status'] === 'accepted',
        'Administrators can accept event suggestions',
    );

    $eventStart = (new DateTimeImmutable('-30 seconds', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    $eventEnd = (new DateTimeImmutable('+60 seconds', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    [$status, $eventCreated] = request(
        $baseUrl,
        '/api/admin/events',
        $cookies,
        [
            'title' => 'Community choice',
            'description' => 'Choose the next community activity.',
            'starts_at' => $eventStart,
            'ends_at' => $eventEnd,
            'options' => ['Puzzle night', 'Art showcase'],
        ],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201
        && $eventCreated['data']['event']['starts_at'] === $eventStart
        && $eventCreated['data']['event']['ends_at'] === $eventEnd,
        'Administrators can create a community event with poll options and UTC timestamp values',
    );
    $eventId = $eventCreated['data']['event']['id'];
    $firstOptionId = $eventCreated['data']['event']['options'][0]['id'];
    [$status, $eventUpdated] = request(
        $baseUrl,
        '/api/admin/events/' . $eventId,
        $cookies,
        ['title' => 'Community choice edited'],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200
        && $eventUpdated['data']['event']['title'] === 'Community choice edited'
        && $eventUpdated['data']['event']['starts_at'] === $eventStart,
        'Administrators can edit event details while preserving exact UTC dates',
    );
    [$status, $eventPublished] = request(
        $baseUrl,
        '/api/admin/events/' . $eventId,
        $cookies,
        ['status' => 'published'],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200
        && $eventPublished['data']['event']['status'] === 'published',
        'Administrators can publish a community event',
    );
    [$status, $publicEvent] = request($baseUrl, '/api/events/' . $eventId, $cookies);
    assertTrue(
        $status === 200
        && $publicEvent['data']['event']['options'][0]['label'] === 'Puzzle night'
        && $publicEvent['data']['event']['total_votes'] === 0,
        'Published event details include server-defined options and vote totals',
    );
    [$status, $voteResponse] = request(
        $baseUrl,
        '/api/events/' . $eventId . '/votes',
        $cookies,
        ['option_id' => $firstOptionId],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201
        && $voteResponse['data']['event']['total_votes'] === 1
        && $voteResponse['data']['event']['my_vote_option_id'] === $firstOptionId,
        'Authenticated participants can vote and receive server-computed event totals',
    );
    [$status] = request(
        $baseUrl,
        '/api/events/' . $eventId . '/votes',
        $cookies,
        ['option_id' => $eventCreated['data']['event']['options'][1]['id']],
        $csrf,
        'POST',
    );
    assertTrue($status === 409, 'A participant cannot vote twice in the same event');

    $eventEndPast = (new DateTimeImmutable('-1 second', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
    [$status] = request(
        $baseUrl,
        '/api/admin/events/' . $eventId,
        $cookies,
        ['ends_at' => $eventEndPast],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 200, 'Authorized administrators can close voting by updating the event window');
    [$status, $approvedResults] = request(
        $baseUrl,
        '/api/admin/events/' . $eventId . '/results/approve',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $approvedResults['data']['event']['status'] === 'results_published'
        && $approvedResults['data']['event']['results'][0]['vote_count'] === 1,
        'Administrators can approve results computed from stored votes after voting closes',
    );
    [$status, $eventReward] = request(
        $baseUrl,
        '/api/admin/events/' . $eventId . '/rewards',
        $cookies,
        ['user_id' => $userId, 'amount' => 5, 'reason' => 'Participation reward.'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201
        && $eventReward['data']['created'] === true,
        'Administrators can issue a configured event reward after results are approved',
    );
    [$status, $eventRewardReplay] = request(
        $baseUrl,
        '/api/admin/events/' . $eventId . '/rewards',
        $cookies,
        ['user_id' => $userId, 'amount' => 5, 'reason' => 'Participation reward.'],
        $csrf,
        'POST',
    );
    [$statusRewards, $myEventRewards] = request($baseUrl, '/api/events/rewards', $cookies);
    [$statusWallet, $eventWallet] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue(
        $status === 200
        && $eventRewardReplay['data']['created'] === false
        && $statusRewards === 200
        && $myEventRewards['data']['rewards'][0]['reward_amount'] === 5
        && $statusWallet === 200
        && $eventWallet['data']['coin_balance'] === 712,
        'Event rewards are visible to participants, credited through the wallet, and not paid twice',
    );

    [$status, $diamondCatalog] = request($baseUrl, '/api/diamonds/packages', $cookies);
    assertTrue(
        $status === 200 && $diamondCatalog['data']['packages'] === [],
        'The diamond package catalog has no invented prices or quantities',
    );
    [$status] = request($baseUrl, '/api/diamonds/orders', $anonymousCookies);
    assertTrue($status === 401, 'Anonymous visitors cannot submit diamond recharge requests');
    [$status] = request($baseUrl, '/api/diamonds/orders', $cookies, [], $csrf, 'POST');
    assertTrue($status === 422, 'Diamond order endpoints require validated package and Player ID fields');
    $database->prepare(
        "DELETE FROM user_roles
         WHERE user_id = :user_id AND role_id = (SELECT id FROM roles WHERE name = 'super_admin')",
    )->execute(['user_id' => $userId]);
    [$status] = request($baseUrl, '/api/admin/diamonds/packages', $cookies);
    assertTrue($status === 403, 'Ordinary accounts cannot manage diamond package settings');
    $grantAdmin->execute([
        'user_id' => $userId,
        'role_id' => $superAdminRoleId,
        'granted_at' => gmdate('Y-m-d H:i:s'),
    ]);

    [$status, $diamondPackage] = request(
        $baseUrl,
        '/api/admin/diamonds/packages',
        $cookies,
        [
            'name' => 'Test diamond package',
            'diamond_amount' => '50',
            'price' => '2.50',
            'currency_code' => 'JOD',
        ],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201
        && $diamondPackage['data']['package']['is_active'] === false
        && $diamondPackage['data']['package']['diamond_amount'] === 50
        && $diamondPackage['data']['package']['price'] === '2.50'
        && $diamondPackage['data']['package']['currency_code'] === 'JOD',
        'Administrators can create inactive packages with explicitly configured terms',
    );
    $diamondPackageId = $diamondPackage['data']['package']['id'];
    [$status, $updatedJodPackage] = request(
        $baseUrl,
        '/api/admin/diamonds/packages/' . $diamondPackageId,
        $cookies,
        ['price' => '4.50'],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200
        && $updatedJodPackage['data']['package']['price'] === '4.50'
        && $updatedJodPackage['data']['package']['currency_code'] === 'JOD',
        'JOD package prices preserve two decimal places for 2.50 and 4.50 amounts',
    );
    [$status] = request(
        $baseUrl,
        '/api/admin/diamonds/packages/' . $diamondPackageId,
        $cookies,
        ['is_active' => true],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 200, 'Administrators can publish a configured diamond package');
    [$status, $diamondCatalog] = request($baseUrl, '/api/diamonds/packages', $cookies);
    assertTrue(
        $status === 200
        && count($diamondCatalog['data']['packages']) === 1
        && $diamondCatalog['data']['packages'][0]['diamond_amount'] === 50,
        'Users see only active packages and server-owned package data',
    );
    [$status] = request(
        $baseUrl,
        '/api/diamonds/orders',
        $cookies,
        [
            'package_id' => $diamondPackageId,
            'player_id' => '123456789',
            'diamond_amount' => 999999,
            'price' => '0.01',
            'idempotency_key' => 'diamond-tamper-test-01',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 422, 'Diamond order requests cannot override server package quantities or prices');
    [$status] = request(
        $baseUrl,
        '/api/diamonds/orders',
        $cookies,
        [
            'package_id' => $diamondPackageId,
            'player_id' => '123456791',
            'payment_reference' => "Transfer\nInjected",
            'idempotency_key' => 'diamond-reference-invalid-01',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 422, 'Payment references reject control characters before storage');

    [$status, $diamondOrderResponse] = request(
        $baseUrl,
        '/api/diamonds/orders',
        $cookies,
        [
            'package_id' => $diamondPackageId,
            'player_id' => '123456789',
            'payment_reference' => '  InstaPay transfer 987654321  ',
            'idempotency_key' => 'diamond-order-test-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 201
        && $diamondOrderResponse['data']['created'] === true
        && preg_match('/\AND-[A-F0-9]{16}\z/', $diamondOrderResponse['data']['order']['order_number']) === 1
        && $diamondOrderResponse['data']['order']['order_status'] === 'pending'
        && $diamondOrderResponse['data']['order']['payment_status'] === 'pending'
        && $diamondOrderResponse['data']['order']['delivery_status'] === 'not_started'
        && $diamondOrderResponse['data']['order']['price'] === '4.50'
        && $diamondOrderResponse['data']['order']['currency_code'] === 'JOD'
        && $diamondOrderResponse['data']['order']['payment_reference'] === 'InstaPay transfer 987654321',
        'Submitting a valid recharge records a sanitized payment reference with separate payment and delivery states',
    );
    $diamondOrder = $diamondOrderResponse['data']['order'];
    [$status, $diamondOrderReplay] = request(
        $baseUrl,
        '/api/diamonds/orders',
        $cookies,
        [
            'package_id' => $diamondPackageId,
            'player_id' => '123456789',
            'payment_reference' => 'InstaPay transfer 987654321',
            'idempotency_key' => 'diamond-order-test-001',
        ],
        $csrf,
        'POST',
    );
    [$statusDuplicate, $activeDiamondOrder] = request(
        $baseUrl,
        '/api/diamonds/orders',
        $cookies,
        [
            'package_id' => $diamondPackageId,
            'player_id' => '123456789',
            'idempotency_key' => 'diamond-order-retry-002',
        ],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $diamondOrderReplay['data']['created'] === false
        && $statusDuplicate === 200
        && $activeDiamondOrder['data']['order']['order_number'] === $diamondOrder['order_number'],
        'Idempotent retries and duplicate open orders return the existing request instead of creating another',
    );

    $diamondTransition = static function (string $orderId, string $action, string $reason) use (
        $baseUrl,
        &$cookies,
        &$csrf,
    ): array {
        return request(
            $baseUrl,
            '/api/admin/diamonds/orders/' . $orderId . '/actions',
            $cookies,
            ['action' => $action, 'note' => $reason],
            $csrf,
            'POST',
        );
    };
    [$status, $beginPaymentReview] = $diamondTransition((string) $diamondOrder['id'], 'begin_verification', 'Start manual payment verification.');
    [$paidStatus, $markPaid] = request(
        $baseUrl,
        '/api/admin/diamonds/orders/' . $diamondOrder['id'] . '/actions',
        $cookies,
        [
            'action' => 'mark_paid',
            'note' => 'Payment verified in the approved manual workflow.',
        ],
        $csrf,
        'POST',
    );
    [$processingStatus, $beginDelivery] = $diamondTransition((string) $diamondOrder['id'], 'begin_delivery', 'Begin manual fulfillment.');
    [$deliveredStatus, $markDelivered] = $diamondTransition((string) $diamondOrder['id'], 'mark_delivered', 'Manual test delivery completed.');
    [$statusHistory, $diamondHistory] = request(
        $baseUrl,
        '/api/admin/diamonds/orders/' . $diamondOrder['id'],
        $cookies,
    );
    [$statusUserOrders, $userDiamondOrders] = request($baseUrl, '/api/diamonds/orders', $cookies);
    assertTrue(
        $status === 200
        && $beginPaymentReview['data']['transition']['order_status'] === 'payment_verification'
        && $paidStatus === 200
        && $markPaid['data']['transition']['payment_status'] === 'paid'
        && $processingStatus === 200
        && $beginDelivery['data']['transition']['delivery_status'] === 'processing'
        && $deliveredStatus === 200
        && $markDelivered['data']['transition']['order_status'] === 'delivered'
        && $statusHistory === 200
        && count($diamondHistory['data']['order']['activity']) === 5
        && $statusUserOrders === 200
        && $userDiamondOrders['data']['orders'][0]['order_status'] === 'delivered'
        && $userDiamondOrders['data']['orders'][0]['payment_reference'] === 'InstaPay transfer 987654321',
        'Admin and user views track the full payment verification through delivered lifecycle and its actor history',
    );
    [$statusDeliveredRefund] = $diamondTransition((string) $diamondOrder['id'], 'refund', 'Delivered orders cannot be refunded.');
    assertTrue($statusDeliveredRefund === 409, 'Delivered diamond orders cannot be refunded through a status shortcut');
    [$statusSearch, $diamondSearch] = request(
        $baseUrl,
        '/api/admin/diamonds/orders?q=123456789',
        $cookies,
    );
    assertTrue(
        $statusSearch === 200
        && count($diamondSearch['data']['orders']) === 1
        && $diamondSearch['data']['orders'][0]['player_id'] === '123456789'
        && $diamondSearch['data']['orders'][0]['payment_reference'] === 'InstaPay transfer 987654321',
        'Administrators can search recharge requests and review payment references',
    );

    [$statusRejectedOrder, $rejectedDiamondOrder] = request(
        $baseUrl,
        '/api/diamonds/orders',
        $cookies,
        [
            'package_id' => $diamondPackageId,
            'player_id' => '123456790',
            'idempotency_key' => 'diamond-order-test-002',
        ],
        $csrf,
        'POST',
    );
    [$statusRejected] = $diamondTransition(
        (string) $rejectedDiamondOrder['data']['order']['id'],
        'reject_payment',
        'Test payment verification was rejected.',
    );
    assertTrue(
        $statusRejectedOrder === 201
        && $statusRejected === 200,
        'Administrators can reject an unverified payment and record the reason',
    );

    [$statusRefundOrder, $refundableOrder] = request(
        $baseUrl,
        '/api/diamonds/orders',
        $cookies,
        [
            'package_id' => $diamondPackageId,
            'player_id' => '123456791',
            'idempotency_key' => 'diamond-order-test-003',
        ],
        $csrf,
        'POST',
    );
    $refundOrderId = (string) $refundableOrder['data']['order']['id'];
    $diamondTransition($refundOrderId, 'begin_verification', 'Begin refund test verification.');
    $diamondTransition($refundOrderId, 'mark_paid', 'Test payment verified before refund.');
    [$statusRefunded, $refundedOrder] = $diamondTransition($refundOrderId, 'refund', 'Refund recorded in test workflow.');
    assertTrue(
        $statusRefundOrder === 201
        && $statusRefunded === 200
        && $refundedOrder['data']['transition']['order_status'] === 'refunded'
        && $refundedOrder['data']['transition']['payment_status'] === 'refunded',
        'The admin workflow supports an audited refund before delivery',
    );

    unset($database);

    $authenticatedCookies = $cookies;
    $cookies = [];
    [$status, $response] = request($baseUrl, '/api/auth/me', $cookies);
    assertTrue($status === 401, 'Unauthenticated requests cannot access protected user data');
    checkResponse($response, false, 'Unauthenticated request');
    [$status] = request($baseUrl, '/api/wallet', $cookies);
    assertTrue($status === 401, 'Unauthenticated requests cannot access protected wallet data');
    foreach ([
        '/api/users/me',
        '/api/attendance/daily',
        '/api/diamonds/orders',
        '/api/events/suggestions',
        '/api/events/rewards',
    ] as $protectedPath) {
        [$protectedStatus] = request($baseUrl, $protectedPath, $cookies);
        assertTrue($protectedStatus === 401, 'Unauthenticated requests cannot access ' . $protectedPath);
    }
    $cookies = $authenticatedCookies;

    [$status] = request(
        $baseUrl,
        '/api/auth/logout',
        $cookies,
        [],
        'invalid-csrf-token',
        'POST',
    );
    assertTrue($status === 403, 'Logout rejects an invalid CSRF token');
    [$status, $response] = request($baseUrl, '/api/auth/logout', $cookies, [], $csrf, 'POST');
    assertTrue($status === 200 && $response['data']['logged_out'] === true, 'Authenticated logout succeeds');
    [$status] = request($baseUrl, '/api/auth/me', $cookies);
    assertTrue($status === 401, 'Logged-out session is revoked');

    [$status, $response] = request($baseUrl, '/api/auth/csrf', $cookies);
    assertTrue($status === 200, 'Password recovery receives an anonymous CSRF token');
    $csrf = $response['data']['csrf_token'];
    [$status, $response] = request(
        $baseUrl,
        '/api/auth/login',
        $cookies,
        ['email' => 'player@example.com', 'password' => 'Correct-Horse-123'],
        $csrf,
        'POST',
    );
    assertTrue($status === 200, 'User can establish a session before password recovery');
    $csrf = $response['data']['csrf_token'];

    [$status] = request(
        $baseUrl,
        '/api/auth/password/change',
        $cookies,
        ['current_password' => 'Incorrect-Current-Password', 'new_password' => 'Direct-New-Password-456'],
        $csrf,
        'POST',
    );
    assertTrue($status === 401, 'Direct password change rejects an incorrect current password');

    [$status, $response] = request(
        $baseUrl,
        '/api/auth/password/change',
        $cookies,
        ['current_password' => 'Correct-Horse-123', 'new_password' => 'Direct-New-Password-456'],
        $csrf,
        'POST',
    );
    assertTrue($status === 200 && $response['data']['password_changed'] === true,
        'Direct password change succeeds for an authenticated user');

    [$status, $response] = request(
        $baseUrl,
        '/api/auth/password/change',
        $cookies,
        ['current_password' => 'Direct-New-Password-456', 'new_password' => 'Correct-Horse-123'],
        $csrf,
        'POST',
    );
    assertTrue($status === 200 && $response['data']['password_changed'] === true,
        'Password can be restored after direct change testing');

    [$status, $response] = request(
        $baseUrl,
        '/api/auth/password/forgot',
        $cookies,
        ['email' => 'player@example.com'],
        $csrf,
        'POST',
    );
    assertTrue($status === 202, 'Password recovery request is accepted');
    $mailLines = file($mailPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    assertTrue(is_array($mailLines) && count($mailLines) >= 1, 'Password reset email is delivered to the test sink');
    $mail = json_decode($mailLines[count($mailLines) - 1], true, 16, JSON_THROW_ON_ERROR);
    assertTrue(preg_match('/reset_token=([A-Za-z0-9_-]{43})/', $mail['message'], $match) === 1,
        'Password reset email contains a one-time reset link');
    $resetToken = $match[1];
    $database = new PDO('sqlite:' . $databasePath);
    assertTrue(
        $database->query('SELECT token_hash FROM password_reset_tokens')->fetchColumn() === hash('sha256', $resetToken),
        'Only a hash of the password reset token is stored',
    );
    unset($database);

    [$status, $response] = request(
        $baseUrl,
        '/api/auth/password/reset',
        $cookies,
        ['token' => $resetToken, 'password' => 'New-Correct-Password-456'],
        $csrf,
        'POST',
    );
    assertTrue($status === 200 && $response['data']['password_reset'] === true, 'Password reset succeeds');
    [$status] = request($baseUrl, '/api/auth/me', $cookies);
    assertTrue($status === 401, 'Password reset revokes existing sessions');
    [$status, $response] = request($baseUrl, '/api/auth/csrf', $cookies);
    assertTrue($status === 200, 'A fresh anonymous CSRF token is issued after password reset');
    $csrf = $response['data']['csrf_token'];
    [$status] = request(
        $baseUrl,
        '/api/auth/password/reset',
        $cookies,
        ['token' => $resetToken, 'password' => 'Another-Correct-Password-789'],
        $csrf,
        'POST',
    );
    assertTrue($status === 422, 'A reset token cannot be reused');

    [$status] = request(
        $baseUrl,
        '/api/auth/login',
        $cookies,
        ['email' => 'player@example.com', 'password' => 'Correct-Horse-123'],
        $csrf,
        'POST',
    );
    assertTrue($status === 401, 'Old password no longer authenticates after reset');
    [$status, $response] = request(
        $baseUrl,
        '/api/auth/login',
        $cookies,
        ['email' => 'player@example.com', 'password' => 'New-Correct-Password-456'],
        $csrf,
        'POST',
    );
    assertTrue($status === 200, 'New password authenticates after reset');

    [$status, $page, $headers] = requestPage($baseUrl, '/events-admin.php', []);
    assertTrue(
        $status === 302
        && in_array('Location: /account.html?auth=required', $headers, true)
        && !str_contains($page, 'NEEN Admin Dashboard'),
        'Anonymous visitors are redirected before the admin dashboard is sent',
    );
    $anonymousCookies = [];
    [$status] = request($baseUrl, '/api/admin/coin-orders', $anonymousCookies);
    assertTrue($status === 401, 'Anonymous direct requests to administrative APIs are denied');
    [$status, $legacyPage] = requestPage($baseUrl, '/events-admin.html', []);
    assertTrue(
        $status === 200
        && str_contains($legacyPage, 'events-admin.php')
        && !str_contains($legacyPage, 'data-panel')
        && !str_contains($legacyPage, 'Coin Orders'),
        'The legacy HTML address contains only a redirect and no unprotected dashboard',
    );
    $database = new PDO('sqlite:' . $databasePath);
    $statement = $database->prepare(
        "DELETE FROM user_roles
         WHERE user_id = :user_id AND role_id = (SELECT id FROM roles WHERE name = 'super_admin')",
    );
    $statement->execute(['user_id' => $userId]);
    unset($database);

    [$status, $page] = requestPage($baseUrl, '/events-admin.php', $cookies);
    assertTrue(
        $status === 403
        && str_contains($page, 'Access denied')
        && !str_contains($page, 'NEEN Admin Dashboard')
        && !str_contains($page, 'data-panel'),
        'An authenticated user without an administrative permission receives no dashboard markup',
    );
    [$status] = request($baseUrl, '/api/admin/coin-orders', $cookies);
    assertTrue($status === 403, 'A regular user cannot directly access protected Coin order APIs');
    [$status] = request($baseUrl, '/api/admin/audit-logs', $cookies);
    assertTrue($status === 403, 'A regular user cannot directly access protected audit APIs');
    foreach ([
        '/api/admin/homepage-rewards',
        '/api/admin/events',
        '/api/admin/event-suggestions',
        '/api/admin/diamonds/packages',
        '/api/admin/diamonds/orders',
        '/api/admin/users',
        '/api/admin/wallets',
    ] as $adminOnlyPath) {
        [$adminOnlyStatus] = request($baseUrl, $adminOnlyPath, $cookies);
        assertTrue($adminOnlyStatus === 403, 'A regular user cannot access protected admin module ' . $adminOnlyPath);
    }

    $database = new PDO('sqlite:' . $databasePath);
    $statement = $database->prepare(
        "INSERT INTO user_roles (user_id, role_id, granted_at)
         SELECT :user_id, id, :granted_at FROM roles WHERE name = 'super_admin'",
    );
    $statement->execute(['user_id' => $userId, 'granted_at' => gmdate('Y-m-d H:i:s')]);
    unset($database);

    [$status, $response] = request($baseUrl, '/api/auth/csrf', $cookies);
    assertTrue($status === 200, 'Authenticated admin receives a session-bound CSRF token');
    $csrf = $response['data']['csrf_token'];
    [$status, $page] = requestPage($baseUrl, '/events-admin.php', $cookies);
    assertTrue(
        $status === 200
        && str_contains($page, 'NEEN Admin Dashboard')
        && str_contains($page, 'data-panel="dashboard"')
        && str_contains($page, 'data-panel="homepage-rewards"')
        && str_contains($page, 'data-panel="community-events"')
        && str_contains($page, 'data-panel="event-suggestions"')
        && str_contains($page, 'data-panel="event-results"')
        && str_contains($page, 'data-panel="diamond-packages"')
        && str_contains($page, 'data-panel="diamond-orders"')
        && stripos($page, 'neenplayer') !== false,
        'An account with an existing server-assigned admin permission receives integrated Rewards, Events, and Diamonds admin sections',
    );
    [$ownerPageStatus] = requestPage($baseUrl, '/owner-dashboard.php', $cookies);
    [$ownerApiStatus] = request($baseUrl, '/api/owner/dashboard', $cookies);
    assertTrue(
        $ownerPageStatus === 403 && $ownerApiStatus === 403,
        'An administrator without the distinct Owner role cannot access Owner pages or APIs (page '
            . $ownerPageStatus . ', API ' . $ownerApiStatus . ')',
    );
    [$ownerSettingsBeforeStatus] = request($baseUrl, '/api/owner/site-settings', $cookies);
    assertTrue($ownerSettingsBeforeStatus === 403, 'Administrators without the Owner role cannot read Owner site settings');
    [$status, $response] = request($baseUrl, '/api/admin/audit-logs', $cookies);
    assertTrue(
        $status === 200
        && isset($response['data']['entries'])
        && count($response['data']['entries']) > 0,
        'The existing view_audit_logs permission exposes read-only audit records',
    );
    [$status, $adminUsers] = request($baseUrl, '/api/admin/users?q=NeenPlayer', $cookies);
    assertTrue(
        $status === 200
        && count($adminUsers['data']['users']) === 1
        && $adminUsers['data']['users'][0]['username'] === 'neenplayer'
        && in_array('super_admin', $adminUsers['data']['users'][0]['roles'], true)
        && in_array('manage_diamond_orders', $adminUsers['data']['users'][0]['permissions'], true),
        'The permission-scoped admin directory returns matching users, roles, and effective permissions',
    );
    [$status, $adminAccounts] = request($baseUrl, '/api/admin/users?admins_only=1', $cookies);
    [$walletStatus, $wallets] = request($baseUrl, '/api/admin/wallets?q=NeenPlayer', $cookies);
    [$ledgerStatus, $walletLedger] = request($baseUrl, '/api/admin/wallets/' . $userId . '/transactions', $cookies);
    assertTrue(
        $status === 200
        && count($adminAccounts['data']['users']) > 0
        && $walletStatus === 200
        && count($wallets['data']['wallets']) === 1
        && $wallets['data']['wallets'][0]['user_id'] === $userId
        && $ledgerStatus === 200
        && is_array($walletLedger['data']['entries']),
        'Authorized admins can read real account roles and wallet ledger data without balance mutation',
    );
    [$status] = request(
        $baseUrl,
        '/api/admin/coin-orders/1/review',
        $cookies,
        [],
        null,
        'POST',
    );
    assertTrue($status === 403, 'Admin order mutations reject requests without CSRF tokens');
    [$status] = request(
        $baseUrl,
        '/api/admin/coin-orders/1/review',
        $cookies,
        [],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 422,
        'A valid session-bound CSRF token is accepted before the API validates an order review',
    );

    $database = new PDO('sqlite:' . $databasePath);
    $now = gmdate('Y-m-d H:i:s');
    $insertUser = $database->prepare(
        'INSERT INTO users (username, email, password_hash, status, email_verified, created_at, updated_at, last_login_at)
         VALUES (:username, :email, :password_hash, \'active\', 0, :created_at, :updated_at, :last_login_at)',
    );
    $insertUser->execute([
        'username' => 'OwnerTarget',
        'email' => 'ownertarget@example.test',
        'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
        'created_at' => $now,
        'updated_at' => $now,
        'last_login_at' => $now,
    ]);
    $targetUserId = (int) $database->lastInsertId();
    $database->prepare(
        'INSERT INTO user_profiles
            (user_id, full_name, display_name, avatar_url, country, bio, created_at, updated_at)
         VALUES (:user_id, \'Owner Target\', \'Target\', NULL, \'Egypt\', \'Original bio\', :created_at, :updated_at)',
    )->execute(['user_id' => $targetUserId, 'created_at' => $now, 'updated_at' => $now]);
    $database->prepare(
        'INSERT INTO wallets
            (user_id, coin_balance, purchased_coin_balance, earned_coin_balance, diamond_balance, currency_code, updated_at)
         VALUES (:user_id, 0, 0, 0, 0, \'EGP\', :updated_at)',
    )->execute(['user_id' => $targetUserId, 'updated_at' => $now]);
    $targetSessionToken = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $database->prepare(
        'INSERT INTO user_sessions
            (user_id, session_token_hash, csrf_token_hash, expires_at, last_activity_at, created_at)
         VALUES (:user_id, :session_token_hash, :csrf_token_hash, :expires_at, :last_activity_at, :created_at)',
    )->execute([
        'user_id' => $targetUserId,
        'session_token_hash' => hash('sha256', $targetSessionToken),
        'csrf_token_hash' => hash('sha256', bin2hex(random_bytes(32))),
        'expires_at' => gmdate('Y-m-d H:i:s', time() + 1800),
        'last_activity_at' => $now,
        'created_at' => $now,
    ]);
    $database->prepare(
        "INSERT INTO user_roles (user_id, role_id, granted_at)
         SELECT :user_id, id, :granted_at FROM roles WHERE name = 'owner'",
    )->execute(['user_id' => $userId, 'granted_at' => $now]);
    unset($database);

    [$status, $ownerPage] = requestPage($baseUrl, '/owner-dashboard.php', $cookies);
    assertTrue(
        $status === 200
        && str_contains($ownerPage, 'Owner Dashboard')
        && str_contains($ownerPage, 'data-panel="overview"')
        && str_contains($ownerPage, 'data-panel="users"')
        && str_contains($ownerPage, 'data-panel="wallet"')
        && str_contains($ownerPage, 'data-panel="activity"')
        && str_contains($ownerPage, 'data-owner-media-grid'),
        'A separately assigned Owner role receives the dashboard and its integrated Site Media manager',
    );
    [$status, $ownerOverview] = request($baseUrl, '/api/owner/dashboard', $cookies);
    assertTrue(
        $status === 200
        && $ownerOverview['data']['overview']['counts']['users_total'] >= 2
        && is_array($ownerOverview['data']['overview']['series']['users']),
        'Owner overview statistics and chart series come from the connected database',
    );
    [$status, $ownerSiteSettings] = request($baseUrl, '/api/owner/site-settings', $cookies);
    assertTrue(
        $status === 200 && isset($ownerSiteSettings['data']['settings']['tiktok_url']),
        'Owner can read persistent site settings',
    );
    [$status, $ownerGames] = request($baseUrl, '/api/owner/games', $cookies);
    $memoryGame = array_values(array_filter(
        $ownerGames['data']['games'],
        static fn (array $game): bool => $game['slug'] === 'memory-match',
    ))[0] ?? null;
    $sunnyIslandGame = array_values(array_filter(
        $ownerGames['data']['games'],
        static fn (array $game): bool => $game['slug'] === 'sunny_island',
    ))[0] ?? null;
    assertTrue(
        $status === 200 && is_array($memoryGame) && is_array($sunnyIslandGame),
        'Owner can read existing games and the Sunny Island settings record',
    );
    $gameImageFixture = glob($root . '/assets/hero-loot/Memory Match pic/*.webp')[0] ?? null;
    assertTrue(is_string($gameImageFixture) && is_file($gameImageFixture), 'A valid game image fixture is available');
    [$memoryCatalogBeforeStatus, $memoryCatalogBefore] = request($baseUrl, '/api/games/memory-match', $cookies);
    $originalMemoryImage = $memoryCatalogBefore['data']['game']['images'][0] ?? null;
    [$memoryMediaUploadStatus, $memoryMediaUpload] = uploadRequest(
        $baseUrl,
        '/api/owner/site-media/upload',
        $cookies,
        ['setting_key' => 'media_game_memory_match_01'],
        $gameImageFixture,
        'memory-match-image.webp',
        $csrf,
    );
    [$memoryCatalogAfterUploadStatus, $memoryCatalogAfterUpload] = request(
        $baseUrl,
        '/api/games/memory-match',
        $cookies,
    );
    $memoryMediaPath = $memoryMediaUpload['data']['settings']['media_game_memory_match_01']['value'] ?? null;
    $memoryPublicUrl = is_string($memoryMediaPath)
        ? '/' . implode('/', array_map('rawurlencode', explode('/', $memoryMediaPath)))
        : null;
    $memoryUpdatedImage = $memoryCatalogAfterUpload['data']['game']['images'][0] ?? null;
    assertTrue(
        $memoryCatalogBeforeStatus === 200
        && is_array($originalMemoryImage)
        && $memoryMediaUploadStatus === 200
        && is_string($memoryMediaPath)
        && str_starts_with($memoryMediaPath, 'uploads/site/media_game_memory_match_01_')
        && $memoryCatalogAfterUploadStatus === 200
        && is_array($memoryUpdatedImage)
        && $memoryUpdatedImage['src'] === $memoryPublicUrl
        && count($memoryCatalogAfterUpload['data']['game']['images']) === 8
        && $memoryCatalogAfterUpload['data']['game']['pairs_total'] === 8
        && $memoryCatalogAfterUpload['data']['game']['cards_total'] === 16,
        'The existing Site Media upload system replaces a Memory Match image without changing game size',
    );
    [$memoryMediaResetStatus] = request(
        $baseUrl,
        '/api/owner/site-media/reset',
        $cookies,
        ['setting_key' => 'media_game_memory_match_01'],
        $csrf,
        'POST',
    );
    [$memoryCatalogAfterResetStatus, $memoryCatalogAfterReset] = request(
        $baseUrl,
        '/api/games/memory-match',
        $cookies,
    );
    assertTrue(
        $memoryMediaResetStatus === 200
        && $memoryCatalogAfterResetStatus === 200
        && $memoryCatalogAfterReset['data']['game']['images'][0]['src'] === $originalMemoryImage['src'],
        'Resetting a Memory Match media slot restores its original manifest image',
    );

    [$puzzleCatalogBeforeStatus, $puzzleCatalogBefore] = request($baseUrl, '/api/games/picture-jigsaw', $cookies);
    $originalPuzzleImage = $puzzleCatalogBefore['data']['game']['images'][1] ?? null;
    [$puzzleMediaUploadStatus, $puzzleMediaUpload] = uploadRequest(
        $baseUrl,
        '/api/owner/site-media/upload',
        $cookies,
        ['setting_key' => 'media_game_picture_jigsaw_02'],
        $gameImageFixture,
        'picture-jigsaw-image.webp',
        $csrf,
    );
    [$puzzleCatalogAfterUploadStatus, $puzzleCatalogAfterUpload] = request(
        $baseUrl,
        '/api/games/picture-jigsaw',
        $cookies,
    );
    $puzzleMediaPath = $puzzleMediaUpload['data']['settings']['media_game_picture_jigsaw_02']['value'] ?? null;
    $puzzlePublicUrl = is_string($puzzleMediaPath)
        ? '/' . implode('/', array_map('rawurlencode', explode('/', $puzzleMediaPath)))
        : null;
    assertTrue(
        $puzzleCatalogBeforeStatus === 200
        && is_array($originalPuzzleImage)
        && $puzzleMediaUploadStatus === 200
        && is_string($puzzleMediaPath)
        && str_starts_with($puzzleMediaPath, 'uploads/site/media_game_picture_jigsaw_02_')
        && $puzzleCatalogAfterUploadStatus === 200
        && $puzzleCatalogAfterUpload['data']['game']['images'][1]['src'] === $puzzlePublicUrl
        && count($puzzleCatalogAfterUpload['data']['game']['images']) === 3
        && $puzzleCatalogAfterUpload['data']['game']['board_size'] === 3
        && $puzzleCatalogAfterUpload['data']['game']['piece_count'] === 9,
        'The existing Site Media upload system replaces a Picture Jigsaw image without changing its puzzle rules',
    );
    [$puzzleMediaResetStatus] = request(
        $baseUrl,
        '/api/owner/site-media/reset',
        $cookies,
        ['setting_key' => 'media_game_picture_jigsaw_02'],
        $csrf,
        'POST',
    );
    [$puzzleCatalogAfterResetStatus, $puzzleCatalogAfterReset] = request(
        $baseUrl,
        '/api/games/picture-jigsaw',
        $cookies,
    );
    assertTrue(
        $puzzleMediaResetStatus === 200
        && $puzzleCatalogAfterResetStatus === 200
        && $puzzleCatalogAfterReset['data']['game']['images'][1]['src'] === $originalPuzzleImage['src'],
        'Resetting a Picture Jigsaw media slot restores its original manifest image',
    );
    [$sunnyOwnerUpdateStatus] = request(
        $baseUrl,
        '/api/owner/games/' . $sunnyIslandGame['id'],
        $cookies,
        ['free_attempt_limit' => 4, 'reward_amount_coins' => 75],
        $csrf,
        'PATCH',
    );
    [$sunnyOwnerStateStatus, $sunnyOwnerState] = request(
        $baseUrl,
        '/api/games/sunny-island/state',
        $cookies,
    );
    assertTrue(
        $sunnyOwnerUpdateStatus === 200
        && $sunnyOwnerStateStatus === 200
        && $sunnyOwnerState['data']['state']['adventure']['free_attempts_per_day'] === 4
        && $sunnyOwnerState['data']['state']['adventure']['reward_amount_coins'] === 75,
        'Owner-configured Sunny Island attempts and rewards are used by the game runtime',
    );
    [$sunnyOwnerRestoreStatus] = request(
        $baseUrl,
        '/api/owner/games/' . $sunnyIslandGame['id'],
        $cookies,
        [
            'free_attempt_limit' => $sunnyIslandGame['free_attempt_limit'],
            'reward_amount_coins' => $sunnyIslandGame['reward_amount_coins'],
        ],
        $csrf,
        'PATCH',
    );
    assertTrue($sunnyOwnerRestoreStatus === 200, 'Sunny Island owner settings can be restored after integration testing');
    [$status] = request(
        $baseUrl,
        '/api/owner/games/' . $memoryGame['id'],
        $cookies,
        [
            'status' => 'maintenance',
            'free_attempt_limit' => 4,
            'reward_amount_coins' => 77,
        ],
        $csrf,
        'PATCH',
    );
    [$catalogStatus, $gameCatalog] = request($baseUrl, '/api/games/memory-match', $cookies);
    [$blockedGameStatus] = request(
        $baseUrl,
        '/api/games/memory-match/sessions',
        $cookies,
        ['idempotency_key' => 'owner-disabled-game-001'],
        $csrf,
        'POST',
    );
    assertTrue(
        $status === 200
        && $catalogStatus === 200
        && $gameCatalog['data']['game']['status'] === 'maintenance'
        && $gameCatalog['data']['game']['free_attempts_per_day'] === 4
        && $gameCatalog['data']['game']['reward_amount_coins'] === 77
        && $blockedGameStatus === 503,
        'Owner game status, free attempts, and win rewards are enforced by the runtime',
    );
    [$restoreGameStatus] = request(
        $baseUrl,
        '/api/owner/games/' . $memoryGame['id'],
        $cookies,
        [
            'status' => 'active',
            'free_attempt_limit' => $memoryGame['free_attempt_limit'],
            'reward_amount_coins' => $memoryGame['reward_amount_coins'],
        ],
        $csrf,
        'PATCH',
    );
    assertTrue($restoreGameStatus === 200, 'Owner game settings can be restored after the integration check');
    [$publicSettingsStatus, $publicSettings] = request($baseUrl, '/api/site-settings', $cookies);
    assertTrue(
        $publicSettingsStatus === 200
        && $publicSettings['data']['settings']['tiktok_url']['value'] === 'https://www.tiktok.com/@neen_0o',
        'The public site reads persisted settings without exposing administrative metadata',
    );
    [$settingsStatus] = request(
        $baseUrl,
        '/api/owner/site-settings',
        $cookies,
        [
            'tiktok_url' => 'https://www.tiktok.com/@neen_test',
            'site_description' => 'Updated site description for Owner integration testing.',
        ],
        $csrf,
        'PATCH',
    );
    [$updatedPublicSettingsStatus, $updatedPublicSettings] = request($baseUrl, '/api/site-settings', $cookies);
    assertTrue(
        $settingsStatus === 200
        && $updatedPublicSettingsStatus === 200
        && $updatedPublicSettings['data']['settings']['tiktok_url']['value'] === 'https://www.tiktok.com/@neen_test'
        && $updatedPublicSettings['data']['settings']['site_description']['value']
            === 'Updated site description for Owner integration testing.',
        'Owner site settings persist and are returned by the public settings API',
    );
    [$invalidSettingsStatus] = request(
        $baseUrl,
        '/api/owner/site-settings',
        $cookies,
        ['tiktok_url' => 'javascript:alert(1)'],
        $csrf,
        'PATCH',
    );
    assertTrue($invalidSettingsStatus === 422, 'Owner site settings reject non-HTTPS and non-TikTok profile URLs');
    [$restoreSettingsStatus] = request(
        $baseUrl,
        '/api/owner/site-settings',
        $cookies,
        [
            'tiktok_url' => 'https://www.tiktok.com/@neen_0o',
            'site_description' => 'NEEN — Free Fire content creator. Explore videos, character art, and more.',
        ],
        $csrf,
        'PATCH',
    );
    assertTrue($restoreSettingsStatus === 200, 'Owner site settings can be restored after integration tests');
    [$status, $ownerUsers] = request($baseUrl, '/api/owner/users?q=OwnerTarget', $cookies);
    assertTrue(
        $status === 200
        && count($ownerUsers['data']['users']) === 1
        && $ownerUsers['data']['users'][0]['id'] === $targetUserId
        && isset($ownerUsers['data']['pagination']['total']),
        'Owner can search, filter, sort, and paginate the account directory',
    );
    [$status, $ownerDetails] = request($baseUrl, '/api/owner/users/' . $targetUserId, $cookies);
    assertTrue(
        $status === 200
        && $ownerDetails['data']['user']['coin_balance'] === 0
        && $ownerDetails['data']['user']['active_sessions'] === 1
        && count($ownerDetails['data']['user']['recent_transactions']) === 0,
        'Owner account details show the real wallet, session count, and ledger history',
    );
    [$status, $profileUpdate] = request(
        $baseUrl,
        '/api/owner/users/' . $targetUserId . '/profile',
        $cookies,
        ['display_name' => 'Edited Target', 'bio' => 'Updated by the Owner'],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200
        && $profileUpdate['data']['user']['profile']['display_name'] === 'Edited Target',
        'Owner profile edits update only the permitted user profile fields',
    );
    [$status, $roleGrant] = request(
        $baseUrl,
        '/api/owner/users/' . $targetUserId . '/roles',
        $cookies,
        ['role' => 'moderator', 'operation' => 'grant'],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200 && in_array('moderator', $roleGrant['data']['user']['roles'], true),
        'Owner can grant a non-owner administrative role',
    );
    [$status, $roleRevoke] = request(
        $baseUrl,
        '/api/owner/users/' . $targetUserId . '/roles',
        $cookies,
        ['role' => 'moderator', 'operation' => 'revoke'],
        $csrf,
        'PATCH',
    );
    assertTrue(
        $status === 200 && !in_array('moderator', $roleRevoke['data']['user']['roles'], true),
        'Owner can revoke a non-owner administrative role',
    );
    [$status] = request(
        $baseUrl,
        '/api/owner/users/' . $targetUserId . '/roles',
        $cookies,
        ['role' => 'owner', 'operation' => 'grant'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 422, 'Owner APIs never grant or revoke the Owner role');
    [$status] = request(
        $baseUrl,
        '/api/owner/users/' . $userId . '/roles',
        $cookies,
        ['role' => 'moderator', 'operation' => 'grant'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 409, 'Owner cannot promote or demote their own account');
    [$status] = request(
        $baseUrl,
        '/api/owner/users/' . $userId . '/status',
        $cookies,
        ['status' => 'suspended'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 409, 'Owner cannot suspend their own account through the dashboard');
    [$status] = request(
        $baseUrl,
        '/api/owner/users/' . $targetUserId . '/status',
        $cookies,
        ['status' => 'suspended'],
        null,
        'PATCH',
    );
    assertTrue($status === 403, 'Owner status changes require session-bound CSRF protection');
    [$status, $statusChange] = request(
        $baseUrl,
        '/api/owner/users/' . $targetUserId . '/status',
        $cookies,
        ['status' => 'suspended'],
        $csrf,
        'PATCH',
    );
    $database = new PDO('sqlite:' . $databasePath);
    $revokedSession = $database->query(
        'SELECT revoked_at FROM user_sessions WHERE user_id = ' . $targetUserId,
    )->fetchColumn();
    assertTrue(
        $status === 200 && $statusChange['data']['user']['status'] === 'suspended' && $revokedSession !== null,
        'Suspending a user revokes all active sessions and records the status change',
    );
    [$status] = request(
        $baseUrl,
        '/api/owner/users/' . $targetUserId . '/status',
        $cookies,
        ['status' => 'active'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 200, 'Owner can reactivate a suspended account');
    [$status, $adjustment] = request(
        $baseUrl,
        '/api/admin/wallets/' . $targetUserId . '/adjustments',
        $cookies,
        [
            'direction' => 'credit',
            'balance_type' => 'earned',
            'amount' => 25,
            'reason' => 'Owner integration test adjustment',
            'idempotency_key' => 'owner-test-credit-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 201, 'Owner can add Coins only through the existing wallet ledger API');
    [$duplicateAdjustmentStatus, $duplicateAdjustment] = request(
        $baseUrl,
        '/api/admin/wallets/' . $targetUserId . '/adjustments',
        $cookies,
        [
            'direction' => 'credit',
            'balance_type' => 'earned',
            'amount' => 25,
            'reason' => 'Owner integration test adjustment',
            'idempotency_key' => 'owner-test-credit-001',
        ],
        $csrf,
        'POST',
    );
    [$status, $afterAdjustment] = request($baseUrl, '/api/owner/users/' . $targetUserId, $cookies);
    assertTrue(
        $duplicateAdjustmentStatus === 201
        && $duplicateAdjustment['data']['ledger_entry_id'] === $adjustment['data']['ledger_entry_id']
        && $status === 200
        && $afterAdjustment['data']['user']['coin_balance'] === 25
        && $afterAdjustment['data']['user']['earned_coin_balance'] === 25,
        'Repeated idempotent wallet adjustments do not duplicate credits and preserve ledger balances',
    );
    [$status] = request(
        $baseUrl,
        '/api/admin/wallets/' . $targetUserId . '/adjustments',
        $cookies,
        [
            'direction' => 'debit',
            'balance_type' => 'earned',
            'amount' => 26,
            'reason' => 'Insufficient balance test',
            'idempotency_key' => 'owner-test-debit-001',
        ],
        $csrf,
        'POST',
    );
    assertTrue($status === 409, 'Wallet adjustments cannot make the balance negative');
    [$status, $ownerActivity] = request($baseUrl, '/api/owner/activity?q=owner_user', $cookies);
    assertTrue(
        $status === 200
        && count($ownerActivity['data']['entries']) >= 4
        && $ownerActivity['data']['entries'][0]['actor_role'] === 'owner',
        'Owner activity is read-only, searchable, paginated, and records the actor role',
    );
    $database->prepare(
        "INSERT INTO user_roles (user_id, role_id, granted_at)
         SELECT :user_id, id, :granted_at FROM roles WHERE name = 'owner'",
    )->execute(['user_id' => $targetUserId, 'granted_at' => gmdate('Y-m-d H:i:s')]);
    unset($database);
    [$status] = request(
        $baseUrl,
        '/api/owner/users/' . $targetUserId . '/status',
        $cookies,
        ['status' => 'suspended'],
        $csrf,
        'PATCH',
    );
    assertTrue($status === 403, 'One Owner cannot mutate another Owner through the user-management API');

    $attendanceDb = new PDO('sqlite::memory:');
    $attendanceDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $attendanceDb->exec($schema);
    $attendanceUser = static function (PDO $db, string $username): int {
        $now = gmdate('Y-m-d H:i:s');
        $insert = $db->prepare(
            'INSERT INTO users (username, email, password_hash, status, email_verified, created_at, updated_at)
             VALUES (:username, :email, :password_hash, \'active\', 0, :created_at, :updated_at)',
        );
        $insert->execute([
            'username' => $username,
            'email' => strtolower($username) . '@attendance.test',
            'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $userId = (int) $db->lastInsertId();
        $wallet = $db->prepare(
            'INSERT INTO wallets
                (user_id, coin_balance, purchased_coin_balance, earned_coin_balance, diamond_balance, currency_code, updated_at)
             VALUES (:user_id, 0, 0, 0, 0, \'EGP\', :updated_at)',
        );
        $wallet->execute(['user_id' => $userId, 'updated_at' => $now]);
        return $userId;
    };
    $weeklyUserId = $attendanceUser($attendanceDb, 'AttendanceWeekly');
    $simulatedDate = '2026-01-01';
    $weeklyAttendance = new Attendance(
        $attendanceDb,
        static function () use (&$simulatedDate): string {
            return $simulatedDate;
        },
    );
    for ($dayOffset = 0; $dayOffset < 7; $dayOffset++) {
        $simulatedDate = (new DateTimeImmutable('2026-01-01', new DateTimeZone('Africa/Cairo')))
            ->modify('+' . $dayOffset . ' days')
            ->format('Y-m-d');
        $checkin = $weeklyAttendance->checkIn($weeklyUserId);
        assertTrue(
            $checkin['created'] === true
            && $checkin['attendance']['cycle_progress'] === $dayOffset + 1,
            'Attendance advances exactly one cycle day for each consecutive Cairo date',
        );
    }
    $weeklyState = $weeklyAttendance->state($weeklyUserId);
    assertTrue(
        $weeklyState['cycle_progress'] === 7
        && count(array_filter($weeklyState['cycle_days'], static fn (array $day): bool => $day['attended'])) === 7
        && $weeklyState['wallet']['earned_coin_balance'] === 700
        && $weeklyState['wallet']['coin_balance'] === 700,
        'Seven consecutive check-ins credit exactly 700 Earned Coins',
    );
    $simulatedDate = '2026-01-08';
    $nextCycle = $weeklyAttendance->checkIn($weeklyUserId);
    assertTrue(
        $nextCycle['attendance']['cycle_number'] === 2
        && $nextCycle['attendance']['cycle_progress'] === 1
        && $nextCycle['attendance']['wallet']['earned_coin_balance'] === 800,
        'A new cycle starts on the day after a completed seven-day cycle',
    );
    $simulatedDate = '2026-01-10';
    $afterMissedDay = $weeklyAttendance->checkIn($weeklyUserId);
    assertTrue(
        $afterMissedDay['attendance']['cycle_number'] === 3
        && $afterMissedDay['attendance']['cycle_progress'] === 1
        && $afterMissedDay['attendance']['cycle_days'][0]['attended'] === true
        && $afterMissedDay['attendance']['cycle_days'][1]['attended'] === false
        && $afterMissedDay['attendance']['wallet']['earned_coin_balance'] === 900,
        'Missing a Cairo day resets progress to day one but never removes earned Coins',
    );
    $independentUserId = $attendanceUser($attendanceDb, 'AttendanceIndependent');
    $independentDate = '2026-03-01';
    $independentAttendance = new Attendance($attendanceDb, static fn (): string => $independentDate);
    $independentResult = $independentAttendance->checkIn($independentUserId);
    assertTrue(
        $independentResult['created'] === true
        && $independentResult['attendance']['cycle_number'] === 1
        && $independentResult['attendance']['cycle_progress'] === 1
        && $independentResult['attendance']['wallet']['earned_coin_balance'] === 100
        && $weeklyAttendance->state($weeklyUserId)['wallet']['earned_coin_balance'] === 900,
        'Attendance cycles and rewards are isolated between different users',
    );
    $failedAttendanceUserId = $attendanceUser($attendanceDb, 'AttendanceFailed');
    $attendanceDb->exec(
        "CREATE TRIGGER test_fail_attendance BEFORE INSERT ON coin_ledger
         WHEN NEW.source = 'earned_reward' AND NEW.reference_type = 'daily_attendance'
         BEGIN SELECT RAISE(ABORT, 'simulated failure'); END",
    );
    $failedDate = '2026-04-01';
    $failedAttendance = new Attendance($attendanceDb, static fn (): string => $failedDate);
    try {
        $failedAttendance->checkIn($failedAttendanceUserId);
        throw new RuntimeException('Attendance must roll back when its ledger write fails.');
    } catch (PDOException) {
        assertTrue(
            $attendanceDb->query(
                'SELECT COUNT(*) FROM daily_attendance WHERE user_id = ' . $failedAttendanceUserId,
            )->fetchColumn() == 0
            && $attendanceDb->query(
                'SELECT coin_balance FROM wallets WHERE user_id = ' . $failedAttendanceUserId,
            )->fetchColumn() == 0,
            'Failed direct Ledger writes leave no check-in and do not change balances',
        );
    }
    $attendanceDb->exec('DROP TRIGGER test_fail_attendance');

    echo "PASS: account security and Coins ledger; Rewards, Community Events, Diamond Recharge; admin permissions and CSRF; "
        . "Memory Match, Picture Jigsaw, Target Rush, Loot Sequence, Sunny Island, leaderboards, daily rewards, and attendance cycles.\n";
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($temporaryDirectory . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($temporaryDirectory);
    foreach (glob($siteMediaDirectory . '/*') ?: [] as $file) {
        if (!in_array($file, $siteMediaFilesBefore, true) && is_file($file)) {
            unlink($file);
        }
    }
    if (!$siteMediaProtectionFileExisted && is_file($siteMediaProtectionFile)) {
        unlink($siteMediaProtectionFile);
    }
    if (!$siteMediaDirectoryExisted && is_dir($siteMediaDirectory) && glob($siteMediaDirectory . '/*') === []) {
        rmdir($siteMediaDirectory);
    }
    if (!$uploadsDirectoryExisted && is_dir($uploadsDirectory) && glob($uploadsDirectory . '/*') === []) {
        rmdir($uploadsDirectory);
    }
}
