CREATE TABLE notebook_entries (
    entry_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NOT NULL,
    vocab_id INT UNSIGNED DEFAULT NULL COMMENT "Optional link to vocab term",
    ciphertext VARBINARY(8192) NOT NULL COMMENT "Encrypted JSON payload",
    nonce VARBINARY(24) NOT NULL,
    logged_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT "When the entry was recorded",
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES actors(actor_id),
    FOREIGN KEY (vocab_id) REFERENCES notebook_vocab(vocab_id),
    KEY idx_actor_logged (actor_id, logged_at DESC),
    KEY idx_actor_vocab (actor_id, vocab_id, logged_at DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
