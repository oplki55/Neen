<?php
declare(strict_types=1);

final class AdminPageAccessException extends RuntimeException
{
    public function __construct(public readonly int $httpStatus)
    {
        parent::__construct('Administrative page access denied.');
    }
}

final class AdminPage
{
    private const PAGE_PERMISSIONS = [
        'approve_reward',
        'adjust_wallet',
        'manage_coin_packages',
        'manage_coin_payment_methods',
        'manage_community_events',
        'manage_diamond_orders',
        'manage_diamond_packages',
        'manage_event_rewards',
        'manage_game_settings',
        'manage_homepage_rewards',
        'review_event_suggestions',
        'review_coin_orders',
        'review_payment',
        'view_audit_logs',
        'view_admin_users',
        'view_all_wallets',
        'access_owner_dashboard',
    ];

    public static function authorize(PDO $db, bool $ownerOnly = false): array
    {
        $environment = getenv('APP_ENV') ?: 'production';
        if ($environment !== 'test') {
            $https = $_SERVER['HTTPS'] ?? '';
            if ($https === '' || strtolower((string) $https) === 'off') {
                throw new AdminPageAccessException(400);
            }
        }

        $token = $_COOKIE['neen_session'] ?? null;
        if (!is_string($token) || !preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token)) {
            throw new AdminPageAccessException(401);
        }

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nowText = $now->format('Y-m-d H:i:s');
        $statement = $db->prepare(
            'SELECT s.id AS session_id, s.created_at, s.expires_at,
                    u.id AS user_id, u.username, u.email, u.status
             FROM user_sessions s
             JOIN users u ON u.id = s.user_id
             WHERE s.session_token_hash = :token_hash AND s.revoked_at IS NULL
             LIMIT 1',
        );
        $statement->execute(['token_hash' => hash('sha256', $token)]);
        $session = $statement->fetch();
        if ($session === false || $session['status'] !== 'active') {
            throw new AdminPageAccessException(401);
        }

        $absoluteExpires = (new DateTimeImmutable((string) $session['created_at'], new DateTimeZone('UTC')))
            ->modify('+12 hours');
        if ($session['expires_at'] <= $nowText || $absoluteExpires <= $now) {
            $statement = $db->prepare(
                'UPDATE user_sessions SET revoked_at = :revoked_at
                 WHERE id = :id AND revoked_at IS NULL',
            );
            $statement->execute(['revoked_at' => $nowText, 'id' => $session['session_id']]);
            throw new AdminPageAccessException(401);
        }

        $statement = $db->prepare(
            'SELECT r.name
             FROM user_roles ur
             JOIN roles r ON r.id = ur.role_id
             WHERE ur.user_id = :user_id
             ORDER BY r.name',
        );
        $statement->execute(['user_id' => $session['user_id']]);
        $roles = array_column($statement->fetchAll(), 'name');

        $statement = $db->prepare(
            'SELECT DISTINCT p.name
             FROM user_roles ur
             JOIN role_permissions rp ON rp.role_id = ur.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE ur.user_id = :user_id
             ORDER BY p.name',
        );
        $statement->execute(['user_id' => $session['user_id']]);
        $permissions = array_column($statement->fetchAll(), 'name');

        if ($ownerOnly && (!in_array('owner', $roles, true) || !in_array('access_owner_dashboard', $permissions, true))) {
            throw new AdminPageAccessException(403);
        }

        if (array_intersect(self::PAGE_PERMISSIONS, $permissions) === []) {
            throw new AdminPageAccessException(403);
        }

        $expires = $now->modify('+30 minutes');
        $statement = $db->prepare(
            'UPDATE user_sessions SET expires_at = :expires_at, last_activity_at = :last_activity_at
             WHERE id = :id AND revoked_at IS NULL',
        );
        $statement->execute([
            'expires_at' => $expires->format('Y-m-d H:i:s'),
            'last_activity_at' => $nowText,
            'id' => $session['session_id'],
        ]);
        setcookie('neen_session', $token, [
            'expires' => $expires->getTimestamp(),
            'path' => '/',
            'secure' => $environment !== 'test',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return [
            'user_id' => (int) $session['user_id'],
            'username' => (string) $session['username'],
            'email' => (string) $session['email'],
            'roles' => $roles,
            'permissions' => $permissions,
        ];
    }
}
