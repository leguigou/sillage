SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='tickets' AND column_name='progress_percent')=0, 'ALTER TABLE tickets ADD COLUMN progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0 CHECK (progress_percent <= 100)', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='comments' AND column_name='body_format')=0, 'ALTER TABLE comments ADD COLUMN body_format VARCHAR(10) NOT NULL DEFAULT ''text''', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
