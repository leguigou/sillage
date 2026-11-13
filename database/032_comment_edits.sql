SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='comments' AND column_name='edited_at')=0, 'ALTER TABLE comments ADD COLUMN edited_at DATETIME NULL AFTER created_at', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
