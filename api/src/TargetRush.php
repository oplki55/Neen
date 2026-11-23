<?php
declare(strict_types=1);

final class TargetRush extends ChallengeGame
{
    private const GAME_SLUG = 'vault_cracker';
    private const ROUND_DURATION_MS = 30_000;
    private const TARGET_INTERVAL_MS = 1_100;
    private const TARGET_COUNT = 28;
    private const LOOKAHEAD_TARGET_COUNT = 6;
    private const NETWORK_GRACE_MS = 300;
    private const SCORE_TO_WIN = 200;
    private const TARGET_TYPES = ['normal', 'gold', 'danger'];
    private const POSITIONS = [
        'upper-left',
        'upper-right',
        'middle-left',
        'middle-right',
        'lower-left',
        'lower-right',
    ];
    private const POSITIONS_BY_TYPE = [
        'normal' => ['upper-left', 'middle-left'],
        'gold' => ['upper-right', 'middle-right', 'lower-left'],
        'danger' => ['lower-right'],
    ];

    protected function slug(): string
    {
        return self::GAME_SLUG;
    }

    public function catalog(): array
    {
        return array_merge(parent::catalog(), [
            'slug' => 'target-rush',
            'name' => 'NEEN Target Rush',
            'description' => 'Hit targets for 30 seconds; gold targets add bonus points and danger targets reduce your score.',
            'round_duration_seconds' => self::ROUND_DURATION_MS / 1000,
            'score_to_win' => self::SCORE_TO_WIN,
        ]);
    }

