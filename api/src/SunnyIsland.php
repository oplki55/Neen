<?php
declare(strict_types=1);

final class SunnyIsland extends ChallengeGame
{
    private const GAME_SLUG = 'sunny_island';
    private const OBJECTIVES_PER_ADVENTURE = 3;
    private const XP_PER_ADVENTURE = 100;
    private const MAX_LEVEL = 100;
    private const MINIMUM_PRODUCTION_DURATION_SECONDS = 10;
    private const ZONES = [
        ['id' => 'neon_pier', 'name' => 'Neon Pier'],
        ['id' => 'palm_grove', 'name' => 'Palm Grove'],
        ['id' => 'loot_canyon', 'name' => 'Loot Canyon'],
        ['id' => 'sunset_ridge', 'name' => 'Sunset Ridge'],
    ];
    private const COLLECTIBLES = [
        ['id' => 'golden_compass', 'name' => 'Golden Compass', 'image' => '/assets/hero-loot/gold-coin.webp', 'rarity' => 'Legendary'],
        ['id' => 'neon_relic', 'name' => 'Neon Relic', 'image' => '/assets/hero-loot/weapon-ak47.webp', 'rarity' => 'Epic'],
        ['id' => 'drift_token', 'name' => 'Drift Token', 'image' => '/assets/hero-loot/weapon-mp40.webp', 'rarity' => 'Rare'],
        ['id' => 'sun_crate', 'name' => 'Sun Crate', 'image' => '/assets/hero-loot/loot-crate.webp', 'rarity' => 'Rare'],
        ['id' => 'palm_pack', 'name' => 'Palm Pack', 'image' => '/assets/hero-loot/backpack.webp', 'rarity' => 'Rare'],
        ['id' => 'snoop_badge', 'name' => 'Snoop Badge', 'image' => '/assets/hero-loot/snoop-sticker-01.webp', 'rarity' => 'Epic'],
        ['id' => 'reef_sticker', 'name' => 'Reef Sticker', 'image' => '/assets/hero-loot/snoop-sticker-02.webp', 'rarity' => 'Uncommon'],
        ['id' => 'sunset_sticker', 'name' => 'Sunset Sticker', 'image' => '/assets/hero-loot/snoop-sticker-03.webp', 'rarity' => 'Epic'],
        ['id' => 'canyon_sticker', 'name' => 'Canyon Sticker', 'image' => '/assets/hero-loot/snoop-sticker-04.webp', 'rarity' => 'Rare'],
    ];
    private const OBJECTIVES = [
        [
            'id' => 'collect_drops',
            'zone' => 'palm_grove',
            'description' => 'Collect three sunny drops in Palm Grove.',
            'target_count' => 3,
        ],
        [
            'id' => 'activate_beacons',
            'zone' => 'neon_pier',
            'description' => 'Activate the two beacons at Neon Pier.',
            'target_count' => 2,
        ],
        [
            'id' => 'reach_sunset_ridge',
            'zone' => 'sunset_ridge',
            'description' => 'Reach the lookout at Sunset Ridge.',
            'target_count' => 1,
        ],
        [
            'id' => 'find_relic',
            'zone' => 'loot_canyon',
            'description' => 'Find the Golden Compass in Loot Canyon.',
            'target_count' => 1,
            'collectible_id' => 'golden_compass',
        ],
    ];
    private const PICKUPS = [
        ['id' => 'snoop_badge', 'zone' => 'neon_pier', 'x' => 390, 'y' => 690],
        ['id' => 'golden_compass', 'zone' => 'loot_canyon', 'x' => 945, 'y' => 515],
        ['id' => 'neon_relic', 'zone' => 'loot_canyon', 'x' => 475, 'y' => 715],
        ['id' => 'drift_token', 'zone' => 'sunset_ridge', 'x' => 560, 'y' => 700],
        ['id' => 'sun_crate', 'zone' => 'palm_grove', 'x' => 385, 'y' => 690],
        ['id' => 'palm_pack', 'zone' => 'palm_grove', 'x' => 895, 'y' => 430],
        ['id' => 'reef_sticker', 'zone' => 'palm_grove', 'x' => 1215, 'y' => 700],
        ['id' => 'sunset_sticker', 'zone' => 'neon_pier', 'x' => 755, 'y' => 340],
        ['id' => 'canyon_sticker', 'zone' => 'sunset_ridge', 'x' => 1165, 'y' => 720],
    ];
    private const BEACONS = [
        ['id' => 'pier-beacon-a', 'zone' => 'neon_pier', 'x' => 800, 'y' => 440],
        ['id' => 'pier-beacon-b', 'zone' => 'neon_pier', 'x' => 1110, 'y' => 600],
    ];
    private const ZONE_ENTRANCES = [
        'neon_pier' => ['x' => 150, 'y' => 520],
        'palm_grove' => ['x' => 260, 'y' => 785],
        'loot_canyon' => ['x' => 260, 'y' => 815],
        'sunset_ridge' => ['x' => 780, 'y' => 825],
    ];
    private const INTERACTION_RADIUS = 74.0;

    protected function slug(): string
    {
        return self::GAME_SLUG;
    }

    public function state(int $userId): array
    {
        $progress = $this->transaction(function () use ($userId): array {
            $this->ensureProgressRow($userId);
            return $this->progressRecord($userId);
        });
        $game = $this->catalog();
        $active = $game['status'] === 'active';
        $session = $active ? $this->current($userId) : null;
        if (($session['status'] ?? null) === 'completed') {
            $session = null;
        }

        return [
            'progress' => $progress,
            'zones' => self::ZONES,
            'codex' => self::COLLECTIBLES,
            'adventure' => [
                'status' => $game['status'],
                'free_attempts_per_day' => $game['free_attempts_per_day'],
                'attempts_remaining_today' => $active ? $this->attemptsRemainingToday($userId) : 0,
                'reward_amount_coins' => $game['reward_amount_coins'],
                'reset_timezone' => $game['reset_timezone'],
                'active_session' => $session,
            ],
        ];
    }

