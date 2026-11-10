SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='comments' AND column_name='parent_id')=0, 'ALTER TABLE comments ADD COLUMN parent_id BIGINT UNSIGNED NULL AFTER ticket_id, ADD KEY comments_parent (parent_id), ADD CONSTRAINT comments_parent_fk FOREIGN KEY (parent_id) REFERENCES comments(id) ON DELETE CASCADE', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
SET @sillage_sql = IF((SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='projects' AND column_name='comment_order')=0, 'ALTER TABLE projects ADD COLUMN comment_order VARCHAR(4) NOT NULL DEFAULT ''asc''', 'DO 0');
PREPARE sillage_statement FROM @sillage_sql;
EXECUTE sillage_statement;
DEALLOCATE PREPARE sillage_statement;
