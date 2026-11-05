CREATE TABLE ticket_collaborators (
 ticket_id BIGINT UNSIGNED NOT NULL,
 user_id BIGINT UNSIGNED NOT NULL,
 PRIMARY KEY(ticket_id,user_id),
 INDEX(user_id,ticket_id),
 FOREIGN KEY(ticket_id) REFERENCES tickets(id) ON DELETE CASCADE,
 FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
