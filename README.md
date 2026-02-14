# Medicine Intake Log (PHP)

Simple PHP + MySQL website for logging medicine intake.

## Features
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
    index.php
    calendar.php
    trends.php
  sql/
    init.sql
    migrations/
      20260214_add_mood_and_rating.sql
      20260214_remove_mood_from_intake_logs.sql
  src/
    Database.php
    Env.php
  .env
  .env.example
```

## Setup
1. Configure DB credentials in `.env`.
2. Initialize database:
   ```bash
   mysql -u root -p < sql/init.sql
   ```
3. Apply migrations (for existing databases) in order:
   ```bash
   mysql -u root -p < sql/migrations/20260214_add_mood_and_rating.sql
   mysql -u root -p < sql/migrations/20260214_remove_mood_from_intake_logs.sql
   ```
4. Start local PHP server:
   ```bash
   php -S localhost:8080 -t public
   ```
5. Open:
   `http://localhost:8080`

## Notes
- Default DB name in the SQL and `.env` is `medicine_log`.
- If you change DB name in `sql/init.sql`, keep `DB_NAME` in `.env` in sync.
