CREATE TABLE IF NOT EXISTS development_deployment_prs (
 deployment_event_id BIGINT UNSIGNED NOT NULL,
 pr_event_id BIGINT UNSIGNED NOT NULL,
 base_sha VARCHAR(64) CHARACTER SET ascii NOT NULL,
 head_sha VARCHAR(64) CHARACTER SET ascii NOT NULL,
 verified_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY(deployment_event_id,pr_event_id),
 FOREIGN KEY(deployment_event_id) REFERENCES development_events(id) ON DELETE CASCADE,
 FOREIGN KEY(pr_event_id) REFERENCES development_events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
