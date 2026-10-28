ALTER TABLE users ADD COLUMN ticket_email_enabled BOOLEAN NOT NULL DEFAULT TRUE;
CREATE TABLE project_email_settings (
 project_id BIGINT UNSIGNED PRIMARY KEY,
 enabled BOOLEAN NOT NULL DEFAULT FALSE,
 notify_actor BOOLEAN NOT NULL DEFAULT FALSE,
 rules_json JSON NOT NULL,
 recipients_json JSON NOT NULL,
 version INT UNSIGNED NOT NULL DEFAULT 1,
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE notification_outbox (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 batch_key CHAR(32) NOT NULL,
 ticket_id BIGINT UNSIGNED NOT NULL,
 project_id BIGINT UNSIGNED NOT NULL,
 recipient_id BIGINT UNSIGNED NOT NULL,
 recipient_email VARCHAR(254) NOT NULL,
 events_json JSON NOT NULL,
 fields_json JSON NOT NULL,
 subject VARCHAR(500) NOT NULL,
 body MEDIUMTEXT NOT NULL,
 state ENUM('pending','sending','sent','cancelled','failed') NOT NULL DEFAULT 'pending',
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 lease_token CHAR(32) NULL,
 available_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 last_attempt_at DATETIME NULL,
 sent_at DATETIME NULL,
 last_error VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY notification_group(batch_key,ticket_id,recipient_id),
 INDEX(state,available_at,id),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 FOREIGN KEY(recipient_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
