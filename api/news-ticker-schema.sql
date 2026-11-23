CREATE TABLE IF NOT EXISTS news_ticker_settings (
    singleton TINYINT UNSIGNED NOT NULL DEFAULT 1,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    theme VARCHAR(32) NOT NULL DEFAULT 'pink_indigo',
    speed_seconds SMALLINT UNSIGNED NOT NULL DEFAULT 55,
    background_color CHAR(7) NOT NULL DEFAULT '#17132F',
    text_color CHAR(7) NOT NULL DEFAULT '#FFF8FC',
    sender_color CHAR(7) NOT NULL DEFAULT '#F3A3C6',
    separator_color CHAR(7) NOT NULL DEFAULT '#C8C8ED',
    glow_color CHAR(7) NOT NULL DEFAULT '#D65F96',
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (singleton),
    CONSTRAINT chk_news_ticker_singleton CHECK (singleton = 1),
    CONSTRAINT chk_news_ticker_speed CHECK (speed_seconds BETWEEN 20 AND 120),
    CONSTRAINT fk_news_ticker_settings_updater FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS news_ticker_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_name VARCHAR(40) NOT NULL,
    message VARCHAR(280) NOT NULL,
    status ENUM('Pending', 'Approved', 'Rejected', 'Deleted') NOT NULL DEFAULT 'Pending',
    display_active TINYINT(1) NOT NULL DEFAULT 0,
    ip_address VARCHAR(45) NULL,
    source ENUM('Visitor', 'Owner') NOT NULL DEFAULT 'Visitor',
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_news_ticker_public (status, display_active, approved_at),
    KEY idx_news_ticker_status_created (status, created_at),
    CONSTRAINT fk_news_ticker_approver FOREIGN KEY (approved_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO news_ticker_settings
    (singleton, enabled, theme, speed_seconds, background_color, text_color,
     sender_color, separator_color, glow_color, updated_at)
VALUES
    (1, 1, 'pink_indigo', 55, '#17132F', '#FFF8FC', '#F3A3C6', '#C8C8ED', '#D65F96', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE singleton = singleton;