    public function catalog(): array
    {
        return parent::catalog() + [
            'zones' => self::ZONES,
            'codex' => self::COLLECTIBLES,
        ];
    }

    protected function initializeState(int $sessionId): void
    {
        $pool = self::OBJECTIVES;
        $objectives = [];
        for ($index = 0; $index < self::OBJECTIVES_PER_ADVENTURE; $index++) {
            $selectedIndex = random_int(0, count($pool) - 1);
            $objective = array_splice($pool, $selectedIndex, 1)[0];
            $objective['token'] = self::randomToken();
            $objectives[] = $objective;
        }

        $statement = $this->db->prepare(
            'INSERT INTO sunny_island_states
                (session_id, contract_json, completed_objectives_json)
             VALUES (:session_id, :contract_json, :completed_objectives_json)',
        );
        $statement->execute([
            'session_id' => $sessionId,
            'contract_json' => json_encode(
                [
                    'version' => 1,
                    'objectives' => $objectives,
                    'activated_beacons' => [],
                    'current_zone' => 'neon_pier',
                    'collected_collectibles' => [],
                    'discovered_zones' => ['neon_pier'],
                    'action_nonce' => self::randomToken(),
                    'action_sequence' => 0,
                ],
                JSON_THROW_ON_ERROR,
            ),
            'completed_objectives_json' => '[]',
        ]);
    }

    protected function publicGameState(int $sessionId): array
    {
        $state = $this->islandSessionRecord($sessionId);
        $contract = $this->decodeContract((string) $state['contract_json']);
        $completed = $this->jsonArray((string) $state['completed_objectives_json']);
        $completedIds = [];
        foreach ($completed as $receipt) {
            if (!is_array($receipt) || !is_string($receipt['id'] ?? null)) {
                throw new ApiException('GAME_STATE_INVALID', 'The Sunny Island objective records are invalid.', 409);
            }
            $completedIds[] = $receipt['id'];
        }
        $objectives = [];
        foreach ($contract['objectives'] as $objective) {
            if (!is_array($objective) || !isset($objective['id'], $objective['zone'])) {
                throw new ApiException('GAME_STATE_INVALID', 'The Sunny Island adventure contract is invalid.', 409);
            }
            $objectives[] = [
                'id' => $objective['id'],
                'zone' => $objective['zone'],
                'description' => $objective['description'],
                'target_count' => $objective['target_count'],
                'completed' => in_array($objective['id'], $completedIds, true),
            ];
        }

        return [
            'objectives' => $objectives,
            'objectives_total' => count($objectives),
            'objectives_completed' => count($completedIds),
            'current_zone' => (string) ($contract['current_zone'] ?? 'neon_pier'),
            'collected_collectibles' => $contract['collected_collectibles'] ?? [],
            'discovered_zones' => $contract['discovered_zones'] ?? ['neon_pier'],
            'activated_beacons' => $contract['activated_beacons'] ?? [],
            'action_nonce' => (string) ($contract['action_nonce'] ?? ''),
            'action_sequence' => (int) ($contract['action_sequence'] ?? 0),
        ];
    }

    public function syncProgress(int $userId, array $data): array
    {
        throw new ApiException(
            'SUNNY_PROGRESS_DEPRECATED',
            'Use the server-authoritative Sunny Island action endpoint.',
            410,
        );
    }

