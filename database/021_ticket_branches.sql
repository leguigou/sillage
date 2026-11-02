CREATE TABLE IF NOT EXISTS ticket_branch_links (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 connection_id BIGINT UNSIGNED NOT NULL,
 external_key VARCHAR(190) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 branch_name VARCHAR(1000) COLLATE utf8mb4_bin NOT NULL,
 created_by BIGINT UNSIGNED NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY ticket_branch_identity(ticket_id,connection_id,external_key),
 INDEX branch_connection(connection_id,external_key,ticket_id),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 FOREIGN KEY(connection_id) REFERENCES connections(id) ON DELETE CASCADE,
 FOREIGN KEY(created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
