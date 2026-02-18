CREATE TABLE workspaces (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspaces_name (name)
) ENGINE=InnoDB;

CREATE TABLE workspace_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    role ENUM('owner', 'editor', 'viewer') NOT NULL DEFAULT 'viewer',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspace_users_workspace_user (workspace_id, user_id),
    INDEX idx_workspace_users_user_active (user_id, is_active),
    INDEX idx_workspace_users_workspace_active (workspace_id, is_active),
    CONSTRAINT fk_workspace_users_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_workspace_users_user
        FOREIGN KEY (user_id) REFERENCES app_users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO workspaces (name) VALUES ('Default Workspace');

SET @default_workspace_id = (SELECT id FROM workspaces ORDER BY id ASC LIMIT 1);

ALTER TABLE medicines
    ADD COLUMN workspace_id INT UNSIGNED NULL AFTER id;

UPDATE medicines
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

ALTER TABLE medicines
    DROP INDEX uq_medicines_name;

ALTER TABLE medicines
    MODIFY COLUMN workspace_id INT UNSIGNED NOT NULL;

ALTER TABLE medicines
    ADD CONSTRAINT fk_medicines_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE;

ALTER TABLE medicines
    ADD UNIQUE KEY uq_medicines_workspace_name (workspace_id, name);

ALTER TABLE medicines
    ADD INDEX idx_medicines_workspace_id (workspace_id);

ALTER TABLE medicine_intake_logs
    ADD COLUMN workspace_id INT UNSIGNED NULL AFTER id;

UPDATE medicine_intake_logs
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

ALTER TABLE medicine_intake_logs
    MODIFY COLUMN workspace_id INT UNSIGNED NOT NULL;

ALTER TABLE medicine_intake_logs
    ADD CONSTRAINT fk_logs_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE;

ALTER TABLE medicine_intake_logs
    ADD INDEX idx_intake_workspace_taken_at (workspace_id, taken_at);

ALTER TABLE medicine_intake_logs
    ADD INDEX idx_intake_workspace_medicine_id (workspace_id, medicine_id);

ALTER TABLE dose_schedules
    ADD COLUMN workspace_id INT UNSIGNED NULL AFTER id;

UPDATE dose_schedules
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

ALTER TABLE dose_schedules
    MODIFY COLUMN workspace_id INT UNSIGNED NOT NULL;

ALTER TABLE dose_schedules
    ADD CONSTRAINT fk_schedule_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE;

ALTER TABLE dose_schedules
    ADD INDEX idx_schedule_workspace_user_active (workspace_id, user_id, is_active);

ALTER TABLE push_subscriptions
    ADD COLUMN workspace_id INT UNSIGNED NULL AFTER id;

UPDATE push_subscriptions
SET workspace_id = @default_workspace_id
WHERE workspace_id IS NULL;

ALTER TABLE push_subscriptions
    DROP INDEX uq_push_endpoint_hash;

ALTER TABLE push_subscriptions
    MODIFY COLUMN workspace_id INT UNSIGNED NOT NULL;

ALTER TABLE push_subscriptions
    ADD CONSTRAINT fk_push_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE;

ALTER TABLE push_subscriptions
    ADD UNIQUE KEY uq_push_workspace_endpoint_hash (workspace_id, endpoint_hash);

ALTER TABLE push_subscriptions
    ADD INDEX idx_push_workspace_user_active (workspace_id, user_id, is_active);

INSERT INTO workspace_users (workspace_id, user_id, role, is_active)
SELECT
    @default_workspace_id,
    u.id,
    'owner',
    u.is_active
FROM app_users u;
