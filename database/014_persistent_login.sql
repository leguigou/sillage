CREATE TABLE persistent_logins (
 selector CHAR(24) PRIMARY KEY,
 validator_hash CHAR(64) NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 auth_version INT UNSIGNED NOT NULL,
 expires_at DATETIME NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_used_at DATETIME NULL,
 INDEX(expires_at),
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
