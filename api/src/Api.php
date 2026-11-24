<?php
declare(strict_types=1);

final class Api
{
    private readonly RateLimiter $rateLimiter;
    private readonly string $requestId;
    private readonly string $appEnvironment;
    private array $body = [];

    public function __construct(
        private readonly PDO $db,
        private readonly string $appKey,
        private readonly ?string $appUrl,
    ) {
        $this->rateLimiter = new RateLimiter($db, $appKey);
        $this->requestId = self::uuid();
        $this->appEnvironment = getenv('APP_ENV') ?: 'production';
    }

    public function dispatch(): never
    {
        try {
            $this->requireHttps();
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (!is_string($path) || !str_starts_with($path, '/api')) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Endpoint not found.', 404);
            }
            $path = rtrim($path, '/') ?: '/';
            $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

            if (in_array($method, ['POST', 'PATCH', 'PUT', 'DELETE'], true) && $path !== '/api/owner/site-media/upload') {
                $this->body = $this->readJsonBody();
            }

            if ($path === '/api/site-settings') {
                $this->expectMethod($method, 'GET');
                $this->send(
                    true,
                    ['settings' => (new OwnerDashboard($this->db))->siteSettings()],
                    [],
                    200,
                    ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=30'],
                );
            }

            if ($path === '/api/news-ticker') {
                $this->expectMethod($method, 'GET');
                $this->send(
                    true,
                    (new NewsTicker($this->db))->publicData(),
                    [],
                    200,
                    ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=30'],
                );
            }

            if ($path === '/api/news-ticker/messages') {
                $this->expectMethod($method, 'POST');
                $this->requireCsrf();
                $this->requireAllowedFields(['sender_name', 'message']);
                $senderName = $this->body['sender_name'] ?? null;
                $message = $this->body['message'] ?? null;
                if (!is_string($senderName) || !is_string($message)) {
                    throw new ApiException('VALIDATION_ERROR', 'Sender name and message are required.', 422);
                }
                $ip = $this->clientIp() ?? 'unknown';
                $this->rateLimiter->consume('news-ticker:submit:ip:' . $ip, 5, 3600);
                $ticker = new NewsTicker($this->db);
                $result = $ticker->submit($senderName, $message, $ip);
                $this->audit(null, 'news_ticker_submitted', 'news_ticker_message', $result['id'], [
                    'status' => 'Pending',
                ]);
                $this->send(true, ['submission' => $result], [], 201);
            }

            if ($path === '/api/owner/news-ticker') {
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $ticker = new NewsTicker($this->db);
                if ($method === 'GET') {
                    $this->send(true, $ticker->ownerData());
                }
                $this->expectMethod($method, 'PATCH');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['enabled', 'theme', 'speed_seconds', 'colors']);
                $settings = $this->transaction(fn (): array => $ticker->updateSettings($this->body));
                $this->audit($auth['user_id'], 'news_ticker_settings_saved', 'news_ticker_settings', 1, [
                    'theme' => $settings['theme'],
                    'enabled' => $settings['enabled'],
                    'speed_seconds' => $settings['speed_seconds'],
                ]);
                $this->send(true, ['settings' => $settings]);
            }

