CREATE TABLE IF NOT EXISTS project_connections (
 project_id BIGINT UNSIGNED NOT NULL,
 connection_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(project_id,connection_id), INDEX(connection_id,project_id),
 FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 FOREIGN KEY(connection_id) REFERENCES connections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO project_connections(project_id,connection_id) SELECT project_id,id FROM connections;
SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='repository_connection_id')=0, 'ALTER TABLE projects ADD COLUMN repository_connection_id BIGINT UNSIGNED NULL, ADD CONSTRAINT projects_repository_connection FOREIGN KEY(repository_connection_id) REFERENCES connections(id) ON DELETE SET NULL', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
