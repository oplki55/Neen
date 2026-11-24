<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/src/ApiException.php';
require_once __DIR__ . '/../api/src/CoinsLedger.php';
require_once __DIR__ . '/../api/src/SunnyIslandShop.php';

function shopAssert(bool $condition, string $label): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$databasePath = tempnam(sys_get_temp_dir(), 'neen-island-shop-');
if ($databasePath === false) {
    throw new RuntimeException('Could not create the temporary Island Shop database.');
}

$db = new PDO('sqlite:' . $databasePath);
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec(file_get_contents(__DIR__ . '/fixtures/account-schema.sqlite.sql') ?: '');
$db->exec(
    'CREATE TABLE user_island_inventory (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        item_key TEXT NOT NULL,
        price_paid_coins INTEGER NOT NULL,
        created_at TEXT NOT NULL,
        UNIQUE (user_id, item_key)
    )',
);
$db->exec(
    'CREATE TABLE user_island_loadout (
        user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
        equipped_skin TEXT NULL,
        equipped_weapon TEXT NULL,
        equipped_pet TEXT NULL,
        equipped_trail TEXT NULL,
        updated_at TEXT NOT NULL
    )',
);

$now = gmdate('Y-m-d H:i:s');
$db->prepare(
    "INSERT INTO users (username, email, password_hash, status, email_verified, created_at, updated_at)
     VALUES ('islandshop', 'islandshop@example.com', 'test-hash', 'active', 1, :now, :now)",
)->execute(['now' => $now]);
$userId = (int) $db->lastInsertId();
$db->prepare(
    'INSERT INTO wallets
     (user_id, coin_balance, purchased_coin_balance, earned_coin_balance, diamond_balance, currency_code, updated_at)
     VALUES (:user_id, :total, :purchased, :earned, 0, \'EGP\', :now)',
)->execute([
    'user_id' => $userId,
    'total' => 1200,
    'purchased' => 600,
    'earned' => 600,
    'now' => $now,
]);

$shop = new SunnyIslandShop($db);
$initial = $shop->state($userId);
shopAssert(count($initial['catalog']) === 10, 'Catalog fetch returns all 10 shop items');
shopAssert(
    $initial['wallet']['purchased'] === 600
        && $initial['wallet']['earned'] === 600
        && $initial['wallet']['total'] === 1200,
    'Catalog fetch returns Purchased, Earned, and total balances',
);

$purchasedBuy = $shop->buy($userId, 'weapon_mp40', 'island-shop-purchased-001');
shopAssert(
    in_array('weapon_mp40', $purchasedBuy['owned'], true)
        && $purchasedBuy['wallet']['purchased'] === 350
        && $purchasedBuy['wallet']['earned'] === 600
        && $purchasedBuy['wallet']['total'] === 950,
    'Purchased Coins buy a permanent weapon',
);

$mixedBuy = $shop->buy($userId, 'skin_golden_empress', 'island-shop-mixed-001');
shopAssert(
    in_array('skin_golden_empress', $mixedBuy['owned'], true)
        && $mixedBuy['wallet']['purchased'] === 0
        && $mixedBuy['wallet']['earned'] === 150
        && $mixedBuy['wallet']['total'] === 150,
    'Mixed Purchased/Earned balance is debited in sequence',
);

try {
    $shop->buy($userId, 'weapon_ak47', 'island-shop-insufficient-001');
    shopAssert(false, 'Insufficient balance is rejected');
} catch (ApiException $exception) {
    shopAssert($exception->errorCode === 'INSUFFICIENT_BALANCE', 'Insufficient balance is rejected');
}

try {
    $shop->buy($userId, 'weapon_mp40', 'island-shop-duplicate-001');
    shopAssert(false, 'Duplicate purchase is rejected');
} catch (ApiException $exception) {
    shopAssert($exception->errorCode === 'SHOP_ALREADY_OWNED', 'Duplicate purchase is rejected');
}

try {
    $shop->saveLoadout($userId, 'skin_sakura_bloom', null, null, null);
    shopAssert(false, 'Equipping an unowned item is rejected');
} catch (ApiException $exception) {
    shopAssert($exception->errorCode === 'LOADOUT_NOT_OWNED', 'Equipping an unowned item is rejected');
}

$shop->buy($userId, 'trail_neon_pink', 'island-shop-trail-001');
$loaded = $shop->saveLoadout($userId, 'skin_golden_empress', 'weapon_mp40', null, 'trail_neon_pink');
shopAssert(
    $loaded['loadout']['skin'] === 'skin_golden_empress'
        && $loaded['loadout']['weapon'] === 'weapon_mp40'
        && $loaded['loadout']['trail'] === 'trail_neon_pink',
    'Owned loadout is persisted',
);
$reloaded = $shop->state($userId);
shopAssert(
    $reloaded['loadout']['skin'] === 'skin_golden_empress'
        && $reloaded['loadout']['weapon'] === 'weapon_mp40'
        && $reloaded['loadout']['trail'] === 'trail_neon_pink',
    'Persisted loadout is returned by catalog fetch',
);

unlink($databasePath);
