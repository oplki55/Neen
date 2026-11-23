<?php
declare(strict_types=1);

final class PictureJigsaw
{
    private const GAME_SLUG = 'picture-jigsaw';
    private const BOARD_SIZE = 3;
    private const SESSION_IDLE_MINUTES = 30;

    public function __construct(
        private readonly PDO $db,
        private readonly string $manifestPath,
        private readonly GameRewards $rewards,
    ) {
    }

    public function catalog(): array
    {
        $game = $this->gameSettings();
        return [
            'slug' => self::GAME_SLUG,
            'name' => $game['name'],
            'description' => $game['description'],
            'status' => $game['status'],
            'board_size' => self::BOARD_SIZE,
            'piece_count' => self::BOARD_SIZE ** 2,
            'free_attempts_per_day' => (int) $game['free_attempt_limit'],
            'reward_amount_coins' => (int) $game['reward_amount_coins'],
            'reset_timezone' => $game['reset_timezone'],
            'images' => $this->images(),
        ];
    }

    public function start(int $userId, string $imageId, string $idempotencyKey): array
    {
        if (!preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $idempotencyKey)) {
            throw new ApiException('VALIDATION_ERROR', 'A valid idempotency key is required.', 422);
        }
        $selectedImage = $this->imageById($imageId);

