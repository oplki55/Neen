<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/src/ApiException.php';
require_once __DIR__ . '/../api/src/NewsTicker.php';

function tickerAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$databasePath = tempnam(sys_get_temp_dir(), 'neen-news-ticker-');
if ($databasePath === false) {
    throw new RuntimeException('Could not create a temporary News Ticker database.');
}

$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec(file_get_contents(__DIR__ . '/fixtures/account-schema.sqlite.sql') ?: '');
$ticker = new NewsTicker($db);

$db->exec("INSERT INTO users (username, email, password_hash, status, email_verified, created_at, updated_at)
    VALUES ('owner', 'owner@example.com', 'test-hash', 'active', 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
$ownerId = (int) $db->lastInsertId();

$submission = $ticker->submit('SNOOP', 'Welcome to NEEN Gaming Arena!', '127.0.0.1');
tickerAssert($submission['status'] === 'Pending', 'Visitor submission is created with Pending status');

$beforeApproval = $ticker->publicData();
tickerAssert(
    $beforeApproval['messages'] === [],
    'Pending messages are absent from public API data',
);

$pending = $ticker->ownerData();
tickerAssert(count($pending['messages']) === 1, 'Owner data includes the pending submission');

after:
try {
    $ticker->submit('<script>alert(1)</script>', 'Unsafe message', '127.0.0.1');
    tickerAssert(false, 'HTML-like sender names are rejected');
} catch (ApiException $exception) {
    tickerAssert($exception->httpStatus === 422, 'Unsafe sender name is rejected with 422');
}

try {
    $ticker->submit('SNOOP', '<img src=x onerror=alert(1)>', '127.0.0.1');
    tickerAssert(false, 'HTML-like message text is rejected');
} catch (ApiException $exception) {
    tickerAssert($exception->httpStatus === 422, 'Unsafe message text is rejected with 422');
}

$approved = $ticker->setStatus($submission['id'], 'Approved', $ownerId);
tickerAssert(
    $approved['status'] === 'Approved'
        && $approved['display_active'] === true
        && $approved['approved_at'] !== null,
    'Owner approval publishes, activates, and records the approval time',
);

$afterApproval = $ticker->publicData();
tickerAssert(
    count($afterApproval['messages']) === 1
        && $afterApproval['messages'][0]['sender_name'] === 'SNOOP'
        && $afterApproval['messages'][0]['message'] === 'Welcome to NEEN Gaming Arena!',
    'Approved message appears publicly with sender and message',
);

$paused = $ticker->pause($submission['id']);
tickerAssert($paused['status'] === 'Approved' && $paused['display_active'] === false,
    'Approved message can be paused without losing approval');

$afterPause = $ticker->publicData();
tickerAssert($afterPause['messages'] === [], 'Paused approved message is absent publicly');

$resumed = $ticker->resume($submission['id']);
tickerAssert($resumed['status'] === 'Approved' && $resumed['display_active'] === true,
    'Paused approved message can resume');

$edited = $ticker->updateMessage($submission['id'], [
    'sender_name' => 'NEEN',
    'message' => 'Arena update: new challenges are live.',
]);
tickerAssert(
    $edited['sender_name'] === 'NEEN'
        && $edited['message'] === 'Arena update: new challenges are live.',
    'Owner can edit sender name and message before or after approval',
);

$ownerMessage = $ticker->publishOwnerMessage('NEEN', 'Owner announcement: Arena schedule updated.', $ownerId);
tickerAssert(
    $ownerMessage['status'] === 'Approved'
        && $ownerMessage['display_active'] === true
        && $ownerMessage['source'] === 'Owner',
    'Owner message publishes directly and is marked as Owner',
);
$afterOwnerPublish = $ticker->publicData();
tickerAssert(
    count($afterOwnerPublish['messages']) >= 1
        && $ownerMessage['id'] === (int) $afterOwnerPublish['messages'][0]['id'],
    'Owner message appears publicly immediately',
);

$rejectedSubmission = $ticker->submit('SPAMMER', 'Rejected for community rules', '127.0.0.1');
$rejected = $ticker->setStatus($rejectedSubmission['id'], 'Rejected', $ownerId);
tickerAssert($rejected['status'] === 'Rejected', 'Owner can reject a pending message');
tickerAssert(
    !in_array($rejectedSubmission['id'], array_column($ticker->publicData()['messages'], 'id'), true),
    'Rejected messages do not appear in public data',
);

$deleted = $ticker->setStatus($rejectedSubmission['id'], 'Deleted', null);
tickerAssert($deleted['status'] === 'Deleted', 'Owner can delete a message with Deleted state');

foreach (array_keys(NewsTicker::THEMES) as $themeId) {
    $theme = NewsTicker::THEMES[$themeId];
    $settings = $ticker->updateSettings([
        'enabled' => true,
        'theme' => $themeId,
        'speed_seconds' => 55,
        'colors' => [
            'background' => $theme['background'],
            'text' => $theme['text'],
            'sender' => $theme['sender'],
            'separator' => $theme['separator'],
            'glow' => $theme['glow'],
        ],
    ]);
    tickerAssert($settings['theme'] === $themeId, "Theme {$themeId} can be saved independently");
}

$bulkMessage = $db->prepare(
    "INSERT INTO news_ticker_messages
        (sender_name, message, status, display_active, source, approved_at, created_at, updated_at)
     VALUES (:sender_name, :message, 'Approved', 1, 'Owner', :approved_at, :created_at, :updated_at)",
);
for ($index = 0; $index < 51; $index++) {
    $now = gmdate('Y-m-d H:i:s');
    $bulkMessage->execute([
        'sender_name' => 'Owner ' . $index,
        'message' => 'Approved public message ' . $index,
        'approved_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
}
tickerAssert(count($ticker->publicData()['messages']) === 50, 'Public ticker data is capped at 50 approved messages');

@unlink($databasePath);
