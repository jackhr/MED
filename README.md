# Medicine Intake Log (PHP)

Simple PHP + MySQL website for logging medicine intake.

## Features
- Login page (no signup flow) using DB-backed credentials from `app_users`
- Dashboard landing page with quick metrics
- Async add/edit flows (no full-page reload after save/update)
- Edit existing entries in a modal, including delete
- Medicine entity/table (`medicines`) with picker tabs: select existing or add new
- Dosage as numeric amount + unit (`mg`, `ml`, etc.), defaulting to `20 mg`
- Interactive `rating` tags with 5 hover/click stars
- Paginated entries table (10 entries per page)
- Dashboard metrics: entries today, entries this week, average rating this week
- Separate trends page (`trends.php`) with chart + table views (monthly, weekly, weekday, top medicines)
- Separate calendar page (`calendar.php`) with monthly day-by-day intake view
- Search/filter history tools (medicine, rating, date range, text search, quick ranges)
- Export + backup tools (filtered/all CSV export, full JSON backup, full SQL backup)
- Daily dose schedules with browser push reminders
- Manual reminder runner + cron-ready reminder endpoint
- Uses `.env` for database and app configuration

## Project Structure
```
medicine-log/
  public/
    assets/
      app.js
      calendar.js
      trends.js
      style.css
    push-sw.js
    index.php
    calendar.php
    trends.php
  sql/
    init.sql
    migrations/
      20260214_add_mood_and_rating.sql
      20260214_remove_mood_from_intake_logs.sql
      20260215_add_app_users_table.sql
      20260215_add_dose_schedules_and_push.sql
      20260215_convert_tables_to_utf8mb4.sql
  scripts/
    generate_vapid_keys.php
  src/
    Auth.php
    Database.php
    Env.php
  .env
  .env.example
```

## Setup
1. Copy `.env.example` to `.env` and configure DB values.
2. Initialize database:
   ```bash
   mysql -u root -p < sql/init.sql
   ```
3. Apply migrations (for existing databases) in order:
   ```bash
   mysql -u root -p < sql/migrations/20260214_add_mood_and_rating.sql
   mysql -u root -p < sql/migrations/20260214_remove_mood_from_intake_logs.sql
   mysql -u root -p < sql/migrations/20260215_add_app_users_table.sql
   mysql -u root -p < sql/migrations/20260215_add_dose_schedules_and_push.sql
   mysql -u root -p < sql/migrations/20260215_convert_tables_to_utf8mb4.sql
   ```
4. Create your login user:
   ```bash
   php -r "echo password_hash('your_password_here', PASSWORD_DEFAULT), PHP_EOL;"
   ```
   ```sql
   INSERT INTO app_users (username, password_hash)
   VALUES ('your_username', 'paste_hash_here');
   ```
5. Generate VAPID keys for browser push and copy them into `.env`:
   ```bash
   php scripts/generate_vapid_keys.php
   ```
   Keep the private key on one line in `.env` (the script outputs `\n` escapes for you).
6. Start local PHP server:
   ```bash
   php -S localhost:8080 -t public
   ```
7. Open:
   `http://localhost:8080`

## Notes
- Default DB name in the SQL and `.env` is `medicine_log`.
- If you change DB name in `sql/init.sql`, keep `DB_NAME` in `.env` in sync.
- Dashboard, trends, and calendar pages require login (`login.php`).
- Reminders run on demand from the dashboard and can be automated via cron:
  - `https://your-domain/index.php?api=process_reminders&token=YOUR_REMINDER_CRON_TOKEN`
- Set a strong random value for `REMINDER_CRON_TOKEN` in `.env` before enabling cron.
