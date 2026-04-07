CREATE TABLE notebook_vocab (
    vocab_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_id INT UNSIGNED NOT NULL,
    slug VARCHAR(100) NOT NULL COMMENT "Machine-readable identifier (e.g. mood, energy, focus)",
    label_ciphertext VARBINARY(512) NOT NULL COMMENT "Encrypted display label",
    label_nonce VARBINARY(24) NOT NULL,
    description_ciphertext VARBINARY(1024) DEFAULT NULL COMMENT "Encrypted description",
    description_nonce VARBINARY(24) DEFAULT NULL,
    value_type ENUM("text", "number", "boolean", "scale") NOT NULL DEFAULT "text",
    scale_min TINYINT DEFAULT NULL,
    scale_max TINYINT DEFAULT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (actor_id) REFERENCES actors(actor_id),
    UNIQUE KEY uk_actor_slug (actor_id, slug),
    KEY idx_actor_active (actor_id, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
