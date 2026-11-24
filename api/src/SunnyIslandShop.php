<?php
declare(strict_types=1);

final class SunnyIslandShop
{
    private const CATALOG = [
        'weapon_mp40' => ['key' => 'weapon_mp40', 'category' => 'weapons', 'name' => 'MP40 Rapid', 'price' => 250],
        'weapon_ak47' => ['key' => 'weapon_ak47', 'category' => 'weapons', 'name' => 'AK-47 Heavy', 'price' => 450],
        'skin_neon_crown' => ['key' => 'skin_neon_crown', 'category' => 'skins', 'name' => 'Neon Crown', 'price' => 300],
        'skin_sakura_bloom' => ['key' => 'skin_sakura_bloom', 'category' => 'skins', 'name' => 'Sakura Bloom', 'price' => 400],
        'skin_golden_empress' => ['key' => 'skin_golden_empress', 'category' => 'skins', 'name' => 'Golden Empress', 'price' => 800],
        'pet_snoop_classic' => ['key' => 'pet_snoop_classic', 'category' => 'pets', 'name' => 'Snoop Classic', 'price' => 350],
        'pet_snoop_cyber' => ['key' => 'pet_snoop_cyber', 'category' => 'pets', 'name' => 'Snoop Cyber', 'price' => 650],
        'trail_neon_pink' => ['key' => 'trail_neon_pink', 'category' => 'trails', 'name' => 'Neon Pink', 'price' => 150],
        'trail_cyber_lightning' => ['key' => 'trail_cyber_lightning', 'category' => 'trails', 'name' => 'Cyber Lightning', 'price' => 250],
        'trail_solar_gold' => ['key' => 'trail_solar_gold', 'category' => 'trails', 'name' => 'Solar Gold', 'price' => 400],
    ];

    public function __construct(private readonly PDO $db)
    {
    }

    public static function catalog(): array
    {
        return array_values(self::CATALOG);
    }

    public function state(int $userId): array
    {
        $this->ensureLoadoutRow($userId);
        return [
            'catalog' => self::catalog(),
            'owned' => $this->ownedKeys($userId),
            'loadout' => $this->loadout($userId),
            'wallet' => $this->walletSnapshot($userId),
        ];
    }

    public function leaderboard(): array
    {
        $statement = $this->db->prepare(
            'SELECT u.username,
                    p.level AS island_level,
                    p.xp,
                    p.total_adventures_completed AS completed_adventures,
                    l.equipped_skin,
                    l.equipped_pet
             FROM sunny_island_progress p
             JOIN users u ON u.id = p.user_id
             LEFT JOIN user_island_loadout l ON l.user_id = p.user_id
             WHERE u.status = :status
             ORDER BY p.xp DESC, p.total_adventures_completed DESC, u.username ASC
             LIMIT 10',
        );
        $statement->execute(['status' => 'active']);
        $rows = $statement->fetchAll();
        $leaderboard = [];
        foreach ($rows as $row) {
            $leaderboard[] = [
                'username' => (string) $row['username'],
                'island_level' => (int) $row['island_level'],
                'xp' => (int) $row['xp'],
                'completed_adventures' => (int) $row['completed_adventures'],
                'equipped_skin' => $row['equipped_skin'] === null ? null : (string) $row['equipped_skin'],
                'equipped_pet' => $row['equipped_pet'] === null ? null : (string) $row['equipped_pet'],
            ];
        }
        return ['leaderboard' => $leaderboard];
    }

