SET @sillage_estimate_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tickets' AND column_name='estimated_hours')=0, 'ALTER TABLE tickets ADD COLUMN estimated_hours DECIMAL(8,2) UNSIGNED NULL', 'DO 0');
PREPARE sillage_estimate_statement FROM @sillage_estimate_sql;
EXECUTE sillage_estimate_statement;
DEALLOCATE PREPARE sillage_estimate_statement;

CREATE TABLE IF NOT EXISTS teams (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL UNIQUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS team_members (
 team_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(team_id,user_id),
 FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
