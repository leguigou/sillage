SET @sillage_connection_fk = (SELECT constraint_name FROM information_schema.key_column_usage WHERE table_schema=DATABASE() AND table_name='connections' AND column_name='project_id' AND referenced_table_name='projects' LIMIT 1);
SET @sillage_sql = IF((SELECT is_nullable FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='connections' AND column_name='project_id')='NO', CONCAT('ALTER TABLE connections DROP FOREIGN KEY `',REPLACE(@sillage_connection_fk,'`','``'),'`, MODIFY COLUMN project_id BIGINT UNSIGNED NULL, ADD CONSTRAINT connections_creation_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE SET NULL'), 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