        $utcNow = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $now = $utcNow->format('Y-m-d H:i:s');
        $expiresAt = $utcNow->modify('+' . self::SESSION_IDLE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $cairoNow = $utcNow->setTimezone(new DateTimeZone('Africa/Cairo'));
        $attemptDate = $cairoNow->format('Y-m-d');
        $resetAt = $cairoNow->modify('tomorrow')->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d H:i:s');

        $result = $this->transaction(function () use (
            $userId,
            $imageId,
            $selectedImage,
            $idempotencyKey,
            $now,
            $expiresAt,
            $attemptDate,
            $resetAt,
        ): array {
            $game = $this->activeGame();
            $replayed = $this->sessionByIdempotency($userId, $idempotencyKey);
            if ($replayed !== null) {
                $replayedState = $this->state($replayed);
                if ($replayedState['image']['id'] !== $imageId) {
                    throw new ApiException(
                        'IDEMPOTENCY_CONFLICT',
                        'This idempotency key was already used for a different picture.',
                        409,
                    );
                }
                return ['created' => false, 'session' => $replayedState];
            }

            $this->upsertDailyAttempts($userId, (int) $game['id'], $attemptDate, $resetAt, $now);
            $daily = $this->dailyAttempts($userId, (int) $game['id'], $attemptDate, true);
            $this->failExpiredSessions($userId, (int) $game['id'], $now);

            $active = $this->activeSession($userId, (int) $game['id'], $now, true);
            if ($active !== null) {
                return ['created' => false, 'session' => $this->state($active)];
            }

            if ((int) $daily['free_attempts_used'] >= (int) $game['free_attempt_limit']) {
                throw new ApiException(
                    'ATTEMPT_LIMIT_EXCEEDED',
                    'All free Picture Jigsaw starts for today have been used.',
                    429,
                );
            }

            $permutation = range(0, self::BOARD_SIZE ** 2 - 1);
            $this->secureShuffle($permutation);
            if ($permutation === range(0, self::BOARD_SIZE ** 2 - 1)) {
                $permutation = [1, 0, ...array_slice($permutation, 2)];
            }

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

            $stateInsert = $this->db->prepare(
                'INSERT INTO picture_jigsaw_states (session_id, image_id, permutation_json, board_size)
                 VALUES (:session_id, :image_id, :permutation_json, :board_size)',
            );
            $stateInsert->execute([
                'session_id' => $sessionId,
                'image_id' => $imageId,
                'permutation_json' => json_encode($permutation, JSON_THROW_ON_ERROR),
                'board_size' => self::BOARD_SIZE,
            ]);
            $this->setDailyAttemptsUsed(
                $userId,
                (int) $game['id'],
                $attemptDate,
                (int) $daily['free_attempts_used'] + 1,
                $now,
            );

            return [
                'created' => true,
                'session' => $this->state($this->sessionById($sessionId, $userId), $selectedImage),
            ];
        });

        $result['attempts_remaining_today'] = $this->attemptsRemainingToday($userId);
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

    public function swap(int $userId, int $sessionId, int $fromIndex, int $toIndex): array
    {
        $result = $this->transaction(function () use ($userId, $sessionId, $fromIndex, $toIndex): array {
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
                return ['expired' => true];
            }
            $pieceCount = self::BOARD_SIZE ** 2;
            if (
                $fromIndex < 0
                || $fromIndex >= $pieceCount
                || $toIndex < 0
                || $toIndex >= $pieceCount
                || $fromIndex === $toIndex
            ) {
                throw new ApiException('VALIDATION_ERROR', 'Choose two different positions on the puzzle board.', 422);
            }

            $stored = $this->storedState($sessionId, true);
            $permutation = $stored['permutation'];
            [$permutation[$fromIndex], $permutation[$toIndex]] = [$permutation[$toIndex], $permutation[$fromIndex]];

            $complete = $permutation === range(0, $pieceCount - 1);
            $status = $complete ? 'completed' : 'in_progress';
            $expiresAt = $nowObject->modify('+' . self::SESSION_IDLE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
            $update = $this->db->prepare(
                'UPDATE game_sessions
                 SET status = :status, move_count = move_count + 1,
                     last_activity_at = :last_activity_at, expires_at = :expires_at,
                     completed_at = :completed_at, updated_at = :updated_at
                 WHERE id = :id AND user_id = :user_id AND status IN (\'started\', \'in_progress\')',
            );
            $update->execute([
                'status' => $status,
                'last_activity_at' => $now,
                'expires_at' => $expiresAt,
                'completed_at' => $complete ? $now : null,
                'updated_at' => $now,
                'id' => $sessionId,
                'user_id' => $userId,
            ]);

            $save = $this->db->prepare(
                'UPDATE picture_jigsaw_states SET permutation_json = :permutation_json WHERE session_id = :session_id',
            );
            $save->execute([
                'permutation_json' => json_encode($permutation, JSON_THROW_ON_ERROR),
                'session_id' => $sessionId,
            ]);

            $updatedSession = $this->state($this->sessionById($sessionId, $userId));
            $result = ['session' => $updatedSession];
            if ($complete) {
                $result['reward'] = $this->rewards->awardCompletedWin($userId, self::GAME_SLUG, $sessionId);
            }
            return $result;
        });
        if (($result['expired'] ?? false) === true) {
            throw new ApiException('GAME_SESSION_EXPIRED', 'This game session expired after inactivity.', 410);
        }
        return $result;
    }

    public function abandon(int $userId, int $sessionId): void
    {
        $this->transaction(function () use ($userId, $sessionId): void {
            $session = $this->sessionById($sessionId, $userId, true);
            if ($session === null) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Game session not found.', 404);
            }
            if (!in_array($session['status'], ['started', 'in_progress'], true)) {
                throw new ApiException('GAME_SESSION_CLOSED', 'This game session is no longer active.', 409);
            }
            $this->updateSessionStatus($sessionId, 'cancelled', $this->now());
        });
    }

    private function images(): array
    {
        $manifest = file_get_contents($this->manifestPath);
        if ($manifest === false) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The Picture Jigsaw image list is unavailable.', 503);
        }
        try {
            $records = json_decode($manifest, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The Picture Jigsaw image list is invalid.', 503);
        }
        if (!is_array($records)) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The Picture Jigsaw image list is invalid.', 503);
        }

        $projectRoot = dirname(__DIR__, 2);
        $assetRoot = realpath($projectRoot . '/assets/Picture Jigsaw pic');
        if ($assetRoot === false) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'The Picture Jigsaw image folder is unavailable.', 503);
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
                || !str_starts_with($record['src'], 'assets/Picture Jigsaw pic/')
                || !is_string($record['alt'])
                || $record['alt'] === ''
                || strlen($record['alt']) > 200
            ) {
                continue;
            }

            $mediaKey = 'media_game_picture_jigsaw_' . sprintf('%02d', $index + 1);
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
            $images[] = [
                'id' => $record['id'],
                'alt' => $record['alt'],
                'src' => '/' . $source,
                'width' => (int) $imageInfo[0],
                'height' => (int) $imageInfo[1],
            ];
            $seen[$record['id']] = true;
        }
        if (count($images) !== 3) {
            throw new ApiException('GAME_ASSETS_UNAVAILABLE', 'All three approved Picture Jigsaw images must be available.', 503);
        }
        return $images;
    }

    private function imageById(string $imageId): array
    {
        foreach ($this->images() as $image) {
            if ($image['id'] === $imageId) {
                return $image;
            }
        }
        throw new ApiException('VALIDATION_ERROR', 'Choose one of the approved Picture Jigsaw images.', 422);
    }

    private function state(?array $session, ?array $image = null): array
    {
        if ($session === null) {
            throw new RuntimeException('Picture Jigsaw session disappeared during an update.');
        }
        $stored = $this->storedState((int) $session['id']);
        return [
            'id' => (int) $session['id'],
            'status' => $session['status'],
            'attempt_type' => 'free',
            'image' => $image ?? $this->imageById($stored['image_id']),
            'board_size' => $stored['board_size'],
            'piece_count' => $stored['board_size'] ** 2,
            'permutation' => $stored['permutation'],
            'move_count' => (int) $session['move_count'],
            'started_at' => $session['started_at'],
            'expires_at' => $session['expires_at'],
            'completed_at' => $session['completed_at'],
        ];
    }

    private function activeGame(): array
    {
        $statement = $this->db->prepare(
            'SELECT id, free_attempt_limit FROM game_catalog WHERE slug = :slug AND status = \'active\' LIMIT 1',
        );
        $statement->execute(['slug' => self::GAME_SLUG]);
        $game = $statement->fetch();
        if ($game === false) {
            throw new ApiException('GAME_NOT_AVAILABLE', 'Picture Jigsaw is currently unavailable.', 503);
        }
        $game['id'] = (int) $game['id'];
        $game['free_attempt_limit'] = (int) $game['free_attempt_limit'];
        return $game;
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
            throw new ApiException('GAME_NOT_AVAILABLE', 'Picture Jigsaw is currently unavailable.', 503);
        }
        return $game;
    }

    private function gameId(): int
    {
        return (int) $this->activeGame()['id'];
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

    private function dailyAttempts(int $userId, int $gameId, string $date): array
    {
        $statement = $this->db->prepare(
            'SELECT free_attempts_used FROM user_game_daily_attempts
             WHERE user_id = :user_id AND game_id = :game_id AND attempt_date = :attempt_date'
            . $this->lockClause(),
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $gameId, 'attempt_date' => $date]);
        $daily = $statement->fetch();
        if ($daily === false) {
            throw new RuntimeException('Daily Picture Jigsaw attempt row was not created.');
        }
        return $daily;
    }

    private function setDailyAttemptsUsed(int $userId, int $gameId, string $date, int $used, string $now): void
    {
        $statement = $this->db->prepare(
            'UPDATE user_game_daily_attempts SET free_attempts_used = :used, updated_at = :now
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

    private function activeSession(int $userId, int $gameId, string $now, bool $lock = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, game_id, status, move_count, started_at, expires_at, completed_at
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
            'SELECT id, user_id, game_id, status, move_count, started_at, expires_at, completed_at
             FROM game_sessions WHERE user_id = :user_id AND idempotency_key = :idempotency_key LIMIT 1',
        );
        $statement->execute(['user_id' => $userId, 'idempotency_key' => $key]);
        $session = $statement->fetch();
        return $session === false ? null : $session;
    }

    private function sessionById(int $sessionId, int $userId, bool $lock = false): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, user_id, game_id, status, move_count, started_at, expires_at, completed_at
             FROM game_sessions WHERE id = :id AND user_id = :user_id LIMIT 1'
            . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['id' => $sessionId, 'user_id' => $userId]);
        $session = $statement->fetch();
        return $session === false ? null : $session;
    }

    private function storedState(int $sessionId, bool $lock = false): array
    {
        $statement = $this->db->prepare(
            'SELECT image_id, permutation_json, board_size
             FROM picture_jigsaw_states WHERE session_id = :session_id' . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new RuntimeException('Picture Jigsaw session state is missing.');
        }
        $permutation = json_decode($state['permutation_json'], true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($permutation) || count($permutation) !== (int) $state['board_size'] ** 2) {
            throw new RuntimeException('Picture Jigsaw session state is invalid.');
        }
        $sorted = array_values($permutation);
        sort($sorted);
        if ($sorted !== range(0, count($permutation) - 1)) {
            throw new RuntimeException('Picture Jigsaw session state is invalid.');
        }
        return [
            'image_id' => $state['image_id'],
            'board_size' => (int) $state['board_size'],
            'permutation' => array_map('intval', $permutation),
        ];
    }

    private function updateSessionStatus(int $sessionId, string $status, string $now): void
    {
        $statement = $this->db->prepare(
            'UPDATE game_sessions SET status = :status, updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute(['status' => $status, 'updated_at' => $now, 'id' => $sessionId]);
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
