CREATE TABLE IF NOT EXISTS credit_ledger (
    ledger_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    account_id INT UNSIGNED NOT NULL,
    delta INT NOT NULL,
    balance_after INT NOT NULL,
    reason VARCHAR(50) NOT NULL,
    related_endpoint VARCHAR(100) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (account_id) REFERENCES accounts(account_id),
    KEY idx_account_created (account_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Also: ALTER TABLE accounts ADD COLUMN credit_balance INT NOT NULL DEFAULT 0;
