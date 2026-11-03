SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='git_sync_jobs' AND column_name='request_id')=0, 'ALTER TABLE git_sync_jobs ADD COLUMN request_id CHAR(32) CHARACTER SET ascii NULL', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
