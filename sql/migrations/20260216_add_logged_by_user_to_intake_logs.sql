ALTER TABLE medicine_intake_logs
    ADD COLUMN logged_by_user_id INT UNSIGNED NULL AFTER medicine_id;

ALTER TABLE medicine_intake_logs
    ADD INDEX idx_intake_logged_by_user_id (logged_by_user_id);

ALTER TABLE medicine_intake_logs
    ADD CONSTRAINT fk_logs_user
    FOREIGN KEY (logged_by_user_id) REFERENCES app_users(id)
    ON UPDATE CASCADE
    ON DELETE SET NULL;
