<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC');

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$dbError = null;
try {
    $pdo = Database::connection();
} catch (Throwable $exception) {
    $pdo = null;
    $dbError = $exception->getMessage();
}

$dbReady = $pdo instanceof PDO;
$initialMonth = (string) ($_GET['month'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Intake Calendar</title>
    <link rel="stylesheet" href="assets/style.css">
    <script>
        window.MEDICINE_CALENDAR_CONFIG = {
            apiPath: "index.php",
            initialMonth: <?= json_encode($initialMonth, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>
        };
    </script>
    <script src="assets/calendar.js" defer></script>
</head>
<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard calendar-dashboard">
        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Calendar View</h1>
            <p>Review every intake by date and inspect day-by-day patterns.</p>
            <div class="hero-actions">
                <a class="ghost-btn nav-link" href="index.php">Back To Dashboard</a>
                <a class="ghost-btn nav-link" href="trends.php">View Trends</a>
            </div>
        </header>

        <?php if ($dbError !== null): ?>
            <div class="alert alert-error">
                <strong>Database connection failed:</strong> <?= e($dbError) ?>
            </div>
        <?php endif; ?>

        <div id="calendar-status" class="alert" hidden></div>

        <section class="card calendar-controls" aria-label="Calendar Controls">
            <div class="calendar-nav">
                <button id="calendar-prev" type="button" class="ghost-btn">Previous</button>
                <h2 id="calendar-month-label">Loading...</h2>
                <button id="calendar-next" type="button" class="ghost-btn">Next</button>
            </div>
            <div class="calendar-month-picker-wrap">
                <label for="calendar-month-picker">Jump to month</label>
                <input id="calendar-month-picker" type="month">
            </div>
        </section>

        <section class="calendar-layout">
            <article class="card">
                <div class="calendar-scroll">
                    <div class="calendar-weekdays" aria-hidden="true">
                        <span>Sun</span>
                        <span>Mon</span>
                        <span>Tue</span>
                        <span>Wed</span>
                        <span>Thu</span>
                        <span>Fri</span>
                        <span>Sat</span>
                    </div>
                    <div id="calendar-grid" class="calendar-grid">
                        <div class="calendar-cell is-empty">
                            <p class="calendar-empty-text">Loading calendar...</p>
                        </div>
                    </div>
                </div>
            </article>

            <article class="card day-details-card">
                <h2 id="calendar-day-title">Day Details</h2>
                <p id="calendar-day-summary" class="meta-text">Select a day to inspect intakes.</p>
                <ul id="calendar-day-list" class="day-entry-list">
                    <li class="day-entry-item is-empty">No day selected.</li>
                </ul>
            </article>
        </section>
    </main>
</body>
</html>
