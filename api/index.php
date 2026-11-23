<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

require_once __DIR__ . '/src/ApiException.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/EmailDelivery.php';
require_once __DIR__ . '/src/RateLimiter.php';
require_once __DIR__ . '/src/CoinsLedger.php';
require_once __DIR__ . '/src/Attendance.php';
require_once __DIR__ . '/src/HomepageRewards.php';
require_once __DIR__ . '/src/CommunityEvents.php';
require_once __DIR__ . '/src/DiamondRecharge.php';
require_once __DIR__ . '/src/DiamondProviders.php';
require_once __DIR__ . '/src/GameRewards.php';
require_once __DIR__ . '/src/ChallengeGame.php';
require_once __DIR__ . '/src/MemoryMatch.php';
require_once __DIR__ . '/src/PictureJigsaw.php';
require_once __DIR__ . '/src/TargetRush.php';
require_once __DIR__ . '/src/LootSequence.php';
require_once __DIR__ . '/src/SunnyIsland.php';
require_once __DIR__ . '/src/OwnerDashboard.php';
require_once __DIR__ . '/src/NewsTicker.php';
require_once __DIR__ . '/src/Api.php';

try {
    $appKey = getenv('NEEN_APP_KEY');
    if ($appKey === false || strlen($appKey) < 32) {
        throw new RuntimeException('NEEN_APP_KEY must contain at least 32 characters.');
    }

    $appUrl = getenv('NEEN_APP_URL');
    $api = new Api(
        Database::connect(),
        $appKey,
        $appUrl === false || $appUrl === '' ? null : $appUrl,
    );
    $api->dispatch();
} catch (Throwable $exception) {
    error_log('NEEN API bootstrap failure: ' . get_class($exception));
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode([
        'success' => false,
        'data' => null,
        'meta' => [
            'request_id' => bin2hex(random_bytes(16)),
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        ],
        'errors' => [[
            'code' => 'SERVICE_UNAVAILABLE',
            'message' => 'The account service is not configured or available.',
        ]],
    ], JSON_UNESCAPED_SLASHES);
}
