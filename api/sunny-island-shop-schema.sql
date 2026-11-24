CREATE TABLE IF NOT EXISTS user_island_inventory (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    item_key VARCHAR(80) NOT NULL,
    price_paid_coins BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_island_item (user_id, item_key),
    CONSTRAINT fk_user_island_inventory_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_island_loadout (
    user_id BIGINT UNSIGNED NOT NULL,
    equipped_skin VARCHAR(80) NULL,
    equipped_weapon VARCHAR(80) NULL,
    equipped_pet VARCHAR(80) NULL,
    equipped_trail VARCHAR(80) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_user_island_loadout_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
