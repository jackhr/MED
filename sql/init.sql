CREATE DATABASE IF NOT EXISTS medicine_log
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE medicine_log;

DROP TABLE IF EXISTS medicine_intake_logs;
DROP TABLE IF EXISTS medicines;

CREATE TABLE medicines (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_medicines_name (name)
) ENGINE=InnoDB;

CREATE TABLE medicine_intake_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    medicine_id INT UNSIGNED NOT NULL,
    dosage_value DECIMAL(10,2) NOT NULL DEFAULT 20.00,
    dosage_unit VARCHAR(20) NOT NULL DEFAULT 'mg',
    rating TINYINT UNSIGNED NOT NULL DEFAULT 3,
    taken_at DATETIME NOT NULL,
    notes VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_logs_medicine
        FOREIGN KEY (medicine_id) REFERENCES medicines(id)
        ON UPDATE CASCADE
        ON DELETE RESTRICT,
    INDEX idx_intake_taken_at (taken_at),
    INDEX idx_intake_medicine_id (medicine_id)
) ENGINE=InnoDB;

INSERT INTO medicines (name) VALUES
    ('Sample Medicine');

INSERT INTO medicine_intake_logs (medicine_id, dosage_value, dosage_unit, rating, taken_at, notes)
SELECT
    (SELECT id FROM medicines WHERE name = 'Sample Medicine' LIMIT 1),
    20.00,
    'mg',
    3,
    NOW(),
    'Sample row so the table is not empty';
