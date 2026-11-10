SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='archived_at')=0, 'ALTER TABLE projects ADD COLUMN archived_at DATETIME NULL', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
