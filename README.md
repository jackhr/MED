# MED (Medicine Intake Log)

PHP + MySQL web app for tracking medicine intake, trends, schedules, and reminders.

## Features
- Login/logout with DB-backed users (`app_users`)
- Workspace-scoped data with role-based membership (`workspaces`, `workspace_users`)
- Owner/editor/viewer permissions with read-only UI + API enforcement for viewers
- Dashboard with async entry create/edit/delete flows
- Grouped day history with pagination, filters, and search
- Medicine management (`medicines`) with quick select or add-new
- Numeric dosage + unit (`mg`, `ml`, etc.)
- Interactive 1-5 star ratings
- Trends page with charts/tables, including:
  - Dose Time Highlights
  - Single-dose and combined-day views
  - Average time lines for 1st..nth doses
  - Average dosage summaries for 1st..nth doses
- Calendar page (monthly intake view)
- Schedules page with push notification reminders
- Settings page for account profile and password updates
- Export and backup tools (CSV, JSON, SQL)
- Server-side request/reminder logging to file for troubleshooting
- Environment-based config via `.env`

## Main Pages
- `public/login.php`
- `public/index.php` (dashboard + API endpoint)
- `public/trends.php`
- `public/calendar.php`
- `public/schedules.php`
- `public/settings.php`
- `public/logout.php`

## Project Structure
```text
MED/
  public/
    assets/
      app.js
      calendar.js
      nav.js
      schedules.js
      settings.js
      trends.js
      style.css
    favicon.svg
    info.php
    login.php
    logout.php
    index.php
    calendar.php
    trends.php
    schedules.php
    settings.php
    push-sw.js
  sql/
    init.sql
    migrations/
      20260214_add_mood_and_rating.sql
      20260214_remove_mood_from_intake_logs.sql
      20260215_add_app_users_table.sql
      20260215_add_dose_schedules_and_push.sql
      20260215_convert_tables_to_utf8mb4.sql
      20260216_add_logged_by_user_to_intake_logs.sql
      20260216_add_profile_fields_to_app_users.sql
      20260216_add_reminder_message_to_dose_schedules.sql
      20260218_add_workspaces_and_memberships.sql
  scripts/
    generate_vapid_keys.php
    seed_fake_users.php
  src/
    Auth.php
    Database.php
    Env.php
    PushNotifications.php
  .env
  .env.example
```

## Setup
1. Copy `.env.example` to `.env` and set DB/app values.
2. Initialize a new database from scratch:
   ```bash
   mysql -u root -p < sql/init.sql
   ```
3. For existing installs, apply migrations in order:
   ```bash
   mysql -u root -p medicine_log < sql/migrations/20260214_add_mood_and_rating.sql
   mysql -u root -p medicine_log < sql/migrations/20260214_remove_mood_from_intake_logs.sql
   mysql -u root -p medicine_log < sql/migrations/20260215_add_app_users_table.sql
   mysql -u root -p medicine_log < sql/migrations/20260215_add_dose_schedules_and_push.sql
   mysql -u root -p medicine_log < sql/migrations/20260215_convert_tables_to_utf8mb4.sql
   mysql -u root -p medicine_log < sql/migrations/20260216_add_logged_by_user_to_intake_logs.sql
   mysql -u root -p medicine_log < sql/migrations/20260216_add_profile_fields_to_app_users.sql
   mysql -u root -p medicine_log < sql/migrations/20260216_add_reminder_message_to_dose_schedules.sql
   mysql -u root -p medicine_log < sql/migrations/20260218_add_workspaces_and_memberships.sql
   ```
4. Create your first login user:
   ```bash
   php -r "echo password_hash('your_password_here', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   ```sql
   INSERT INTO app_users (username, password_hash, display_name, email)
   VALUES ('your_username', 'REPLACE_WITH_HASH', 'Your Name', 'you@example.com');

   INSERT INTO workspace_users (workspace_id, user_id, role, is_active)
   VALUES (
       (SELECT id FROM workspaces ORDER BY id ASC LIMIT 1),
       (SELECT id FROM app_users WHERE username = 'your_username' LIMIT 1),
       'owner',
       1
   );
   ```
   For read-only accounts, use role `'viewer'` in `workspace_users`.
5. Generate VAPID keys for browser push and set them in `.env`:
   ```bash
   php scripts/generate_vapid_keys.php
   ```
6. Optional: seed two read-only demo users (auto-reads `.env` DB settings):
   ```bash
   php scripts/seed_fake_users.php
   ```
7. Optional but recommended reminder logging config in `.env`:
   - `APP_LOG_ENABLED=1`
   - `APP_LOG_FILE=logs/medicine.log`
   - `REMINDER_GRACE_SECONDS=90`
8. Run locally:
   ```bash
   php -S localhost:8080 -t public
   ```
9. Open `http://localhost:8080`.

## Cron Reminders
- Reminder processing endpoint:
  - `https://your-domain/index.php?api=process_reminders&token=YOUR_REMINDER_CRON_TOKEN`
- cPanel cron command example:
  - `/usr/bin/curl -fsS "https://your-domain/index.php?api=process_reminders&token=YOUR_REMINDER_CRON_TOKEN" >/dev/null`
- Set a strong `REMINDER_CRON_TOKEN` in `.env`.
- `REMINDER_GRACE_SECONDS` adds tolerance for late cron starts to avoid missed reminders.

## Notes
- Schema/database defaults to `utf8mb4` for emoji-safe content.
- Keep `DB_NAME` in `.env` aligned with the database selected in `sql/init.sql`.
- All app pages require login except `login.php`.
- Reminder + push logs are written to `APP_LOG_FILE` (default: `logs/medicine.log`, fallback: system temp dir).
