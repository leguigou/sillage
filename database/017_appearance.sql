ALTER TABLE projects ADD COLUMN icon VARCHAR(32) NOT NULL DEFAULT 'folder';

CREATE TABLE appearance_settings (
    id TINYINT UNSIGNED PRIMARY KEY,
    version INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;
INSERT INTO appearance_settings(id) VALUES(1);

CREATE TABLE appearance_marks (
    kind VARCHAR(16) NOT NULL,
    mark_key VARCHAR(16) NOT NULL,
    icon VARCHAR(32) NOT NULL,
    color CHAR(7) NOT NULL,
    PRIMARY KEY(kind,mark_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
