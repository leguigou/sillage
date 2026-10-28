CREATE TABLE team_projects (
    team_id BIGINT UNSIGNED NOT NULL,
    project_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY(team_id,project_id),
    INDEX(project_id),
    FOREIGN KEY(team_id) REFERENCES teams(id) ON DELETE CASCADE,
    FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE
) ENGINE=InnoDB;
ALTER TABLE tickets ADD COLUMN team_id BIGINT UNSIGNED NULL,
    ADD INDEX(team_id,project_id),
    ADD CONSTRAINT tickets_project_team FOREIGN KEY(team_id,project_id) REFERENCES team_projects(team_id,project_id);
