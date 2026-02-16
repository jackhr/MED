<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/PushNotifications.php';

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
$signedInUsername = Auth::displayLabel() ?? '';
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Dose Schedules</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="shortcut icon" href="favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
    <script>
        window.MEDICINE_SCHEDULES_CONFIG = {
            apiPath: "index.php",
            pushPublicKey: <?= json_encode(PushNotifications::publicKey(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            pushConfigured: <?= PushNotifications::isConfigured() ? 'true' : 'false' ?>
        };
    </script>
    <script src="assets/nav.js" defer></script>
    <script src="assets/schedules.js" defer></script>
</head>
<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard schedules-dashboard">
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
                <a class="hamburger-link" href="calendar.php">Calendar</a>
                <a class="hamburger-link is-active" href="schedules.php">Schedules</a>
                <a class="hamburger-link" href="settings.php">Settings</a>
                <a class="hamburger-link" href="logout.php">Log Out</a>
            </div>
        </nav>

        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Dose Schedules</h1>
            <p>Manage recurring reminders and browser push notification delivery.</p>
            <?php if ($signedInUsername !== ''): ?>
                <p class="meta-text">Signed in as <strong><?= e($signedInUsername) ?></strong></p>
            <?php endif; ?>
        </header>

        <?php if ($dbError !== null): ?>
            <div class="alert alert-error">
                <strong>Database connection failed:</strong> <?= e($dbError) ?>
            </div>
        <?php endif; ?>

        <div id="schedules-status" class="alert" hidden></div>

        <section class="schedule-grid">
            <article class="card">
                <div class="section-header">
                    <h2>Dose Schedules</h2>
                    <span id="schedule-meta" class="meta-text">Daily reminder times with browser push alerts.</span>
                </div>

                <div class="push-controls">
                    <button id="enable-push-btn" type="button" class="ghost-btn">Enable Push Notifications</button>
                    <button id="disable-push-btn" type="button" class="ghost-btn">Disable Push Notifications</button>
                    <button id="test-push-btn" type="button" class="ghost-btn">Send Test Push</button>
                    <button id="run-reminders-btn" type="button" class="ghost-btn">Run Reminder Check</button>
                </div>
                <p id="push-status" class="meta-text">Push not configured.</p>

                <form id="schedule-form" novalidate>
                    <div class="schedule-form-grid">
                        <div>
                            <label for="schedule_medicine_id">Medicine</label>
                            <select id="schedule_medicine_id" name="schedule_medicine_id" required></select>
                        </div>

                        <div>
                            <label for="schedule_time_of_day">Reminder Time</label>
                            <input id="schedule_time_of_day" name="schedule_time_of_day" type="time" required>
                        </div>

                        <div>
                            <label for="schedule_reminder_message">Reminder Message (Optional)</label>
                            <input
                                id="schedule_reminder_message"
                                name="schedule_reminder_message"
                                type="text"
                                maxlength="255"
                                placeholder="e.g. Please log morning dose now."
                            >
                        </div>

                        <div id="schedule-dosage-wrap">
                            <label for="schedule_dosage_value">Dosage</label>
                            <div class="dosage-fields">
                                <input id="schedule_dosage_value" name="schedule_dosage_value" type="number" min="0.01" step="0.01" value="20" required>
                                <select id="schedule_dosage_unit" name="schedule_dosage_unit" required>
                                    <option value="mg" selected>mg</option>
                                    <option value="ml">ml</option>
                                    <option value="g">g</option>
                                    <option value="mcg">mcg</option>
                                    <option value="tablet">tablet</option>
                                    <option value="drop">drop</option>
                                </select>
                            </div>
                        </div>

                        <div class="schedule-active-wrap">
                            <label for="schedule_is_active">Enabled</label>
                            <input id="schedule_is_active" name="schedule_is_active" type="checkbox" checked>
                        </div>
                    </div>

                    <div class="schedule-form-actions">
                        <button id="schedule-submit-btn" class="primary-btn" type="submit">Add Schedule</button>
                    </div>
                </form>
            </article>

            <article class="card">
                <h2>Current Schedules</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Medicine</th>
                                <th>Dosage</th>
                                <th>Message</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="schedules-body">
                            <tr>
                                <td class="empty-cell" colspan="6">Loading schedules...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </main>

    <section id="schedule-edit-modal" class="modal" hidden>
        <div class="modal-backdrop" data-close-schedule-modal="true"></div>
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="schedule-edit-modal-title">
            <div class="modal-header">
                <h3 id="schedule-edit-modal-title">Edit Schedule</h3>
                <button type="button" class="ghost-btn" data-close-schedule-modal="true">Close</button>
            </div>

            <form id="schedule-edit-form" novalidate>
                <input id="schedule_edit_id" name="schedule_edit_id" type="hidden">

                <div class="schedule-form-grid">
                    <div>
                        <label for="schedule_edit_medicine_id">Medicine</label>
                        <select id="schedule_edit_medicine_id" name="schedule_edit_medicine_id" required></select>
                    </div>

                    <div>
                        <label for="schedule_edit_time_of_day">Reminder Time</label>
                        <input id="schedule_edit_time_of_day" name="schedule_edit_time_of_day" type="time" required>
                    </div>

                    <div>
                        <label for="schedule_edit_reminder_message">Reminder Message (Optional)</label>
                        <input
                            id="schedule_edit_reminder_message"
                            name="schedule_edit_reminder_message"
                            type="text"
                            maxlength="255"
                            placeholder="e.g. Please log morning dose now."
                        >
                    </div>

                    <div>
                        <label for="schedule_edit_dosage_value">Dosage</label>
                        <div class="dosage-fields">
                            <input id="schedule_edit_dosage_value" name="schedule_edit_dosage_value" type="number" min="0.01" step="0.01" required>
                            <select id="schedule_edit_dosage_unit" name="schedule_edit_dosage_unit" required>
                                <option value="mg">mg</option>
                                <option value="ml">ml</option>
                                <option value="g">g</option>
                                <option value="mcg">mcg</option>
                                <option value="tablet">tablet</option>
                                <option value="drop">drop</option>
                            </select>
                        </div>
                    </div>

                    <div class="schedule-active-wrap">
                        <label for="schedule_edit_is_active">Enabled</label>
                        <input id="schedule_edit_is_active" name="schedule_edit_is_active" type="checkbox">
                    </div>
                </div>

                <div class="modal-actions">
                    <button id="schedule-edit-delete-btn" type="button" class="danger-btn">Delete Schedule</button>
                    <button id="schedule-edit-toggle-btn" type="button" class="ghost-btn" hidden>Pause Schedule</button>
                    <button id="schedule-edit-submit-btn" class="primary-btn" type="submit">Save Schedule</button>
                    <button type="button" class="ghost-btn" data-close-schedule-modal="true">Cancel</button>
                </div>
            </form>
        </div>
    </section>
</body>
</html>
