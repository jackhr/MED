CREATE TABLE medicine_inventory (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    stock_on_hand DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    unit VARCHAR(20) NOT NULL DEFAULT 'mg',
    low_stock_threshold DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    reorder_quantity DECIMAL(10,2) NULL,
    last_restocked_at DATETIME NULL,
    updated_by_user_id INT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inventory_workspace_medicine (workspace_id, medicine_id),
    INDEX idx_inventory_workspace_stock (workspace_id, stock_on_hand),
    INDEX idx_inventory_workspace_threshold (workspace_id, low_stock_threshold),
    CONSTRAINT fk_inventory_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_updated_by_user
        FOREIGN KEY (updated_by_user_id) REFERENCES app_users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE medicine_inventory_adjustments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    workspace_id INT UNSIGNED NOT NULL,
    inventory_id INT UNSIGNED NOT NULL,
    medicine_id INT UNSIGNED NOT NULL,
    changed_by_user_id INT UNSIGNED NULL,
    change_amount DECIMAL(10,2) NOT NULL,
    resulting_stock DECIMAL(10,2) NOT NULL,
    unit VARCHAR(20) NOT NULL DEFAULT 'mg',
    reason VARCHAR(32) NOT NULL DEFAULT 'manual_adjustment',
    note VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_inventory_adjustments_workspace_created (workspace_id, created_at),
    INDEX idx_inventory_adjustments_inventory_created (inventory_id, created_at),
    CONSTRAINT fk_inventory_adjustments_workspace
        FOREIGN KEY (workspace_id) REFERENCES workspaces(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_adjustments_inventory
        FOREIGN KEY (inventory_id) REFERENCES medicine_inventory(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_adjustments_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        ON UPDATE CASCADE
        ON DELETE CASCADE,
    CONSTRAINT fk_inventory_adjustments_user
        FOREIGN KEY (changed_by_user_id) REFERENCES app_users(id)
        ON UPDATE CASCADE
        ON DELETE SET NULL
) ENGINE=InnoDB;