    public function applyAction(
        int $userId,
        int $sessionId,
        string $requestKey,
        string $actionNonce,
        string $action,
        string $targetId,
    ): array {
        $payload = [
            'action' => $action,
            'target_id' => $targetId,
            'action_nonce' => $actionNonce,
        ];
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->transaction(function () use (
            $userId,
            $sessionId,
            $requestKey,
            $actionNonce,
            $action,
            $targetId,
            $payload,
            $payloadHash,
        ): array {
            $existing = $this->findActionRequest($sessionId, $requestKey, true);
            if ($existing !== false) {
                if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                    throw new ApiException('DUPLICATE_REQUEST', 'Request key was reused with different action data.', 409);
                }
                if ($existing['status'] === 'completed' && is_string($existing['response_payload_json'])) {
                    return json_decode($existing['response_payload_json'], true, 16, JSON_THROW_ON_ERROR);
                }
                throw new ApiException('DUPLICATE_REQUEST', 'The same action is already being processed.', 409);
            }

            $now = $this->now();
            $statement = $this->db->prepare(
                'INSERT INTO sunny_island_action_requests
                    (session_id, user_id, request_key, payload_hash, action_type, target_id,
                     status, response_payload_json, created_at, updated_at)
                 VALUES
                    (:session_id, :user_id, :request_key, :payload_hash, :action_type, :target_id,
                     \'processing\', NULL, :created_at, :updated_at)',
            );
            $statement->execute([
                'session_id' => $sessionId,
                'user_id' => $userId,
                'request_key' => $requestKey,
                'payload_hash' => $payloadHash,
                'action_type' => $action,
                'target_id' => $targetId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $session = $this->lockedSession($userId, $sessionId);
            $state = $this->islandSessionRecord($sessionId, true);
            $contract = $this->decodeContract((string) $state['contract_json']);
            $nonce = (string) ($contract['action_nonce'] ?? '');
            if ($nonce === '' || !hash_equals($nonce, $actionNonce)) {
                $this->logSunnyAction($userId, $sessionId, 'sunny_action_nonce_invalid', [
                    'action' => $action,
                    'target_id' => $targetId,
                    'request_key' => substr(hash('sha256', $requestKey), 0, 16),
                ]);
                throw new ApiException('SUNNY_ACTION_INVALID', 'The Sunny Island action nonce is invalid or stale.', 409);
            }

            $contract['current_zone'] = (string) ($contract['current_zone'] ?? 'neon_pier');
            $contract['collected_collectibles'] = $this->jsonArray(
                json_encode($contract['collected_collectibles'] ?? [], JSON_THROW_ON_ERROR),
            );
            $contract['discovered_zones'] = $this->jsonArray(
                json_encode($contract['discovered_zones'] ?? ['neon_pier'], JSON_THROW_ON_ERROR),
            );
            $contract['activated_beacons'] = $this->jsonArray(
                json_encode($contract['activated_beacons'] ?? [], JSON_THROW_ON_ERROR),
            );

            if ($action === 'enter_zone') {
                $this->applyEnterZone($contract, $targetId);
            } elseif ($action === 'collect') {
                $this->applyCollect($contract, $targetId);
            } elseif ($action === 'activate_beacon') {
                $this->applyActivateBeacon($contract, $targetId);
            } else {
                $this->logSunnyAction($userId, $sessionId, 'sunny_action_rejected', [
                    'action' => $action,
                    'target_id' => $targetId,
                ]);
                throw new ApiException('SUNNY_ACTION_INVALID', 'Unknown Sunny Island action.', 422);
            }

            $this->syncObjectiveReceipts($contract, (string) $state['completed_objectives_json']);
            $contract['action_sequence'] = (int) ($contract['action_sequence'] ?? 0) + 1;
            $contract['action_nonce'] = self::randomToken();

            $statement = $this->db->prepare(
                'UPDATE sunny_island_states
                 SET contract_json = :contract_json,
                     completed_objectives_json = :completed_objectives_json
                 WHERE session_id = :session_id',
            );
            $statement->execute([
                'contract_json' => json_encode($contract, JSON_THROW_ON_ERROR),
                'completed_objectives_json' => json_encode($contract['_completed_receipts'] ?? [], JSON_THROW_ON_ERROR),
                'session_id' => $sessionId,
            ]);
            unset($contract['_completed_receipts']);

            $this->persistServerState($userId, $contract);
            $this->updateSession(
                $sessionId,
                'in_progress',
                (int) $session['move_count'] + 1,
                null,
                $now,
            );

            $response = [
                'session' => $this->getState($userId, $sessionId),
                'progress' => $this->progressRecord($userId),
                'action_nonce' => (string) $contract['action_nonce'],
                'action_sequence' => (int) $contract['action_sequence'],
            ];
            $responseJson = json_encode($response, JSON_THROW_ON_ERROR);
            $statement = $this->db->prepare(
                'UPDATE sunny_island_action_requests
                 SET status = \'completed\', response_payload_json = :response_payload,
                     updated_at = :updated_at
                 WHERE session_id = :session_id AND request_key = :request_key',
            );
            $statement->execute([
                'response_payload' => $responseJson,
                'updated_at' => $now,
                'session_id' => $sessionId,
                'request_key' => $requestKey,
            ]);
            $this->logSunnyAction($userId, $sessionId, 'sunny_action_accepted', [
                'action' => $action,
                'target_id' => $targetId,
                'sequence' => (int) $contract['action_sequence'],
            ]);
            return $response;
        });
    }

    public function completeAdventure(
        int $userId,
        int $sessionId,
        string $requestKey,
        string $completionNonce,
    ): array {
        $payload = ['completion_nonce' => $completionNonce];
        $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return $this->transaction(function () use (
            $userId,
            $sessionId,
            $requestKey,
            $completionNonce,
            $payloadHash,
        ): array {
            $existing = $this->findActionRequest($sessionId, $requestKey, true);
            if ($existing !== false) {
                if (!hash_equals((string) $existing['payload_hash'], $payloadHash)) {
                    throw new ApiException('DUPLICATE_REQUEST', 'Completion key was reused with different data.', 409);
                }
                if ($existing['status'] === 'completed' && is_string($existing['response_payload_json'])) {
                    return json_decode($existing['response_payload_json'], true, 16, JSON_THROW_ON_ERROR);
                }
                throw new ApiException('DUPLICATE_REQUEST', 'The same completion is already being processed.', 409);
            }

            $now = $this->now();
            $statement = $this->db->prepare(
                'INSERT INTO sunny_island_action_requests
                    (session_id, user_id, request_key, payload_hash, action_type, target_id,
                     status, response_payload_json, created_at, updated_at)
                 VALUES
                    (:session_id, :user_id, :request_key, :payload_hash, \'complete\', NULL,
                     \'processing\', NULL, :created_at, :updated_at)',
            );
            $statement->execute([
                'session_id' => $sessionId,
                'user_id' => $userId,
                'request_key' => $requestKey,
                'payload_hash' => $payloadHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $session = $this->lockedSession($userId, $sessionId);
            $this->assertMinimumDuration((string) $session['started_at']);
            $state = $this->islandSessionRecord($sessionId, true);
            $contract = $this->decodeContract((string) $state['contract_json']);
            $nonce = (string) ($contract['action_nonce'] ?? '');
            if ($nonce === '' || !hash_equals($nonce, $completionNonce)) {
                $this->logSunnyAction($userId, $sessionId, 'sunny_completion_rejected', [
                    'reason' => 'invalid_completion_nonce',
                ]);
                throw new ApiException('SUNNY_COMPLETION_INVALID', 'The completion nonce is invalid or stale.', 409);
            }

            $required = array_column($contract['objectives'], 'id');
            $completed = $this->jsonArray((string) $state['completed_objectives_json']);
            $completedIds = array_map(
                static fn (mixed $receipt): string => is_array($receipt) && is_string($receipt['id'] ?? null)
                    ? $receipt['id']
                    : '',
                $completed,
            );
            sort($required);
            sort($completedIds);
            if ($required === [] || $completedIds !== $required) {
                $this->logSunnyAction($userId, $sessionId, 'sunny_completion_rejected', [
                    'reason' => 'objectives_incomplete',
                ]);
                throw new ApiException('ADVENTURE_INCOMPLETE', 'All adventure objectives must be completed first.', 409);
            }

            $progress = $this->progressRecord($userId, true);
            $xp = (int) $progress['xp'] + self::XP_PER_ADVENTURE;
            $level = self::levelProgressFromXp($xp)['level'];
            $statement = $this->db->prepare(
                'UPDATE sunny_island_progress
                 SET xp = :xp, level = :level,
                     total_adventures_completed = total_adventures_completed + 1,
                     updated_at = :updated_at
                 WHERE user_id = :user_id',
            );
            $statement->execute([
                'xp' => $xp,
                'level' => $level,
                'updated_at' => $now,
                'user_id' => $userId,
            ]);

            $this->updateSession($sessionId, 'completed', count($completedIds), $now, $now);
            $reward = $this->rewards->awardCompletedWin($userId, $this->slug(), $sessionId);
            $reward['xp_awarded'] = self::XP_PER_ADVENTURE;

            $response = [
                'session' => $this->getState($userId, $sessionId),
                'progress' => $this->progressRecord($userId),
                'reward' => $reward,
                'xp_awarded' => self::XP_PER_ADVENTURE,
            ];
            $responseJson = json_encode($response, JSON_THROW_ON_ERROR);
            $statement = $this->db->prepare(
                'UPDATE sunny_island_action_requests
                 SET status = \'completed\', response_payload_json = :response_payload,
                     updated_at = :updated_at
                 WHERE session_id = :session_id AND request_key = :request_key',
            );
            $statement->execute([
                'response_payload' => $responseJson,
                'updated_at' => $now,
                'session_id' => $sessionId,
                'request_key' => $requestKey,
            ]);
            $this->logSunnyAction($userId, $sessionId, 'sunny_reward_awarded', [
                'xp' => self::XP_PER_ADVENTURE,
                'coins' => (int) $reward['amount'],
            ]);
            return $response;
        });
    }


    public static function levelProgressFromXp(int $xp): array
    {
        $xp = max(0, $xp);
        $level = min(self::MAX_LEVEL, intdiv($xp, 300) + 1);
        $currentXp = $level >= self::MAX_LEVEL ? 0 : $xp % 300;
        $requiredXp = 300;

        return [
            'level' => $level,
            'current_xp' => $currentXp,
            'required_xp' => $requiredXp,
        ];
    }

    public static function grantXp(PDO $db, int $userId, int $xpAmount): array
    {
        if ($userId < 1 || $xpAmount < 1) {
            return ['xp' => 0, 'level' => 1];
        }
        $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insert = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $init = $db->prepare(
            $insert . " INTO sunny_island_progress
                (user_id, xp, level, unlocked_collectibles_json, discovered_zones_json,
                 total_adventures_completed, last_position_json, created_at, updated_at)
             VALUES (:user_id, 0, 1, '[]', '[]', 0, NULL, :created_at, :updated_at)",
        );
        $init->execute(['user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);

        $lock = $driver === 'mysql' ? ' FOR UPDATE' : '';
        $select = $db->prepare('SELECT xp FROM sunny_island_progress WHERE user_id = :user_id' . $lock);
        $select->execute(['user_id' => $userId]);
        $currentXp = (int) ($select->fetchColumn() ?: 0);
        $newXp = $currentXp + $xpAmount;
        $lvl = self::levelProgressFromXp($newXp);

        $update = $db->prepare(
            'UPDATE sunny_island_progress
             SET xp = :xp, level = :level, updated_at = :updated_at
             WHERE user_id = :user_id',
        );
        $update->execute([
            'xp' => $newXp,
            'level' => $lvl['level'],
            'updated_at' => $now,
            'user_id' => $userId,
        ]);

        return ['xp' => $newXp, 'level' => $lvl['level']];
    }

    public static function collectibleIds(): array
    {
        return array_column(self::COLLECTIBLES, 'id');
    }

    public static function zoneIds(): array
    {
        return array_column(self::ZONES, 'id');
    }

    public static function objectiveIds(): array
    {
        return array_column(self::OBJECTIVES, 'id');
    }

    private function recordObjectiveReports(int $userId, int $sessionId, mixed $reports): void
    {
        if (!is_array($reports) || !array_is_list($reports) || count($reports) > self::OBJECTIVES_PER_ADVENTURE) {
            throw new ApiException('VALIDATION_ERROR', 'Adventure objective reports are invalid.', 422);
        }
        $session = $this->lockedSession($userId, $sessionId);
        $state = $this->islandSessionRecord($sessionId, true);
        $contract = $this->decodeContract((string) $state['contract_json']);
        $objectives = [];
        foreach ($contract['objectives'] as $objective) {
            if (
                !is_array($objective)
                || !is_string($objective['id'] ?? null)
                || !is_string($objective['zone'] ?? null)
            ) {
                throw new ApiException('GAME_STATE_INVALID', 'The Sunny Island adventure contract is invalid.', 409);
            }
            $objectives[$objective['id']] = $objective;
        }

        $progress = $this->progressRecord($userId);
        $completed = $this->jsonArray((string) $state['completed_objectives_json']);
        $completedIds = [];
        foreach ($completed as $receipt) {
            if (
                !is_array($receipt)
                || !is_string($receipt['id'] ?? null)
                || !is_string($receipt['proof'] ?? null)
                || !isset($objectives[$receipt['id']])
                || !hash_equals(
                    hash_hmac('sha256', $receipt['id'], (string) $objectives[$receipt['id']]['token']),
                    $receipt['proof'],
                )
            ) {
                throw new ApiException('GAME_STATE_INVALID', 'The Sunny Island objective records are invalid.', 409);
            }
            $completedIds[] = $receipt['id'];
        }
        $newObjectives = 0;
        foreach ($reports as $report) {
            if (!is_string($report) || !isset($objectives[$report])) {
                throw new ApiException('VALIDATION_ERROR', 'An adventure objective report is invalid.', 422);
            }
            $objective = $objectives[$report];
            if (
                !in_array($objective['zone'], $progress['discovered_zones'], true)
                || ($progress['last_position']['zone'] ?? null) !== $objective['zone']
            ) {
                throw new ApiException(
                    'OBJECTIVE_NOT_VERIFIED',
                    'Reach and discover the objective zone before reporting completion.',
                    409,
                );
            }
            if (
                ($objective['id'] === 'collect_drops'
                    && count($progress['unlocked_collectibles']) < (int) $objective['target_count'])
                || (isset($objective['collectible_id'])
                    && !in_array($objective['collectible_id'], $progress['unlocked_collectibles'], true))
                || ($objective['id'] === 'activate_beacons'
                    && count($contract['activated_beacons'] ?? []) < (int) $objective['target_count'])
            ) {
                throw new ApiException(
                    'OBJECTIVE_NOT_VERIFIED',
                    'The required Sunny Island collectibles have not been discovered.',
                    409,
                );
            }
            if (!in_array($report, $completedIds, true)) {
                $completed[] = [
                    'id' => $report,
                    'proof' => hash_hmac('sha256', $report, (string) $objective['token']),
                ];
                $completedIds[] = $report;
                $newObjectives++;
            }
        }

        $statement = $this->db->prepare(
            'UPDATE sunny_island_states
             SET completed_objectives_json = :completed_objectives_json
             WHERE session_id = :session_id',
        );
        $statement->execute([
            'completed_objectives_json' => json_encode(array_values($completed), JSON_THROW_ON_ERROR),
            'session_id' => $sessionId,
        ]);
        if ($newObjectives > 0) {
            $this->updateSession(
                $sessionId,
                'in_progress',
                (int) $session['move_count'] + $newObjectives,
                null,
                $this->now(),
            );
        }
    }

    private function assertMinimumDuration(string $startedAt): void
    {
        $minimum = self::MINIMUM_PRODUCTION_DURATION_SECONDS;
        if (getenv('APP_ENV') === 'test') {
            $configured = getenv('SUNNY_ISLAND_MIN_DURATION_SECONDS');
            if ($configured === false || $configured === '') {
                $minimum = 0;
            } elseif (!preg_match('/\A[0-9]{1,4}\z/', $configured)) {
                throw new RuntimeException('SUNNY_ISLAND_MIN_DURATION_SECONDS must be an integer from 0 to 9999 in test mode.');
            } else {
                $minimum = (int) $configured;
            }
        }

        $started = new DateTimeImmutable($startedAt, new DateTimeZone('UTC'));
        $elapsed = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->getTimestamp()
            - $started->getTimestamp();
        if ($elapsed < $minimum) {
            throw new ApiException(
                'ADVENTURE_TOO_FAST',
                'The Sunny Island adventure needs more time before it can be completed.',
                409,
            );
        }
    }

    private function persistProgress(int $userId, array $data, ?int $sessionId = null): array
    {
        $this->ensureProgressRow($userId);
        $current = $this->progressRecord($userId, true);
        $collectibles = $current['unlocked_collectibles'];
        $zones = $current['discovered_zones'];
        $position = $current['last_position'];
        if (array_key_exists('progress_event', $data)) {
            $event = $this->validatedProgressEvent($data['progress_event'], $position);
            $position = $event['position'];
            if ($event['type'] === 'collectible') {
                if (!in_array($event['id'], $collectibles, true)) {
                    $collectibles[] = $event['id'];
                }
            } elseif ($event['type'] === 'zone') {
                if (!in_array($event['id'], $zones, true)) {
                    $zones[] = $event['id'];
                }
            } elseif ($event['type'] === 'beacon' && $sessionId !== null) {
                $this->recordBeaconProgressEvent($userId, $sessionId, $event['id']);
            }
        }
        foreach (['collectibles', 'discovered_zones', 'last_position'] as $forbiddenField) {
            if (array_key_exists($forbiddenField, $data)) {
                throw new ApiException(
                    'VALIDATION_ERROR',
                    'Sunny Island progress must be recorded through server-verified events.',
                    422,
                );
            }
        }

        $statement = $this->db->prepare(
            'UPDATE sunny_island_progress
             SET unlocked_collectibles_json = :collectibles,
                 discovered_zones_json = :zones,
                 last_position_json = :position,
                 updated_at = :updated_at
             WHERE user_id = :user_id',
        );
        $statement->execute([
            'collectibles' => json_encode($collectibles, JSON_THROW_ON_ERROR),
            'zones' => json_encode($zones, JSON_THROW_ON_ERROR),
            'position' => $position === null ? null : json_encode($position, JSON_THROW_ON_ERROR),
            'updated_at' => $this->now(),
            'user_id' => $userId,
        ]);
        return $this->progressRecord($userId);
    }

    private function validatedProgressEvent(mixed $event, ?array $currentPosition): array
    {
        if (
            !is_array($event)
            || array_is_list($event)
            || !is_string($event['type'] ?? null)
            || !is_string($event['id'] ?? null)
            || !is_array($event['position'] ?? null)
        ) {
            throw new ApiException('VALIDATION_ERROR', 'Sunny Island progress event is invalid.', 422);
        }

        $position = $this->validatedPosition($event['position']);
        if ($position === null) {
            throw new ApiException('VALIDATION_ERROR', 'Sunny Island progress event position is invalid.', 422);
        }

        $type = $event['type'];
        $id = $event['id'];
        $adjacentZones = [
            'neon_pier' => ['palm_grove'],
            'palm_grove' => ['neon_pier', 'loot_canyon'],
            'loot_canyon' => ['palm_grove', 'sunset_ridge'],
            'sunset_ridge' => ['loot_canyon'],
        ];
        if ($type === 'collectible') {
            foreach (self::PICKUPS as $pickup) {
                if (
                    $pickup['id'] === $id
                    && $pickup['zone'] === $position['zone']
                    && $this->distance($position, $pickup) <= self::INTERACTION_RADIUS
                    && (
                        $currentPosition === null
                        || $currentPosition['zone'] === $position['zone']
                        || in_array($position['zone'], $adjacentZones[$currentPosition['zone']] ?? [], true)
                    )
                ) {
                    return ['type' => $type, 'id' => $id, 'position' => $position];
                }
            }
        } elseif ($type === 'zone') {
            $adjacentZones = [
                'neon_pier' => ['palm_grove'],
                'palm_grove' => ['neon_pier', 'loot_canyon'],
                'loot_canyon' => ['palm_grove', 'sunset_ridge'],
                'sunset_ridge' => ['loot_canyon'],
            ];
            if (
                $currentPosition !== null
                && $currentPosition['zone'] !== $id
                && !in_array($id, $adjacentZones[$currentPosition['zone']] ?? [], true)
            ) {
                throw new ApiException(
                    'PROGRESS_EVENT_NOT_VERIFIED',
                    'Sunny Island zone entry is not reachable from the current position.',
                    409,
                );
            }
            $zoneEntrances = match ($id) {
                'neon_pier' => [
                    self::ZONE_ENTRANCES[$id],
                    ['x' => 240, 'y' => 565],
                ],
                'palm_grove' => [
                    self::ZONE_ENTRANCES[$id],
                    ['x' => 115, 'y' => 785],
                    ['x' => 1480, 'y' => 665],
                ],
                'loot_canyon' => [
                    self::ZONE_ENTRANCES[$id],
                    ['x' => 115, 'y' => 815],
                    ['x' => 1480, 'y' => 815],
                ],
                'sunset_ridge' => [
                    self::ZONE_ENTRANCES[$id],
                    ['x' => 115, 'y' => 815],
                ],
                default => [],
            };
            foreach ($zoneEntrances as $entrance) {
                if (
                    $position['zone'] === $id
                    && $this->distance($position, $entrance) <= self::INTERACTION_RADIUS
                ) {
                    return ['type' => $type, 'id' => $id, 'position' => $position];
                }
            }
        } elseif ($type === 'beacon') {
            foreach (self::BEACONS as $beacon) {
                if (
                    $beacon['id'] === $id
                    && $beacon['zone'] === $position['zone']
                    && $this->distance($position, $beacon) <= self::INTERACTION_RADIUS
                    && (
                        $currentPosition === null
                        || $currentPosition['zone'] === $position['zone']
                        || in_array($position['zone'], $adjacentZones[$currentPosition['zone']] ?? [], true)
                    )
                ) {
                    return ['type' => $type, 'id' => $id, 'position' => $position];
                }
            }
        }

        throw new ApiException(
            'PROGRESS_EVENT_NOT_VERIFIED',
            'Sunny Island progress event could not be verified at this location.',
            409,
        );
    }

    private function distance(array $position, array $point): float
    {
        return sqrt(
            (($position['x'] - $point['x']) ** 2)
            + (($position['y'] - $point['y']) ** 2)
        );
    }

    private function findActionRequest(int $sessionId, string $requestKey, bool $lock): array|false
    {
        $lockClause = $lock && $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'
            ? ' FOR UPDATE'
            : '';
        $statement = $this->db->prepare(
            'SELECT request_key, payload_hash, status, response_payload_json
             FROM sunny_island_action_requests
             WHERE session_id = :session_id AND request_key = :request_key LIMIT 1'
             . $lockClause,
        );
        $statement->execute([
            'session_id' => $sessionId,
            'request_key' => $requestKey,
        ]);
        return $statement->fetch();
    }

    private function applyEnterZone(array &$contract, string $targetId): void
    {
        if (!in_array($targetId, self::zoneIds(), true)) {
            $this->logSunnyActionRejectedPayload('enter_zone', $targetId);
            throw new ApiException('SUNNY_ACTION_INVALID', 'Unknown Sunny Island zone.', 422);
        }

        $current = (string) ($contract['current_zone'] ?? 'neon_pier');
        $adjacent = [
            'neon_pier' => ['palm_grove'],
            'palm_grove' => ['neon_pier', 'loot_canyon'],
            'loot_canyon' => ['palm_grove', 'sunset_ridge'],
            'sunset_ridge' => ['loot_canyon'],
        ];
        if ($current !== $targetId && !in_array($targetId, $adjacent[$current] ?? [], true)) {
            throw new ApiException(
                'SUNNY_ACTION_INVALID',
                'The requested zone is not reachable from the current zone.',
                409,
            );
        }

        $contract['current_zone'] = $targetId;
        $discovered = $contract['discovered_zones'] ?? [];
        if (!in_array($targetId, $discovered, true)) {
            $discovered[] = $targetId;
        }
        $contract['discovered_zones'] = array_values($discovered);
    }

    private function applyCollect(array &$contract, string $targetId): void
    {
        $currentZone = (string) ($contract['current_zone'] ?? 'neon_pier');
        $pickup = null;
        foreach (self::PICKUPS as $candidate) {
            if ($candidate['id'] === $targetId) {
                $pickup = $candidate;
                break;
            }
        }
        if (
            $pickup === null
            || $pickup['zone'] !== $currentZone
        ) {
            throw new ApiException(
                'SUNNY_ACTION_INVALID',
                'The collectible is not available in the current zone.',
                409,
            );
        }

        $collected = $contract['collected_collectibles'] ?? [];
        if (in_array($targetId, $collected, true)) {
            throw new ApiException('SUNNY_ACTION_REPLAY', 'The collectible was already collected.', 409);
        }
        $collected[] = $targetId;
        $contract['collected_collectibles'] = array_values($collected);
    }

    private function applyActivateBeacon(array &$contract, string $targetId): void
    {
        $currentZone = (string) ($contract['current_zone'] ?? 'neon_pier');
        $beacon = null;
        foreach (self::BEACONS as $candidate) {
            if ($candidate['id'] === $targetId) {
                $beacon = $candidate;
                break;
            }
        }
        if ($beacon === null || $beacon['zone'] !== $currentZone) {
            throw new ApiException(
                'SUNNY_ACTION_INVALID',
                'The beacon is not available in the current zone.',
                409,
            );
        }

        $activated = $contract['activated_beacons'] ?? [];
        if (in_array($targetId, $activated, true)) {
            throw new ApiException('SUNNY_ACTION_REPLAY', 'The beacon was already activated.', 409);
        }
        $activated[] = $targetId;
        $contract['activated_beacons'] = array_values($activated);
    }

    private function syncObjectiveReceipts(array &$contract, string $completedJson): void
    {
        $completed = $this->jsonArray($completedJson);
        $receiptsById = [];
        foreach ($completed as $receipt) {
            if (is_array($receipt) && is_string($receipt['id'] ?? null)) {
                $receiptsById[$receipt['id']] = $receipt;
            }
        }

        $collected = $contract['collected_collectibles'] ?? [];
        $zone = (string) ($contract['current_zone'] ?? 'neon_pier');
        $beacons = $contract['activated_beacons'] ?? [];
        $discovered = $contract['discovered_zones'] ?? [];

        foreach ($contract['objectives'] as $objective) {
            $id = (string) ($objective['id'] ?? '');
            $complete = false;
            if ($id === 'collect_drops') {
                $complete = in_array('palm_grove', $discovered, true)
                    && count(array_intersect($collected, ['sun_crate', 'palm_pack', 'reef_sticker'])) >= 3;
            } elseif ($id === 'activate_beacons') {
                $complete = count($beacons) >= 2;
            } elseif ($id === 'reach_sunset_ridge') {
                $complete = $zone === 'sunset_ridge';
            } elseif ($id === 'find_relic') {
                $complete = in_array('golden_compass', $collected, true);
            }

            if ($complete && !isset($receiptsById[$id])) {
                $receiptsById[$id] = [
                    'id' => $id,
                    'proof' => hash_hmac(
                        'sha256',
                        $id,
                        (string) ($objective['token'] ?? ''),
                    ),
                ];
            }
        }

        $contract['_completed_receipts'] = array_values($receiptsById);
    }

    private function persistServerState(int $userId, array $contract): void
    {
        $collected = $contract['collected_collectibles'] ?? [];
        $discovered = $contract['discovered_zones'] ?? [];
        $position = [
            'zone' => (string) ($contract['current_zone'] ?? 'neon_pier'),
            'x' => 0,
            'y' => 0,
        ];
        $statement = $this->db->prepare(
            'UPDATE sunny_island_progress
             SET unlocked_collectibles_json = :collectibles,
                 discovered_zones_json = :zones,
                 last_position_json = :position,
                 updated_at = :updated_at
             WHERE user_id = :user_id',
        );
        $statement->execute([
            'collectibles' => json_encode(array_values($collected), JSON_THROW_ON_ERROR),
            'zones' => json_encode(array_values($discovered), JSON_THROW_ON_ERROR),
            'position' => json_encode($position, JSON_THROW_ON_ERROR),
            'updated_at' => $this->now(),
            'user_id' => $userId,
        ]);
    }

    private function logSunnyAction(int $userId, int $sessionId, string $action, array $payload): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO audit_logs
                (actor_user_id, actor_role, action_type, entity_type, entity_id, payload,
                 ip_address, user_agent, created_at)
             VALUES
                (:actor_user_id, NULL, :action_type, \'sunny_island_session\', :entity_id, :payload,
                 NULL, NULL, :created_at)',
        );
        $statement->execute([
            'actor_user_id' => $userId,
            'action_type' => $action,
            'entity_id' => $sessionId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'created_at' => $this->now(),
        ]);
    }

    private function logSunnyActionRejectedPayload(string $action, string $targetId): void
    {
        // Deliberately no-op: caller adds session context after validation.
    }

    private function recordBeaconProgressEvent(int $userId, int $sessionId, string $beaconId): void
    {
        $this->lockedSession($userId, $sessionId);
        $state = $this->islandSessionRecord($sessionId, true);
        $contract = $this->decodeContract((string) $state['contract_json']);
        $beacons = $contract['activated_beacons'] ?? [];
        if (!is_array($beacons) || !is_array($beacons)) {
            $beacons = [];
        }
        if (!in_array($beaconId, $beacons, true)) {
            $beacons[] = $beaconId;
            $contract['activated_beacons'] = array_values($beacons);
            $statement = $this->db->prepare(
                'UPDATE sunny_island_states SET contract_json = :contract_json WHERE session_id = :session_id',
            );
            $statement->execute([
                'contract_json' => json_encode($contract, JSON_THROW_ON_ERROR),
                'session_id' => $sessionId,
            ]);
        }
    }

    private function validatedList(mixed $value, array $allowed, string $field): array
    {
        if (!is_array($value) || !array_is_list($value) || count($value) > count($allowed)) {
            throw new ApiException('VALIDATION_ERROR', 'Sunny Island ' . $field . ' are invalid.', 422);
        }
        $unique = [];
        foreach ($value as $item) {
            if (!is_string($item) || !in_array($item, $allowed, true) || in_array($item, $unique, true)) {
                throw new ApiException('VALIDATION_ERROR', 'Sunny Island ' . $field . ' are invalid.', 422);
            }
            $unique[] = $item;
        }
        return $unique;
    }

    private function validatedPosition(mixed $position): ?array
    {
        if ($position === null) {
            return null;
        }
        if (
            !is_array($position)
            || array_is_list($position)
            || count($position) !== 3
            || !is_string($position['zone'] ?? null)
            || !in_array($position['zone'], self::zoneIds(), true)
        ) {
            throw new ApiException('VALIDATION_ERROR', 'Sunny Island map coordinates are invalid.', 422);
        }
        $coordinates = [];
        foreach (['x', 'y'] as $axis) {
            $coordinate = $position[$axis] ?? null;
            if (
                (!is_int($coordinate) && !is_float($coordinate))
                || !is_finite((float) $coordinate)
                || $coordinate < 0
                || $coordinate > 100000
            ) {
                throw new ApiException('VALIDATION_ERROR', 'Sunny Island map coordinates are invalid.', 422);
            }
            $coordinates[$axis] = $coordinate;
        }
        return ['zone' => $position['zone'], 'x' => $coordinates['x'], 'y' => $coordinates['y']];
    }

    private function ensureProgressRow(int $userId): void
    {
        if ($userId < 1) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Account not found.', 404);
        }
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $insert = $driver === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE';
        $statement = $this->db->prepare(
            $insert . ' INTO sunny_island_progress
                (user_id, xp, level, unlocked_collectibles_json, discovered_zones_json,
                 total_adventures_completed, last_position_json, created_at, updated_at)
             VALUES (:user_id, 0, 1, \'[]\', \'[]\', 0, NULL, :created_at, :updated_at)',
        );
        $now = $this->now();
        $statement->execute(['user_id' => $userId, 'created_at' => $now, 'updated_at' => $now]);
    }

    private function progressRecord(int $userId, bool $lock = false): array
    {
        $statement = $this->db->prepare(
            'SELECT xp, level, unlocked_collectibles_json, discovered_zones_json,
                    total_adventures_completed, last_position_json
             FROM sunny_island_progress WHERE user_id = :user_id'
             . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new RuntimeException('Sunny Island progress row was not created.');
        }
        $position = null;
        if ($row['last_position_json'] !== null) {
            try {
                $decoded = json_decode((string) $row['last_position_json'], true, 16, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new ApiException('GAME_STATE_INVALID', 'The saved Sunny Island position is invalid.', 409);
            }
            if (!is_array($decoded) || array_is_list($decoded)) {
                throw new ApiException('GAME_STATE_INVALID', 'The saved Sunny Island position is invalid.', 409);
            }
            $position = $decoded;
        }
        return [
            'xp' => (int) $row['xp'],
            'level' => (int) $row['level'],
            'unlocked_collectibles' => $this->jsonArray((string) $row['unlocked_collectibles_json']),
            'discovered_zones' => $this->jsonArray((string) $row['discovered_zones_json']),
            'total_adventures_completed' => (int) $row['total_adventures_completed'],
            'last_position' => $position,
        ];
    }

    private function islandSessionRecord(int $sessionId, bool $lock = false): array
    {
        $statement = $this->db->prepare(
            'SELECT contract_json, completed_objectives_json
             FROM sunny_island_states WHERE session_id = :session_id'
             . ($lock ? $this->lockClause() : ''),
        );
        $statement->execute(['session_id' => $sessionId]);
        $state = $statement->fetch();
        if ($state === false) {
            throw new ApiException('GAME_STATE_INVALID', 'The Sunny Island adventure state is unavailable.', 409);
        }
        return $state;
    }

    private function decodeContract(string $json): array
    {
        try {
            $contract = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ApiException('GAME_STATE_INVALID', 'The Sunny Island adventure contract is invalid.', 409);
        }
        if (
            !is_array($contract)
            || array_is_list($contract)
            || ($contract['version'] ?? null) !== 1
            || !is_array($contract['objectives'] ?? null)
            || !array_is_list($contract['objectives'])
        ) {
            throw new ApiException('GAME_STATE_INVALID', 'The Sunny Island adventure contract is invalid.', 409);
        }
        return $contract;
    }

    private function validatedSessionId(mixed $sessionId): int
    {
        if (!is_int($sessionId) || $sessionId < 1) {
            throw new ApiException('VALIDATION_ERROR', 'A valid Sunny Island session is required.', 422);
        }
        return $sessionId;
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
