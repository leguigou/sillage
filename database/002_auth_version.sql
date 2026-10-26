SET @sillage_auth_column_exists = (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='users' AND column_name='auth_version');
SET @sillage_auth_column_sql = IF(@sillage_auth_column_exists=0, 'ALTER TABLE users ADD COLUMN auth_version INT NOT NULL DEFAULT 1', 'DO 0');
PREPARE sillage_auth_column_statement FROM @sillage_auth_column_sql;
EXECUTE sillage_auth_column_statement;
DEALLOCATE PREPARE sillage_auth_column_statement;
