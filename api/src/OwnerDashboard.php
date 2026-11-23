<?php
declare(strict_types=1);

final class OwnerDashboard
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function overview(): array
    {
        $from = gmdate('Y-m-d 00:00:00', time() - 29 * 86400);
        $today = gmdate('Y-m-d 23:59:59');
        $now = gmdate('Y-m-d H:i:s');
        $counts = [
            'users_total' => $this->count('SELECT COUNT(*) FROM users'),
            'users_new_30d' => $this->count('SELECT COUNT(*) FROM users WHERE created_at >= :from', ['from' => $from]),
            'users_active_30d' => $this->count(
                'SELECT COUNT(*) FROM users WHERE status = \'active\' AND last_login_at >= :from',
                ['from' => $from],
            ),
            'coins_circulating' => $this->count('SELECT COALESCE(SUM(coin_balance), 0) FROM wallets'),
            'wallet_transactions_total' => $this->count('SELECT COUNT(*) FROM coin_ledger'),
            'wallet_transactions_30d' => $this->count(
                'SELECT COUNT(*) FROM coin_ledger WHERE created_at >= :from',
                ['from' => $from],
            ),
            'diamond_orders_total' => $this->count('SELECT COUNT(*) FROM diamond_recharge_orders'),
            'diamond_orders_pending' => $this->count(
                "SELECT COUNT(*) FROM diamond_recharge_orders
                 WHERE order_status IN ('pending', 'payment_verification', 'paid', 'processing')",
            ),
            'events_active' => $this->count(
                "SELECT COUNT(*) FROM community_events
                 WHERE status = 'published' AND starts_at <= :starts_at AND ends_at > :ends_at",
                [
                    'starts_at' => $now,
                    'ends_at' => $now,
                ],
            ),
            'events_total' => $this->count('SELECT COUNT(*) FROM community_events'),
            'event_votes_total' => $this->count('SELECT COUNT(*) FROM community_event_votes'),
            'event_rewards_total' => $this->count('SELECT COUNT(*) FROM community_event_rewards'),
            'homepage_reward_claims' => $this->count('SELECT COUNT(*) FROM homepage_reward_claims'),
            'attendance_checkins' => $this->count('SELECT COUNT(*) FROM daily_attendance'),
            'games_active' => $this->count("SELECT COUNT(*) FROM game_catalog WHERE status = 'active'"),
            'games_total' => $this->count('SELECT COUNT(*) FROM game_catalog'),
        ];

        return [
            'period_days' => 30,
            'counts' => $counts,
            'diamond_orders_by_status' => $this->groupedCounts(
                'SELECT order_status AS status, COUNT(*) AS total
                 FROM diamond_recharge_orders GROUP BY order_status ORDER BY order_status',
            ),
            'events_by_status' => $this->groupedCounts(
                'SELECT status, COUNT(*) AS total FROM community_events GROUP BY status ORDER BY status',
            ),
            'games_by_status' => $this->groupedCounts(
                'SELECT status, COUNT(*) AS total FROM game_catalog GROUP BY status ORDER BY status',
            ),
            'series' => [
                'users' => $this->dailySeries('users', 'COUNT(*)', $from, $today),
                'wallet_transactions' => $this->dailySeries('coin_ledger', 'COUNT(*)', $from, $today),
                'wallet_coin_volume' => $this->dailySeries('coin_ledger', 'SUM(amount)', $from, $today),
                'diamond_orders' => $this->dailySeries('diamond_recharge_orders', 'COUNT(*)', $from, $today),
                'event_votes' => $this->dailySeries('community_event_votes', 'COUNT(*)', $from, $today),
            ],
            'recent_users' => $this->rows(
                'SELECT id, username, status, created_at, last_login_at
                 FROM users ORDER BY id DESC LIMIT 8',
            ),
            'recent_diamond_orders' => $this->rows(
                'SELECT o.id, o.order_number, o.user_id, u.username, o.player_id,
                        o.diamond_amount, o.price, o.currency_code, o.order_status, o.payment_reference, o.created_at
                 FROM diamond_recharge_orders o
                 JOIN users u ON u.id = o.user_id
                 ORDER BY o.id DESC LIMIT 8',
            ),
            'recent_activity' => $this->activityRows(8),
        ];
    }

    public function users(string $search, ?string $status, string $sort, int $page, int $perPage): array
    {
        $filters = [];
        $parameters = [];
        if ($search !== '') {
            $filters[] = '(u.username LIKE :username OR u.email LIKE :email OR CAST(u.id AS CHAR) = :user_id)';
            $parameters['username'] = '%' . $search . '%';
            $parameters['email'] = '%' . $search . '%';
            $parameters['user_id'] = $search;
        }
        if ($status !== null) {
            $filters[] = 'u.status = :status';
            $parameters['status'] = $status;
        }
        $where = $filters === [] ? '' : ' WHERE ' . implode(' AND ', $filters);
        $order = match ($sort) {
            'username' => 'u.username ASC, u.id DESC',
            'last_login' => 'u.last_login_at DESC, u.id DESC',
            'coins' => 'w.coin_balance DESC, u.id DESC',
            default => 'u.id DESC',
        };
        $count = $this->db->prepare('SELECT COUNT(*) FROM users u' . $where);
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();

        $statement = $this->db->prepare(
            'SELECT u.id, u.username, u.email, u.status, u.email_verified, u.created_at, u.last_login_at,
                    COALESCE(w.coin_balance, 0) AS coin_balance,
                    COALESCE(w.diamond_balance, 0) AS diamond_balance
             FROM users u LEFT JOIN wallets w ON w.user_id = u.id'
            . $where . ' ORDER BY ' . $order . ' LIMIT :limit OFFSET :offset',
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $users = $statement->fetchAll();
        foreach ($users as &$user) {
            foreach (['id', 'coin_balance', 'diamond_balance'] as $field) {
                $user[$field] = (int) $user[$field];
            }
            $user['email_verified'] = (bool) $user['email_verified'];
            $user['roles'] = $this->userRoles($user['id']);
        }
        unset($user);

        return [
            'users' => $users,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function userDetails(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT u.id, u.username, u.email, u.status, u.email_verified, u.created_at, u.updated_at,
                    u.last_login_at, p.full_name, p.display_name, p.avatar_url, p.country, p.bio,
                    w.id AS wallet_id, w.coin_balance, w.purchased_coin_balance, w.earned_coin_balance,
                    w.diamond_balance, w.currency_code, w.updated_at AS wallet_updated_at
             FROM users u
             LEFT JOIN user_profiles p ON p.user_id = u.id
             LEFT JOIN wallets w ON w.user_id = u.id
             WHERE u.id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);
        $user = $statement->fetch();
        if ($user === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'User not found.', 404);
        }
        foreach (['id', 'wallet_id', 'coin_balance', 'purchased_coin_balance', 'earned_coin_balance', 'diamond_balance'] as $field) {
            $user[$field] = $user[$field] === null ? null : (int) $user[$field];
        }
        $user['email_verified'] = (bool) $user['email_verified'];
        $user['roles'] = $this->userRoles($userId);
        $user['permissions'] = $this->userPermissions($userId);
        $user['active_sessions'] = $this->count(
            'SELECT COUNT(*) FROM user_sessions WHERE user_id = :user_id AND revoked_at IS NULL AND expires_at > :now',
            ['user_id' => $userId, 'now' => gmdate('Y-m-d H:i:s')],
        );
        $user['recent_transactions'] = $this->rows(
            'SELECT id, operation_type, source, amount, balance_type, balance_before, balance_after,
                    total_balance_before, total_balance_after, status, note, created_at
             FROM coin_ledger WHERE user_id = :user_id ORDER BY id DESC LIMIT 10',
            ['user_id' => $userId],
        );
        $user['recent_diamond_orders'] = $this->rows(
            'SELECT id, order_number, player_id, diamond_amount, price, currency_code,
                    order_status, payment_status, delivery_status, payment_reference, created_at, updated_at
             FROM diamond_recharge_orders WHERE user_id = :user_id ORDER BY id DESC LIMIT 10',
            ['user_id' => $userId],
        );
        $user['recent_games'] = $this->rows(
            'SELECT s.id, g.name AS game_name, s.status, s.score, s.started_at, s.completed_at
             FROM game_sessions s JOIN game_catalog g ON g.id = s.game_id
             WHERE s.user_id = :user_id ORDER BY s.id DESC LIMIT 10',
            ['user_id' => $userId],
        );
        $user['recent_admin_activity'] = $this->rows(
            'SELECT a.id, a.actor_user_id, u.username AS actor_username, a.actor_role,
                    a.action_type, a.entity_type, a.entity_id, a.payload, a.created_at
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE (a.entity_type = \'users\' AND a.entity_id = :user_id)
                OR (a.entity_type = \'coin_ledger\'
                    AND a.entity_id IN (SELECT id FROM coin_ledger WHERE user_id = :ledger_user_id))
             ORDER BY a.id DESC LIMIT 10',
            ['user_id' => $userId, 'ledger_user_id' => $userId],
        );
        foreach ($user['recent_admin_activity'] as &$entry) {
            if (is_string($entry['payload'])) {
                $entry['payload'] = json_decode($entry['payload'], true);
            }
        }
        unset($entry);
        foreach (['active_sessions', 'id', 'wallet_id', 'coin_balance', 'purchased_coin_balance', 'earned_coin_balance', 'diamond_balance'] as $field) {
            if ($user[$field] !== null) {
                $user[$field] = (int) $user[$field];
            }
        }
        foreach (['recent_transactions' => ['id', 'amount', 'balance_before', 'balance_after', 'total_balance_before', 'total_balance_after'],
            'recent_diamond_orders' => ['id', 'diamond_amount'],
            'recent_games' => ['id', 'score']] as $group => $fields) {
            foreach ($user[$group] as &$row) {
                foreach ($fields as $field) {
                    if (isset($row[$field])) {
                        $row[$field] = (int) $row[$field];
                    }
                }
            }
            unset($row);
        }
        return $user;
    }

    public function updateStatus(int $actorId, int $userId, string $status): array
    {
        if ($actorId === $userId) {
            throw new ApiException('SELF_CHANGE_FORBIDDEN', 'You cannot change your own account status.', 409);
        }
        if (!in_array($status, ['active', 'suspended'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Account status must be active or suspended.', 422);
        }
        $user = $this->lockedUser($userId);
        if ($this->hasRole($userId, 'owner')) {
            throw new ApiException('OWNER_PROTECTED', 'Owner accounts cannot be changed through the dashboard.', 403);
        }
        $before = ['status' => (string) $user['status']];
        if ($before['status'] !== $status) {
            $statement = $this->db->prepare(
                'UPDATE users SET status = :status, updated_at = :updated_at WHERE id = :user_id',
            );
            $statement->execute([
                'status' => $status,
                'updated_at' => gmdate('Y-m-d H:i:s'),
                'user_id' => $userId,
            ]);
            if ($status === 'suspended') {
                $statement = $this->db->prepare(
                    'UPDATE user_sessions SET revoked_at = :revoked_at
                     WHERE user_id = :user_id AND revoked_at IS NULL',
                );
                $statement->execute(['revoked_at' => gmdate('Y-m-d H:i:s'), 'user_id' => $userId]);
            }
            $this->audit($actorId, 'owner_user_status_changed', 'users', $userId, [
                'before' => $before,
                'after' => ['status' => $status],
            ]);
        }
        return ['id' => $userId, 'status' => $status];
    }

    public function updateRole(int $actorId, int $userId, string $role, string $operation): array
    {
        if ($actorId === $userId) {
            throw new ApiException('SELF_CHANGE_FORBIDDEN', 'You cannot change your own roles.', 409);
        }
        if ($role === 'owner' || $role === 'user' || !in_array($operation, ['grant', 'revoke'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Role operation is not allowed.', 422);
        }
        $this->lockedUser($userId);
        if ($this->hasRole($userId, 'owner')) {
            throw new ApiException('OWNER_PROTECTED', 'Owner roles cannot be changed through the dashboard.', 403);
        }
        $roleQuery = $this->db->prepare('SELECT id FROM roles WHERE name = :name');
        $roleQuery->execute(['name' => $role]);
        $roleId = $roleQuery->fetchColumn();
        if ($roleId === false) {
            throw new ApiException('VALIDATION_ERROR', 'The requested role does not exist.', 422);
        }

        $before = $this->userRoles($userId);
        $wasAssigned = in_array($role, $before, true);
        if ($operation === 'grant' && !$wasAssigned) {
            $statement = $this->db->prepare(
                'INSERT INTO user_roles (user_id, role_id, granted_at)
                 VALUES (:user_id, :role_id, :granted_at)',
            );
            $statement->execute([
                'user_id' => $userId,
                'role_id' => (int) $roleId,
                'granted_at' => gmdate('Y-m-d H:i:s'),
            ]);
        } elseif ($operation === 'revoke' && $wasAssigned) {
            $statement = $this->db->prepare(
                'DELETE FROM user_roles WHERE user_id = :user_id AND role_id = :role_id',
            );
            $statement->execute(['user_id' => $userId, 'role_id' => (int) $roleId]);
        }
        $after = $this->userRoles($userId);
        if ($before !== $after) {
            $this->audit($actorId, 'owner_user_role_changed', 'users', $userId, [
                'before' => $before,
                'after' => $after,
                'operation' => $operation,
                'role' => $role,
            ]);
        }
        return ['id' => $userId, 'roles' => $after];
    }

    public function updateProfile(int $actorId, int $userId, array $fields): array
    {
        $this->lockedUser($userId);
        $limits = ['full_name' => 150, 'display_name' => 80, 'country' => 80, 'bio' => 2000];
        if ($fields === [] || array_diff(array_keys($fields), array_keys($limits)) !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Profile fields are invalid.', 422);
        }
        foreach ($fields as $field => $value) {
            if (!is_string($value) || strlen($value) > $limits[$field]) {
                throw new ApiException('VALIDATION_ERROR', 'A profile field has an invalid value.', 422);
            }
            $fields[$field] = trim($value) === '' ? null : trim($value);
        }

        $statement = $this->db->prepare(
            'SELECT full_name, display_name, country, bio FROM user_profiles WHERE user_id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);
        $before = $statement->fetch();
        if ($before === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'User profile not found.', 404);
        }
        $updates = [];
        $parameters = ['user_id' => $userId, 'updated_at' => gmdate('Y-m-d H:i:s')];
        foreach ($fields as $field => $value) {
            $updates[] = $field . ' = :' . $field;
            $parameters[$field] = $value;
        }
        $statement = $this->db->prepare(
            'UPDATE user_profiles SET ' . implode(', ', $updates) . ', updated_at = :updated_at WHERE user_id = :user_id',
        );
        $statement->execute($parameters);
        $after = array_replace($before, $fields);
        if ($before !== $after) {
            $this->audit($actorId, 'owner_user_profile_changed', 'users', $userId, [
                'before' => array_intersect_key($before, $fields),
                'after' => $fields,
            ]);
        }
        return ['id' => $userId, 'profile' => $after];
    }

    public function activity(int $page, int $perPage, string $search): array
    {
        $filters = [];
        $parameters = [];
        if ($search !== '') {
            $filters[] = '(a.action_type LIKE :action OR a.entity_type LIKE :entity OR u.username LIKE :username)';
            $parameters = [
                'action' => '%' . $search . '%',
                'entity' => '%' . $search . '%',
                'username' => '%' . $search . '%',
            ];
        }
        $where = $filters === [] ? '' : ' WHERE ' . implode(' AND ', $filters);
        $count = $this->db->prepare(
            'SELECT COUNT(*) FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id' . $where,
        );
        $count->execute($parameters);
        $total = (int) $count->fetchColumn();
        $statement = $this->db->prepare(
            'SELECT a.id, a.actor_user_id, u.username AS actor_username, a.actor_role,
                    a.action_type, a.entity_type, a.entity_id, a.payload, a.ip_address, a.created_at
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id'
            . $where . ' ORDER BY a.id DESC LIMIT :limit OFFSET :offset',
        );
        foreach ($parameters as $key => $value) {
            $statement->bindValue(':' . $key, $value);
        }
        $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $statement->execute();
        $entries = $statement->fetchAll();
        foreach ($entries as &$entry) {
            foreach (['id', 'actor_user_id', 'entity_id'] as $field) {
                $entry[$field] = $entry[$field] === null ? null : (int) $entry[$field];
            }
            if (is_string($entry['payload'])) {
                $entry['payload'] = json_decode($entry['payload'], true);
            }
        }
        unset($entry);
        return [
            'entries' => $entries,
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
            ],
        ];
    }

    public function games(): array
    {
        $games = $this->rows(
            'SELECT id, slug, name, description, status, free_attempt_limit,
                    reward_amount_coins, reset_timezone, created_at, updated_at
             FROM game_catalog ORDER BY id',
        );
        foreach ($games as &$game) {
            $game['free_attempt_limit'] = (int) $game['free_attempt_limit'];
            $game['reward_amount_coins'] = (int) $game['reward_amount_coins'];
        }
        unset($game);
        return $games;
    }

    public function updateGame(int $actorId, int $gameId, array $fields): array
    {
        $allowed = ['name', 'description', 'status', 'free_attempt_limit', 'reward_amount_coins'];
        if ($fields === [] || array_diff(array_keys($fields), $allowed) !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Game settings are invalid.', 422);
        }
        if (isset($fields['name']) && (!is_string($fields['name']) || trim($fields['name']) === '' || strlen($fields['name']) > 100)) {
            throw new ApiException('VALIDATION_ERROR', 'Game name is invalid.', 422);
        }
        if (isset($fields['description']) && (!is_string($fields['description']) || strlen($fields['description']) > 2000)) {
            throw new ApiException('VALIDATION_ERROR', 'Game description is invalid.', 422);
        }
        if (isset($fields['status']) && !in_array($fields['status'], ['active', 'disabled', 'maintenance'], true)) {
            throw new ApiException('VALIDATION_ERROR', 'Game status is invalid.', 422);
        }
        if (isset($fields['free_attempt_limit']) && (
            !is_int($fields['free_attempt_limit']) || $fields['free_attempt_limit'] < 0 || $fields['free_attempt_limit'] > 1000
        )) {
            throw new ApiException('VALIDATION_ERROR', 'Daily free-attempt limit must be between 0 and 1000.', 422);
        }
        if (isset($fields['reward_amount_coins']) && (
            !is_int($fields['reward_amount_coins']) || $fields['reward_amount_coins'] < 1
            || $fields['reward_amount_coins'] > 100000000
        )) {
            throw new ApiException('VALIDATION_ERROR', 'Game reward amount is invalid.', 422);
        }

        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare(
            'SELECT id, slug, name, description, status, free_attempt_limit, reward_amount_coins
             FROM game_catalog WHERE id = :id' . $lock,
        );
        $statement->execute(['id' => $gameId]);
        $before = $statement->fetch();
        if ($before === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Game not found.', 404);
        }
        $before['free_attempt_limit'] = (int) $before['free_attempt_limit'];
        $before['reward_amount_coins'] = (int) $before['reward_amount_coins'];
        $updates = [];
        $parameters = ['id' => $gameId, 'updated_at' => gmdate('Y-m-d H:i:s')];
        foreach ($fields as $field => $value) {
            $updates[] = $field . ' = :' . $field;
            $parameters[$field] = $field === 'description' && trim($value) === '' ? null : $value;
        }
        $statement = $this->db->prepare(
            'UPDATE game_catalog SET ' . implode(', ', $updates) . ', updated_at = :updated_at WHERE id = :id',
        );
        $statement->execute($parameters);
        $after = array_replace($before, $parameters);
        unset($after['id'], $after['updated_at']);
        $beforeValues = array_intersect_key($before, $fields);
        $afterValues = array_intersect_key($after, $fields);
        if ($beforeValues !== $afterValues) {
            $this->audit($actorId, 'owner_game_settings_changed', 'game_catalog', $gameId, [
                'before' => $beforeValues,
                'after' => $afterValues,
            ]);
        }
        $result = $this->games();
        foreach ($result as $game) {
            if ((int) $game['id'] === $gameId) {
                return $game;
            }
        }
        throw new RuntimeException('Updated game could not be read.');
    }

    public function siteSettings(): array
    {
        $statement = $this->db->query(
            'SELECT setting_key, setting_value, updated_at
             FROM site_settings ORDER BY setting_key',
        );
        $settings = [];
        foreach ($statement->fetchAll() as $row) {
            $settings[$row['setting_key']] = [
                'value' => $row['setting_value'],
                'updated_at' => $row['updated_at'],
            ];
        }
        return $settings;
    }

    public function updateSiteSettings(int $actorId, array $fields): array
    {
        $limits = ['tiktok_url' => 80, 'site_description' => 300];
        if ($fields === [] || array_diff(array_keys($fields), array_keys($limits)) !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Site settings are invalid.', 422);
        }
        foreach ($fields as $key => $value) {
            if (!is_string($value) || trim($value) === '' || strlen($value) > $limits[$key]) {
                throw new ApiException('VALIDATION_ERROR', 'A site setting has an invalid value.', 422);
            }
            $fields[$key] = trim($value);
        }
        if (
            isset($fields['tiktok_url'])
            && preg_match('/\Ahttps:\/\/www\.tiktok\.com\/@[A-Za-z0-9._-]{2,40}\/?\z/', $fields['tiktok_url']) !== 1
        ) {
            throw new ApiException('VALIDATION_ERROR', 'TikTok profile URL must use the official profile URL format.', 422);
        }

        $before = $this->siteSettings();
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
               VALUES (:setting_key, :setting_value, :updated_by, :updated_at)
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                   updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
            : 'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
               VALUES (:setting_key, :setting_value, :updated_by, :updated_at)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value,
                   updated_by = excluded.updated_by, updated_at = excluded.updated_at';
        $statement = $this->db->prepare($sql);
        foreach ($fields as $key => $value) {
            $statement->execute([
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_by' => $actorId,
                'updated_at' => gmdate('Y-m-d H:i:s'),
            ]);
        }
        $after = $this->siteSettings();
        $beforeValues = [];
        $afterValues = [];
        foreach ($fields as $key => $value) {
            $beforeValues[$key] = $before[$key]['value'] ?? null;
            $afterValues[$key] = $after[$key]['value'] ?? null;
        }
        if ($beforeValues !== $afterValues) {
            $this->audit($actorId, 'owner_site_settings_changed', 'site_settings', null, [
                'before' => $beforeValues,
                'after' => $afterValues,
            ]);
        }
        return $after;
    }


    public static function allowedMediaKeys(): array
    {
        $keys = [
            'media_home_hero',
            'media_about_portrait',
            'media_default_avatar',
            'media_coin_icon',
            'media_events_banner_1',
            'media_events_banner_2',
            'media_game_sunny_island',
            'media_game_vault_cracker',
            'media_game_loot_sequence',
            'media_game_memory_match',
            'media_game_picture_jigsaw',
            'media_video_poster_1',
            'media_video_poster_2',
            'media_video_poster_3',
        ];
        foreach (['memory_match' => 8, 'picture_jigsaw' => 3] as $game => $imageCount) {
            for ($image = 1; $image <= $imageCount; $image++) {
                $keys[] = sprintf('media_game_%s_%02d', $game, $image);
            }
        }
        for ($i = 1; $i <= 18; $i++) {
            $keys[] = sprintf('media_gallery_%02d', $i);
        }
        return $keys;
    }

    public function uploadSiteMedia(int $actorId, string $settingKey, array $file, string $projectRoot): array
    {
        if (!in_array($settingKey, self::allowedMediaKeys(), true)) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid site media slot key.', 422);
        }
        $errorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            throw new ApiException('VALIDATION_ERROR', 'Image upload failed or no file was selected.', 422);
        }
        $tmpName = (string) ($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            throw new ApiException('VALIDATION_ERROR', 'Uploaded file could not be verified.', 422);
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size < 1 || $size > 5 * 1024 * 1024) {
            throw new ApiException('VALIDATION_ERROR', 'Image size must be 5 MB or less.', 422);
        }
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = $finfo !== false ? (string) finfo_file($finfo, $tmpName) : '';
        if ($finfo !== false) {
            finfo_close($finfo);
        }
        $mimeToExt = [
            'image/webp' => 'webp',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/gif' => 'gif',
        ];
        $imageInfo = @getimagesize($tmpName);
        if (!isset($mimeToExt[$mime]) || $imageInfo === false || ($imageInfo['mime'] ?? null) !== $mime) {
            throw new ApiException('VALIDATION_ERROR', 'Only valid WebP, PNG, JPG, or GIF images are allowed.', 422);
        }

        $uploadDir = rtrim($projectRoot, '/\\') . '/uploads/site';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Could not create uploads/site directory.');
        }
        $htaccessPath = $uploadDir . '/.htaccess';
        if (!file_exists($htaccessPath)) {
            $written = @file_put_contents(
                $htaccessPath,
                "<FilesMatch \"\\.(php|phtml|php3|php4|php5|php7|php8|pht|phar)$\">\n    Require all denied\n</FilesMatch>\nOptions -Indexes\n",
                LOCK_EX,
            );
            if ($written === false) {
                throw new RuntimeException('Could not protect uploads/site from script execution.');
            }
        }

        $filename = $settingKey . '_' . gmdate('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $mimeToExt[$mime];
        $destination = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($tmpName, $destination)) {
            throw new RuntimeException('Could not save uploaded image file.');
        }

        $before = $this->siteSettings();
        $oldValue = $before[$settingKey]['value'] ?? null;
        $newValue = 'uploads/site/' . $filename;

        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $sql = $driver === 'mysql'
            ? 'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
               VALUES (:setting_key, :setting_value, :updated_by, :updated_at)
               ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value),
                   updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
            : 'INSERT INTO site_settings (setting_key, setting_value, updated_by, updated_at)
               VALUES (:setting_key, :setting_value, :updated_by, :updated_at)
               ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value,
                   updated_by = excluded.updated_by, updated_at = excluded.updated_at';
        $statement = $this->db->prepare($sql);
        $statement->execute([
            'setting_key' => $settingKey,
            'setting_value' => $newValue,
            'updated_by' => $actorId,
            'updated_at' => gmdate('Y-m-d H:i:s'),
        ]);

        if (is_string($oldValue) && str_starts_with($oldValue, 'uploads/site/')) {
            $oldPath = rtrim($projectRoot, '/\\') . '/' . $oldValue;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        $this->audit($actorId, 'owner_site_settings_changed', 'site_settings', null, [
            'setting_key' => $settingKey,
            'before' => $oldValue,
            'after' => $newValue,
        ]);

        return $this->siteSettings();
    }

    public function resetSiteMedia(int $actorId, string $settingKey, string $projectRoot): array
    {
        if (!in_array($settingKey, self::allowedMediaKeys(), true)) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid site media slot key.', 422);
        }
        $before = $this->siteSettings();
        $oldValue = $before[$settingKey]['value'] ?? null;

        $statement = $this->db->prepare('DELETE FROM site_settings WHERE setting_key = :setting_key');
        $statement->execute(['setting_key' => $settingKey]);

        if (is_string($oldValue) && str_starts_with($oldValue, 'uploads/site/')) {
            $oldPath = rtrim($projectRoot, '/\\') . '/' . $oldValue;
            if (is_file($oldPath)) {
                @unlink($oldPath);
            }
        }

        if ($oldValue !== null) {
            $this->audit($actorId, 'owner_site_settings_changed', 'site_settings', null, [
                'setting_key' => $settingKey,
                'before' => $oldValue,
                'after' => null,
            ]);
        }

        return $this->siteSettings();
    }

    private function lockedUser(int $userId): array
    {
        $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $statement = $this->db->prepare('SELECT id, status FROM users WHERE id = :user_id' . $lock);
        $statement->execute(['user_id' => $userId]);
        $user = $statement->fetch();
        if ($user === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'User not found.', 404);
        }
        return $user;
    }

    private function hasRole(int $userId, string $role): bool
    {
        $statement = $this->db->prepare(
            'SELECT 1 FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id AND r.name = :role LIMIT 1',
        );
        $statement->execute(['user_id' => $userId, 'role' => $role]);
        return $statement->fetchColumn() !== false;
    }

    private function userRoles(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT r.name FROM user_roles ur JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id ORDER BY r.name',
        );
        $statement->execute(['user_id' => $userId]);
        return array_column($statement->fetchAll(), 'name');
    }

    private function userPermissions(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT DISTINCT p.name FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id ORDER BY p.name',
        );
        $statement->execute(['user_id' => $userId]);
        return array_column($statement->fetchAll(), 'name');
    }

    private function audit(int $actorId, string $action, string $entity, ?int $entityId, array $payload): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO audit_logs
                (actor_user_id, actor_role, action_type, entity_type, entity_id, payload,
                 ip_address, user_agent, created_at)
             VALUES
                (:actor_user_id, \'owner\', :action_type, :entity_type, :entity_id, :payload,
                 :ip_address, :user_agent, :created_at)',
        );
        $statement->execute([
            'actor_user_id' => $actorId,
            'action_type' => $action,
            'entity_type' => $entity,
            'entity_id' => $entityId,
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 1000) : null,
            'created_at' => gmdate('Y-m-d H:i:s'),
        ]);
    }

    private function count(string $sql, array $parameters = []): int
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        return (int) $statement->fetchColumn();
    }

    private function rows(string $sql, array $parameters = []): array
    {
        $statement = $this->db->prepare($sql);
        $statement->execute($parameters);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            foreach ($row as $key => $value) {
                if ($value !== null && in_array($key, [
                    'id', 'user_id', 'diamond_amount', 'amount', 'coin_balance',
                        'actor_user_id', 'entity_id', 'total_balance_before', 'total_balance_after',
                ], true)) {
                    $row[$key] = (int) $value;
                }
            }
        }
        unset($row);
        return $rows;
    }

    private function groupedCounts(string $sql): array
    {
        $statement = $this->db->query($sql);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['total'] = (int) $row['total'];
        }
        unset($row);
        return $rows;
    }

    private function dailySeries(string $table, string $aggregate, string $from, string $to): array
    {
        $allowedTables = ['users', 'coin_ledger', 'diamond_recharge_orders', 'community_event_votes'];
        $allowedAggregates = ['COUNT(*)', 'SUM(amount)'];
        if (!in_array($table, $allowedTables, true) || !in_array($aggregate, $allowedAggregates, true)) {
            throw new LogicException('Unsupported dashboard series query.');
        }
        $statement = $this->db->prepare(
            'SELECT DATE(created_at) AS day, ' . $aggregate . ' AS value
             FROM ' . $table . '
             WHERE created_at >= :from AND created_at <= :to
             GROUP BY DATE(created_at) ORDER BY DATE(created_at)',
        );
        $statement->execute(['from' => $from, 'to' => $to]);
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            $row['value'] = (int) $row['value'];
        }
        unset($row);
        return $rows;
    }

    private function activityRows(int $limit): array
    {
        $statement = $this->db->prepare(
            'SELECT a.id, a.actor_user_id, u.username AS actor_username, a.action_type,
                    a.entity_type, a.entity_id, a.created_at
             FROM audit_logs a LEFT JOIN users u ON u.id = a.actor_user_id
             ORDER BY a.id DESC LIMIT :limit',
        );
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();
        $rows = $statement->fetchAll();
        foreach ($rows as &$row) {
            foreach (['id', 'actor_user_id', 'entity_id'] as $field) {
                $row[$field] = $row[$field] === null ? null : (int) $row[$field];
            }
        }
        unset($row);
        return $rows;
    }
}