    public function buy(int $userId, string $itemKey, string $idempotencyKey): array
    {
        $item = self::CATALOG[$itemKey] ?? null;
        if ($item === null) {
            throw new ApiException('SHOP_ITEM_INVALID', 'The requested island item does not exist.', 422);
        }

        $startedHere = !$this->db->inTransaction();
        if ($startedHere) {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $this->db->exec('BEGIN IMMEDIATE');
            } else {
                $this->db->beginTransaction();
            }
        }
        try {
            $lock = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
            $existing = $this->db->prepare(
                'SELECT item_key FROM user_island_inventory WHERE user_id = :user_id AND item_key = :item_key' . $lock,
            );
            $existing->execute(['user_id' => $userId, 'item_key' => $itemKey]);
            if ($existing->fetchColumn() !== false) {
                throw new ApiException('SHOP_ALREADY_OWNED', 'This island item is already owned.', 409);
            }

            $wallet = $this->db->prepare(
                'SELECT id, coin_balance, purchased_coin_balance, earned_coin_balance, version
                 FROM wallets WHERE user_id = :user_id' . $lock,
            );
            $wallet->execute(['user_id' => $userId]);
            $walletRow = $wallet->fetch();
            if ($walletRow === false) {
                throw new ApiException('RESOURCE_NOT_FOUND', 'Wallet not found.', 404);
            }
            $purchased = (int) $walletRow['purchased_coin_balance'];
            $earned = (int) $walletRow['earned_coin_balance'];
            $total = $purchased + $earned;
            $price = (int) $item['price'];
            if ($total < $price) {
                throw new ApiException('INSUFFICIENT_BALANCE', 'Insufficient NEEN Coins balance.', 409);
            }

            $purchasedPart = min($purchased, $price);
            $earnedPart = $price - $purchasedPart;
            $ledger = new CoinsLedger($this->db);
            if ($purchasedPart > 0) {
                $ledger->debitAfterConfirmedUsage(
                    $userId,
                    'purchased',
                    $purchasedPart,
                    (int) $walletRow['id'],
                    $idempotencyKey . '-p',
                    'Sunny Island shop purchase: ' . $itemKey,
                );
            }
            if ($earnedPart > 0) {
                $ledger->debitAfterConfirmedUsage(
                    $userId,
                    'earned',
                    $earnedPart,
                    (int) $walletRow['id'],
                    $idempotencyKey . '-e',
                    'Sunny Island shop purchase: ' . $itemKey,
                );
            }

            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $insert = $this->db->prepare(
                'INSERT INTO user_island_inventory (user_id, item_key, price_paid_coins, created_at)
                 VALUES (:user_id, :item_key, :price, :created_at)',
            );
            $insert->execute([
                'user_id' => $userId,
                'item_key' => $itemKey,
                'price' => $price,
                'created_at' => $now,
            ]);

            if ($startedHere) {
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $this->db->exec('COMMIT');
                } else {
                    $this->db->commit();
                }
            }
            return $this->state($userId);
        } catch (Throwable $exception) {
            if ($startedHere && $this->db->inTransaction()) {
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $this->db->exec('ROLLBACK');
                } else {
                    $this->db->rollBack();
                }
            }
            throw $exception;
        }
    }

    public function saveLoadout(int $userId, mixed $skin, mixed $weapon, mixed $pet, mixed $trail): array
    {
        $values = ['skin' => $skin, 'weapon' => $weapon, 'pet' => $pet, 'trail' => $trail];
        foreach ($values as $field => $value) {
            if ($value !== null && (!is_string($value) || $value === '')) {
                throw new ApiException('VALIDATION_ERROR', 'Island loadout values must be strings or null.', 422);
            }
            if ($value !== null) {
                $item = self::CATALOG[$value] ?? null;
                $expected = $field === 'skin' ? 'skins'
                    : ($field === 'weapon' ? 'weapons'
                        : ($field === 'pet' ? 'pets' : 'trails'));
                if ($item === null || $item['category'] !== $expected) {
                    throw new ApiException('LOADOUT_ITEM_INVALID', 'The selected loadout item is invalid.', 422);
                }
            }
        }

        $startedHere = !$this->db->inTransaction();
        if ($startedHere) {
            if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                $this->db->exec('BEGIN IMMEDIATE');
            } else {
                $this->db->beginTransaction();
            }
        }
        try {
            $owned = $this->ownedKeys($userId);
            $ownedSet = array_fill_keys($owned, true);
            foreach ($values as $value) {
                if ($value !== null && !isset($ownedSet[$value])) {
                    throw new ApiException('LOADOUT_NOT_OWNED', 'The selected island item is not owned.', 409);
                }
            }
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
            $this->ensureLoadoutRow($userId);
            $update = $this->db->prepare(
                'UPDATE user_island_loadout
                 SET equipped_skin = :skin, equipped_weapon = :weapon,
                     equipped_pet = :pet, equipped_trail = :trail, updated_at = :updated_at
                 WHERE user_id = :user_id',
            );
            $update->execute([
                'skin' => $skin,
                'weapon' => $weapon,
                'pet' => $pet,
                'trail' => $trail,
                'updated_at' => $now,
                'user_id' => $userId,
            ]);
            if ($startedHere) {
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $this->db->exec('COMMIT');
                } else {
                    $this->db->commit();
                }
            }
            return ['loadout' => $this->loadout($userId), 'owned' => $owned];
        } catch (Throwable $exception) {
            if ($startedHere && $this->db->inTransaction()) {
                if ($this->db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
                    $this->db->exec('ROLLBACK');
                } else {
                    $this->db->rollBack();
                }
            }
            throw $exception;
        }
    }

    private function ensureLoadoutRow(int $userId): void
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $driver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        $statement = $driver === 'mysql'
            ? $this->db->prepare(
                'INSERT IGNORE INTO user_island_loadout
                 (user_id, equipped_skin, equipped_weapon, equipped_pet, equipped_trail, updated_at)
                 VALUES (:user_id, NULL, NULL, NULL, NULL, :updated_at)',
            )
            : $this->db->prepare(
                'INSERT OR IGNORE INTO user_island_loadout
                 (user_id, equipped_skin, equipped_weapon, equipped_pet, equipped_trail, updated_at)
                 VALUES (:user_id, NULL, NULL, NULL, NULL, :updated_at)',
            );
        $statement->execute(['user_id' => $userId, 'updated_at' => $now]);
    }

    private function ownedKeys(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT item_key FROM user_island_inventory WHERE user_id = :user_id ORDER BY id',
        );
        $statement->execute(['user_id' => $userId]);
        return array_values(array_map(
            static fn (array $row): string => (string) $row['item_key'],
            $statement->fetchAll(),
        ));
    }

    private function loadout(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT equipped_skin, equipped_weapon, equipped_pet, equipped_trail
             FROM user_island_loadout WHERE user_id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if ($row === false) {
            return ['skin' => null, 'weapon' => null, 'pet' => null, 'trail' => null];
        }
        return [
            'skin' => $row['equipped_skin'],
            'weapon' => $row['equipped_weapon'],
            'pet' => $row['equipped_pet'],
            'trail' => $row['equipped_trail'],
        ];
    }

    private function walletSnapshot(int $userId): array
    {
        $statement = $this->db->prepare(
            'SELECT coin_balance, purchased_coin_balance, earned_coin_balance
             FROM wallets WHERE user_id = :user_id',
        );
        $statement->execute(['user_id' => $userId]);
        $row = $statement->fetch();
        if ($row === false) {
            throw new ApiException('RESOURCE_NOT_FOUND', 'Wallet not found.', 404);
        }
        return [
            'earned' => (int) $row['earned_coin_balance'],
            'purchased' => (int) $row['purchased_coin_balance'],
            'total' => (int) $row['coin_balance'],
        ];
    }
}