    public function current(int $userId): ?array
    {
        $this->retireExpiredTargetRushRounds($userId);
        $now = $this->now();
        $statement = $this->db->prepare(
            'SELECT s.id FROM game_sessions s
             JOIN game_catalog g ON g.id = s.game_id
             WHERE s.user_id = :user_id AND g.slug = :slug
               AND (
                    (s.status IN (\'started\', \'in_progress\') AND s.expires_at > :now)
               )
               ORDER BY s.id DESC',
        );
        $statement->execute(['user_id' => $userId, 'slug' => self::GAME_SLUG, 'now' => $now]);
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sessionId) {
            if ($this->isTargetRushState((int) $sessionId)) {
                return $this->getState($userId, (int) $sessionId);
            }
        }
        return null;
    }

    public function start(int $userId, string $idempotencyKey): array
    {
        $this->retireLegacyActiveSessions($userId);
        $this->retireExpiredTargetRushRounds($userId);
        return parent::start($userId, $idempotencyKey);
    }

    protected function initializeState(int $sessionId): void
    {
        $targets = [];
        for ($index = 0; $index < self::TARGET_COUNT; $index++) {
            $roll = random_int(1, 100);
            $type = $roll <= 55 ? 'normal' : ($roll <= 80 ? 'gold' : 'danger');
            $targets[] = [
                'token' => bin2hex(random_bytes(16)),
                'type' => $type,
                'position' => self::POSITIONS_BY_TYPE[$type][
                    random_int(0, count(self::POSITIONS_BY_TYPE[$type]) - 1)
                ],
            ];
        }
        $state = [
            'started_at_ms' => $this->nowMilliseconds(),
            'finished_at_ms' => null,
            'hits' => [],
        ];
        $statement = $this->db->prepare(
            'INSERT INTO vault_cracker_states (session_id, secret_json, guesses_json)
             VALUES (:session_id, :targets_json, :state_json)',
        );
        $statement->execute([
            'session_id' => $sessionId,
            'targets_json' => json_encode($targets, JSON_THROW_ON_ERROR),
            'state_json' => json_encode($state, JSON_THROW_ON_ERROR),
        ]);
    }

    protected function publicGameState(int $sessionId): array
    {
        $stored = $this->storedState($sessionId);
        $now = $this->nowMilliseconds();
        $elapsed = max(0, $now - $stored['state']['started_at_ms']);
        $index = intdiv($elapsed, self::TARGET_INTERVAL_MS);
        $activeTarget = null;
        if ($elapsed < self::ROUND_DURATION_MS && $index < self::TARGET_COUNT) {
            $alreadyHit = false;
            foreach ($stored['state']['hits'] as $hit) {
                if ($hit['index'] === $index) {
                    $alreadyHit = true;
                    break;
                }
            }
            if (!$alreadyHit) {
                $activeTarget = ['index' => $index] + $stored['targets'][$index];
            }
        }
        $lookaheadTargets = [];
        $hitIndexes = [];
        foreach ($stored['state']['hits'] as $hit) {
            $hitIndexes[$hit['index']] = true;
        }
        for ($targetIndex = $index; $targetIndex < min(
            self::TARGET_COUNT,
            $index + self::LOOKAHEAD_TARGET_COUNT,
        ); $targetIndex++) {
            if (isset($hitIndexes[$targetIndex])) {
                continue;
            }
            $target = $stored['targets'][$targetIndex];
            $lookaheadTargets[] = [
                'index' => $targetIndex,
                'starts_at_ms' => $targetIndex * self::TARGET_INTERVAL_MS,
                'ends_at_ms' => min(
                    ($targetIndex + 1) * self::TARGET_INTERVAL_MS,
                    self::ROUND_DURATION_MS,
                ),
                'type' => $target['type'],
                'position' => $target['position'],
                'token' => $target['token'],
            ];
        }
        $stats = $this->scoreHits($stored['targets'], $stored['state']['hits']);
        return [
            'score' => $stats['score'],
            'combo' => $stats['combo'],
            'best_combo' => $stats['best_combo'],
            'hits' => count($stored['state']['hits']),
            'won' => $stored['state']['finished_at_ms'] !== null
                && $stats['score'] >= self::SCORE_TO_WIN,
            'round_duration_ms' => self::ROUND_DURATION_MS,
            'time_remaining_ms' => max(0, self::ROUND_DURATION_MS - $elapsed),
            'server_time_ms' => $now,
            'server_elapsed_ms' => $elapsed,
            'next_target_at_ms' => $stored['state']['started_at_ms']
                + ($index + 1) * self::TARGET_INTERVAL_MS,
            'active_target' => $activeTarget,
            'lookahead_targets' => $lookaheadTargets,
        ];
    }

    public function hit(int $userId, int $sessionId, mixed $token): array
    {
        if (!is_string($token) || !preg_match('/\A[a-f0-9]{32}\z/', $token)) {
            throw new ApiException('VALIDATION_ERROR', 'A valid target token is required.', 422);
        }

        return $this->transaction(function () use ($userId, $sessionId, $token): array {
            $this->lockedSession($userId, $sessionId);
            $stored = $this->storedState($sessionId, true);
            $nowMs = $this->nowMilliseconds();
            $elapsed = max(0, $nowMs - $stored['state']['started_at_ms']);
            $index = null;
            foreach ($stored['targets'] as $targetIndex => $candidate) {
                if (hash_equals($candidate['token'], $token)) {
                    $index = $targetIndex;
                    break;
                }
            }
            if ($index === null) {
                throw new ApiException('TARGET_NOT_ACTIVE', 'That target is not active now.', 409);
            }
            $target = $stored['targets'][$index];
            $startsAt = $index * self::TARGET_INTERVAL_MS;
            $endsAt = min(($index + 1) * self::TARGET_INTERVAL_MS, self::ROUND_DURATION_MS);
            if ($elapsed < $startsAt || $elapsed > $endsAt + self::NETWORK_GRACE_MS) {
                throw new ApiException('TARGET_NOT_ACTIVE', 'That target is not active now.', 409);
            }
            foreach ($stored['state']['hits'] as $hit) {
                if ($hit['index'] === $index) {
                    throw new ApiException('TARGET_ALREADY_HIT', 'That target has already been hit.', 409);
                }
            }

            $stored['state']['hits'][] = [
                'index' => $index,
                'token' => $token,
                'type' => $target['type'],
                'received_at_ms' => $nowMs,
            ];
            $this->saveState($sessionId, $stored['state']);
            $stats = $this->scoreHits($stored['targets'], $stored['state']['hits']);
            $now = $this->now();
            $this->updateSession($sessionId, 'in_progress', count($stored['state']['hits']), null, $now);
            $this->saveScore($sessionId, $stats['score']);
            return [
                'session' => $this->getState($userId, $sessionId),
                'attempts_remaining_today' => $this->attemptsRemainingToday($userId),
            ];
        });
    }

    public function finishRound(int $userId, int $sessionId): array
    {
        return $this->transaction(function () use ($userId, $sessionId): array {
            $this->lockedSession($userId, $sessionId);
            $stored = $this->storedState($sessionId, true);
            $nowMs = $this->nowMilliseconds();
            if ($nowMs - $stored['state']['started_at_ms'] < self::ROUND_DURATION_MS) {
                throw new ApiException('GAME_ROUND_ACTIVE', 'The 30-second round is still active.', 409);
            }
            return $this->completeRound($userId, $sessionId, $stored, $nowMs);
        });
    }

    private function completeRound(int $userId, int $sessionId, array $stored, int $nowMs): array
    {
        if ($stored['state']['finished_at_ms'] !== null) {
            throw new ApiException('GAME_SESSION_CLOSED', 'This Target Rush round is already closed.', 409);
        }
        $stored['state']['finished_at_ms'] = $nowMs;
        $this->saveState($sessionId, $stored['state']);
        $stats = $this->scoreHits($stored['targets'], $stored['state']['hits']);
        $this->saveScore($sessionId, $stats['score']);
        $now = $this->now();
        if ($stats['score'] < self::SCORE_TO_WIN) {
            $this->updateSession($sessionId, 'failed', count($stored['state']['hits']), null, $now);
            return [
                'session' => $this->getState($userId, $sessionId),
                'reward' => null,
                'attempts_remaining_today' => $this->attemptsRemainingToday($userId),
            ];
        }
        $result = $this->finish($userId, $sessionId, count($stored['state']['hits']), $now);
        $result['attempts_remaining_today'] = $this->attemptsRemainingToday($userId);
        return $result;
    }

    private function scoreHits(array $targets, array $hits): array
    {
        $score = 0;
        $combo = 0;
        $bestCombo = 0;
        $previousIndex = null;
        foreach ($hits as $hit) {
            $index = $hit['index'];
            $target = $targets[$index] ?? null;
            if ($previousIndex !== null && $index !== $previousIndex + 1) {
                $combo = 0;
            }
            if ($target['type'] === 'danger') {
                $score = max(0, $score - 15);
                $combo = 0;
            } else {
                $combo++;
                $bestCombo = max($bestCombo, $combo);
                $bonus = min(5, $combo - 1);
                $score += $target['type'] === 'gold' ? 20 + ($bonus * 3) : 10 + ($bonus * 2);
            }
            $previousIndex = $index;
        }
        return ['score' => $score, 'combo' => $combo, 'best_combo' => $bestCombo];
    }

    private function storedState(int $sessionId, bool $lock = false): array
    {
        $statement = $this->db->prepare(
            'SELECT secret_json, guesses_json FROM vault_cracker_states
             WHERE session_id = :session_id' . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['session_id' => $sessionId]);
        $stored = $statement->fetch();
        if ($stored === false) {
            throw new ApiException('GAME_STATE_INVALID', 'The Target Rush state is unavailable.', 409);
        }
        try {
            $targets = json_decode((string) $stored['secret_json'], true, 16, JSON_THROW_ON_ERROR);
            $state = json_decode((string) $stored['guesses_json'], true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_STATE_INVALID', 'The Target Rush state is invalid.', 409);
        }
        if (
            !is_array($targets)
            || !array_is_list($targets)
            || count($targets) !== self::TARGET_COUNT
            || !is_array($state)
            || array_is_list($state)
            || !is_int($state['started_at_ms'] ?? null)
            || !is_array($state['hits'] ?? null)
            || !array_is_list($state['hits'])
            || !array_key_exists('finished_at_ms', $state)
            || ($state['finished_at_ms'] !== null && !is_int($state['finished_at_ms']))
            || ($state['finished_at_ms'] !== null
                && $state['finished_at_ms'] - $state['started_at_ms'] < self::ROUND_DURATION_MS)
        ) {
            throw new ApiException('GAME_STATE_INVALID', 'The Target Rush state is invalid.', 409);
        }
        $tokens = [];
        foreach ($targets as $target) {
            if (
                !is_array($target)
                || !is_string($target['token'] ?? null)
                || !preg_match('/\A[a-f0-9]{32}\z/', $target['token'])
                || !in_array($target['type'] ?? null, self::TARGET_TYPES, true)
                || !in_array($target['position'] ?? null, self::POSITIONS, true)
                || isset($tokens[$target['token']])
            ) {
                throw new ApiException('GAME_STATE_INVALID', 'The Target Rush targets are invalid.', 409);
            }
            $tokens[$target['token']] = true;
        }
        $seenIndexes = [];
        $previousIndex = null;
        foreach ($state['hits'] as $hit) {
            if (!is_array($hit)) {
                throw new ApiException('GAME_STATE_INVALID', 'A Target Rush hit is invalid.', 409);
            }
            $index = $hit['index'] ?? null;
            if (
                !is_int($index)
                || $index < 0
                || $index >= self::TARGET_COUNT
                || !is_string($hit['token'] ?? null)
                || $hit['token'] !== $targets[$index]['token']
                || ($hit['type'] ?? null) !== $targets[$index]['type']
                || !is_int($hit['received_at_ms'] ?? null)
                || isset($seenIndexes[$index])
                || ($previousIndex !== null && $index <= $previousIndex)
                || $hit['received_at_ms'] < $state['started_at_ms'] + ($index * self::TARGET_INTERVAL_MS)
                || $hit['received_at_ms'] > min(
                    $state['started_at_ms'] + (($index + 1) * self::TARGET_INTERVAL_MS),
                    $state['started_at_ms'] + self::ROUND_DURATION_MS,
                ) + self::NETWORK_GRACE_MS
            ) {
                throw new ApiException('GAME_STATE_INVALID', 'A Target Rush hit is invalid.', 409);
            }
            $seenIndexes[$index] = true;
            $previousIndex = $index;
        }
        return ['targets' => $targets, 'state' => $state];
    }

    private function isTargetRushState(int $sessionId): bool
    {
        $statement = $this->db->prepare(
            'SELECT guesses_json FROM vault_cracker_states WHERE session_id = :session_id',
        );
        $statement->execute(['session_id' => $sessionId]);
        $json = $statement->fetchColumn();
        if (!is_string($json)) {
            return false;
        }
        try {
            $state = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }
        return is_array($state)
            && !array_is_list($state)
            && is_int($state['started_at_ms'] ?? null)
            && is_array($state['hits'] ?? null);
    }

    private function retireExpiredTargetRushRounds(int $userId): void
    {
        $this->transaction(function () use ($userId): void {
            $statement = $this->db->prepare(
                'SELECT s.id FROM game_sessions s
                 JOIN game_catalog g ON g.id = s.game_id
                 WHERE s.user_id = :user_id AND g.slug = :slug
                   AND s.status IN (\'started\', \'in_progress\')'
                 . $this->lockClause(),
            );
            $statement->execute(['user_id' => $userId, 'slug' => self::GAME_SLUG]);
            $now = $this->now();
            $fail = $this->db->prepare(
                'UPDATE game_sessions SET status = \'failed\', updated_at = :updated_at
                 WHERE id = :id AND status IN (\'started\', \'in_progress\')',
            );
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sessionId) {
                $id = (int) $sessionId;
                if (!$this->isTargetRushState($id)) {
                    $fail->execute(['updated_at' => $now, 'id' => $id]);
                    continue;
                }
                $stored = $this->storedState($id, true);
                if (
                    $stored['state']['finished_at_ms'] === null
                    && $this->nowMilliseconds() - $stored['state']['started_at_ms'] >= self::ROUND_DURATION_MS
                ) {
                    $this->completeRound($userId, $id, $stored, $this->nowMilliseconds());
                }
            }
        });
    }

    private function retireLegacyActiveSessions(int $userId): void
    {
        $this->transaction(function () use ($userId): void {
            $statement = $this->db->prepare(
                'SELECT s.id FROM game_sessions s
                 JOIN game_catalog g ON g.id = s.game_id
                 WHERE s.user_id = :user_id AND g.slug = :slug
                   AND s.status IN (\'started\', \'in_progress\')'
                 . $this->lockClause(),
            );
            $statement->execute(['user_id' => $userId, 'slug' => self::GAME_SLUG]);
            $now = $this->now();
            $fail = $this->db->prepare(
                'UPDATE game_sessions SET status = \'failed\', updated_at = :updated_at
                 WHERE id = :id AND status IN (\'started\', \'in_progress\')',
            );
            foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $sessionId) {
                if (!$this->isTargetRushState((int) $sessionId)) {
                    $fail->execute(['updated_at' => $now, 'id' => $sessionId]);
                }
            }
        });
    }

    private function saveState(int $sessionId, array $state): void
    {
        $statement = $this->db->prepare(
            'UPDATE vault_cracker_states SET guesses_json = :state_json WHERE session_id = :session_id',
        );
        $statement->execute([
            'state_json' => json_encode($state, JSON_THROW_ON_ERROR),
            'session_id' => $sessionId,
        ]);
    }

    private function saveScore(int $sessionId, int $score): void
    {
        $statement = $this->db->prepare('UPDATE game_sessions SET score = :score WHERE id = :id');
        $statement->execute(['score' => $score, 'id' => $sessionId]);
    }

    private function nowMilliseconds(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
