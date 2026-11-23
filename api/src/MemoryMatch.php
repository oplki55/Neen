<?php
declare(strict_types=1);

final class MemoryMatch
{
    private const GAME_SLUG = 'memory-match';
    private const MISMATCH_REVEAL_MILLISECONDS = 900;
    private const SESSION_IDLE_MINUTES = 30;

    public function __construct(
        private readonly PDO $db,
        private readonly string $manifestPath,
        private readonly GameRewards $rewards,
    ) {
    }

    public function catalog(): array
    {
        $images = $this->images();
        $game = $this->gameSettings();
        return [
            'slug' => self::GAME_SLUG,
            'name' => $game['name'],
            'description' => $game['description'],
            'status' => $game['status'],
            'pairs_total' => count($images),
            'cards_total' => count($images) * 2,
            'free_attempts_per_day' => (int) $game['free_attempt_limit'],
            'reward_amount_coins' => (int) $game['reward_amount_coins'],
            'reset_timezone' => $game['reset_timezone'],
            'images' => $images,
        ];
    }

    public function start(int $userId, string $idempotencyKey): array
    {
        if (!preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $idempotencyKey)) {
            throw new ApiException('VALIDATION_ERROR', 'A valid idempotency key is required.', 422);
        }

        $images = $this->images();
        if (count($images) < 2) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game needs at least two valid images.', 503);
        }

        $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $utcNow->format('Y-m-d H:i:s');
        $expiresAt = $utcNow->modify('+' . self::SESSION_IDLE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $cairoNow = $utcNow->setTimezone(new DateTimeZone('Africa/Cairo'));
        $attemptDate = $cairoNow->format('Y-m-d');
        $resetAt = $cairoNow->modify('tomorrow')->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $result = $this->transaction(function () use (
            $userId,
            $idempotencyKey,
            $images,
            $now,
            $expiresAt,
            $attemptDate,
            $resetAt,
        ): array {
            $game = $this->activeGame();
            $replayed = $this->sessionByIdempotency($userId, $idempotencyKey);
            if ($replayed !== null) {
                return ['created' => false, 'session' => $this->state($replayed)];
            }

            $this->upsertDailyAttempts($userId, (int) $game['id'], $attemptDate, $resetAt, $now);
            $daily = $this->dailyAttempts($userId, (int) $game['id'], $attemptDate, true);

            $active = $this->activeSession($userId, (int) $game['id'], $now, true);
            if ($active !== null) {
                return ['created' => false, 'session' => $this->state($active)];
            }

            $this->failExpiredSessions($userId, (int) $game['id'], $now);
            if ((int) $daily['free_attempts_used'] >= (int) $game['free_attempt_limit']) {
                throw new ApiException(
                    'ATTEMPT_LIMIT_EXCEEDED',
                    'All free Memory Match attempts for today have been used.',
                    429,
                );
            }

            $deck = [];
            foreach ($images as $image) {
                $deck[] = $image['id'];
                $deck[] = $image['id'];
            }
            $this->secureShuffle($deck);

            $statement = $this->db->prepare(
                'INSERT INTO game_sessions
                    (user_id, game_id, attempt_type, status, move_count, matched_pairs,
                     idempotency_key, started_at, last_activity_at, expires_at, created_at, updated_at)
                 VALUES
                    (:user_id, :game_id, \'free\', \'started\', 0, 0,
                     :idempotency_key, :started_at, :last_activity_at, :expires_at, :created_at, :updated_at)',
            );
            $statement->execute([
                'user_id' => $userId,
                'game_id' => $game['id'],
                'idempotency_key' => $idempotencyKey,
                'started_at' => $now,
                'last_activity_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $sessionId = (int) $this->db->lastInsertId();

            $statement = $this->db->prepare(
                'INSERT INTO memory_match_states
                    (session_id, deck_json, matched_cards_json, open_cards_json, mismatch_at)
                 VALUES (:session_id, :deck_json, :matched_cards_json, :open_cards_json, NULL)',
            );
            $statement->execute([
                'session_id' => $sessionId,
                'deck_json' => json_encode($deck, JSON_THROW_ON_ERROR),
                'matched_cards_json' => json_encode(new stdClass(), JSON_THROW_ON_ERROR),
                'open_cards_json' => '[]',
            ]);
            $this->setDailyAttemptsUsed($userId, (int) $game['id'], $attemptDate, (int) $daily['free_attempts_used'] + 1, $now);

            return [
                'created' => true,
                'session' => $this->state($this->sessionById($sessionId, $userId)),
            ];
        });

        $result['attempts_remaining_today'] = max(
            0,
            (int) $this->activeGame()['free_attempt_limit'] - $this->currentDayAttempts($userId),
        );
        return $result;
    }

    public function current(int $userId): ?array
    {
        $now = $this->now();
        $statement = $this->db->prepare(
            'SELECT id FROM game_sessions
             WHERE user_id = :user_id AND game_id = :game_id
               AND (
                    (status IN (\'started\', \'in_progress\') AND expires_at > :now)
                    OR status = \'completed\'
               )
             ORDER BY CASE WHEN status IN (\'started\', \'in_progress\') THEN 0 ELSE 1 END, id DESC
             LIMIT 1',
        );
        $statement->execute([
            'user_id' => $userId,
            'game_id' => $this->gameId(),
            'now' => $now,
        ]);
        $sessionId = $statement->fetchColumn();
        return $sessionId === false ? null : $this->getState($userId, (int) $sessionId);
    }

    public function attemptsRemainingToday(int $userId): int
    {
        return max(0, (int) $this->activeGame()['free_attempt_limit'] - $this->currentDayAttempts($userId));
    }

    public function getState(int $userId, int $sessionId): array
    {
        $session = $this->sessionById($sessionId, $userId);
        if ($session === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Game session not found.', 404);
        }
        if (
            in_array($session['status'], ['started', 'in_progress'], true)
            && $session['expires_at'] <= $this->now()
        ) {
            throw new ApiException('GAME_SESSION_EXPIRED', 'This game session expired after inactivity.', 410);
        }
        return $this->state($session);
    }

    public function flip(int $userId, int $sessionId, int $cardIndex): array
    {
        $result = $this->transaction(function () use ($userId, $sessionId, $cardIndex): ?array {
            $session = $this->sessionById($sessionId, $userId, true);
            if ($session === null) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Game session not found.', 404);
            }
            if (!in_array($session['status'], ['started', 'in_progress'], true)) {
                throw new ApiException('GAME_SESSION_CLOSED', 'This game session is no longer active.', 409);
            }

            $nowObject = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $now = $nowObject->format('Y-m-d H:i:s');
            if ($session['expires_at'] <= $now) {
                $this->updateSessionStatus($sessionId, 'failed', $now);
                return null;
            }

            $state = $this->storedState($sessionId, true);
            $deck = $state['deck'];
            if ($cardIndex < 0 || $cardIndex >= count($deck)) {
                throw new ApiException('VALIDATION_ERROR', 'Card index is outside the game board.', 422);
            }

            $matchedCards = $state['matched_cards'];
            if (array_key_exists((string) $cardIndex, $matchedCards)) {
                throw new ApiException('CARD_ALREADY_MATCHED', 'A matched card cannot be flipped again.', 409);
            }

            $openCards = $state['open_cards'];
            $mismatchAt = $state['mismatch_at'];
            if ($mismatchAt !== null) {
                $revealUntil = (new DateTimeImmutable($mismatchAt, new DateTimeZone('UTC')))
                    ->modify('+' . self::MISMATCH_REVEAL_MILLISECONDS . ' milliseconds');
                if ($nowObject < $revealUntil) {
                    throw new ApiException(
                        'PAIR_REVEAL_PENDING',
                        'Wait for the unmatched cards to turn face down.',
                        409,
                        ['Retry-After' => '1'],
                    );
                }
                $openCards = [];
                $mismatchAt = null;
            }

            if (in_array($cardIndex, $openCards, true)) {
                throw new ApiException('CARD_ALREADY_REVEALED', 'This card is already face up.', 409);
            }
            if (count($openCards) >= 2) {
                throw new ApiException('INVALID_GAME_STATE', 'The game board is waiting for its next turn.', 409);
            }

            $openCards[] = $cardIndex;
            $moves = (int) $session['move_count'];
            $matches = (int) $session['matched_pairs'];
            $status = 'in_progress';
            $completedAt = null;
            if (count($openCards) === 2) {
                $moves++;
                [$first, $second] = $openCards;
                if ($deck[$first] === $deck[$second]) {
                    $matchedCards[(string) $first] = $deck[$first];
                    $matchedCards[(string) $second] = $deck[$second];
                    $openCards = [];
                    $matches++;
                    if ($matches === count($deck) / 2) {
                        $status = 'completed';
                        $completedAt = $now;
                    }
                } else {
                    $mismatchAt = $now;
                }
            }

            $expiresAt = $nowObject->modify('+' . self::SESSION_IDLE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
            $update = $this->db->prepare(
                'UPDATE game_sessions
                 SET status = :status, move_count = :move_count, matched_pairs = :matched_pairs,
                    last_activity_at = :last_activity_at, expires_at = :expires_at,
                    completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id AND status IN (\'started\', \'in_progress\')',
            );
            $update->execute([
                'status' => $status,
                'move_count' => $moves,
                'matched_pairs' => $matches,
                'last_activity_at' => $now,
                'updated_at' => $now,
                'expires_at' => $expiresAt,
                'completed_at' => $completedAt,
                'id' => $sessionId,
                'user_id' => $userId,
            ]);
            $storedMismatchAt = $mismatchAt === null ? null : $nowObject->format('Y-m-d H:i:s.v');
            $this->saveStoredState($sessionId, $matchedCards, $openCards, $storedMismatchAt);

            $updatedSession = $this->state($this->sessionById($sessionId, $userId));
            $result = ['session' => $updatedSession];
            if ($status === 'completed') {
                $result['reward'] = $this->rewards->awardCompletedWin($userId, self::GAME_SLUG, $sessionId);
            }
            return $result;
        });

        if ($result === null) {
            throw new ApiException('GAME_SESSION_EXPIRED', 'This game session expired after inactivity.', 410);
        }
        return $result;
    }

    public function hideMismatch(int $userId, int $sessionId): array
    {
        return $this->transaction(function () use ($userId, $sessionId): array {
            $session = $this->sessionById($sessionId, $userId, true);
            if ($session === null) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Game session not found.', 404);
            }
            if (!in_array($session['status'], ['started', 'in_progress'], true)) {
                throw new ApiException('GAME_SESSION_CLOSED', 'This game session is no longer active.', 409);
            }

            $nowObject = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $now = $nowObject->format('Y-m-d H:i:s');
            if ($session['expires_at'] <= $now) {
                $this->updateSessionStatus((int) $session['id'], 'failed', $now);
                return ['expired' => true];
            }

            $state = $this->storedState((int) $session['id'], true);
            if ($state['mismatch_at'] !== null) {
                $revealUntil = (new DateTimeImmutable($state['mismatch_at'], new DateTimeZone('UTC')))
                    ->modify('+' . self::MISMATCH_REVEAL_MILLISECONDS . ' milliseconds');
                if ($nowObject < $revealUntil) {
                    throw new ApiException(
                        'PAIR_REVEAL_PENDING',
                        'The unmatched cards must remain visible a little longer.',
                        409,
                        ['Retry-After' => '1'],
                    );
                }
                $this->saveStoredState((int) $session['id'], $state['matched_cards'], [], null);
                $expiresAt = $nowObject->modify('+' . self::SESSION_IDLE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
                $statement = $this->db->prepare(
                    'UPDATE game_sessions
                     SET last_activity_at = :last_activity_at, expires_at = :expires_at, updated_at = :updated_at
                     WHERE id = :id AND user_id = :user_id',
                );
                $statement->execute([
                    'last_activity_at' => $now,
                    'expires_at' => $expiresAt,
                    'updated_at' => $now,
                    'id' => $sessionId,
                    'user_id' => $userId,
                ]);
            }
            return ['session' => $this->state($this->sessionById($sessionId, $userId))];
        });
    }

    private function activeGame(): array
    {
        $statement = $this->db->prepare(
            'SELECT id, free_attempt_limit FROM game_catalog WHERE slug = :slug AND status = \'active\' LIMIT 1',
        );
        $statement->execute(['slug' => self::GAME_SLUG]);
        $game = $statement->fetch();
        if ($game === false) {
            throw new ApiException('GAME_NOT_AVAILABLE', 'Memory Match is currently unavailable.', 503);
        }
        $game['id'] = (int) $game['id'];
        $game['free_attempt_limit'] = (int) $game['free_attempt_limit'];
        return $game;
    }

    private function images(): array
    {
        $manifest = file_get_contents($this->manifestPath);
        if ($manifest === false) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game image list is unavailable.', 503);
        }
        try {
            $records = json_decode($manifest, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game image list is invalid.', 503);
        }
        if (!is_array($records)) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game image list is invalid.', 503);
        }

        $projectRoot = dirname(__DIR__, 2);
        $assetRoot = realpath($projectRoot . '/assets/hero-loot/Memory Match pic');
        if ($assetRoot === false) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The game image folder is unavailable.', 503);
        }
        $mediaSettings = (new OwnerDashboard($this->db))->siteSettings();
        $images = [];
        $seen = [];
        foreach ($records as $index => $record) {
            if (
                !is_int($index)
                || !is_array($record)
                || !isset($record['id'], $record['src'], $record['alt'])
                || !is_string($record['id'])
                || !preg_match('/\A[a-z0-9-]{1,60}\z/', $record['id'])
                || isset($seen[$record['id']])
                || !is_string($record['src'])
                || !str_starts_with($record['src'], 'assets/hero-loot/Memory Match pic/')
                || !is_string($record['alt'])
                || $record['alt'] === ''
                || strlen($record['alt']) > 200
            ) {
                continue;
            }

            $mediaKey = 'media_game_memory_match_' . sprintf('%02d', $index + 1);
            $customSource = $mediaSettings[$mediaKey]['value'] ?? null;
            if (is_string($customSource) && $customSource !== '') {
                if (
                    !str_starts_with($customSource, 'uploads/site/')
                    || str_contains($customSource, '..')
                    || str_contains($customSource, '\\')
                ) {
                    throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'A configured game image path is invalid.', 503);
                }
                $allowedRoot = realpath($projectRoot . '/uploads/site');
                $path = realpath($projectRoot . '/' . $customSource);
                if (
                    $allowedRoot === false
                    || $path === false
                    || !str_starts_with($path, $allowedRoot . DIRECTORY_SEPARATOR)
                    || !is_file($path)
                ) {
                    throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'A configured game image is unavailable.', 503);
                }
            } else {
                $path = realpath($projectRoot . '/' . $record['src']);
                if (
                    $path === false
                    || !str_starts_with($path, $assetRoot . DIRECTORY_SEPARATOR)
                    || !is_file($path)
                ) {
                    continue;
                }
            }
            $imageInfo = @getimagesize($path);
            if (
                $imageInfo === false
                || !in_array($imageInfo['mime'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
            ) {
                continue;
            }

            $relativeSource = substr($path, strlen($projectRoot) + 1);
            $source = implode('/', array_map('rawurlencode', explode('/', $relativeSource)));
            $images[] = ['id' => $record['id'], 'alt' => $record['alt'], 'src' => '/' . $source];
            $seen[$record['id']] = true;
        }

        if ($images === []) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'No supported game images are available.', 503);
        }
        return $images;
    }

    private function gameSettings(): array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, description, status, free_attempt_limit, reward_amount_coins, reset_timezone
             FROM game_catalog WHERE slug = :slug LIMIT 1',
        );
        $statement->execute(['slug' => self::GAME_SLUG]);
        $game = $statement->fetch();
        if ($game === false) {
            throw new ApiException('GAME_NOT_AVAILABLE', 'Memory Match is currently unavailable.', 503);
        }
        return $game;
    }

    private function gameId(): int
    {
        return (int) $this->activeGame()['id'];
    }

    private function upsertDailyAttempts(int $userId, int $gameId, string $date, string $resetAt, string $now): void
    {
        if ($this->driver() === 'mysql') {
            $sql = 'INSERT INTO user_game_daily_attempts
                        (user_id, game_id, attempt_date, free_attempts_used, paid_attempts_used,
                         free_attempts_reset_at, created_at, updated_at)
                        VALUES (:user_id, :game_id, :attempt_date, 0, 0, :reset_at, :created_at, :updated_at)
                    ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)';
        } else {
            $sql = 'INSERT OR IGNORE INTO user_game_daily_attempts
                        (user_id, game_id, attempt_date, free_attempts_used, paid_attempts_used,
                         free_attempts_reset_at, created_at, updated_at)
                        VALUES (:user_id, :game_id, :attempt_date, 0, 0, :reset_at, :created_at, :updated_at)';
        }
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'user_id' => $userId,
            'game_id' => $gameId,
            'attempt_date' => $date,
            'reset_at' => $resetAt,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function dailyAttempts(int $userId, int $gameId, string $date, bool $lock): array
    {
        $statement = $this->db->prepare(
            'SELECT id, free_attempts_used FROM user_game_daily_attempts
             WHERE user_id = :user_id AND game_id = :game_id AND attempt_date = :attempt_date'
            . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $gameId, 'attempt_date' => $date]);
        $daily = $statement->fetch();
        if ($daily === false) {
            throw new RuntimeException('Daily game attempt row was not created.');
        }
        return $daily;
    }

    private function setDailyAttemptsUsed(int $userId, int $gameId, string $date, int $used, string $now): void
    {
        $statement = $this->db->prepare(
            'UPDATE user_game_daily_attempts
             SET free_attempts_used = :used, updated_at = :now
             WHERE user_id = :user_id AND game_id = :game_id AND attempt_date = :attempt_date',
        );
        $statement->execute([
            'used' => $used,
            'now' => $now,
            'user_id' => $userId,
            'game_id' => $gameId,
            'attempt_date' => $date,
        ]);
    }

    private function currentDayAttempts(int $userId): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $date = $now->setTimezone(new DateTimeZone('Africa/Cairo'))->format('Y-m-d');
        $statement = $this->db->prepare(
            'SELECT free_attempts_used FROM user_game_daily_attempts
             WHERE user_id = :user_id AND game_id = :game_id AND attempt_date = :attempt_date',
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $this->gameId(), 'attempt_date' => $date]);
        return (int) ($statement->fetchColumn() ?: 0);
    }

    private function activeSession(int $userId, int $gameId, string $now, bool $lock): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, game_id, status, move_count, matched_pairs, started_at,
                    last_activity_at, expires_at, completed_at
             FROM game_sessions
             WHERE user_id = :user_id AND game_id = :game_id
               AND status IN (\'started\', \'in_progress\') AND expires_at > :now
             ORDER BY id DESC LIMIT 1' . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $gameId, 'now' => $now]);
        $session = $statement->fetch();
        return $session === false ? null : $session;
    }

    private function failExpiredSessions(int $userId, int $gameId, string $now): void
    {
        $statement = $this->db->prepare(
            'UPDATE game_sessions SET status = \'failed\', updated_at = :updated_at
             WHERE user_id = :user_id AND game_id = :game_id
               AND status IN (\'started\', \'in_progress\') AND expires_at <= :now',
        );
        $statement->execute([
            'updated_at' => $now,
            'now' => $now,
            'user_id' => $userId,
            'game_id' => $gameId,
        ]);
    }

    private function sessionByIdempotency(int $userId, string $key): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, game_id, status, move_count, matched_pairs, started_at,
                    last_activity_at, expires_at, completed_at
             FROM game_sessions WHERE user_id = :user_id AND idempotency_key = :idempotency_key LIMIT 1',
        );
        $statement->execute(['user_id' => $userId, 'idempotency_key' => $key]);
        $session = $statement->fetch();
        return $session === false ? null : $session;
    }

    private function sessionById(int $sessionId, int $userId, bool $lock = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, game_id, status, move_count, matched_pairs, started_at,
                    last_activity_at, expires_at, completed_at
             FROM game_sessions WHERE id = :id AND user_id = :user_id LIMIT 1' . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['id' => $sessionId, 'user_id' => $userId]);
        $session = $statement->fetch();
        return $session === false ? null : $session;
    }

    private function storedState(int $sessionId, bool $lock = false): array
    {
        $statement = $this->db->prepare(
            'SELECT deck_json, matched_cards_json, open_cards_json, mismatch_at
             FROM memory_match_states WHERE session_id = :session_id' . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['session_id' => $sessionId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new RuntimeException('Memory Match session state is missing.');
        }
        return [
            'deck' => json_decode($row['deck_json'], true, 16, JSON_THROW_ON_ERROR),
            'matched_cards' => json_decode($row['matched_cards_json'], true, 16, JSON_THROW_ON_ERROR),
            'open_cards' => json_decode($row['open_cards_json'], true, 16, JSON_THROW_ON_ERROR),
            'mismatch_at' => $row['mismatch_at'],
        ];
    }

    private function saveStoredState(int $sessionId, array $matchedCards, array $openCards, ?string $mismatchAt): void
    {
        $statement = $this->db->prepare(
            'UPDATE memory_match_states
             SET matched_cards_json = :matched_cards, open_cards_json = :open_cards, mismatch_at = :mismatch_at
             WHERE session_id = :session_id',
        );
        $statement->execute([
            'matched_cards' => json_encode((object) $matchedCards, JSON_THROW_ON_ERROR),
            'open_cards' => json_encode(array_values($openCards), JSON_THROW_ON_ERROR),
            'mismatch_at' => $mismatchAt,
            'session_id' => $sessionId,
        ]);
    }

    private function state(?array $session): array
    {
        if ($session === null) {
            throw new RuntimeException('Memory Match session disappeared during an update.');
        }
        $stored = $this->storedState((int) $session['id']);
        $imageMap = [];
        foreach ($this->images() as $image) {
            $imageMap[$image['id']] = $image;
        }

        $matched = [];
        foreach ($stored['matched_cards'] as $index => $imageId) {
            if (isset($imageMap[$imageId])) {
                $matched[] = ['index' => (int) $index, 'image' => $imageMap[$imageId]];
            }
        }
        $revealed = [];
        foreach ($stored['open_cards'] as $index) {
            $imageId = $stored['deck'][$index] ?? null;
            if (is_string($imageId) && isset($imageMap[$imageId])) {
                $revealed[] = ['index' => (int) $index, 'image' => $imageMap[$imageId]];
            }
        }
        $waitMilliseconds = 0;
        if ($stored['mismatch_at'] !== null) {
            $revealUntil = (new DateTimeImmutable($stored['mismatch_at'], new DateTimeZone('UTC')))
                ->modify('+' . self::MISMATCH_REVEAL_MILLISECONDS . ' milliseconds');
            $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $waitMilliseconds = max(0, (int) ceil(
                ((float) $revealUntil->format('U.u') - (float) $now->format('U.u')) * 1000,
            ));
        }

        return [
            'id' => (int) $session['id'],
            'status' => $session['status'],
            'attempt_type' => 'free',
            'cards_total' => count($stored['deck']),
            'pairs_total' => (int) (count($stored['deck']) / 2),
            'matched_pairs' => (int) $session['matched_pairs'],
            'move_count' => (int) $session['move_count'],
            'matched_cards' => $matched,
            'revealed_cards' => $revealed,
            'mismatch_wait_ms' => $waitMilliseconds,
            'started_at' => $session['started_at'],
            'expires_at' => $session['expires_at'],
            'completed_at' => $session['completed_at'],
        ];
    }

    private function updateSessionStatus(int $sessionId, string $status, string $now): void
    {
        $statement = $this->db->prepare(
            'UPDATE game_sessions SET status = :status, updated_at = :now WHERE id = :id',
        );
        $statement->execute(['status' => $status, 'now' => $now, 'id' => $sessionId]);
    }

    private function secureShuffle(array &$values): void
    {
        for ($index = count($values) - 1; $index > 0; $index--) {
            $other = random_int(0, $index);
            [$values[$index], $values[$other]] = [$values[$other], $values[$index]];
        }
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

    private function lockClause(): string
    {
        return $this->driver() === 'mysql' ? ' FOR UPDATE' : '';
    }

    private function driver(): string
    {
        return (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}
