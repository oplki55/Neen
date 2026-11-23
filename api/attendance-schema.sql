CREATE TABLE IF NOT EXISTS daily_attendance (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    attendance_date DATE NOT NULL,
    cycle_number INT UNSIGNED NOT NULL,
    cycle_day TINYINT UNSIGNED NOT NULL,
    reward_amount BIGINT UNSIGNED NOT NULL DEFAULT 100,
    ledger_entry_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_daily_attendance_user_date (user_id, attendance_date),
    UNIQUE KEY uq_daily_attendance_user_cycle_day (user_id, cycle_number, cycle_day),
    UNIQUE KEY uq_daily_attendance_ledger_entry (ledger_entry_id),
    KEY idx_daily_attendance_user_cycle (user_id, cycle_number, cycle_day),
    CONSTRAINT chk_daily_attendance_cycle_day CHECK (cycle_day BETWEEN 1 AND 7),
    CONSTRAINT chk_daily_attendance_reward CHECK (reward_amount = 100),
    CONSTRAINT fk_daily_attendance_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE RESTRICT,
    CONSTRAINT fk_daily_attendance_ledger FOREIGN KEY (ledger_entry_id) REFERENCES coin_ledger (id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
