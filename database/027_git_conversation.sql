ALTER TABLE comments MODIFY user_id BIGINT UNSIGNED NULL;
CREATE TABLE IF NOT EXISTS ticket_git_messages (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 connection_id BIGINT UNSIGNED NULL,
 event_id BIGINT UNSIGNED NULL,
 pr_key VARCHAR(190) NOT NULL,
 message_kind VARCHAR(24) NOT NULL,
 comment_id BIGINT UNSIGNED NOT NULL UNIQUE,
 UNIQUE(ticket_id,connection_id,pr_key,message_kind),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 FOREIGN KEY(connection_id) REFERENCES connections(id) ON DELETE SET NULL,
 FOREIGN KEY(event_id) REFERENCES development_events(id) ON DELETE SET NULL,
 FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS ticket_test_procedures (
 comment_id BIGINT UNSIGNED PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NULL,
 submission_key CHAR(32) CHARACTER SET ascii NOT NULL,
 request_hash CHAR(64) CHARACTER SET ascii NOT NULL,
 UNIQUE(ticket_id,submission_key),
 FOREIGN KEY(comment_id) REFERENCES comments(id) ON DELETE CASCADE,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 FOREIGN KEY(event_id) REFERENCES development_events(id) ON DELETE SET NULL
) ENGINE=InnoDB;
