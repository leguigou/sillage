SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='notification_outbox' AND column_name='html_body')=0, 'ALTER TABLE notification_outbox ADD COLUMN html_body MEDIUMTEXT NULL AFTER body', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='company_branding' AND column_name='mail_from_name')=0, 'ALTER TABLE company_branding ADD COLUMN mail_from_name VARCHAR(100) NULL', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
