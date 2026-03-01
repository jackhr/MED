ALTER TABLE medicine_inventory
    ADD COLUMN initial_stock_on_hand DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER medicine_id,
    ADD COLUMN low_stock_notified_at DATETIME NULL AFTER last_restocked_at;

UPDATE medicine_inventory
SET initial_stock_on_hand = stock_on_hand;

CREATE TABLE medicine_inventory_consumptions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    intake_log_id INT UNSIGNED NOT NULL,
    inventory_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    dosage_value DECIMAL(10,2) NOT NULL,
    dosage_unit VARCHAR(20) NOT NULL DEFAULT 'mg',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_consumptions_intake_log_id (intake_log_id),
    INDEX idx_inventory_consumptions_workspace_inventory (workspace_id, inventory_id),
    INDEX idx_inventory_consumptions_workspace_medicine (workspace_id, medicine_id),
    CONSTRAINT fk_inventory_consumptions_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_consumptions_intake_log
        FOREIGN KEY (intake_log_id) REFERENCES medicine_intake_logs(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_consumptions_inventory
        FOREIGN KEY (inventory_id) REFERENCES medicine_inventory(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_consumptions_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE push_messages (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    user_id INT UNSIGNED NOT NULL,
    source VARCHAR(40) NOT NULL DEFAULT 'manual',
    title VARCHAR(120) NOT NULL,
    body VARCHAR(255) NOT NULL,
    url VARCHAR(255) NOT NULL DEFAULT '/index.php',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_push_messages_user_created (user_id, created_at),
    INDEX idx_push_messages_workspace_created (workspace_id, created_at),
    CONSTRAINT fk_push_messages_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_push_messages_user
        FOREIGN KEY (user_id) REFERENCES app_users(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE
) ENGINE=InnoDB;
