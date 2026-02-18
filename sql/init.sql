CREATE DATABASE medicine_log
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE medicine_log;

DROP TABLE reminder_dispatches;
DROP TABLE push_subscriptions;
DROP TABLE dose_schedules;
DROP TABLE medicine_intake_logs;
DROP TABLE workspace_users;
DROP TABLE medicines;
DROP TABLE app_users;
DROP TABLE workspaces;

CREATE TABLE workspaces (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_workspaces_name (name)
) ENGINE=InnoDB;

CREATE TABLE app_users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(120) NOT NULL,
    display_name VARCHAR(120) NULL,
    email VARCHAR(190) NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_app_users_username (username),
    UNIQUE KEY uq_app_users_email (email),
    INDEX idx_app_users_active (is_active)
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

CREATE TABLE medicines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_medicines_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    UNIQUE KEY uq_medicines_workspace_name (workspace_id, name),
    INDEX idx_medicines_workspace_id (workspace_id)
) ENGINE=InnoDB;

CREATE TABLE medicine_intake_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    logged_by_user_id INT UNSIGNED NULL,
    dosage_value DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    dosage_unit VARCHAR(20) NOT NULL DEFAULT 'mg',
    rating TINYINT UNSIGNED NOT NULL DEFAULT 3,
    taken_at DATETIME NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_logs_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_logs_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    CONSTRAINT fk_logs_user
        FOREIGN KEY (logged_by_user_id) REFERENCES app_users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL,
    INDEX idx_intake_taken_at (taken_at),
    INDEX idx_intake_medicine_id (medicine_id),
    INDEX idx_intake_logged_by_user_id (logged_by_user_id),
    INDEX idx_intake_workspace_taken_at (workspace_id, taken_at),
    INDEX idx_intake_workspace_medicine_id (workspace_id, medicine_id)
) ENGINE=InnoDB;

CREATE TABLE dose_schedules (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    dosage_value DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    dosage_unit VARCHAR(20) NOT NULL DEFAULT 'mg',
    time_of_day TIME NOT NULL,
    reminder_message VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_schedule_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_schedule_user
        FOREIGN KEY (user_id) REFERENCES app_users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_schedule_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_schedule_user_active (user_id, is_active),
    INDEX idx_schedule_workspace_user_active (workspace_id, user_id, is_active),
    INDEX idx_schedule_time (time_of_day)
) ENGINE=InnoDB;

CREATE TABLE push_subscriptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    endpoint TEXT NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth_key VARCHAR(255) NOT NULL,
    user_agent VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    last_seen_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_push_workspace_endpoint_hash (workspace_id, endpoint_hash),
    INDEX idx_push_workspace_user_active (workspace_id, user_id, is_active),
    CONSTRAINT fk_push_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_push_user
        FOREIGN KEY (user_id) REFERENCES app_users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE reminder_dispatches (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    schedule_id INT UNSIGNED NOT NULL,
    scheduled_for DATETIME NOT NULL,
    sent_count INT UNSIGNED NOT NULL DEFAULT 0,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    error_message VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_dispatch_schedule_time (schedule_id, scheduled_for),
    INDEX idx_dispatch_scheduled_for (scheduled_for),
    CONSTRAINT fk_dispatch_schedule
        FOREIGN KEY (schedule_id) REFERENCES dose_schedules(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO workspaces (name) VALUES
    ('Default Workspace');

INSERT INTO medicines (workspace_id, name) VALUES
    (
        (SELECT id FROM workspaces WHERE name = 'Default Workspace' LIMIT 1),
        'Sample Medicine'
    );

INSERT INTO medicine_intake_logs (workspace_id, medicine_id, dosage_value, dosage_unit, rating, taken_at, notes)
SELECT
    (SELECT id FROM workspaces WHERE name = 'Default Workspace' LIMIT 1),
    (SELECT id FROM medicines WHERE name = 'Sample Medicine' LIMIT 1),
    20.00,
    'mg',
    3,
    NOW(),
    'Sample row so the table is not empty';