            if ($path === '/api/owner/news-ticker/messages/publish') {
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['sender_name', 'message']);
                $senderName = $this->body['sender_name'] ?? null;
                $message = $this->body['message'] ?? null;
                if (!is_string($senderName) || !is_string($message)) {
                    throw new ApiException('VALIDATION_ERROR', 'Sender name and message are required.', 422);
                }
                $ticker = new NewsTicker($this->db);
                $published = $this->transaction(fn (): array => $ticker->publishOwnerMessage(
                    $senderName,
                    $message,
                    $auth['user_id'],
                ));
                $this->audit($auth['user_id'], 'news_ticker_owner_message_published', 'news_ticker_message', $published['id']);
                $this->send(true, ['message' => $published], [], 201);
            }

            if (preg_match('#\A/api/owner/news-ticker/messages/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->expectMethod($method, 'PATCH');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['sender_name', 'message', 'display_active']);
                $ticker = new NewsTicker($this->db);
                $message = $this->transaction(fn (): array => $ticker->updateMessage((int) $match[1], $this->body));
                $this->audit($auth['user_id'], 'news_ticker_message_updated', 'news_ticker_message', $message['id']);
                $this->send(true, ['message' => $message]);
            }

            if (preg_match('#\A/api/owner/news-ticker/messages/([1-9][0-9]*)/(approve|reject|delete|pause|resume)\z#', $path, $match) === 1) {
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->requireAllowedFields([]);
                $messageId = (int) $match[1];
                $action = $match[2];
                $ticker = new NewsTicker($this->db);
                $message = match ($action) {
                    'approve' => $ticker->setStatus($messageId, 'Approved', $auth['user_id']),
                    'reject' => $ticker->setStatus($messageId, 'Rejected', $auth['user_id']),
                    'delete' => $ticker->setStatus($messageId, 'Deleted', $auth['user_id']),
                    'pause' => $ticker->pause($messageId),
                    'resume' => $ticker->resume($messageId),
                };
                $this->audit($auth['user_id'], 'news_ticker_message_' . $action, 'news_ticker_message', $messageId);
                $this->send(true, ['message' => $message]);
            }

            if ($path === '/api/owner/dashboard') {
                $this->expectMethod($method, 'GET');
                $this->requireOwner($this->requireAuth()['user_id']);
                $this->send(true, ['overview' => (new OwnerDashboard($this->db))->overview()]);
            }

            if ($path === '/api/owner/games') {
                $this->expectMethod($method, 'GET');
                $this->requireOwner($this->requireAuth()['user_id']);
                $this->send(true, ['games' => (new OwnerDashboard($this->db))->games()]);
            }

            if ($path === '/api/owner/site-settings') {
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                if ($method === 'GET') {
                    $this->send(true, ['settings' => (new OwnerDashboard($this->db))->siteSettings()]);
                }
                $this->expectMethod($method, 'PATCH');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['tiktok_url', 'site_description']);
                $settings = $this->transaction(fn (): array => (new OwnerDashboard($this->db))->updateSiteSettings(
                    $auth['user_id'],
                    $this->body,
                ));
                $this->send(true, ['settings' => $settings]);
            }

            if ($path === '/api/owner/site-media/upload') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->requireCsrf($auth);
                $settingKey = is_string($_POST['setting_key'] ?? null) ? trim($_POST['setting_key']) : '';
                if ($settingKey === '' || !isset($_FILES['image']) || !is_array($_FILES['image'])) {
                    throw new ApiException('VALIDATION_ERROR', 'Setting key and image file are required.', 422);
                }
                $settings = (new OwnerDashboard($this->db))->uploadSiteMedia(
                    $auth['user_id'],
                    $settingKey,
                    $_FILES['image'],
                    dirname(__DIR__, 2),
                );
                $this->send(true, ['settings' => $settings]);
            }

            if ($path === '/api/owner/site-media/reset') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['setting_key']);
                $settingKey = $this->requiredString('setting_key', 5, 80);
                $settings = (new OwnerDashboard($this->db))->resetSiteMedia(
                    $auth['user_id'],
                    trim($settingKey),
                    dirname(__DIR__, 2),
                );
                $this->send(true, ['settings' => $settings]);
            }

            if (preg_match('#\A/api/owner/games/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'PATCH');
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->requireCsrf($auth);
                $this->requireAllowedFields([
                    'name',
                    'description',
                    'status',
                    'free_attempt_limit',
                    'reward_amount_coins',
                ]);
                $game = $this->transaction(fn (): array => (new OwnerDashboard($this->db))->updateGame(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->body,
                ));
                $this->send(true, ['game' => $game]);
            }

            if ($path === '/api/owner/users') {
                $this->expectMethod($method, 'GET');
                $this->requireOwner($this->requireAuth()['user_id']);
                $search = $_GET['q'] ?? '';
                $status = $_GET['status'] ?? null;
                $sort = $_GET['sort'] ?? 'newest';
                $page = $_GET['page'] ?? '1';
                if (
                    !is_string($search)
                    || strlen($search) > 100
                    || ($status !== null && (!is_string($status)
                        || !in_array($status, ['active', 'suspended', 'deleted', 'pending_verification'], true)))
                    || !is_string($sort)
                    || !in_array($sort, ['newest', 'username', 'last_login', 'coins'], true)
                    || !is_string($page)
                    || !preg_match('/\A[1-9][0-9]{0,5}\z/', $page)
                ) {
                    throw new ApiException('VALIDATION_ERROR', 'Owner user-directory filters are invalid.', 422);
                }
                $this->send(true, (new OwnerDashboard($this->db))->users(
                    trim($search),
                    $status,
                    $sort,
                    (int) $page,
                    25,
                ));
            }

            if (preg_match('#\A/api/owner/users/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $this->requireOwner($this->requireAuth()['user_id']);
                $this->send(true, ['user' => (new OwnerDashboard($this->db))->userDetails((int) $match[1])]);
            }

            if (preg_match('#\A/api/owner/users/([1-9][0-9]*)/status\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'PATCH');
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['status']);
                $status = $this->requiredString('status', 1, 24);
                $result = $this->transaction(fn (): array => (new OwnerDashboard($this->db))->updateStatus(
                    $auth['user_id'],
                    (int) $match[1],
                    $status,
                ));
                $this->send(true, ['user' => $result]);
            }

            if (preg_match('#\A/api/owner/users/([1-9][0-9]*)/roles\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'PATCH');
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['role', 'operation']);
                $role = $this->requiredString('role', 1, 60);
                $operation = $this->requiredString('operation', 1, 10);
                $result = $this->transaction(fn (): array => (new OwnerDashboard($this->db))->updateRole(
                    $auth['user_id'],
                    (int) $match[1],
                    $role,
                    $operation,
                ));
                $this->send(true, ['user' => $result]);
            }

            if (preg_match('#\A/api/owner/users/([1-9][0-9]*)/profile\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'PATCH');
                $auth = $this->requireAuth();
                $this->requireOwner($auth['user_id']);
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['full_name', 'display_name', 'country', 'bio']);
                $result = $this->transaction(fn (): array => (new OwnerDashboard($this->db))->updateProfile(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->body,
                ));
                $this->send(true, ['user' => $result]);
            }

            if ($path === '/api/owner/activity') {
                $this->expectMethod($method, 'GET');
                $this->requireOwner($this->requireAuth()['user_id']);
                $search = $_GET['q'] ?? '';
                $page = $_GET['page'] ?? '1';
                if (
                    !is_string($search)
                    || strlen($search) > 100
                    || !is_string($page)
                    || !preg_match('/\A[1-9][0-9]{0,5}\z/', $page)
                ) {
                    throw new ApiException('VALIDATION_ERROR', 'Owner activity filters are invalid.', 422);
                }
                $this->send(true, (new OwnerDashboard($this->db))->activity((int) $page, 50, trim($search)));
            }

            if ($path === '/api/games/picture-jigsaw') {
                $this->expectMethod($method, 'GET');
                $this->send(true, ['game' => $this->pictureJigsaw()->catalog()]);
            }

            if ($path === '/api/games/picture-jigsaw/sessions/current') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $jigsaw = $this->pictureJigsaw();
                $this->send(true, [
                    'session' => $jigsaw->current($auth['user_id']),
                    'attempts_remaining_today' => $jigsaw->attemptsRemainingToday($auth['user_id']),
                ]);
            }

            if ($path === '/api/games/picture-jigsaw/sessions') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['image_id', 'idempotency_key']);
                $result = $this->pictureJigsaw()->start(
                    $auth['user_id'],
                    $this->requiredString('image_id', 1, 60),
                    $this->idempotencyKey($this->body['idempotency_key'] ?? null),
                );
                $this->send(true, $result, [], $result['created'] ? 201 : 200);
            }

            if (preg_match('#\A/api/games/picture-jigsaw/sessions/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->send(true, [
                    'session' => $this->pictureJigsaw()->getState($auth['user_id'], (int) $match[1]),
                ]);
            }

            if (preg_match('#\A/api/games/picture-jigsaw/sessions/([1-9][0-9]*)/swap\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['from_index', 'to_index']);
                $this->send(true, $this->pictureJigsaw()->swap(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->nonNegativeInteger($this->body['from_index'] ?? null, 'from_index'),
                    $this->nonNegativeInteger($this->body['to_index'] ?? null, 'to_index'),
                ));
            }

            if (preg_match('#\A/api/games/picture-jigsaw/sessions/([1-9][0-9]*)/abandon\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields([]);
                $this->pictureJigsaw()->abandon($auth['user_id'], (int) $match[1]);
                $this->send(true, ['abandoned' => true]);
            }

            if ($path === '/api/games/memory-match') {
                $this->expectMethod($method, 'GET');
                $this->send(true, ['game' => $this->memoryMatch()->catalog()]);
            }

            if ($path === '/api/games/memory-match/sessions/current') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $memoryMatch = $this->memoryMatch();
                $this->send(true, [
                    'session' => $memoryMatch->current($auth['user_id']),
                    'attempts_remaining_today' => $memoryMatch->attemptsRemainingToday($auth['user_id']),
                ]);
            }

            if ($path === '/api/games/memory-match/sessions') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['idempotency_key']);
                $result = $this->memoryMatch()->start(
                    $auth['user_id'],
                    $this->idempotencyKey($this->body['idempotency_key'] ?? null),
                );
                $this->send(true, $result, [], $result['created'] ? 201 : 200);
            }

            if (preg_match('#\A/api/games/memory-match/sessions/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->send(true, ['session' => $this->memoryMatch()->getState($auth['user_id'], (int) $match[1])]);
            }

            if (preg_match('#\A/api/games/memory-match/sessions/([1-9][0-9]*)/flip\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['card_index']);
                $this->send(true, $this->memoryMatch()->flip(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->nonNegativeInteger($this->body['card_index'] ?? null, 'card_index'),
                ));
            }

            if (preg_match('#\A/api/games/memory-match/sessions/([1-9][0-9]*)/hide-mismatch\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields([]);
                $result = $this->memoryMatch()->hideMismatch($auth['user_id'], (int) $match[1]);
                if (($result['expired'] ?? false) === true) {
                    throw new ApiException('GAME_SESSION_EXPIRED', 'This game session expired after inactivity.', 410);
                }
                $this->send(true, ['session' => $result['session']]);
            }

            if ($path === '/api/games/vault-cracker') {
                $this->expectMethod($method, 'GET');
                $this->send(true, ['game' => $this->targetRush()->catalog()]);
            }

            if ($path === '/api/games/vault-cracker/sessions/current') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $game = $this->targetRush();
                $this->send(true, [
                    'session' => $game->current($auth['user_id']),
                    'attempts_remaining_today' => $game->attemptsRemainingToday($auth['user_id']),
                ]);
            }

            if ($path === '/api/games/target-rush') {
                $this->expectMethod($method, 'GET');
                $this->send(true, ['game' => $this->targetRush()->catalog()]);
            }

            if ($path === '/api/games/target-rush/sessions/current') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $game = $this->targetRush();
                $this->send(true, [
                    'session' => $game->current($auth['user_id']),
                    'attempts_remaining_today' => $game->attemptsRemainingToday($auth['user_id']),
                ]);
            }

            if ($path === '/api/games/target-rush/sessions') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['idempotency_key']);
                $result = $this->targetRush()->start(
                    $auth['user_id'],
                    $this->idempotencyKey($this->body['idempotency_key'] ?? null),
                );
                $this->send(true, $result, [], $result['created'] ? 201 : 200);
            }

            if (preg_match('#\A/api/games/target-rush/sessions/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->send(true, ['session' => $this->targetRush()->getState($auth['user_id'], (int) $match[1])]);
            }

            if (preg_match('#\A/api/games/target-rush/sessions/([1-9][0-9]*)/hit\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['token']);
                $this->send(true, $this->targetRush()->hit(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->body['token'] ?? null,
                ));
            }

            if (preg_match('#\A/api/games/target-rush/sessions/([1-9][0-9]*)/finish\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields([]);
                $this->send(true, $this->targetRush()->finishRound(
                    $auth['user_id'],
                    (int) $match[1],
                ));
            }

            if ($path === '/api/games/loot-sequence') {
                $this->expectMethod($method, 'GET');
                $this->send(true, ['game' => $this->lootSequence()->catalog()]);
            }

            if ($path === '/api/games/loot-sequence/sessions/current') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $game = $this->lootSequence();
                $this->send(true, [
                    'session' => $game->current($auth['user_id']),
                    'attempts_remaining_today' => $game->attemptsRemainingToday($auth['user_id']),
                ]);
            }

            if ($path === '/api/games/loot-sequence/sessions') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['idempotency_key']);
                $result = $this->lootSequence()->start(
                    $auth['user_id'],
                    $this->idempotencyKey($this->body['idempotency_key'] ?? null),
                );
                $this->send(true, $result, [], $result['created'] ? 201 : 200);
            }

            if (preg_match('#\A/api/games/loot-sequence/sessions/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->send(true, ['session' => $this->lootSequence()->getState($auth['user_id'], (int) $match[1])]);
            }

            if (preg_match('#\A/api/games/loot-sequence/sessions/([1-9][0-9]*)/submit\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['pattern']);
                $this->send(true, $this->lootSequence()->submitPattern(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->body['pattern'] ?? null,
                ));
            }

            if ($path === '/api/games/sunny-island/state') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->send(true, ['state' => $this->sunnyIsland()->state($auth['user_id'])]);
            }

            if ($path === '/api/games/sunny-island/shop') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->send(true, $this->sunnyIslandShop()->state($auth['user_id']));
            }

            if ($path === '/api/games/sunny-island/leaderboard') {
                $this->expectMethod($method, 'GET');
                $this->send(
                    true,
                    $this->sunnyIslandShop()->leaderboard(),
                    [],
                    200,
                    ['Cache-Control' => 'public, max-age=60, stale-while-revalidate=30'],
                );
            }

            if ($path === '/api/games/sunny-island/shop/buy') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['item_key']);
                $itemKey = $this->requiredString('item_key', 3, 80);
                $idempotencyKey = 'island-shop-' . hash('sha256', $auth['user_id'] . ':' . $itemKey);
                $this->send(true, $this->sunnyIslandShop()->buy(
                    $auth['user_id'],
                    $itemKey,
                    $idempotencyKey,
                ));
            }

            if ($path === '/api/games/sunny-island/loadout') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['skin', 'weapon', 'pet', 'trail']);
                $this->send(true, $this->sunnyIslandShop()->saveLoadout(
                    $auth['user_id'],
                    $this->body['skin'] ?? null,
                    $this->body['weapon'] ?? null,
                    $this->body['pet'] ?? null,
                    $this->body['trail'] ?? null,
                ));
            }

            if ($path === '/api/games/sunny-island/start') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['idempotency_key']);
                $result = $this->sunnyIsland()->start(
                    $auth['user_id'],
                    $this->idempotencyKey($this->body['idempotency_key'] ?? null),
                );
                $this->send(true, $result, [], $result['created'] ? 201 : 200);
            }

            if ($path === '/api/games/sunny-island/progress') {
                $this->expectMethod($method, 'POST');
                throw new ApiException(
                    'SUNNY_PROGRESS_DEPRECATED',
                    'Use the server-authoritative Sunny Island action endpoint.',
                    410,
                );
            }

            if ($path === '/api/games/sunny-island/action') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields([
                    'session_id',
                    'request_key',
                    'action_nonce',
                    'action',
                    'target_id',
                ]);
                $sessionId = $this->positiveInteger($this->body['session_id'] ?? null, 'session_id');
                $requestKey = $this->idempotencyKey($this->body['request_key'] ?? null);
                $actionNonce = $this->requiredString('action_nonce', 43, 43);
                $action = $this->requiredString('action', 3, 32);
                $targetId = $this->requiredString('target_id', 1, 80);
                $this->send(true, $this->sunnyIsland()->applyAction(
                    $auth['user_id'],
                    $sessionId,
                    $requestKey,
                    $actionNonce,
                    $action,
                    $targetId,
                ));
            }

            if ($path === '/api/games/sunny-island/complete') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields([
                    'session_id',
                    'request_key',
                    'completion_nonce',
                ]);
                $sessionId = $this->positiveInteger($this->body['session_id'] ?? null, 'session_id');
                $requestKey = $this->idempotencyKey($this->body['request_key'] ?? null);
                $completionNonce = $this->requiredString('completion_nonce', 43, 43);
                $this->send(true, $this->sunnyIsland()->completeAdventure(
                    $auth['user_id'],
                    $sessionId,
                    $requestKey,
                    $completionNonce,
                ));
            }

            if ($path === '/api/wallet/transactions') {
                $this->expectMethod($method, 'GET');
                $this->walletTransactions($this->requireAuth());
            }

            if ($path === '/api/homepage-rewards') {
                $auth = $this->requireAuth();
                if ($method === 'GET') {
                    $this->send(true, ['reward' => $this->homepageRewards()->state($auth['user_id'])]);
                }
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->requireAllowedFields([]);
                $result = $this->homepageRewards()->claim($auth['user_id']);
                $this->send(true, $result, [], $result['created'] ? 201 : 200);
            }

            if ($path === '/api/events') {
                $this->expectMethod($method, 'GET');
                $auth = $this->findAuth();
                $this->send(true, ['events' => $this->communityEvents()->publicEvents($auth['user_id'] ?? null)]);
            }

            if ($path === '/api/events/leaderboard') {
                $this->expectMethod($method, 'GET');
                $this->send(true, ['leaderboards' => $this->communityEvents()->publicLeaderboards()]);
            }

            if ($path === '/api/events/suggestions') {
                $auth = $this->requireAuth();
                if ($method === 'GET') {
                    $this->send(true, ['suggestions' => $this->communityEvents()->mySuggestions($auth['user_id'])]);
                }
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['title', 'description']);
                $suggestionId = $this->communityEvents()->submitSuggestion(
                    $auth['user_id'],
                    $this->requiredString('title', 1, 150),
                    $this->requiredString('description', 1, 4000),
                );
                $this->send(true, ['suggestion_id' => $suggestionId, 'status' => 'pending'], [], 201);
            }

            if ($path === '/api/events/rewards') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->send(true, ['rewards' => $this->communityEvents()->myRewards($auth['user_id'])]);
            }

            if (preg_match('#\A/api/events/([1-9][0-9]*)/votes\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['option_id']);
                $event = $this->communityEvents()->castVote(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->positiveInteger($this->body['option_id'] ?? null, 'option_id'),
                );
                $this->send(true, ['event' => $event], [], 201);
            }

            if (preg_match('#\A/api/events/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $auth = $this->findAuth();
                $this->send(true, [
                    'event' => $this->communityEvents()->eventDetails((int) $match[1], $auth['user_id'] ?? null),
                ]);
            }

            if ($path === '/api/admin/users') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'view_admin_users');
                $search = $_GET['q'] ?? null;
                $adminsOnly = $_GET['admins_only'] ?? null;
                if (
                    ($search !== null && (!is_string($search) || strlen($search) > 100))
                    || ($adminsOnly !== null && $adminsOnly !== '1')
                ) {
                    throw new ApiException('VALIDATION_ERROR', 'User directory filter is invalid.', 422);
                }
                $filters = [];
                $parameters = [];
                if ($search !== null && trim($search) !== '') {
                    $filters[] = '(u.username LIKE :username OR CAST(u.id AS CHAR) = :user_id)';
                    $parameters['username'] = '%' . trim($search) . '%';
                    $parameters['user_id'] = trim($search);
                }
                if ($adminsOnly === '1') {
                    $filters[] = "EXISTS (
                        SELECT 1 FROM user_roles ur
                        JOIN roles r ON r.id = ur.role_id
                        WHERE ur.user_id = u.id AND r.name <> 'user'
                    )";
                }
                $where = $filters === [] ? '' : ' WHERE ' . implode(' AND ', $filters);
                $statement = $this->db->prepare(
                    'SELECT u.id, u.username, u.status, u.created_at
                     FROM users u' . $where . ' ORDER BY u.id DESC LIMIT 100',
                );
                $statement->execute($parameters);
                $users = $statement->fetchAll();
                $rolesQuery = $this->db->prepare(
                    'SELECT DISTINCT r.name FROM user_roles ur
                     JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :user_id ORDER BY r.name',
                );
                $permissionsQuery = $this->db->prepare(
                    'SELECT DISTINCT p.name FROM user_roles ur
                     JOIN role_permissions rp ON rp.role_id = ur.role_id
                     JOIN permissions p ON p.id = rp.permission_id
                     WHERE ur.user_id = :user_id ORDER BY p.name',
                );
                foreach ($users as &$user) {
                    $user['id'] = (int) $user['id'];
                    $rolesQuery->execute(['user_id' => $user['id']]);
                    $user['roles'] = array_column($rolesQuery->fetchAll(), 'name');
                    $permissionsQuery->execute(['user_id' => $user['id']]);
                    $user['permissions'] = array_column($permissionsQuery->fetchAll(), 'name');
                }
                unset($user);
                $this->send(true, ['users' => $users]);
            }

            if ($path === '/api/admin/wallets') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'view_all_wallets');
                $search = $_GET['q'] ?? null;
                if ($search !== null && (!is_string($search) || strlen($search) > 100)) {
                    throw new ApiException('VALIDATION_ERROR', 'Wallet search filter is invalid.', 422);
                }
                $where = '';
                $parameters = [];
                if ($search !== null && trim($search) !== '') {
                    $where = ' WHERE u.username LIKE :username OR CAST(u.id AS CHAR) = :user_id';
                    $parameters = [
                        'username' => '%' . trim($search) . '%',
                        'user_id' => trim($search),
                    ];
                }
                $statement = $this->db->prepare(
                    'SELECT w.user_id, u.username, w.coin_balance, w.purchased_coin_balance,
                            w.earned_coin_balance, w.diamond_balance, w.currency_code, w.updated_at
                     FROM wallets w JOIN users u ON u.id = w.user_id' . $where . '
                     ORDER BY w.user_id DESC LIMIT 100',
                );
                $statement->execute($parameters);
                $wallets = $statement->fetchAll();
                foreach ($wallets as &$wallet) {
                    foreach (['user_id', 'coin_balance', 'purchased_coin_balance', 'earned_coin_balance', 'diamond_balance'] as $field) {
                        $wallet[$field] = (int) $wallet[$field];
                    }
                }
                unset($wallet);
                $this->send(true, ['wallets' => $wallets]);
            }

            if (preg_match('#\A/api/admin/wallets/([1-9][0-9]*)/transactions\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'view_all_wallets');
                $statement = $this->db->prepare(
                    'SELECT id, operation_type, source, amount, balance_type, balance_before, balance_after,
                            total_balance_before, total_balance_after, status, reference_type, reference_id, created_at
                     FROM coin_ledger WHERE user_id = :user_id ORDER BY id DESC LIMIT 100',
                );
                $statement->execute(['user_id' => (int) $match[1]]);
                $entries = $statement->fetchAll();
                foreach ($entries as &$entry) {
                    foreach (['id', 'amount', 'balance_before', 'balance_after', 'total_balance_before', 'total_balance_after'] as $field) {
                        $entry[$field] = (int) $entry[$field];
                    }
                    $entry['reference_id'] = $entry['reference_id'] === null ? null : (int) $entry['reference_id'];
                }
                unset($entry);
                $this->send(true, ['entries' => $entries]);
            }

            if ($path === '/api/admin/events') {
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_community_events');
                if ($method === 'GET') {
                    $this->send(true, ['events' => $this->communityEvents()->adminEvents()]);
                }
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['title', 'description', 'starts_at', 'ends_at', 'options']);
                $event = $this->communityEvents()->createEvent(
                    $auth['user_id'],
                    $this->requiredString('title', 1, 150),
                    $this->requiredString('description', 1, 4000),
                    $this->requiredString('starts_at', 20, 20),
                    $this->requiredString('ends_at', 20, 20),
                    is_array($this->body['options'] ?? null) ? $this->body['options'] : [],
                );
                $this->audit($auth['user_id'], 'community_event_created', 'community_events', $event['id']);
                $this->send(true, ['event' => $event], [], 201);
            }

            if (preg_match('#\A/api/admin/events/([1-9][0-9]*)/results/approve\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_community_events');
                $this->requireCsrf($auth);
                $this->requireAllowedFields([]);
                $event = $this->communityEvents()->approveResults($auth['user_id'], (int) $match[1]);
                $this->audit($auth['user_id'], 'community_event_results_approved', 'community_events', (int) $match[1]);
                $this->send(true, ['event' => $event]);
            }

            if (preg_match('#\A/api/admin/events/([1-9][0-9]*)/rewards\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_event_rewards');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['user_id', 'amount', 'reason']);
                $result = $this->communityEvents()->awardEventReward(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->positiveInteger($this->body['user_id'] ?? null, 'user_id'),
                    $this->positiveInteger($this->body['amount'] ?? null, 'amount'),
                    $this->requiredString('reason', 3, 1000),
                );
                if ($result['created']) {
                    $this->audit($auth['user_id'], 'community_event_reward_issued', 'community_event_rewards', $result['reward_id'], [
                        'event_id' => (int) $match[1],
                        'user_id' => $this->body['user_id'],
                        'amount' => $this->body['amount'],
                    ]);
                }
                $this->send(true, $result, [], $result['created'] ? 201 : 200);
            }

            if (preg_match('#\A/api/admin/events/([1-9][0-9]*)/activity\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_community_events');
                $this->send(true, ['entries' => $this->communityEvents()->activity((int) $match[1])]);
            }

            if (preg_match('#\A/api/admin/events/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'PATCH');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_community_events');
                $this->requireCsrf($auth);
                $event = $this->communityEvents()->updateEvent($auth['user_id'], (int) $match[1], $this->body);
                $this->audit($auth['user_id'], 'community_event_updated', 'community_events', (int) $match[1], [
                    'fields' => array_keys($this->body),
                ]);
                $this->send(true, ['event' => $event]);
            }

            if ($path === '/api/admin/event-suggestions') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'review_event_suggestions');
                $this->send(true, ['suggestions' => $this->communityEvents()->suggestions(true)]);
            }

            if (preg_match('#\A/api/admin/event-suggestions/([1-9][0-9]*)/review\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'review_event_suggestions');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['decision', 'note']);
                $decision = $this->body['decision'] ?? null;
                if (!is_string($decision)) {
                    throw new ApiException('VALIDATION_ERROR', 'A suggestion decision is required.', 422);
                }
                $note = $this->optionalString($this->body['note'] ?? null, 1, 1000, 'note') ?? '';
                $review = $this->communityEvents()->reviewSuggestion(
                    $auth['user_id'],
                    (int) $match[1],
                    $decision,
                    $note,
                );
                $this->audit($auth['user_id'], 'community_event_suggestion_' . $decision, 'community_event_suggestions', (int) $match[1]);
                $this->send(true, ['review' => $review]);
            }

            if ($path === '/api/diamonds/packages') {
                $this->expectMethod($method, 'GET');
                $this->send(true, ['packages' => $this->diamondRecharge()->activePackages()]);
            }

            if ($path === '/api/diamonds/orders') {
                $auth = $this->requireAuth();
                if ($method === 'GET') {
                    $this->send(true, ['orders' => $this->diamondRecharge()->userOrders($auth['user_id'])]);
                }
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['package_id', 'player_id', 'idempotency_key', 'payment_reference']);
                $result = $this->diamondRecharge()->createOrder(
                    $auth['user_id'],
                    $this->positiveInteger($this->body['package_id'] ?? null, 'package_id'),
                    $this->requiredString('player_id', 6, 20),
                    $this->idempotencyKey($this->body['idempotency_key'] ?? null),
                    $this->optionalString($this->body['payment_reference'] ?? null, 1, 150, 'payment_reference'),
                );
                $this->send(true, $result, [], $result['created'] ? 201 : 200);
            }

            if ($path === '/api/admin/diamonds/packages') {
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_diamond_packages');
                if ($method === 'GET') {
                    $this->send(true, ['packages' => $this->diamondRecharge()->adminPackages()]);
                }
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['name', 'diamond_amount', 'price', 'currency_code']);
                $package = $this->diamondRecharge()->createPackage(
                    $auth['user_id'],
                    $this->requiredString('name', 1, 120),
                    $this->positiveInteger($this->body['diamond_amount'] ?? null, 'diamond_amount'),
                    $this->validatedPrice($this->body['price'] ?? null),
                    $this->currencyCode($this->body['currency_code'] ?? null),
                );
                $this->audit($auth['user_id'], 'diamond_package_created', 'diamond_packages', $package['id']);
                $this->send(true, ['package' => $package], [], 201);
            }

            if (preg_match('#\A/api/admin/diamonds/packages/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'PATCH');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_diamond_packages');
                $this->requireCsrf($auth);
                $package = $this->diamondRecharge()->updatePackage(
                    $auth['user_id'],
                    (int) $match[1],
                    $this->body,
                );
                $this->audit($auth['user_id'], 'diamond_package_updated', 'diamond_packages', (int) $match[1]);
                $this->send(true, ['package' => $package]);
            }

            if ($path === '/api/admin/diamonds/orders') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_diamond_orders');
                $search = $_GET['q'] ?? null;
                $statusFilter = $_GET['status'] ?? null;
                if (
                    ($search !== null && (!is_string($search) || strlen($search) > 150))
                    || ($statusFilter !== null && !is_string($statusFilter))
                ) {
                    throw new ApiException('VALIDATION_ERROR', 'Diamond order search filter is invalid.', 422);
                }
                $this->send(true, [
                    'orders' => $this->diamondRecharge()->adminOrders($search, $statusFilter),
                ]);
            }

            if (preg_match('#\A/api/admin/diamonds/orders/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_diamond_orders');
                $this->send(true, ['order' => $this->diamondRecharge()->orderHistory((int) $match[1])]);
            }

            if (preg_match('#\A/api/admin/diamonds/orders/([1-9][0-9]*)/actions\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_diamond_orders');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['action', 'note', 'payment_reference']);
                $action = $this->body['action'] ?? null;
                if (!is_string($action)) {
                    throw new ApiException('VALIDATION_ERROR', 'A diamond order action is required.', 422);
                }
                $paymentReference = $this->optionalString(
                    $this->body['payment_reference'] ?? null,
                    1,
                    150,
                    'payment_reference',
                );
                $result = $this->transaction(function () use ($auth, $match, $action, $paymentReference): array {
                    $result = $this->diamondRecharge()->performAdminAction(
                        $auth['user_id'],
                        (int) $match[1],
                        $action,
                        $this->requiredString('note', 3, 1000),
                        $paymentReference,
                    );
                    $this->audit($auth['user_id'], 'diamond_order_' . $action, 'diamond_recharge_orders', (int) $match[1], [
                        'order_number' => $result['order_number'],
                        'order_status' => $result['order_status'],
                    ]);
                    return $result;
                });
                $this->send(true, ['transition' => $result]);
            }

            if ($path === '/api/coins/packages') {
                $this->expectMethod($method, 'GET');
                $this->publicCoinPackages();
            }

            if ($path === '/api/coins/payment-methods') {
                $this->expectMethod($method, 'GET');
                $this->publicCoinPaymentMethods();
            }

            if ($path === '/api/coins/orders') {
                $auth = $this->requireAuth();
                if ($method === 'GET') {
                    $this->userCoinOrders($auth['user_id']);
                }
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->createCoinOrder($auth['user_id']);
            }

            if (preg_match('#\A/api/coins/orders/([1-9][0-9]*)/evidence\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->submitCoinOrderEvidence($auth['user_id'], (int) $match[1]);
            }

            if ($path === '/api/admin/coin-packages') {
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_coin_packages');
                if ($method === 'GET') {
                    $this->adminCoinPackages();
                }
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->createCoinPackage($auth['user_id']);
            }

            if (preg_match('#\A/api/admin/coin-packages/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'PATCH');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_coin_packages');
                $this->requireCsrf($auth);
                $this->updateCoinPackage($auth['user_id'], (int) $match[1]);
            }

            if ($path === '/api/admin/coin-payment-methods') {
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_coin_payment_methods');
                if ($method === 'GET') {
                    $this->adminCoinPaymentMethods();
                }
                $this->expectMethod($method, 'POST');
                $this->requireCsrf($auth);
                $this->createCoinPaymentMethod($auth['user_id']);
            }

            if (preg_match('#\A/api/admin/coin-payment-methods/([1-9][0-9]*)\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'PATCH');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_coin_payment_methods');
                $this->requireCsrf($auth);
                $this->updateCoinPaymentMethod($auth['user_id'], (int) $match[1]);
            }

            if ($path === '/api/admin/coin-orders') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'review_coin_orders');
                $this->adminCoinOrders();
            }

            if ($path === '/api/admin/audit-logs') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'view_audit_logs');
                $this->adminAuditLogs();
            }

            if ($path === '/api/admin/homepage-rewards') {
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'manage_homepage_rewards');
                if ($method === 'GET') {
                    $this->send(true, ['configuration' => $this->homepageRewards()->configuration()]);
                }
                $this->expectMethod($method, 'PATCH');
                $this->requireCsrf($auth);
                $this->requireAllowedFields(['reward_amount', 'window_seconds', 'is_active']);
                if (count($this->body) !== 3) {
                    throw new ApiException('VALIDATION_ERROR', 'All homepage reward settings are required.', 422);
                }
                $rewardAmount = $this->positiveInteger($this->body['reward_amount'] ?? null, 'reward_amount');
                $windowSeconds = $this->positiveInteger($this->body['window_seconds'] ?? null, 'window_seconds');
                $isActive = $this->booleanValue($this->body['is_active'] ?? null, 'is_active');
                $configuration = $this->transaction(function () use (
                    $auth,
                    $rewardAmount,
                    $windowSeconds,
                    $isActive,
                ): array {
                    $configuration = $this->homepageRewards()->configure(
                        $auth['user_id'],
                        $rewardAmount,
                        $windowSeconds,
                        $isActive,
                    );
                    $this->audit($auth['user_id'], 'homepage_reward_configured', 'homepage_reward_config', 1, [
                        'reward_amount' => $rewardAmount,
                        'window_seconds' => $windowSeconds,
                        'is_active' => $isActive,
                    ]);
                    return $configuration;
                });
                $this->send(true, ['configuration' => $configuration]);
            }

            if (preg_match('#\A/api/admin/coin-orders/([1-9][0-9]*)/review\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'review_coin_orders');
                $this->requireCsrf($auth);
                $this->reviewCoinOrder($auth['user_id'], (int) $match[1]);
            }

            if (preg_match('#\A/api/admin/wallets/([1-9][0-9]*)/adjustments\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'adjust_wallet');
                $this->requireCsrf($auth);
                $this->adjustWallet($auth['user_id'], (int) $match[1]);
            }

            if (preg_match('#\A/api/admin/coin-ledger/([1-9][0-9]*)/reverse\z#', $path, $match) === 1) {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'adjust_wallet');
                $this->requireCsrf($auth);
                $this->reverseCoinLedgerEntry($auth['user_id'], (int) $match[1]);
            }

            if ($path === '/api/auth/csrf') {
                $this->expectMethod($method, 'GET');
                $this->csrfToken();
            }

            if ($path === '/api/auth/register') {
                $this->expectMethod($method, 'POST');
                $this->register();
            }

            if ($path === '/api/auth/email/verify') {
                $this->expectMethod($method, 'POST');
                $this->verifyEmail();
            }

            if ($path === '/api/auth/email/resend') {
                $this->expectMethod($method, 'POST');
                $this->resendEmailVerification();
            }

            if ($path === '/api/auth/login') {
                $this->expectMethod($method, 'POST');
                $this->login();
            }

            if ($path === '/api/auth/logout') {
                $this->expectMethod($method, 'POST');
                $this->logout();
            }

            if ($path === '/api/auth/me') {
                $this->expectMethod($method, 'GET');
                $this->send(true, ['user' => $this->userSummary($this->requireAuth()['user_id'])]);
            }

            if ($path === '/api/attendance/daily') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->send(true, ['attendance' => $this->attendance()->state($auth['user_id'])]);
            }

            if ($path === '/api/attendance/check-in') {
                $this->expectMethod($method, 'POST');
                $auth = $this->requireAuth();
                $this->requireCsrf($auth);
                $this->requireAllowedFields([]);
                $result = $this->attendance()->checkIn($auth['user_id']);
                $this->send(
                    true,
                    $result,
                    [],
                    $result['created'] ? 201 : 200,
                );
            }

            if ($path === '/api/auth/password/forgot') {
                $this->expectMethod($method, 'POST');
                $this->requestPasswordReset();
            }

            if ($path === '/api/auth/password/reset') {
                $this->expectMethod($method, 'POST');
                $this->resetPassword();
            }

            if ($path === '/api/auth/password/change') {
                $this->expectMethod($method, 'POST');
                $this->changePassword();
            }

            if ($path === '/api/users/me') {
                $auth = $this->requireAuth();
                if ($method === 'GET') {
                    $this->send(true, $this->profileData($auth['user_id']));
                }
                if (!in_array($method, ['PATCH', 'POST'], true)) {
                    $this->expectMethod($method, 'PATCH');
                }
                $this->requireCsrf($auth);
                $this->updateProfile($auth['user_id']);
            }

            if ($path === '/api/wallet') {
                $this->expectMethod($method, 'GET');
                $auth = $this->requireAuth();
                $this->requirePermission($auth['user_id'], 'view_wallet');
                $statement = $this->db->prepare(
                    'SELECT id, user_id, coin_balance, purchased_coin_balance, earned_coin_balance,
                            diamond_balance, currency_code, updated_at
                     FROM wallets WHERE user_id = :user_id',
                );
                $statement->execute(['user_id' => $auth['user_id']]);
                $wallet = $statement->fetch();
                if ($wallet === false) {
                    throw new ApiException('RESOURCE_NOT_FOUND', 'Wallet not found.', 404);
                }
                $wallet['id'] = (int) $wallet['id'];
                $wallet['user_id'] = (int) $wallet['user_id'];
                $wallet['coin_balance'] = (int) $wallet['coin_balance'];
                $wallet['purchased_coin_balance'] = (int) $wallet['purchased_coin_balance'];
                $wallet['earned_coin_balance'] = (int) $wallet['earned_coin_balance'];
                $wallet['diamond_balance'] = (int) $wallet['diamond_balance'];
                $wallet['total_coin_balance'] = $wallet['coin_balance'];
                $this->send(true, $wallet);
            }

            throw new ApiException('RESOURCE_NOT_FOUND', 'Endpoint not found.', 404);
        } catch (ApiException $exception) {
            $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if (
                is_string($path)
                && str_starts_with($path, '/api/games/sunny-island/')
                && str_starts_with($exception->errorCode, 'SUNNY_')
            ) {
                $auth = $this->findAuth();
                $this->audit(
                    $auth['user_id'] ?? null,
                    'sunny_action_rejected',
                    'sunny_island_request',
                    null,
                    ['code' => $exception->errorCode, 'message' => $exception->getMessage()],
                );
            }
            $this->send(
                false,
                null,
                [['code' => $exception->errorCode, 'message' => $exception->getMessage()]],
                $exception->httpStatus,
                $exception->headers,
            );
        } catch (Throwable $exception) {
            error_log(sprintf(
                'NEEN API error [request_id=%s] %s: %s in %s:%d',
                $this->requestId,
                get_class($exception),
                $exception->getMessage(),
                $exception->getFile(),
                $exception->getLine(),
            ));
            $this->send(
                false,
                null,
                [['code' => 'INTERNAL_ERROR', 'message' => 'An unexpected error occurred.']],
                500,
            );
        }
    }

    private function register(): never
    {
        $this->requireCsrf();
        $this->requireAllowedFields(['username', 'email', 'password', 'display_name']);
        $this->rateLimiter->consume(
            'registration:ip:' . ($this->clientIp() ?? 'unknown'),
            5,
            3600,
        );
        $username = $this->requiredString('username', 3, 50);
        if (!preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{2,49}\z/', $username)) {
            throw new ApiException(
                'VALIDATION_ERROR',
                'Username must be 3 to 50 characters and use letters, numbers, dots, underscores, or hyphens.',
                422,
            );
        }
        $username = strtolower($username);

        $email = $this->normalizedEmail($this->requiredString('email', 3, 254));
        $password = $this->validatedPassword($this->body['password'] ?? null);
        $displayName = $this->optionalString($this->body['display_name'] ?? $username, 1, 80, 'display_name');
        $now = $this->now();
        $verificationToken = self::randomToken();

        [$userId, $walletId] = $this->transaction(function () use (
            $username,
            $email,
            $password,
            $displayName,
            $now,
            $verificationToken,
        ): array {
            try {
                $statement = $this->db->prepare(
                    "INSERT INTO users (username, email, password_hash, status, email_verified, created_at, updated_at)
                     VALUES (:username, :email, :password_hash, 'pending_verification', 0, :created_at, :updated_at)",
                );
                $statement->execute([
                    'username' => $username,
                    'email' => $email,
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (PDOException $exception) {
                if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                    throw new ApiException(
                        'DUPLICATE_REQUEST',
                        'An account with that username or email already exists.',
                        409,
                    );
                }
                throw $exception;
            }

            $userId = (int) $this->db->lastInsertId();
            $statement = $this->db->prepare(
                'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
                 VALUES (:user_id, :token_hash, :expires_at, :created_at)',
            );
            $statement->execute([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $verificationToken),
                'expires_at' => (new DateTimeImmutable($now, new DateTimeZone('UTC')))
                    ->modify('+24 hours')->format('Y-m-d H:i:s'),
                'created_at' => $now,
            ]);
            $statement = $this->db->prepare(
                'INSERT INTO user_profiles (user_id, display_name, created_at, updated_at)
                 VALUES (:user_id, :display_name, :created_at, :updated_at)',
            );
            $statement->execute([
                'user_id' => $userId,
                'display_name' => $displayName,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $statement = $this->db->prepare(
                "INSERT INTO user_roles (user_id, role_id, granted_at)
                 SELECT :user_id, id, :granted_at FROM roles WHERE name = 'user'",
            );
            $statement->execute(['user_id' => $userId, 'granted_at' => $now]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Default user role is missing from the database.');
            }

            $statement = $this->db->prepare(
                'INSERT INTO wallets (user_id, coin_balance, diamond_balance, currency_code, updated_at)
                 VALUES (:user_id, 0, 0, \'EGP\', :updated_at)',
            );
            $statement->execute(['user_id' => $userId, 'updated_at' => $now]);
            $walletId = (int) $this->db->lastInsertId();

            $this->audit($userId, 'user_registered', 'users', $userId, [
                'wallet_created' => true,
                'initial_coin_balance' => 0,
            ]);

            return [$userId, $walletId];
        });

        $verificationEmailSent = false;
        try {
            $this->sendEmailVerification($email, $verificationToken);
            $verificationEmailSent = true;
        } catch (Throwable $exception) {
            error_log(sprintf(
                'NEEN verification email failed [request_id=%s] %s',
                $this->requestId,
                get_class($exception),
            ));
        }

        $this->send(true, [
            'user_id' => $userId,
            'wallet_created' => true,
            'wallet_id' => $walletId,
            'email_verification_required' => true,
            'verification_email_sent' => $verificationEmailSent,
        ], [], 201);
    }

    private function verifyEmail(): never
    {
        $this->requireCsrf();
        $this->requireAllowedFields(['token']);
        $token = $this->body['token'] ?? null;
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
            throw new ApiException('VALIDATION_ERROR', 'A valid email verification token is required.', 422);
        }

        $now = $this->now();
        $this->transaction(function () use ($token, $now): void {
            $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $statement = $this->db->prepare(
                'SELECT id, user_id FROM email_verification_tokens
                 WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now' . $lock,
            );
            $statement->execute(['token_hash' => hash('sha256', $token), 'now' => $now]);
            $verification = $statement->fetch();
            if ($verification === false) {
                throw new ApiException('VALIDATION_ERROR', 'The verification link is invalid or expired.', 422);
            }

            $statement = $this->db->prepare(
                "UPDATE users SET status = 'active', email_verified = 1, updated_at = :updated_at
                 WHERE id = :user_id AND status IN ('pending_verification', 'active')",
            );
            $statement->execute(['updated_at' => $now, 'user_id' => $verification['user_id']]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException('VALIDATION_ERROR', 'The verification link is invalid or expired.', 422);
            }
            $statement = $this->db->prepare(
                'UPDATE email_verification_tokens SET used_at = :used_at WHERE id = :id AND used_at IS NULL',
            );
            $statement->execute(['used_at' => $now, 'id' => $verification['id']]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException('VALIDATION_ERROR', 'The verification link is invalid or expired.', 422);
            }
            $this->audit((int) $verification['user_id'], 'email_verified', 'users', (int) $verification['user_id']);
        });

        $this->send(true, ['email_verified' => true]);
    }

    private function resendEmailVerification(): never
    {
        $this->requireCsrf();
        $this->requireAllowedFields(['email', 'identifier']);
        $identifierField = array_key_exists('identifier', $this->body) ? 'identifier' : 'email';
        $identifier = $this->requiredString($identifierField, 3, 254);

        if (filter_var(trim($identifier), FILTER_VALIDATE_EMAIL) !== false) {
            $email = $this->normalizedEmail($identifier);
            $lookupField = 'email';
        } elseif (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{2,49}\z/', trim($identifier))) {
            $email = null;
            $identifier = strtolower(trim($identifier));
            $lookupField = 'username';
        } else {
            throw new ApiException('VALIDATION_ERROR', 'Enter a valid email address or username.', 422);
        }

        $ip = $this->clientIp() ?? 'unknown';
        $this->rateLimiter->consume('email-verification:ip:' . $ip, 5, 3600);
        $this->rateLimiter->consume('email-verification:identifier:' . $ip . ':' . $identifier, 3, 3600);

        $statement = $this->db->prepare(
            "SELECT id, email FROM users
             WHERE {$lookupField} = :identifier
               AND email_verified = 0
               AND status IN ('pending_verification', 'active')
             LIMIT 1",
        );
        $statement->execute(['identifier' => $lookupField === 'email' ? $email : $identifier]);
        $pendingUser = $statement->fetch();
        $userId = $pendingUser === false ? false : (int) $pendingUser['id'];
        $email = $pendingUser === false ? null : (string) $pendingUser['email'];

        if ($userId !== false) {
            $token = self::randomToken();
            $now = $this->now();
            $this->transaction(function () use ($userId, $token, $now): void {
                $statement = $this->db->prepare(
                    'UPDATE email_verification_tokens SET used_at = :used_at
                     WHERE user_id = :user_id AND used_at IS NULL',
                );
                $statement->execute(['used_at' => $now, 'user_id' => $userId]);

                $statement = $this->db->prepare(
                    'INSERT INTO email_verification_tokens (user_id, token_hash, expires_at, created_at)
                     VALUES (:user_id, :token_hash, :expires_at, :created_at)',
                );
                $statement->execute([
                    'user_id' => $userId,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => (new DateTimeImmutable($now, new DateTimeZone('UTC')))
                        ->modify('+24 hours')->format('Y-m-d H:i:s'),
                    'created_at' => $now,
                ]);
            });

            try {
                if ($email !== null) {
                    $this->sendEmailVerification($email, $token);
                }
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'NEEN verification resend failed [request_id=%s] %s',
                    $this->requestId,
                    get_class($exception),
                ));
            }
        }

        $this->send(true, [
            'message' => 'If a pending account uses that email, a verification link will be sent.',
        ], [], 202);
    }

    private function login(): never
    {
        $this->requireCsrf();
        $this->requireAllowedFields(['identifier', 'email', 'password', 'remember_me']);
        if (array_key_exists('identifier', $this->body) && array_key_exists('email', $this->body)) {
            throw new ApiException('VALIDATION_ERROR', 'Provide one email address or username.', 422);
        }
        $identifier = $this->requiredString(
            array_key_exists('identifier', $this->body) ? 'identifier' : 'email',
            3,
            254,
        );
        if (filter_var(trim($identifier), FILTER_VALIDATE_EMAIL) !== false) {
            $identifier = $this->normalizedEmail($identifier);
            $lookupField = 'email';
        } elseif (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{2,49}\z/', trim($identifier))) {
            $identifier = strtolower(trim($identifier));
            $lookupField = 'username';
        } else {
            throw new ApiException('VALIDATION_ERROR', 'Enter a valid email address or username.', 422);
        }
        $password = $this->body['password'] ?? null;
        if (!is_string($password) || $password === '' || strlen($password) > 1024) {
            throw new ApiException('VALIDATION_ERROR', 'A valid password is required.', 422);
        }

        $ip = $this->clientIp() ?? 'unknown';
        $this->rateLimiter->consume('login:ip:' . $ip, 50, 900);
        $this->rateLimiter->consume('login:account:' . $ip . ':' . $identifier, 10, 900);

        $statement = $this->db->prepare(
            'SELECT id, email, password_hash, status, email_verified FROM users WHERE ' . $lookupField . ' = :identifier LIMIT 1',
        );
        $statement->execute(['identifier' => $identifier]);
        $user = $statement->fetch();

        $passwordMatches = $user === false
            ? password_verify($password, password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT))
            : password_verify($password, (string) $user['password_hash']);
        if (!$passwordMatches || $user === false) {
            throw new ApiException('AUTH_REQUIRED', 'Email or password is incorrect.', 401);
        }
        if (!in_array($user['status'], ['active', 'pending_verification'], true)) {
            throw new ApiException('AUTH_REQUIRED', 'Email or password is incorrect.', 401);
        }

        if (password_needs_rehash((string) $user['password_hash'], PASSWORD_DEFAULT)) {
            $statement = $this->db->prepare(
                'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id',
            );
            $statement->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at' => $this->now(),
                'id' => $user['id'],
            ]);
        }

        $userId = (int) $user['id'];
        $sessionToken = self::randomToken();
        $csrfToken = self::randomToken();
        $rememberMe = $this->body['remember_me'] ?? false;
        if (!is_bool($rememberMe)) {
            throw new ApiException('VALIDATION_ERROR', 'Remember me must be true or false.', 422);
        }
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $expires = $rememberMe ? $now->modify('+30 days') : $now->modify('+30 minutes');
        $this->transaction(function () use ($userId, $sessionToken, $csrfToken, $now, $expires): void {
            $statement = $this->db->prepare(
                'INSERT INTO user_sessions
                    (user_id, session_token_hash, csrf_token_hash, user_agent, ip_address,
                     expires_at, last_activity_at, created_at)
                 VALUES
                    (:user_id, :token_hash, :csrf_hash, :user_agent, :ip_address,
                     :expires_at, :last_activity_at, :created_at)',
            );
            $statement->execute([
                'user_id' => $userId,
                'token_hash' => hash('sha256', $sessionToken),
                'csrf_hash' => hash('sha256', $csrfToken),
                'user_agent' => $this->userAgent(),
                'ip_address' => $this->clientIp(),
                'expires_at' => $expires->format('Y-m-d H:i:s'),
                'last_activity_at' => $now->format('Y-m-d H:i:s'),
                'created_at' => $now->format('Y-m-d H:i:s'),
            ]);

            $statement = $this->db->prepare(
                'UPDATE users SET last_login_at = :last_login_at WHERE id = :id',
            );
            $statement->execute([
                'last_login_at' => $now->format('Y-m-d H:i:s'),
                'id' => $userId,
            ]);
            $this->audit($userId, 'user_login', 'users', $userId);
        });

        $this->setCookie('neen_session', $sessionToken, $expires->getTimestamp(), true);
        $this->send(true, [
            'user' => $this->userSummary($userId),
            'session_expires_at' => $expires->format('Y-m-d\TH:i:s\Z'),
            'csrf_token' => $csrfToken,
        ]);
    }

    private function logout(): never
    {
        $auth = $this->requireAuth();
        $this->requireCsrf($auth);
        $this->requireAllowedFields([]);
        $now = $this->now();
        $this->transaction(function () use ($auth, $now): void {
            $statement = $this->db->prepare(
                'UPDATE user_sessions SET revoked_at = :revoked_at
                 WHERE id = :id AND revoked_at IS NULL',
            );
            $statement->execute(['revoked_at' => $now, 'id' => $auth['session_id']]);
            $this->audit($auth['user_id'], 'user_logout', 'user_sessions', $auth['session_id']);
        });

        $this->expireCookie('neen_session', true);
        $this->send(true, ['logged_out' => true]);
    }

    private function csrfToken(): never
    {
        $auth = $this->findAuth();
        $token = self::randomToken();
        if ($auth !== null) {
            $statement = $this->db->prepare(
                'UPDATE user_sessions SET csrf_token_hash = :csrf_hash WHERE id = :id',
            );
            $statement->execute([
                'csrf_hash' => hash('sha256', $token),
                'id' => $auth['session_id'],
            ]);
        } else {
            $issuedAt = time();
            $signature = hash_hmac('sha256', $token . '.' . $issuedAt, $this->appKey);
            $this->setCookie('neen_csrf', $token . '.' . $issuedAt . '.' . $signature, $issuedAt + 3600, true);
        }

        $this->send(true, ['csrf_token' => $token]);
    }

    private function requestPasswordReset(): never
    {
        $this->requireCsrf();
        $this->requireAllowedFields(['email']);
        $email = $this->normalizedEmail($this->requiredString('email', 3, 254));
        $ip = $this->clientIp() ?? 'unknown';
        $this->rateLimiter->consume('password-reset:ip:' . $ip, 5, 3600);
        $this->rateLimiter->consume('password-reset:email:' . $email, 3, 3600);

        $statement = $this->db->prepare(
            "SELECT id FROM users WHERE email = :email AND status = 'active' LIMIT 1",
        );
        $statement->execute(['email' => $email]);
        $userId = $statement->fetchColumn();

        if ($userId !== false) {
            $token = self::randomToken();
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $expires = $now->modify('+60 minutes');
            $this->transaction(function () use ($userId, $token, $now, $expires): void {
                $statement = $this->db->prepare(
                    'UPDATE password_reset_tokens SET used_at = :used_at
                     WHERE user_id = :user_id AND used_at IS NULL',
                );
                $statement->execute(['used_at' => $now->format('Y-m-d H:i:s'), 'user_id' => $userId]);

                $statement = $this->db->prepare(
                    'INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, created_at)
                     VALUES (:user_id, :token_hash, :expires_at, :created_at)',
                );
                $statement->execute([
                    'user_id' => $userId,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => $expires->format('Y-m-d H:i:s'),
                    'created_at' => $now->format('Y-m-d H:i:s'),
                ]);
            });

            try {
                $this->sendPasswordResetEmail($email, $token);
            } catch (Throwable $exception) {
                error_log(sprintf(
                    'NEEN password reset email failed [request_id=%s] %s',
                    $this->requestId,
                    get_class($exception),
                ));
            }
        }

        $this->send(true, [
            'message' => 'If an active account uses that email, password reset instructions will be sent.',
        ], [], 202);
    }

    private function resetPassword(): never
    {
        $this->requireCsrf();
        $this->requireAllowedFields(['token', 'password']);
        $token = $this->body['token'] ?? null;
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
            throw new ApiException('VALIDATION_ERROR', 'A valid reset token is required.', 422);
        }
        $password = $this->validatedPassword($this->body['password'] ?? null);
        $now = $this->now();
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $lockClause = $driver === 'mysql' ? ' FOR UPDATE' : '';

        $this->transaction(function () use ($token, $password, $now, $lockClause): void {
            $statement = $this->db->prepare(
                'SELECT id, user_id FROM password_reset_tokens
                 WHERE token_hash = :token_hash AND used_at IS NULL AND expires_at > :now'
                . $lockClause,
            );
            $statement->execute(['token_hash' => hash('sha256', $token), 'now' => $now]);
            $reset = $statement->fetch();
            if ($reset === false) {
                throw new ApiException('VALIDATION_ERROR', 'The reset link is invalid or expired.', 422);
            }

            $statement = $this->db->prepare(
                'UPDATE users SET password_hash = :password_hash, email_verified = 1, updated_at = :updated_at
                 WHERE id = :id AND status = \'active\'',
            );
            $statement->execute([
                'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'updated_at' => $now,
                'id' => $reset['user_id'],
            ]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException('VALIDATION_ERROR', 'The reset link is invalid or expired.', 422);
            }

            $statement = $this->db->prepare(
                'UPDATE password_reset_tokens SET used_at = :used_at
                 WHERE user_id = :user_id AND used_at IS NULL',
            );
            $statement->execute(['used_at' => $now, 'user_id' => $reset['user_id']]);

            $statement = $this->db->prepare(
                'UPDATE user_sessions SET revoked_at = :revoked_at
                 WHERE user_id = :user_id AND revoked_at IS NULL',
            );
            $statement->execute(['revoked_at' => $now, 'user_id' => $reset['user_id']]);

            $this->audit((int) $reset['user_id'], 'password_reset_completed', 'users', (int) $reset['user_id']);
        });

        $this->expireCookie('neen_session', true);
        $this->send(true, ['password_reset' => true]);
    }

    private function changePassword(): never
    {
        $auth = $this->requireAuth();
        $this->requireCsrf($auth);
        $this->requireAllowedFields(['current_password', 'new_password']);

        $currentPassword = $this->body['current_password'] ?? null;
        if (!is_string($currentPassword) || $currentPassword === '' || strlen($currentPassword) > 1024) {
            throw new ApiException('VALIDATION_ERROR', 'A valid current password is required.', 422);
        }
        $newPassword = $this->validatedPassword($this->body['new_password'] ?? null);

        $statement = $this->db->prepare(
            'SELECT password_hash FROM users WHERE id = :id AND status = \'active\' LIMIT 1',
        );
        $statement->execute(['id' => $auth['user_id']]);
        $user = $statement->fetch();

        if (
            $user === false
            || !password_verify($currentPassword, (string) $user['password_hash'])
        ) {
            throw new ApiException('AUTH_REQUIRED', 'The current password is incorrect.', 401);
        }

        if ($currentPassword === $newPassword) {
            throw new ApiException('VALIDATION_ERROR', 'The new password must be different from the current password.', 422);
        }

        $now = $this->now();
        $this->transaction(function () use ($auth, $newPassword, $now): void {
            $statement = $this->db->prepare(
                'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id',
            );
            $statement->execute([
                'password_hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'updated_at' => $now,
                'id' => $auth['user_id'],
            ]);

            $statement = $this->db->prepare(
                'UPDATE user_sessions SET revoked_at = :revoked_at
                 WHERE user_id = :user_id AND id != :session_id AND revoked_at IS NULL',
            );
            $statement->execute([
                'revoked_at' => $now,
                'user_id' => $auth['user_id'],
                'session_id' => $auth['session_id'],
            ]);

            $this->audit(
                $auth['user_id'],
                'password_changed',
                'users',
                $auth['user_id'],
                ['other_sessions_revoked' => true],
            );
        });

        $this->send(true, ['password_changed' => true]);
    }

    private function updateProfile(int $userId): never
    {
        $allowed = ['display_name', 'full_name', 'avatar_url', 'country', 'bio', 'free_fire_id'];
        if ($this->body === []) {
            throw new ApiException('VALIDATION_ERROR', 'Provide at least one profile field.', 422);
        }

        $fields = [];
        $values = [];
        $hasFreeFireId = array_key_exists('free_fire_id', $this->body);
        $freeFireId = null;
        foreach ($this->body as $field => $value) {
            if (!in_array($field, $allowed, true)) {
                throw new ApiException('VALIDATION_ERROR', 'The request contains an unsupported profile field.', 422);
            }
            if ($field === 'free_fire_id') {
                if ($value === null) {
                    $freeFireId = null;
                    continue;
                }
                if (!is_string($value)) {
                    throw new ApiException('VALIDATION_ERROR', 'Free Fire ID must contain 5 to 15 digits.', 422);
                }
                $value = trim($value);
                if ($value === '') {
                    $freeFireId = null;
                    continue;
                }
                if (preg_match('/\A[0-9]{5,15}\z/', $value) !== 1) {
                    throw new ApiException('VALIDATION_ERROR', 'Free Fire ID must contain 5 to 15 digits.', 422);
                }
                $freeFireId = $value;
                continue;
            }
            $maximum = match ($field) {
                'display_name' => 80,
                'full_name' => 150,
                'avatar_url' => 255,
                'country' => 80,
                'bio' => 500,
            };
            if ($value !== null && (!is_string($value) || strlen($value) > $maximum)) {
                throw new ApiException('VALIDATION_ERROR', 'A profile field has an invalid value.', 422);
            }
            if ($field === 'display_name' && $value === '') {
                throw new ApiException('VALIDATION_ERROR', 'Display name cannot be empty.', 422);
            }
            if ($field === 'avatar_url' && $value !== null) {
                $presetAvatars = ['icone/snoop.webp'];
                for ($index = 1; $index <= 9; $index++) {
                    $presetAvatars[] = sprintf('assets/hero-loot/snoop-sticker-%02d.webp', $index);
                }
                $isPreset = in_array($value, $presetAvatars, true);
                $url = $isPreset ? false : filter_var($value, FILTER_VALIDATE_URL);
                if (!$isPreset && ($url === false || parse_url($value, PHP_URL_SCHEME) !== 'https')) {
                    throw new ApiException('VALIDATION_ERROR', 'Avatar URL must be a valid HTTPS URL.', 422);
                }
            }

            $parameter = 'field_' . $field;
            $fields[] = $field . ' = :' . $parameter;
            $values[$parameter] = $value;
        }

        $now = $this->now();
        $this->transaction(function () use ($fields, $values, $now, $userId, $hasFreeFireId, $freeFireId): void {
            $values['updated_at'] = $now;
            $values['user_id'] = $userId;
            if ($fields !== []) {
                $statement = $this->db->prepare(
                    'UPDATE user_profiles SET ' . implode(', ', $fields) . ', updated_at = :updated_at
                     WHERE user_id = :user_id',
                );
                $statement->execute($values);
            }
            if ($hasFreeFireId) {
                $statement = $this->db->prepare(
                    'UPDATE users SET free_fire_id = :free_fire_id, updated_at = :updated_at
                     WHERE id = :user_id',
                );
                $statement->execute([
                    'free_fire_id' => $freeFireId,
                    'updated_at' => $now,
                    'user_id' => $userId,
                ]);
                $statement = $this->db->prepare(
                    'UPDATE user_profiles SET updated_at = :updated_at WHERE user_id = :user_id',
                );
                $statement->execute(['updated_at' => $now, 'user_id' => $userId]);
            }
            $this->audit($userId, 'user_profile_updated', 'user_profiles', $userId);
        });

        $this->send(true, $this->profileData($userId));
    }

    private function findAuth(): ?array
    {
        $token = $_COOKIE['neen_session'] ?? null;
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
            return null;
        }

        $now = $this->now();
        $statement = $this->db->prepare(
            'SELECT s.id AS session_id, s.user_id, s.csrf_token_hash, s.expires_at,
                    s.last_activity_at,
                    u.status, u.email_verified
             FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.session_token_hash = :token_hash AND s.revoked_at IS NULL
             LIMIT 1',
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $auth = $statement->fetch();
        if (
            $auth === false
            || !in_array($auth['status'], ['active', 'pending_verification'], true)
        ) {
            return null;
        }
        if ($auth['expires_at'] <= $now) {
            $statement = $this->db->prepare(
                'UPDATE user_sessions SET revoked_at = :revoked_at
                 WHERE id = :id AND revoked_at IS NULL',
            );
            $statement->execute(['revoked_at' => $now, 'id' => $auth['session_id']]);
            return null;
        }

        $lastActivityTimestamp = is_string($auth['last_activity_at'])
            ? strtotime($auth['last_activity_at'] . ' UTC')
            : false;
        $nowTimestamp = strtotime($now . ' UTC');
        if ($lastActivityTimestamp === false || $lastActivityTimestamp < $nowTimestamp - 120) {
            $expires = (new DateTimeImmutable($now, new DateTimeZone('UTC')))->modify('+30 minutes');
            $statement = $this->db->prepare(
                'UPDATE user_sessions SET expires_at = :expires_at, last_activity_at = :last_activity_at
                 WHERE id = :id AND revoked_at IS NULL',
            );
            $statement->execute([
                'expires_at' => $expires->format('Y-m-d H:i:s'),
                'last_activity_at' => $now,
                'id' => $auth['session_id'],
            ]);
            $this->setCookie('neen_session', $token, $expires->getTimestamp(), true);
        }

        return [
            'session_id' => (int) $auth['session_id'],
            'user_id' => (int) $auth['user_id'],
            'csrf_token_hash' => (string) $auth['csrf_token_hash'],
        ];
    }

    private function requireAuth(): array
    {
        $auth = $this->findAuth();
        if ($auth === null) {
            throw new ApiException('AUTH_REQUIRED', 'Authentication is required.', 401);
        }
        return $auth;
    }

    private function requireCsrf(?array $auth = null): void
    {
        $auth ??= $this->findAuth();
        $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!is_string($provided) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $provided)) {
            throw new ApiException('CSRF_INVALID', 'A valid CSRF token is required.', 403);
        }

        if ($auth !== null) {
            if (!hash_equals($auth['csrf_token_hash'], hash('sha256', $provided))) {
                throw new ApiException('CSRF_INVALID', 'A valid CSRF token is required.', 403);
            }
            return;
        }

        $cookie = $_COOKIE['neen_csrf'] ?? '';
        if (!is_string($cookie)) {
            throw new ApiException('CSRF_INVALID', 'A valid CSRF token is required.', 403);
        }
        $parts = explode('.', $cookie);
        if (count($parts) !== 3 || !ctype_digit($parts[1])) {
            throw new ApiException('CSRF_INVALID', 'A valid CSRF token is required.', 403);
        }
        [$cookieToken, $issuedAt, $signature] = $parts;
        $issuedAt = (int) $issuedAt;
        $expected = hash_hmac('sha256', $cookieToken . '.' . $issuedAt, $this->appKey);
        if (
            !hash_equals($cookieToken, $provided)
            || !hash_equals($expected, $signature)
            || $issuedAt > time()
            || $issuedAt < time() - 3600
        ) {
            throw new ApiException('CSRF_INVALID', 'A valid CSRF token is required.', 403);
        }
    }

    private function requirePermission(int $userId, string $permission): void
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id AND p.name = :permission
             LIMIT 1',
        );
        $statement->execute(['user_id' => $userId, 'permission' => $permission]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('FORBIDDEN', 'You do not have permission to perform this action.', 403);
        }
    }

    private function requireOwner(int $userId): void
    {
        $statement = $this->db->prepare(
            'SELECT 1
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id
               AND r.name = \'owner\'
               AND p.name = \'access_owner_dashboard\'
             LIMIT 1',
        );
        $statement->execute(['user_id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('FORBIDDEN', 'Owner access is required for this operation.', 403);
        }
    }

    private function userSummary(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, username, email, status, email_verified, created_at, last_login_at
             FROM users WHERE id = :id',
        );
        $statement->execute(['id' => $userId]);
        $user = $statement->fetch();
        if ($user === false) {
            throw new ApiException('AUTH_REQUIRED', 'Authentication is required.', 401);
        }

        $statement = $this->db->prepare(
            'SELECT r.name FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = :user_id ORDER BY r.name',
        );
        $statement->execute(['user_id' => $userId]);
        $roles = array_column($statement->fetchAll(), 'name');

        $statement = $this->db->prepare(
            'SELECT DISTINCT p.name FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id ORDER BY p.name',
        );
        $statement->execute(['user_id' => $userId]);
        $permissions = array_column($statement->fetchAll(), 'name');

        $user['id'] = (int) $user['id'];
        $user['email_verified'] = (bool) $user['email_verified'];
        $user['roles'] = $roles;
        $user['permissions'] = $permissions;
        return $user;
    }

    private function profileData(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT u.id AS user_id, u.username, u.email, u.free_fire_id, u.status, u.email_verified,
                    p.full_name, p.display_name, p.avatar_url, p.country, p.bio,
                    p.created_at, p.updated_at
             FROM users u JOIN user_profiles p ON p.user_id = u.id
             WHERE u.id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);
        $profile = $statement->fetch();
        if ($profile === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Profile not found.', 404);
        }
        $profile['user_id'] = (int) $profile['user_id'];
        $profile['email_verified'] = (bool) $profile['email_verified'];
        return ['profile' => $profile];
    }

    private function sendPasswordResetEmail(string $email, string $token): void
    {
        $link = $this->accountLink('reset_token', $token);
        $message = "A password reset was requested for your NEEN account.\n\n"
            . "Use this link within 60 minutes to choose a new password:\n"
            . $link . "\n\n"
            . "If you did not request this, you can ignore this email.";
        EmailDelivery::send($email, 'NEEN password reset', $message);
    }

    private function sendEmailVerification(string $email, string $token): void
    {
        $link = $this->accountLink('verify_token', $token);
        $message = "Welcome to NEEN.\n\n"
            . "Verify your email address using this confirmation link:\n"
            . $link . "\n\n"
            . "If you did not create this account, you can ignore this email.";
        EmailDelivery::send($email, 'Verify your NEEN email address', $message);
    }

    private function accountLink(string $fragmentKey, string $token): string
    {
        if ($this->appUrl === null || filter_var($this->appUrl, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException('Application URL is not configured.');
        }
        $scheme = parse_url($this->appUrl, PHP_URL_SCHEME);
        if ($this->appEnvironment !== 'test' && $scheme !== 'https') {
            throw new RuntimeException('Application URL must use HTTPS.');
        }
        return rtrim($this->appUrl, '/') . '/account.html#'
            . rawurlencode($fragmentKey) . '=' . rawurlencode($token);
    }

    private function walletTransactions(array $auth): never
    {
        $this->requirePermission($auth['user_id'], 'view_wallet_transactions');
        $limit = filter_var($_GET['limit'] ?? 50, FILTER_VALIDATE_INT);
        $offset = filter_var($_GET['offset'] ?? 0, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100 || $offset === false || $offset < 0 || $offset > 1_000_000) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid transaction pagination.', 422);
        }

        $filters = ['user_id = :user_id'];
        $parameters = ['user_id' => $auth['user_id']];
        if (isset($_GET['type'])) {
            if (!in_array($_GET['type'], ['credit', 'debit', 'reversal'], true)) {
                throw new ApiException('VALIDATION_ERROR', 'Invalid transaction type.', 422);
            }
            $filters[] = 'operation_type = :operation_type';
            $parameters['operation_type'] = $_GET['type'];
        }
        if (isset($_GET['balance_type'])) {
            if (!in_array($_GET['balance_type'], ['purchased', 'earned'], true)) {
                throw new ApiException('VALIDATION_ERROR', 'Invalid Coins balance type.', 422);
            }
            $filters[] = 'balance_type = :balance_type';
            $parameters['balance_type'] = $_GET['balance_type'];
        }
        if (isset($_GET['source'])) {
            if (!is_string($_GET['source']) || !preg_match('/\A[a-z_]{1,60}\z/', $_GET['source'])) {
                throw new ApiException('VALIDATION_ERROR', 'Invalid transaction source.', 422);
            }
            $filters[] = 'source = :source';
            $parameters['source'] = $_GET['source'];
        }

        $statement = $this->db->prepare(
            'SELECT id, operation_type, source, amount, balance_type, balance_before, balance_after,
                    total_balance_before, total_balance_after, status, reference_type, reference_id,
                    reversal_of_id, note, created_at
             FROM coin_ledger WHERE ' . implode(' AND ', $filters)
            . ' ORDER BY id DESC LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
        );
        $statement->execute($parameters);
        $entries = $statement->fetchAll();
        foreach ($entries as &$entry) {
            foreach (['id', 'amount', 'balance_before', 'balance_after', 'total_balance_before', 'total_balance_after', 'reference_id', 'reversal_of_id'] as $field) {
                if ($entry[$field] !== null) {
                    $entry[$field] = (int) $entry[$field];
                }
            }
        }
        unset($entry);

        $this->send(true, ['transactions' => $entries, 'limit' => (int) $limit, 'offset' => (int) $offset]);
    }

    private function publicCoinPackages(): never
    {
        $statement = $this->db->query(
            'SELECT id, name, coin_amount, price, currency_code
             FROM coin_packages WHERE is_active = 1 ORDER BY id',
        );
        $this->send(true, ['packages' => $this->normalizeCoinPackages($statement->fetchAll())]);
    }

    private function publicCoinPaymentMethods(): never
    {
        $statement = $this->db->query(
            'SELECT id, code, display_name, instructions, proof_required
             FROM coin_payment_methods WHERE is_active = 1 ORDER BY display_name, id',
        );
        $methods = $statement->fetchAll();
        foreach ($methods as &$method) {
            $method['id'] = (int) $method['id'];
            $method['proof_required'] = (bool) $method['proof_required'];
        }
        unset($method);
        $this->send(true, ['payment_methods' => $methods]);
    }

    private function userCoinOrders(int $userId): never
    {
        $statement = $this->db->prepare(
            'SELECT o.id, o.package_id, o.package_name, o.coin_amount, o.price, o.currency_code,
                    o.payment_method_code, o.payment_method_name, o.proof_required, o.status,
                    o.created_at, o.updated_at, e.payment_reference, e.proof_details, e.created_at AS evidence_submitted_at,
                    r.decision AS review_decision, r.reason AS review_reason, r.created_at AS reviewed_at
             FROM coin_orders o
             LEFT JOIN coin_order_evidence e ON e.coin_order_id = o.id
             LEFT JOIN coin_order_reviews r ON r.coin_order_id = o.id
             WHERE o.user_id = :user_id
             ORDER BY o.id DESC LIMIT 100',
        );
        $statement->execute(['user_id' => $userId]);
        $orders = $statement->fetchAll();
        foreach ($orders as &$order) {
            $order = $this->normalizeCoinOrder($order);
        }
        unset($order);
        $this->send(true, ['orders' => $orders]);
    }

    private function createCoinOrder(int $userId): never
    {
        $this->requireAllowedFields(['package_id', 'payment_method_id', 'idempotency_key']);
        $packageId = $this->positiveInteger($this->body['package_id'] ?? null, 'package_id');
        $paymentMethodId = $this->positiveInteger($this->body['payment_method_id'] ?? null, 'payment_method_id');
        $idempotencyKey = $this->idempotencyKey($this->body['idempotency_key'] ?? null);
        $this->rateLimiter->consume('coin-order:user:' . $userId, 10, 3600);

        [$order, $created] = $this->transaction(function () use ($userId, $packageId, $paymentMethodId, $idempotencyKey): array {
            $this->lockWalletForOrder($userId);
            $existing = $this->db->prepare(
                'SELECT id, package_id, payment_method_id, status
                 FROM coin_orders WHERE user_id = :user_id AND idempotency_key = :idempotency_key',
            );
            $existing->execute(['user_id' => $userId, 'idempotency_key' => $idempotencyKey]);
            $prior = $existing->fetch();
            if ($prior !== false) {
                if ((int) $prior['package_id'] !== $packageId || (int) $prior['payment_method_id'] !== $paymentMethodId) {
                    throw new ApiException('DUPLICATE_REQUEST', 'Idempotency key was already used.', 409);
                }
                return [$this->coinOrderById((int) $prior['id'], $userId), false];
            }

            $statement = $this->db->prepare(
                'SELECT id, name, coin_amount, price, currency_code
                 FROM coin_packages WHERE id = :id AND is_active = 1',
            );
            $statement->execute(['id' => $packageId]);
            $package = $statement->fetch();
            if ($package === false) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Coin package is unavailable.', 404);
            }

            $statement = $this->db->prepare(
                'SELECT id, code, display_name, proof_required
                 FROM coin_payment_methods WHERE id = :id AND is_active = 1',
            );
            $statement->execute(['id' => $paymentMethodId]);
            $method = $statement->fetch();
            if ($method === false) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Payment method is unavailable.', 404);
            }

            $now = $this->now();
            $statement = $this->db->prepare(
                "INSERT INTO coin_orders
                    (user_id, package_id, package_name, coin_amount, price, currency_code,
                     payment_method_id, payment_method_code, payment_method_name, proof_required,
                     status, idempotency_key, created_at, updated_at)
                 VALUES
                    (:user_id, :package_id, :package_name, :coin_amount, :price, :currency_code,
                     :payment_method_id, :payment_method_code, :payment_method_name, :proof_required,
                     'awaiting_payment', :idempotency_key, :created_at, :updated_at)",
            );
            $statement->execute([
                'user_id' => $userId,
                'package_id' => $package['id'],
                'package_name' => $package['name'],
                'coin_amount' => $package['coin_amount'],
                'price' => $package['price'],
                'currency_code' => $package['currency_code'],
                'payment_method_id' => $method['id'],
                'payment_method_code' => $method['code'],
                'payment_method_name' => $method['display_name'],
                'proof_required' => $method['proof_required'],
                'idempotency_key' => $idempotencyKey,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orderId = (int) $this->db->lastInsertId();
            $this->audit($userId, 'coin_order_created', 'coin_orders', $orderId, [
                'package_id' => $packageId,
                'payment_method_id' => $paymentMethodId,
            ]);
            return [$this->coinOrderById($orderId, $userId), true];
        });

        $this->send(true, ['order' => $this->normalizeCoinOrder($order)], [], $created ? 201 : 200);
    }

    private function submitCoinOrderEvidence(int $userId, int $orderId): never
    {
        $this->requireAllowedFields(['payment_reference', 'proof_details', 'idempotency_key']);
        $paymentReference = $this->optionalString($this->body['payment_reference'] ?? null, 1, 150, 'payment_reference');
        $proofDetails = $this->optionalString($this->body['proof_details'] ?? null, 1, 4000, 'proof_details');
        $idempotencyKey = $this->idempotencyKey($this->body['idempotency_key'] ?? null);
        if ($paymentReference === null && $proofDetails === null) {
            throw new ApiException('VALIDATION_ERROR', 'Provide a payment reference or payment details.', 422);
        }

        $order = $this->transaction(function () use (
            $userId,
            $orderId,
            $paymentReference,
            $proofDetails,
            $idempotencyKey,
        ): array {
            $statement = $this->db->prepare(
                'SELECT id, status, proof_required FROM coin_orders
                 WHERE id = :id AND user_id = :user_id' . $this->forUpdateClause(),
            );
            $statement->execute(['id' => $orderId, 'user_id' => $userId]);
            $order = $statement->fetch();
            if ($order === false) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Coin order not found.', 404);
            }

            $statement = $this->db->prepare(
                'SELECT coin_order_id, payment_reference, proof_details
                 FROM coin_order_evidence WHERE user_id = :user_id AND idempotency_key = :idempotency_key',
            );
            $statement->execute(['user_id' => $userId, 'idempotency_key' => $idempotencyKey]);
            $prior = $statement->fetch();
            if ($prior !== false) {
                if (
                    (int) $prior['coin_order_id'] !== $orderId
                    || $prior['payment_reference'] !== $paymentReference
                    || $prior['proof_details'] !== $proofDetails
                ) {
                    throw new ApiException('DUPLICATE_REQUEST', 'Idempotency key was already used.', 409);
                }
                return $this->coinOrderById($orderId, $userId);
            }

            if ($order['status'] !== 'awaiting_payment') {
                throw new ApiException('DUPLICATE_REQUEST', 'This order no longer accepts payment details.', 409);
            }
            if ((bool) $order['proof_required'] && $proofDetails === null && $paymentReference === null) {
                throw new ApiException('VALIDATION_ERROR', 'Payment proof is required for this method.', 422);
            }

            $now = $this->now();
            $statement = $this->db->prepare(
                'INSERT INTO coin_order_evidence
                    (coin_order_id, user_id, payment_reference, proof_details, idempotency_key, created_at)
                 VALUES (:order_id, :user_id, :payment_reference, :proof_details, :idempotency_key, :created_at)',
            );
            $statement->execute([
                'order_id' => $orderId,
                'user_id' => $userId,
                'payment_reference' => $paymentReference,
                'proof_details' => $proofDetails,
                'idempotency_key' => $idempotencyKey,
                'created_at' => $now,
            ]);
            $statement = $this->db->prepare(
                "UPDATE coin_orders SET status = 'pending_review', updated_at = :updated_at
                 WHERE id = :id AND status = 'awaiting_payment'",
            );
            $statement->execute(['updated_at' => $now, 'id' => $orderId]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException('DUPLICATE_REQUEST', 'This order no longer accepts payment details.', 409);
            }
            $this->audit($userId, 'coin_order_evidence_submitted', 'coin_orders', $orderId);
            return $this->coinOrderById($orderId, $userId);
        });

        $this->send(true, ['order' => $this->normalizeCoinOrder($order)]);
    }

    private function adminCoinPackages(): never
    {
        $statement = $this->db->query(
            'SELECT id, name, coin_amount, price, currency_code, is_active, created_by, updated_by, created_at, updated_at
             FROM coin_packages ORDER BY id DESC',
        );
        $packages = $this->normalizeCoinPackages($statement->fetchAll());
        $this->send(true, ['packages' => $packages]);
    }

    private function createCoinPackage(int $adminUserId): never
    {
        $this->requireAllowedFields(['name', 'coin_amount', 'price', 'currency_code', 'is_active']);
        $name = $this->requiredString('name', 1, 120);
        $coinAmount = $this->positiveInteger($this->body['coin_amount'] ?? null, 'coin_amount');
        $price = $this->validatedPrice($this->body['price'] ?? null);
        $currencyCode = $this->currencyCode($this->body['currency_code'] ?? null);
        $isActive = $this->booleanValue($this->body['is_active'] ?? false, 'is_active');
        $now = $this->now();

        $statement = $this->db->prepare(
            'INSERT INTO coin_packages
                (name, coin_amount, price, currency_code, is_active, created_by, updated_by, created_at, updated_at)
             VALUES
                (:name, :coin_amount, :price, :currency_code, :is_active, :created_by, :updated_by, :created_at, :updated_at)',
        );
        $statement->execute([
            'name' => $name,
            'coin_amount' => $coinAmount,
            'price' => $price,
            'currency_code' => $currencyCode,
            'is_active' => $isActive ? 1 : 0,
            'created_by' => $adminUserId,
            'updated_by' => $adminUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $packageId = (int) $this->db->lastInsertId();
        $this->audit($adminUserId, 'coin_package_created', 'coin_packages', $packageId, [
            'coin_amount' => $coinAmount,
            'currency_code' => $currencyCode,
        ]);
        $this->send(true, ['package' => $this->coinPackageById($packageId)], [], 201);
    }

    private function updateCoinPackage(int $adminUserId, int $packageId): never
    {
        $this->requireAllowedFields(['name', 'coin_amount', 'price', 'currency_code', 'is_active']);
        if ($this->body === []) {
            throw new ApiException('VALIDATION_ERROR', 'Provide at least one package field.', 422);
        }
        $fields = [];
        $values = ['id' => $packageId, 'updated_by' => $adminUserId, 'updated_at' => $this->now()];
        foreach ($this->body as $field => $value) {
            $parameter = $field;
            switch ($field) {
                case 'name':
                    $values[$parameter] = $this->optionalString($value, 1, 120, 'name');
                    break;
                case 'coin_amount':
                    $values[$parameter] = $this->positiveInteger($value, 'coin_amount');
                    break;
                case 'price':
                    $values[$parameter] = $this->validatedPrice($value);
                    break;
                case 'currency_code':
                    $values[$parameter] = $this->currencyCode($value);
                    break;
                case 'is_active':
                    $values[$parameter] = $this->booleanValue($value, 'is_active') ? 1 : 0;
                    break;
            }
            $fields[] = $field . ' = :' . $parameter;
        }
        $fields[] = 'updated_by = :updated_by';
        $fields[] = 'updated_at = :updated_at';
        $statement = $this->db->prepare(
            'UPDATE coin_packages SET ' . implode(', ', $fields) . ' WHERE id = :id',
        );
        $statement->execute($values);
        if ($statement->rowCount() === 0 && $this->coinPackageByIdOrNull($packageId) === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Coin package not found.', 404);
        }
        $this->audit($adminUserId, 'coin_package_updated', 'coin_packages', $packageId);
        $this->send(true, ['package' => $this->coinPackageById($packageId)]);
    }

    private function adminCoinPaymentMethods(): never
    {
        $statement = $this->db->query(
            'SELECT id, code, display_name, instructions, proof_required, is_active, created_by, updated_by, created_at, updated_at
             FROM coin_payment_methods ORDER BY id DESC',
        );
        $methods = $statement->fetchAll();
        foreach ($methods as &$method) {
            $method = $this->normalizeCoinPaymentMethod($method);
        }
        unset($method);
        $this->send(true, ['payment_methods' => $methods]);
    }

    private function createCoinPaymentMethod(int $adminUserId): never
    {
        $this->requireAllowedFields(['code', 'display_name', 'instructions', 'proof_required', 'is_active']);
        $code = $this->paymentMethodCode($this->body['code'] ?? null);
        $name = $this->requiredString('display_name', 1, 120);
        $instructions = $this->optionalString($this->body['instructions'] ?? null, 1, 4000, 'instructions');
        $proofRequired = $this->booleanValue($this->body['proof_required'] ?? true, 'proof_required');
        $isActive = $this->booleanValue($this->body['is_active'] ?? false, 'is_active');
        $now = $this->now();
        try {
            $statement = $this->db->prepare(
                'INSERT INTO coin_payment_methods
                    (code, display_name, instructions, proof_required, is_active, created_by, updated_by, created_at, updated_at)
                 VALUES
                    (:code, :display_name, :instructions, :proof_required, :is_active, :created_by, :updated_by, :created_at, :updated_at)',
            );
            $statement->execute([
                'code' => $code,
                'display_name' => $name,
                'instructions' => $instructions,
                'proof_required' => $proofRequired ? 1 : 0,
                'is_active' => $isActive ? 1 : 0,
                'created_by' => $adminUserId,
                'updated_by' => $adminUserId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $exception) {
            if (in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw new ApiException('DUPLICATE_REQUEST', 'Payment method code already exists.', 409);
            }
            throw $exception;
        }
        $methodId = (int) $this->db->lastInsertId();
        $this->audit($adminUserId, 'coin_payment_method_created', 'coin_payment_methods', $methodId);
        $this->send(true, ['payment_method' => $this->coinPaymentMethodById($methodId)], [], 201);
    }

    private function updateCoinPaymentMethod(int $adminUserId, int $methodId): never
    {
        $this->requireAllowedFields(['display_name', 'instructions', 'proof_required', 'is_active']);
        if ($this->body === []) {
            throw new ApiException('VALIDATION_ERROR', 'Provide at least one payment method field.', 422);
        }
        $fields = [];
        $values = ['id' => $methodId, 'updated_by' => $adminUserId, 'updated_at' => $this->now()];
        foreach ($this->body as $field => $value) {
            $parameter = $field;
            switch ($field) {
                case 'display_name':
                    $values[$parameter] = $this->optionalString($value, 1, 120, 'display_name');
                    break;
                case 'instructions':
                    $values[$parameter] = $this->optionalString($value, 1, 4000, 'instructions');
                    break;
                case 'proof_required':
                case 'is_active':
                    $values[$parameter] = $this->booleanValue($value, $field) ? 1 : 0;
                    break;
            }
            $fields[] = $field . ' = :' . $parameter;
        }
        $fields[] = 'updated_by = :updated_by';
        $fields[] = 'updated_at = :updated_at';
        $statement = $this->db->prepare(
            'UPDATE coin_payment_methods SET ' . implode(', ', $fields) . ' WHERE id = :id',
        );
        $statement->execute($values);
        if ($statement->rowCount() === 0 && $this->coinPaymentMethodByIdOrNull($methodId) === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Payment method not found.', 404);
        }
        $this->audit($adminUserId, 'coin_payment_method_updated', 'coin_payment_methods', $methodId);
        $this->send(true, ['payment_method' => $this->coinPaymentMethodById($methodId)]);
    }

    private function adminCoinOrders(): never
    {
        $status = $_GET['status'] ?? null;
        if ($status !== null && !in_array($status, ['awaiting_payment', 'pending_review', 'approved', 'rejected', 'cancelled'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid Coins order status filter.', 422);
        }
        $where = $status === null ? '' : ' WHERE o.status = :status';
        $statement = $this->db->prepare(
            'SELECT o.id, o.user_id, u.username, u.email, o.package_id, o.package_name, o.coin_amount,
                    o.price, o.currency_code, o.payment_method_code, o.payment_method_name,
                    o.proof_required, o.status, o.created_at, o.updated_at,
                    e.payment_reference, e.proof_details, e.created_at AS evidence_submitted_at,
                    r.decision AS review_decision, r.reason AS review_reason, r.reviewer_user_id, r.created_at AS reviewed_at
             FROM coin_orders o
             JOIN users u ON u.id = o.user_id
             LEFT JOIN coin_order_evidence e ON e.coin_order_id = o.id
             LEFT JOIN coin_order_reviews r ON r.coin_order_id = o.id'
            . $where . ' ORDER BY o.id DESC LIMIT 100',
        );
        $statement->execute($status === null ? [] : ['status' => $status]);
        $orders = $statement->fetchAll();
        foreach ($orders as &$order) {
            $order = $this->normalizeCoinOrder($order);
            $order['user_id'] = (int) $order['user_id'];
            $order['username'] = (string) $order['username'];
            $order['email'] = (string) $order['email'];
        }
        unset($order);
        $this->send(true, ['orders' => $orders]);
    }

    private function adminAuditLogs(): never
    {
        $statement = $this->db->query(
            'SELECT a.id, a.actor_user_id, u.username AS actor_username, a.action_type,
                    a.entity_type, a.entity_id, a.created_at
             FROM audit_logs a
             LEFT JOIN users u ON u.id = a.actor_user_id
             ORDER BY a.id DESC
             LIMIT 50',
        );
        $entries = $statement->fetchAll();
        foreach ($entries as &$entry) {
            $entry['id'] = (int) $entry['id'];
            $entry['actor_user_id'] = $entry['actor_user_id'] === null ? null : (int) $entry['actor_user_id'];
            $entry['entity_id'] = $entry['entity_id'] === null ? null : (int) $entry['entity_id'];
        }
        unset($entry);

        $this->send(true, ['entries' => $entries]);
    }

    private function reviewCoinOrder(int $adminUserId, int $orderId): never
    {
        $this->requireAllowedFields(['decision', 'reason', 'idempotency_key']);
        $decision = $this->body['decision'] ?? null;
        if (!is_string($decision) || !in_array($decision, ['approved', 'rejected'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Decision must be approved or rejected.', 422);
        }
        $reason = $this->requiredString('reason', 3, 1000);
        $idempotencyKey = $this->idempotencyKey($this->body['idempotency_key'] ?? null);

        $result = $this->transaction(function () use ($adminUserId, $orderId, $decision, $reason, $idempotencyKey): array {
            $statement = $this->db->prepare(
                'SELECT id, user_id, coin_amount, status, proof_required
                 FROM coin_orders WHERE id = :id' . $this->forUpdateClause(),
            );
            $statement->execute(['id' => $orderId]);
            $order = $statement->fetch();
            if ($order === false) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Coin order not found.', 404);
            }

            $statement = $this->db->prepare(
                'SELECT decision, idempotency_key, ledger_entry_id, reviewer_user_id, reason
                 FROM coin_order_reviews WHERE coin_order_id = :order_id',
            );
            $statement->execute(['order_id' => $orderId]);
            $priorReview = $statement->fetch();
            if ($priorReview !== false) {
                if (
                    (int) $priorReview['reviewer_user_id'] === $adminUserId
                    && $priorReview['idempotency_key'] === $idempotencyKey
                    && $priorReview['decision'] === $decision
                    && $priorReview['reason'] === $reason
                ) {
                    return [
                        'order_id' => $orderId,
                        'status' => $decision,
                        'ledger_entry_id' => $priorReview['ledger_entry_id'] === null
                            ? null
                            : (int) $priorReview['ledger_entry_id'],
                    ];
                }
                throw new ApiException('DUPLICATE_REQUEST', 'This order has already been reviewed.', 409);
            }
            if ($order['status'] !== 'pending_review') {
                throw new ApiException('VALIDATION_ERROR', 'Only orders pending review can be approved or rejected.', 409);
            }

            if ((bool) $order['proof_required']) {
                $statement = $this->db->prepare(
                    'SELECT id FROM coin_order_evidence WHERE coin_order_id = :order_id LIMIT 1',
                );
                $statement->execute(['order_id' => $orderId]);
                if ($statement->fetchColumn() === false) {
                    throw new ApiException('VALIDATION_ERROR', 'Required payment evidence is missing.', 409);
                }
            }

            $ledgerEntryId = null;
            if ($decision === 'approved') {
                $ledgerEntryId = (new CoinsLedger($this->db))->credit(
                    (int) $order['user_id'],
                    'purchased',
                    (int) $order['coin_amount'],
                    'purchase_approval',
                    'coin-order-approval-' . $orderId,
                    'coin_order',
                    $orderId,
                    $reason,
                );
            }

            $now = $this->now();
            $statement = $this->db->prepare(
                'INSERT INTO coin_order_reviews
                    (coin_order_id, reviewer_user_id, decision, reason, idempotency_key, ledger_entry_id, created_at)
                 VALUES
                    (:order_id, :reviewer_user_id, :decision, :reason, :idempotency_key, :ledger_entry_id, :created_at)',
            );
            $statement->execute([
                'order_id' => $orderId,
                'reviewer_user_id' => $adminUserId,
                'decision' => $decision,
                'reason' => $reason,
                'idempotency_key' => $idempotencyKey,
                'ledger_entry_id' => $ledgerEntryId,
                'created_at' => $now,
            ]);
            $statement = $this->db->prepare(
                'UPDATE coin_orders SET status = :status, updated_at = :updated_at
                 WHERE id = :id AND status = \'pending_review\'',
            );
            $statement->execute(['status' => $decision, 'updated_at' => $now, 'id' => $orderId]);
            if ($statement->rowCount() !== 1) {
                throw new ApiException('DUPLICATE_REQUEST', 'This order has already been reviewed.', 409);
            }
            $this->audit($adminUserId, 'coin_order_' . $decision, 'coin_orders', $orderId, [
                'user_id' => (int) $order['user_id'],
                'ledger_entry_id' => $ledgerEntryId,
                'reason' => $reason,
            ]);
            return ['order_id' => $orderId, 'status' => $decision, 'ledger_entry_id' => $ledgerEntryId];
        });

        $this->send(true, ['review' => $result]);
    }

    private function adjustWallet(int $adminUserId, int $userId): never
    {
        $this->requireAllowedFields(['direction', 'balance_type', 'amount', 'reason', 'idempotency_key']);
        $direction = $this->body['direction'] ?? null;
        if (!is_string($direction) || !in_array($direction, ['credit', 'debit'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Direction must be credit or debit.', 422);
        }
        $balanceType = $this->balanceType($this->body['balance_type'] ?? null);
        $amount = $this->positiveInteger($this->body['amount'] ?? null, 'amount');
        if ($amount > 100000000) {
            throw new ApiException('VALIDATION_ERROR', 'Amount must not exceed 100000000 Coins.', 422);
        }
        $reason = $this->requiredString('reason', 3, 1000);
        $idempotencyKey = $this->idempotencyKey($this->body['idempotency_key'] ?? null);
        $entryId = $this->transaction(function () use (
            $userId,
            $balanceType,
            $amount,
            $direction,
            $adminUserId,
            $reason,
            $idempotencyKey,
        ): int {
            $entryId = (new CoinsLedger($this->db))->adjust(
                $userId,
                $balanceType,
                $amount,
                $direction,
                $adminUserId,
                $reason,
                $idempotencyKey,
            );
            $this->audit($adminUserId, 'wallet_adjusted', 'coin_ledger', $entryId, [
                'user_id' => $userId,
                'direction' => $direction,
                'balance_type' => $balanceType,
                'amount' => $amount,
                'reason' => $reason,
            ]);
            return $entryId;
        });
        $this->send(true, ['ledger_entry_id' => $entryId], [], 201);
    }

    private function reverseCoinLedgerEntry(int $adminUserId, int $entryId): never
    {
        $this->requireAllowedFields(['reason', 'idempotency_key']);
        $reason = $this->requiredString('reason', 3, 1000);
        $idempotencyKey = $this->idempotencyKey($this->body['idempotency_key'] ?? null);
        $statement = $this->db->prepare('SELECT user_id FROM coin_ledger WHERE id = :id');
        $statement->execute(['id' => $entryId]);
        $userId = $statement->fetchColumn();
        if ($userId === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Ledger entry not found.', 404);
        }
        $reversalId = $this->transaction(function () use ($adminUserId, $userId, $entryId, $reason, $idempotencyKey): int {
            $reversalId = (new CoinsLedger($this->db))->reverse((int) $userId, $entryId, $reason, $idempotencyKey);
            $this->audit($adminUserId, 'coin_ledger_reversed', 'coin_ledger', $reversalId, [
                'reversed_entry_id' => $entryId,
                'reason' => $reason,
            ]);
            return $reversalId;
        });
        $this->send(true, ['ledger_entry_id' => $reversalId, 'reversed_entry_id' => $entryId], [], 201);
    }

    private function coinOrderById(int $orderId, int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT o.id, o.package_id, o.package_name, o.coin_amount, o.price, o.currency_code,
                    o.payment_method_code, o.payment_method_name, o.proof_required, o.status,
                    o.created_at, o.updated_at, e.payment_reference, e.proof_details, e.created_at AS evidence_submitted_at,
                    r.decision AS review_decision, r.reason AS review_reason, r.created_at AS reviewed_at
             FROM coin_orders o
             LEFT JOIN coin_order_evidence e ON e.coin_order_id = o.id
             LEFT JOIN coin_order_reviews r ON r.coin_order_id = o.id
             WHERE o.id = :id AND o.user_id = :user_id',
        );
        $statement->execute(['id' => $orderId, 'user_id' => $userId]);
        $order = $statement->fetch();
        if ($order === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Coin order not found.', 404);
        }
        return $order;
    }

    private function coinPackageById(int $packageId): array
    {
        $package = $this->coinPackageByIdOrNull($packageId);
        if ($package === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Coin package not found.', 404);
        }
        return $package;
    }

    private function coinPackageByIdOrNull(int $packageId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, coin_amount, price, currency_code, is_active, created_by, updated_by, created_at, updated_at
             FROM coin_packages WHERE id = :id',
        );
        $statement->execute(['id' => $packageId]);
        $package = $statement->fetch();
        return $package === false ? null : $this->normalizeCoinPackage($package);
    }

    private function coinPaymentMethodById(int $methodId): array
    {
        $method = $this->coinPaymentMethodByIdOrNull($methodId);
        if ($method === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Payment method not found.', 404);
        }
        return $method;
    }

    private function coinPaymentMethodByIdOrNull(int $methodId): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, code, display_name, instructions, proof_required, is_active,
                    created_by, updated_by, created_at, updated_at
             FROM coin_payment_methods WHERE id = :id',
        );
        $statement->execute(['id' => $methodId]);
        $method = $statement->fetch();
        return $method === false ? null : $this->normalizeCoinPaymentMethod($method);
    }

    private function normalizeCoinPackages(array $packages): array
    {
        return array_map(fn (array $package): array => $this->normalizeCoinPackage($package), $packages);
    }

    private function normalizeCoinPackage(array $package): array
    {
        foreach (['id', 'coin_amount', 'created_by', 'updated_by'] as $field) {
            if (array_key_exists($field, $package) && $package[$field] !== null) {
                $package[$field] = (int) $package[$field];
            }
        }
        if (array_key_exists('is_active', $package)) {
            $package['is_active'] = (bool) $package['is_active'];
        }
        return $package;
    }

    private function normalizeCoinPaymentMethod(array $method): array
    {
        foreach (['id', 'created_by', 'updated_by'] as $field) {
            if (array_key_exists($field, $method) && $method[$field] !== null) {
                $method[$field] = (int) $method[$field];
            }
        }
        foreach (['proof_required', 'is_active'] as $field) {
            if (array_key_exists($field, $method)) {
                $method[$field] = (bool) $method[$field];
            }
        }
        return $method;
    }

    private function normalizeCoinOrder(array $order): array
    {
        foreach (['id', 'package_id', 'coin_amount', 'user_id', 'reviewer_user_id'] as $field) {
            if (array_key_exists($field, $order) && $order[$field] !== null) {
                $order[$field] = (int) $order[$field];
            }
        }
        if (array_key_exists('proof_required', $order)) {
            $order['proof_required'] = (bool) $order['proof_required'];
        }
        return $order;
    }

    private function positiveInteger(mixed $value, string $field): int
    {
        if (is_int($value)) {
            $number = $value;
        } elseif (is_string($value) && preg_match('/\A[1-9][0-9]{0,17}\z/', $value)) {
            $number = filter_var($value, FILTER_VALIDATE_INT);
            if ($number === false) {
                $number = 0;
            }
        } else {
            $number = 0;
        }
        if ($number < 1) {
            throw new ApiException('VALIDATION_ERROR', 'Field "' . $field . '" must be a positive whole number.', 422);
        }
        return $number;
    }

    private function nonNegativeInteger(mixed $value, string $field): int
    {
        if (!is_int($value) || $value < 0 || $value > 255) {
            throw new ApiException('VALIDATION_ERROR', 'Field "' . $field . '" must be a non-negative whole number.', 422);
        }
        return $value;
    }

    private function memoryMatch(): MemoryMatch
    {
        return new MemoryMatch(
            $this->db,
            dirname(__DIR__, 2) . '/assets/hero-loot/memory-match-images.json',
            new GameRewards($this->db),
        );
    }

    private function pictureJigsaw(): PictureJigsaw
    {
        return new PictureJigsaw(
            $this->db,
            dirname(__DIR__, 2) . '/assets/hero-loot/picture-jigsaw-images.json',
            new GameRewards($this->db),
        );
    }

    private function targetRush(): TargetRush
    {
        return new TargetRush($this->db, new GameRewards($this->db));
    }

    private function lootSequence(): LootSequence
    {
        return new LootSequence($this->db, new GameRewards($this->db));
    }

    private function sunnyIsland(): SunnyIsland
    {
        return new SunnyIsland($this->db, new GameRewards($this->db));
    }

    private function sunnyIslandShop(): SunnyIslandShop
    {
        return new SunnyIslandShop($this->db);
    }

    private function attendance(): Attendance
    {
        return new Attendance($this->db);
    }

    private function homepageRewards(): HomepageRewards
    {
        return new HomepageRewards($this->db);
    }

    private function communityEvents(): CommunityEvents
    {
        return new CommunityEvents($this->db);
    }

    private function diamondRecharge(): DiamondRecharge
    {
        return new DiamondRecharge($this->db);
    }

    private function idempotencyKey(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $value)) {
            throw new ApiException('VALIDATION_ERROR', 'A valid idempotency key is required.', 422);
        }
        return $value;
    }

    private function balanceType(mixed $value): string
    {
        if (!is_string($value) || !in_array($value, ['purchased', 'earned'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Balance type must be purchased or earned.', 422);
        }
        return $value;
    }

    private function validatedPrice(mixed $value): string
    {
        if (
            !is_string($value)
            || !preg_match('/\A(?:0|[1-9][0-9]{0,7})(?:\.[0-9]{1,2})?\z/', $value)
        ) {
            throw new ApiException('VALIDATION_ERROR', 'Price must be a positive amount with up to two decimals.', 422);
        }
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $price = $whole . '.' . str_pad($fraction, 2, '0');
        if ($price === '0.00') {
            throw new ApiException('VALIDATION_ERROR', 'Price must be greater than zero.', 422);
        }
        return $price;
    }

    private function currencyCode(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[A-Z]{3}\z/', $value)) {
            throw new ApiException('VALIDATION_ERROR', 'Currency code must be a three-letter uppercase code.', 422);
        }
        return $value;
    }

    private function paymentMethodCode(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/\A[a-z][a-z0-9_-]{1,59}\z/', $value)) {
            throw new ApiException('VALIDATION_ERROR', 'Payment method code is invalid.', 422);
        }
        return $value;
    }

    private function booleanValue(mixed $value, string $field): bool
    {
        if (!is_bool($value)) {
            throw new ApiException('VALIDATION_ERROR', 'Field "' . $field . '" must be a boolean.', 422);
        }
        return $value;
    }

    private function forUpdateClause(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function lockWalletForOrder(int $userId): void
    {
        $statement = $this->db->prepare(
            'SELECT id FROM wallets WHERE user_id = :user_id' . $this->forUpdateClause(),
        );
        $statement->execute(['user_id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Wallet not found.', 404);
        }
    }

    private function audit(
        ?int $actorUserId,
        string $action,
        string $entity,
        ?int $entityId,
        array $payload = [],
    ): void {
        $actorRole = null;
        if ($actorUserId !== null) {
            $roleQuery = $this->db->prepare(
                'SELECT r.name FROM user_roles ur
                 JOIN roles r ON r.id = ur.role_id
                 WHERE ur.user_id = :user_id
                 ORDER BY CASE r.name
                     WHEN \'owner\' THEN 0
                     WHEN \'super_admin\' THEN 1
                     ELSE 2
                 END, r.name
                 LIMIT 1',
            );
            $roleQuery->execute(['user_id' => $actorUserId]);
            $role = $roleQuery->fetchColumn();
            $actorRole = $role === false ? null : (string) $role;
        }
        $statement = $this->db->prepare(
            'INSERT INTO audit_logs
                (actor_user_id, actor_role, action_type, entity_type, entity_id, payload, ip_address, user_agent, created_at)
             VALUES
                (:actor_user_id, :actor_role, :action_type, :entity_type, :entity_id, :payload,
                 :ip_address, :user_agent, :created_at)',
        );
        $statement->execute([
            'actor_user_id' => $actorUserId,
            'actor_role' => $actorRole,
            'action_type' => $action,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'payload' => $payload === [] ? null : json_encode($payload, JSON_THROW_ON_ERROR),
            'ip_address' => $this->clientIp(),
            'user_agent' => $this->userAgent(),
            'created_at' => $this->now(),
        ]);
    }

    private function transaction(callable $operation): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $operation();
            $this->db->commit();
            return $result;
        } catch (Throwable $exception) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    private function readJsonBody(): array
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        if ($contentLength > 16384) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'Request body is too large.', 413);
        }
        $contentType = strtolower(trim(explode(';', $_SERVER['CONTENT_TYPE'] ?? '')[0]));
        if ($contentType !== 'application/json') {
            throw new ApiException('UNSUPPORTED_MEDIA_TYPE', 'Content-Type must be application/json.', 415);
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 16384) {
            throw new ApiException('PAYLOAD_TOO_LARGE', 'Request body is too large.', 413);
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('VALIDATION_ERROR', 'Request body must contain valid JSON.', 400);
        }
        if (!is_array($decoded) || !str_starts_with(ltrim($raw), '{')) {
            throw new ApiException('VALIDATION_ERROR', 'Request body must be a JSON object.', 400);
        }
        return $decoded;
    }

    private function requiredString(string $field, int $minimum, int $maximum): string
    {
        $value = $this->body[$field] ?? null;
        if (!is_string($value) || strlen($value) < $minimum || strlen($value) > $maximum) {
            throw new ApiException('VALIDATION_ERROR', 'A required field has an invalid value.', 422);
        }
        return $value;
    }

    private function requireAllowedFields(array $allowed): void
    {
        foreach (array_keys($this->body) as $field) {
            if (!in_array($field, $allowed, true)) {
                throw new ApiException('VALIDATION_ERROR', 'The request contains an unsupported field.', 422);
            }
        }
    }

    private function optionalString(mixed $value, int $minimum, int $maximum, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || strlen($value) < $minimum || strlen($value) > $maximum) {
            throw new ApiException('VALIDATION_ERROR', 'Field "' . $field . '" has an invalid value.', 422);
        }
        return $value;
    }

    private function normalizedEmail(string $email): string
    {
        $email = strtolower(trim($email));
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiException('VALIDATION_ERROR', 'A valid email address is required.', 422);
        }
        return $email;
    }

    private function validatedPassword(mixed $password): string
    {
        if (!is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            throw new ApiException(
                'VALIDATION_ERROR',
                'Password must be between 12 and 72 bytes.',
                422,
            );
        }
        return $password;
    }

    private function clientIp(): ?string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        return is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : null;
    }

    private function userAgent(): ?string
    {
        $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';
        return is_string($userAgent) ? substr($userAgent, 0, 512) : null;
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    private function setCookie(string $name, string $value, int $expires, bool $httpOnly): void
    {
        setcookie($name, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => $this->appEnvironment !== 'test',
            'httponly' => $httpOnly,
            'samesite' => 'Lax',
        ]);
    }

    private function expireCookie(string $name, bool $httpOnly): void
    {
        $this->setCookie($name, '', time() - 3600, $httpOnly);
    }

    private function requireHttps(): void
    {
        if ($this->appEnvironment === 'test') {
            return;
        }
        $https = $_SERVER['HTTPS'] ?? '';
        if ($https === '' || strtolower((string) $https) === 'off') {
            throw new ApiException('HTTPS_REQUIRED', 'HTTPS is required for account operations.', 400);
        }
    }

    private function expectMethod(string $actual, string $expected): void
    {
        if ($actual !== $expected) {
            throw new ApiException(
                'METHOD_NOT_ALLOWED',
                'This endpoint does not support the requested method.',
                405,
                ['Allow' => $expected],
            );
        }
    }

    private function send(
        bool $success,
        mixed $data,
        array $errors = [],
        int $status = 200,
        array $extraHeaders = [],
    ): never {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: no-referrer');
        header('X-Request-Id: ' . $this->requestId);
        foreach ($extraHeaders as $name => $value) {
            header($name . ': ' . $value);
        }
        echo json_encode([
            'success' => $success,
            'data' => $data,
            'meta' => [
                'request_id' => $this->requestId,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ],
            'errors' => $errors,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4)
            . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
