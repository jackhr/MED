<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Auth.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC');
Auth::startSession();
Auth::requireAuthForPage('login.php');

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
$canWriteWorkspaceData = Auth::canWrite();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Intake Calendar</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="shortcut icon" href="favicon.svg">
    <link rel="stylesheet" href="assets/min/style.css">
    <script>
        window.MEDICINE_CALENDAR_CONFIG = {
            apiPath: "index.php",
            initialMonth: <?= json_encode($initialMonth, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            canWrite: <?= $canWriteWorkspaceData ? 'true' : 'false' ?>
        };
    </script>
    <script src="assets/min/nav.js" defer></script>
    <script src="assets/min/calendar.js" defer></script>
</head>
<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard calendar-dashboard">
        <nav class="hamburger-nav" aria-label="Primary Navigation">
            <button
                type="button"
                class="ghost-btn hamburger-toggle"
                aria-expanded="false"
                aria-controls="primary-menu"
            >
                <span class="hamburger-icon" aria-hidden="true"></span>
                Menu
            </button>
            <div id="primary-menu" class="hamburger-panel" hidden>
                <a class="hamburger-link" href="index.php">Dashboard</a>
                <a class="hamburger-link" href="trends.php">Trends</a>
                <a class="hamburger-link is-active" href="calendar.php">Calendar</a>
                <?php if ($canWriteWorkspaceData): ?>
                    <a class="hamburger-link" href="schedules.php">Schedules</a>
                <?php endif; ?>
                <a class="hamburger-link" href="settings.php">Settings</a>
                <a class="hamburger-link" href="logout.php">Log Out</a>
            </div>
        </nav>

        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Calendar View</h1>
            <p>Review every intake by date and inspect day-by-day patterns.</p>
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
            <div class="calendar-controls-side">
                <div class="calendar-month-picker-wrap">
                    <label for="calendar-month-picker">Jump to month</label>
                    <input id="calendar-month-picker" type="month">
                </div>
                <?php if ($canWriteWorkspaceData): ?>
                    <button id="calendar-open-create-modal-btn" type="button" class="primary-btn">
                        Add Intake
                    </button>
                <?php endif; ?>
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

    <?php if ($canWriteWorkspaceData): ?>
        <section id="calendar-create-modal" class="modal" hidden>
            <div class="modal-backdrop" data-close-calendar-create-modal="true"></div>
            <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="calendar-create-modal-title">
                <div class="modal-header">
                    <h3 id="calendar-create-modal-title">Add Intake</h3>
                    <button type="button" class="ghost-btn" data-close-calendar-create-modal="true">Close</button>
                </div>

                <form id="calendar-create-intake-form" novalidate>
                    <label>Medicine</label>
                    <div id="calendar-create-medicine-picker" class="medicine-picker" data-mode="existing">
                        <div class="medicine-tabs">
                            <button type="button" class="medicine-tab is-active" data-tab-mode="existing">Select Existing</button>
                            <button type="button" class="medicine-tab" data-tab-mode="new">Add New</button>
                        </div>
                        <div class="medicine-panel" data-panel-mode="existing">
                            <select id="calendar-medicine-select" name="medicine_select" required></select>
                        </div>
                        <div class="medicine-panel" data-panel-mode="new" hidden>
                            <input id="calendar-medicine-custom" name="medicine_custom" type="text" maxlength="120" placeholder="Type a new medicine name">
                        </div>
                    </div>

                    <label for="calendar-dosage-value">Dosage</label>
                    <div class="dosage-fields">
                        <input id="calendar-dosage-value" name="dosage_value" type="number" min="0.01" step="0.01" value="20" required>
                        <select id="calendar-dosage-unit" name="dosage_unit" required>
                            <option value="mg" selected>mg</option>
                            <option value="ml">ml</option>
                            <option value="g">g</option>
                            <option value="mcg">mcg</option>
                            <option value="tablet">tablet</option>
                            <option value="drop">drop</option>
                        </select>
                    </div>

                    <label for="calendar-rating">Rating</label>
                    <div id="calendar-create-rating-widget" class="star-rating" role="radiogroup" aria-label="Choose rating">
                        <button type="button" class="star-btn is-active" data-star="1" role="radio" aria-label="1 star" aria-checked="false">★</button>
                        <button type="button" class="star-btn is-active" data-star="2" role="radio" aria-label="2 stars" aria-checked="false">★</button>
                        <button type="button" class="star-btn is-active" data-star="3" role="radio" aria-label="3 stars" aria-checked="true">★</button>
                        <button type="button" class="star-btn" data-star="4" role="radio" aria-label="4 stars" aria-checked="false">★</button>
                        <button type="button" class="star-btn" data-star="5" role="radio" aria-label="5 stars" aria-checked="false">★</button>
                    </div>
                    <input id="calendar-rating" name="rating" type="hidden" value="3" required>

                    <label for="calendar-taken-at">Date & Time Taken</label>
                    <input id="calendar-taken-at" name="taken_at" type="datetime-local" required>

                    <label for="calendar-notes">Notes (Optional)</label>
                    <textarea id="calendar-notes" name="notes" maxlength="255"></textarea>

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" data-close-calendar-create-modal="true">Cancel</button>
                        <button class="primary-btn" type="submit">Save Intake</button>
                    </div>
                </form>
            </div>
        </section>
    <?php endif; ?>
</body>
</html>
