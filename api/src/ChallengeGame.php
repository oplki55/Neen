<?php
declare(strict_types=1);

abstract class ChallengeGame
{
    private const SESSION_IDLE_MINUTES = 30;

    public function __construct(
        protected readonly PDO $db,
        protected readonly GameRewards $rewards,
    ) {
    }

    abstract protected function slug(): string;

    abstract protected function initializeState(int $sessionId): void;

    abstract protected function publicGameState(int $sessionId): array;

    public function catalog(): array
    {
        $game = $this->gameSettings();
        return [
            'slug' => $this->slug(),
            'name' => (string) $game['name'],
            'description' => (string) ($game['description'] ?? ''),
            'status' => (string) $game['status'],
            'free_attempts_per_day' => (int) $game['free_attempt_limit'],
            'reward_amount_coins' => (int) $game['reward_amount_coins'],
            'reset_timezone' => (string) $game['reset_timezone'],
        ];
    }

    public function start(int $userId, string $idempotencyKey): array
    {
        if ($userId < 1 || !preg_match('/\A[A-Za-z0-9._:-]{8,100}\z/', $idempotencyKey)) {
            throw new ApiException('VALIDATION_ERROR', 'A valid game start request is required.', 422);
        }

        $result = $this->transaction(function () use ($userId, $idempotencyKey): array {
            $game = $this->activeGame();
            $nowObject = new DateTimeImmutable('now', new DateTimeZone('UTC'));
            $now = $nowObject->format('Y-m-d H:i:s');
            $attemptDate = $nowObject->setTimezone(new DateTimeZone('Africa/Cairo'))->format('Y-m-d');
            $resetAt = $nowObject->setTimezone(new DateTimeZone('Africa/Cairo'))
                ->modify('tomorrow')->setTime(0, 0)->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
            $storedKey = $this->slug() . '-' . substr(hash('sha256', $idempotencyKey), 0, 64);

            $this->lockActiveUser($userId);
            $existing = $this->sessionByIdempotency($userId, $storedKey);
            if ($existing !== null) {
                if ((int) $existing['game_id'] !== (int) $game['id']) {
                    throw new ApiException('DUPLICATE_REQUEST', 'This start key was already used.', 409);
                }
                return ['created' => false, 'session_id' => (int) $existing['id']];
            }

            $this->upsertDailyAttempts($userId, (int) $game['id'], $attemptDate, $resetAt, $now);
            $daily = $this->dailyAttempts($userId, (int) $game['id'], $attemptDate, true);
            $this->failExpiredSessions($userId, (int) $game['id'], $now);
            $active = $this->activeSession($userId, (int) $game['id'], $now);
            if ($active !== null) {
                return ['created' => false, 'session_id' => (int) $active['id']];
            }
            if ((int) $daily['free_attempts_used'] >= (int) $game['free_attempt_limit']) {
                throw new ApiException(
                    'ATTEMPT_LIMIT_EXCEEDED',
                    'All free attempts for this game today have been used.',
                    429,
                );
            }

            $expiresAt = $nowObject->modify('+' . self::SESSION_IDLE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
            $statement = $this->db->prepare(
                'INSERT INTO game_sessions
                    (user_id, game_id, attempt_type, status, move_count, matched_pairs,
                     idempotency_key, started_at, last_activity_at, expires_at, created_at, updated_at)
                 VALUES
                    (:user_id, :game_id, \'free\', \'started\', 0, 0, :idempotency_key,
                     :started_at, :last_activity_at, :expires_at, :created_at, :updated_at)',
            );
            $statement->execute([
                'user_id' => $userId,
                'game_id' => $game['id'],
                'idempotency_key' => $storedKey,
                'started_at' => $now,
                'last_activity_at' => $now,
                'expires_at' => $expiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $sessionId = (int) $this->db->lastInsertId();
            $this->initializeState($sessionId);
            $this->setDailyAttemptsUsed(
                $userId,
                (int) $game['id'],
                $attemptDate,
                (int) $daily['free_attempts_used'] + 1,
                $now,
            );
            return ['created' => true, 'session_id' => $sessionId];
        });

        $game = $this->activeGame();
        return [
            'created' => $result['created'],
            'session' => $this->getState($userId, $result['session_id']),
            'attempts_remaining_today' => $this->attemptsRemainingToday($userId),
            'reward_amount_coins' => (int) $game['reward_amount_coins'],
        ];
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
        $statement->execute(['user_id' => $userId, 'game_id' => $this->gameId(), 'now' => $now]);
        $sessionId = $statement->fetchColumn();
        return $sessionId === false ? null : $this->getState($userId, (int) $sessionId);
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
        return $this->present($session);
    }

    public function attemptsRemainingToday(int $userId): int
    {
        $game = $this->activeGame();
        $date = (new DateTimeImmutable('now', new DateTimeZone('Africa/Cairo')))->format('Y-m-d');
        $statement = $this->db->prepare(
            'SELECT free_attempts_used FROM user_game_daily_attempts
             WHERE user_id = :user_id AND game_id = :game_id AND attempt_date = :attempt_date',
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $game['id'], 'attempt_date' => $date]);
        return max(0, (int) $game['free_attempt_limit'] - (int) ($statement->fetchColumn() ?: 0));
    }

    protected function lockedSession(int $userId, int $sessionId): array
    {
        $session = $this->sessionById($sessionId, $userId, true);
        if ($session === null) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Game session not found.', 404);
        }
        if (!in_array($session['status'], ['started', 'in_progress'], true)) {
            throw new ApiException('GAME_SESSION_CLOSED', 'This game session is no longer active.', 409);
        }
        $now = $this->now();
        if ($session['expires_at'] <= $now) {
            $this->updateSession($sessionId, 'failed', (int) $session['move_count'], null, $now);
            throw new ApiException('GAME_SESSION_EXPIRED', 'This game session expired after inactivity.', 410);
        }
        return $session;
    }

    protected function updateSession(
        int $sessionId,
        string $status,
        int $moveCount,
        ?string $completedAt,
        string $now,
    ): void {
        $expiresAt = (new DateTimeImmutable($now, new DateTimeZone('UTC')))
            ->modify('+' . self::SESSION_IDLE_MINUTES . ' minutes')->format('Y-m-d H:i:s');
        $statement = $this->db->prepare(
            'UPDATE game_sessions SET status = :status, move_count = :move_count,
                last_activity_at = :last_activity_at, expires_at = :expires_at,
                completed_at = :completed_at, updated_at = :updated_at
             WHERE id = :id',
        );
        $statement->execute([
            'status' => $status,
            'move_count' => $moveCount,
            'last_activity_at' => $now,
            'expires_at' => $expiresAt,
            'completed_at' => $completedAt,
            'updated_at' => $now,
            'id' => $sessionId,
        ]);
    }

    protected function finish(int $userId, int $sessionId, int $moveCount, string $now): array
    {
        $this->updateSession($sessionId, 'completed', $moveCount, $now, $now);
        return [
            'session' => $this->getState($userId, $sessionId),
            'reward' => $this->rewards->awardCompletedWin($userId, $this->slug(), $sessionId),
        ];
    }

    protected function transaction(callable $operation): mixed
    {
        $startedHere = !$this->db->inTransaction();
        if ($startedHere) {
            $this->db->beginTransaction();
        }
        try {
            $result = $operation();
            if ($startedHere) {
                $this->db->commit();
            }
            return $result;
        } catch (Throwable $exception) {
            if ($startedHere && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $exception;
        }
    }

    protected function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }

    protected function lockClause(): string
    {
        return $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
    }

    protected function jsonArray(string $json): array
    {
        try {
            $value = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_STATE_INVALID', 'The server game state is invalid.', 409);
        }
        if (!is_array($value) || !array_is_list($value)) {
            throw new ApiException('GAME_STATE_INVALID', 'The server game state is invalid.', 409);
        }
        return $value;
    }

    private function present(array $session): array
    {
        return [
            'id' => (int) $session['id'],
            'status' => (string) $session['status'],
            'move_count' => (int) $session['move_count'],
            'started_at' => (string) $session['started_at'],
            'expires_at' => (string) $session['expires_at'],
            'completed_at' => $session['completed_at'],
        ] + $this->publicGameState((int) $session['id']);
    }

    private function gameSettings(): array
    {
        $statement = $this->db->prepare(
            'SELECT id, name, description, status, free_attempt_limit, reward_amount_coins, reset_timezone
             FROM game_catalog WHERE slug = :slug LIMIT 1',
        );
        $statement->execute(['slug' => $this->slug()]);
        $game = $statement->fetch();
        if ($game === false) {
            throw new ApiException('GAME_NOT_AVAILABLE', 'This game is currently unavailable.', 503);
        }
        return $game;
    }

    private function activeGame(): array
    {
        $game = $this->gameSettings();
        if ($game['status'] !== 'active') {
            throw new ApiException('GAME_NOT_AVAILABLE', 'This game is currently unavailable.', 503);
        }
        return $game;
    }

    private function gameId(): int
    {
        return (int) $this->activeGame()['id'];
    }

    private function sessionByIdempotency(int $userId, string $key): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id, game_id FROM game_sessions
             WHERE user_id = :user_id AND idempotency_key = :idempotency_key LIMIT 1',
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

    private function activeSession(int $userId, int $gameId, string $now): ?array
    {
        $statement = $this->db->prepare(
            'SELECT id FROM game_sessions
             WHERE user_id = :user_id AND game_id = :game_id
               AND status IN (\'started\', \'in_progress\') AND expires_at > :now
             ORDER BY id DESC LIMIT 1' . $this->lockClause(),
        );
        $statement->execute(['user_id' => $userId, 'game_id' => $gameId, 'now' => $now]);
        $sessionId = $statement->fetchColumn();
        return $sessionId === false ? null : $this->sessionById((int) $sessionId, $userId, true);
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

    private function upsertDailyAttempts(int $userId, int $gameId, string $date, string $resetAt, string $now): void
    {
        if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
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

    private function lockActiveUser(int $userId): void
    {
        $statement = $this->db->prepare(
            "SELECT id FROM users WHERE id = :user_id AND status = 'active'" . $this->lockClause(),
        );
        $statement->execute(['user_id' => $userId]);
        if ($statement->fetchColumn() === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Account not found.', 404);
        }
    }
}
