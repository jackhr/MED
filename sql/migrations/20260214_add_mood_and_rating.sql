USE medicine_log;

ALTER TABLE medicine_intake_logs
    ADD COLUMN mood VARCHAR(20) NOT NULL DEFAULT 'neutral' AFTER dosage_unit,
    ADD COLUMN rating TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER mood;

UPDATE medicine_intake_logs
SET mood = 'neutral'
WHERE mood IS NULL
   OR mood = ''
   OR mood NOT IN ('happy', 'calm', 'sleepy', 'sick', 'irritable', 'neutral');

UPDATE medicine_intake_logs
SET rating = 3
WHERE rating IS NULL
   OR rating < 1
   OR rating > 5;
