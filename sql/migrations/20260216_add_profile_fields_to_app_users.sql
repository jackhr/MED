ALTER TABLE app_users
    ADD COLUMN display_name VARCHAR(120) NULL AFTER username,
    ADD COLUMN email VARCHAR(190) NULL AFTER display_name,
    ADD UNIQUE KEY uq_app_users_email (email);
