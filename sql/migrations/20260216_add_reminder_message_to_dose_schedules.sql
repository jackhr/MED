ALTER TABLE dose_schedules
    ADD COLUMN reminder_message VARCHAR(255) NULL AFTER time_of_day;
