SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='development_events' AND column_name='metadata_json')=0, 'ALTER TABLE development_events ADD COLUMN metadata_json JSON NULL, ADD COLUMN commit_sha VARCHAR(64) NULL, ADD INDEX development_commit(connection_id,commit_sha)', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
CREATE TABLE IF NOT EXISTS development_event_tickets (
 event_id BIGINT UNSIGNED NOT NULL,
 ticket_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(event_id,ticket_id), INDEX(ticket_id,event_id),
 FOREIGN KEY(event_id) REFERENCES development_events(id) ON DELETE CASCADE,
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO development_event_tickets(event_id,ticket_id) SELECT id,ticket_id FROM development_events WHERE ticket_id IS NOT NULL;
