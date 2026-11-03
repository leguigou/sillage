SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='git_sync_state' AND column_name='repository_watermark')=0, 'ALTER TABLE git_sync_state ADD COLUMN repository_watermark DATETIME NULL', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
