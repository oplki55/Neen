<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/src/Database.php';

try {
    $db = Database::connect();
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->exec(
                'CREATE TABLE IF NOT EXISTS user_island_inventory (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                    item_key TEXT NOT NULL,
                    price_paid_coins INTEGER NOT NULL,
                    created_at TEXT NOT NULL,
                    UNIQUE (user_id, item_key)
                )',
            );
            $db->exec(
                'CREATE TABLE IF NOT EXISTS user_island_loadout (
                    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
                    equipped_skin TEXT NULL,
                    equipped_weapon TEXT NULL,
                    equipped_pet TEXT NULL,
                    equipped_trail TEXT NULL,
                    updated_at TEXT NOT NULL
                )',
            );
            fwrite(STDOUT, "SQLite Sunny Island shop schema installed successfully.\n");
            exit;
        }
        throw new RuntimeException('Database migration requires MySQL or SQLite.');
    }

    foreach (['schema.sql', 'coins-schema.sql', 'games-schema.sql', 'sunny-island-shop-schema.sql', 'attendance-schema.sql', 'homepage-rewards-schema.sql', 'community-schema.sql', 'diamonds-schema.sql', 'news-ticker-schema.sql'] as $schemaFile) {
        if ($schemaFile === 'coins-schema.sql') {
            $columns = $db->query(
                "SELECT COLUMN_NAME FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets'",
            )->fetchAll(PDO::FETCH_COLUMN);
            if (
                (!in_array('purchased_coin_balance', $columns, true)
                    || !in_array('earned_coin_balance', $columns, true))
                && (int) $db->query('SELECT COUNT(*) FROM wallets WHERE coin_balance > 0')->fetchColumn() > 0
            ) {
                throw new RuntimeException(
                    'Nonzero legacy wallet balances require owner-approved bucket reconciliation before the Coins migration.',
                );
            }
        }

        $db->beginTransaction();
        try {
            $sunnyGame = $db->prepare(
                'UPDATE game_sessions s
                 JOIN game_catalog g ON g.id = s.game_id
                 SET s.status = \'failed\', s.updated_at = UTC_TIMESTAMP()
                 WHERE g.slug = \'sunny_island\'
                   AND s.status IN (\'started\', \'in_progress\')',
            );
            $sunnyGame->execute();

            $db->exec(
                'INSERT INTO audit_logs
                    (actor_user_id, actor_role, action_type, entity_type, entity_id, payload, ip_address, user_agent, created_at)
                 SELECT NULL, NULL, \'sunny_migration_session_invalidated\', \'game_session\', s.id,
                        JSON_OBJECT(\'reason\', \'legacy_unverified_progress_requires_new_session\'),
                        NULL, NULL, UTC_TIMESTAMP()
                 FROM game_sessions s
                 JOIN game_catalog g ON g.id = s.game_id
                 WHERE g.slug = \'sunny_island\'
                   AND s.status = \'failed\'
                   AND s.updated_at >= UTC_TIMESTAMP() - INTERVAL 1 SECOND',
            );
            $db->commit();
        } catch (Throwable $exception) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $exception;
        }

        $schema = file_get_contents(__DIR__ . '/' . $schemaFile);
        if ($schema === false) {
            throw new RuntimeException('Database schema could not be read: ' . $schemaFile);
        }

        foreach (array_filter(array_map('trim', explode(';', $schema))) as $statement) {
            if (str_starts_with($statement, 'ALTER TABLE coin_order_reviews')) {
                continue;
            }
            $db->exec($statement);
        }
    }

    $userColumns = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'",
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('free_fire_id', $userColumns, true)) {
        $db->exec('ALTER TABLE users ADD COLUMN free_fire_id VARCHAR(20) NULL AFTER email');
    }

    $columns = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets'",
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('purchased_coin_balance', $columns, true)) {
        $db->exec('ALTER TABLE wallets ADD COLUMN purchased_coin_balance BIGINT NOT NULL DEFAULT 0 AFTER coin_balance');
    }
    if (!in_array('earned_coin_balance', $columns, true)) {
        $db->exec('ALTER TABLE wallets ADD COLUMN earned_coin_balance BIGINT NOT NULL DEFAULT 0 AFTER purchased_coin_balance');
    }

    $tickerColumns = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'news_ticker_messages'",
    )->fetchAll(PDO::FETCH_COLUMN);
    if ($tickerColumns !== [] && !in_array('source', $tickerColumns, true)) {
        $db->exec("ALTER TABLE news_ticker_messages
            ADD COLUMN source ENUM('Visitor', 'Owner') NOT NULL DEFAULT 'Visitor' AFTER ip_address");
    }

    $diamondColumns = $db->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'diamond_recharge_orders'",
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('payment_reference', $diamondColumns, true)) {
        $db->exec('ALTER TABLE diamond_recharge_orders ADD COLUMN payment_reference VARCHAR(150) NULL AFTER delivery_status');
    }

    $diamondPackages = [
        ['name' => '100 Diamonds', 'diamond_amount' => 100, 'price' => '1.00'],
        ['name' => '300 Diamonds', 'diamond_amount' => 300, 'price' => '2.50'],
        ['name' => '500 Diamonds', 'diamond_amount' => 500, 'price' => '4.50'],
        ['name' => '1,000 Diamonds', 'diamond_amount' => 1000, 'price' => '8.00'],
        ['name' => '3,000 Diamonds', 'diamond_amount' => 3000, 'price' => '22.00'],
        ['name' => '5,000 Diamonds', 'diamond_amount' => 5000, 'price' => '40.00'],
    ];
    $findDiamondPackage = $db->prepare(
        'SELECT id FROM diamond_packages
         WHERE name = :name AND diamond_amount = :diamond_amount
           AND currency_code = :currency_code LIMIT 1',
    );
    $insertDiamondPackage = $db->prepare(
        'INSERT INTO diamond_packages
            (name, diamond_amount, price, currency_code, is_active, created_by, updated_by, created_at, updated_at)
         VALUES (:name, :diamond_amount, :price, \'JOD\', 1, NULL, NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
    );
    $db->beginTransaction();
    try {
        foreach ($diamondPackages as $package) {
            $findDiamondPackage->execute([
                'name' => $package['name'],
                'diamond_amount' => $package['diamond_amount'],
                'currency_code' => 'JOD',
            ]);
            if ($findDiamondPackage->fetchColumn() === false) {
                $insertDiamondPackage->execute($package);
            }
        }
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $db->beginTransaction();
    try {
        $db->exec(
            'INSERT INTO coin_ledger
                (wallet_id, user_id, operation_type, source, amount, balance_type,
                 balance_before, balance_after, total_balance_before, total_balance_after,
                 status, reference_type, reference_id, idempotency_key, note, created_at)
             SELECT w.id, w.user_id, \'credit\', \'migration_opening_balance\', w.coin_balance, \'earned\',
                    0, w.coin_balance, 0, w.coin_balance, \'completed\', \'wallet\', w.id,
                    CONCAT(\'migration-opening-\', w.id), \'Opening balance recorded during Coins ledger migration.\', UTC_TIMESTAMP()
             FROM wallets w
             WHERE w.coin_balance > 0
               AND NOT EXISTS (
                   SELECT 1 FROM coin_ledger l
                   WHERE l.wallet_id = w.id AND l.source = \'migration_opening_balance\'
               )',
        );
        $db->commit();
    } catch (Throwable $exception) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $exception;
    }

    $bucketTotalConstraint = $db->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'wallets'
           AND CONSTRAINT_NAME = 'chk_wallets_coin_bucket_total'",
    )->fetchColumn();
    if ($bucketTotalConstraint === false) {
        $db->exec(
            'ALTER TABLE wallets
             ADD CONSTRAINT chk_wallets_coin_bucket_total
             CHECK (coin_balance = purchased_coin_balance + earned_coin_balance)',
        );
    }

    $db->exec(
        "INSERT INTO permissions (name, description, created_at) VALUES
            ('view_wallet_transactions', 'View wallet transaction history', UTC_TIMESTAMP()),
            ('manage_coin_packages', 'Create and manage NEEN Coins packages', UTC_TIMESTAMP()),
            ('manage_coin_payment_methods', 'Configure Coins payment methods', UTC_TIMESTAMP()),
            ('review_coin_orders', 'Review and approve Coins purchase orders', UTC_TIMESTAMP()),
            ('manage_homepage_rewards', 'Configure homepage reward eligibility and value', UTC_TIMESTAMP()),
            ('manage_community_events', 'Create and manage community events', UTC_TIMESTAMP()),
            ('review_event_suggestions', 'Review community event suggestions', UTC_TIMESTAMP()),
            ('manage_event_rewards', 'Issue and review community event rewards', UTC_TIMESTAMP()),
            ('manage_diamond_packages', 'Create and manage diamond packages', UTC_TIMESTAMP()),
            ('manage_diamond_orders', 'Review and fulfill diamond recharge orders', UTC_TIMESTAMP()),
            ('view_admin_users', 'View users, administrative roles, and effective permissions', UTC_TIMESTAMP()),
            ('view_all_wallets', 'View all wallet balances and Coins ledger entries', UTC_TIMESTAMP()),
            ('access_owner_dashboard', 'Access the owner-only control dashboard and APIs', UTC_TIMESTAMP())
         ON DUPLICATE KEY UPDATE description = VALUES(description)",
    );
    $db->exec(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id
         FROM roles r JOIN permissions p
         WHERE r.name = 'user' AND p.name IN ('view_wallet', 'view_wallet_transactions')",
    );
    $db->exec(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id
         FROM roles r JOIN permissions p
         WHERE r.name = 'super_admin'
           AND p.name IN ('manage_coin_packages', 'manage_coin_payment_methods', 'review_coin_orders', 'adjust_wallet', 'manage_homepage_rewards', 'manage_community_events', 'review_event_suggestions', 'manage_event_rewards', 'manage_diamond_packages', 'manage_diamond_orders', 'view_admin_users', 'view_all_wallets')",
    );
    $db->exec(
        "INSERT IGNORE INTO roles (name, description, created_at)
         VALUES ('owner', 'Dedicated site-owner access', UTC_TIMESTAMP())",
    );
    $db->exec(
        "INSERT IGNORE INTO role_permissions (role_id, permission_id)
         SELECT r.id, p.id
         FROM roles r JOIN permissions p
         WHERE r.name = 'owner'",
    );

    $reviewLedgerForeignKey = $db->query(
        "SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'coin_order_reviews'
           AND CONSTRAINT_NAME = 'fk_coin_order_reviews_ledger'",
    )->fetchColumn();
    if ($reviewLedgerForeignKey === false) {
        $db->exec(
            'ALTER TABLE coin_order_reviews
             ADD CONSTRAINT fk_coin_order_reviews_ledger
             FOREIGN KEY (ledger_entry_id) REFERENCES coin_ledger (id) ON DELETE RESTRICT',
        );
    }

    foreach ([
        'trg_coin_ledger_no_update' => 'UPDATE',
        'trg_coin_ledger_no_delete' => 'DELETE',
    ] as $triggerName => $operation) {
        $exists = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.TRIGGERS
             WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = :trigger_name',
        );
        $exists->execute(['trigger_name' => $triggerName]);
        if ((int) $exists->fetchColumn() === 0) {
            $db->exec(
                'CREATE TRIGGER ' . $triggerName . ' BEFORE ' . $operation . ' ON coin_ledger
                 FOR EACH ROW SIGNAL SQLSTATE \'45000\'
                 SET MESSAGE_TEXT = \'Coin ledger entries are immutable; create a reversal instead\'',
            );
        }
    }
    fwrite(STDOUT, "NEEN account and Coins schemas installed successfully.\n");
} catch (Throwable $exception) {
    error_log('NEEN migration failed: ' . get_class($exception));
    fwrite(STDERR, "Migration failed. Check the server error log and database configuration.\n");
    exit(1);
}
