USE medicine_log;

ALTER TABLE medicine_intake_logs
    DROP COLUMN IF EXISTS mood;
