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
$signedInUsername = Auth::displayLabel() ?? '';
$canWriteWorkspaceData = Auth::canWrite();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Tracker Settings</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="shortcut icon" href="favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
    <script>
        window.MEDICINE_SETTINGS_CONFIG = {
            apiPath: "index.php"
        };
    </script>
    <script src="assets/nav.js" defer></script>
    <script src="assets/settings.js" defer></script>
</head>
<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard settings-dashboard">
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
                <?php if ($canWriteWorkspaceData): ?>
                    <a class="hamburger-link" href="schedules.php">Schedules</a>
                <?php endif; ?>
                <a class="hamburger-link is-active" href="settings.php">Settings</a>
                <a class="hamburger-link" href="logout.php">Log Out</a>
            </div>
        </nav>

        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Settings</h1>
            <p>Manage your account details and login credentials.</p>
            <?php if ($signedInUsername !== ''): ?>
                <p class="meta-text">Signed in as <strong id="signed-in-username"><?= e($signedInUsername) ?></strong></p>
            <?php endif; ?>
        </header>

        <?php if ($dbError !== null): ?>
            <div class="alert alert-error">
                <strong>Database connection failed:</strong> <?= e($dbError) ?>
            </div>
        <?php endif; ?>

        <div id="settings-status" class="alert" hidden></div>

        <section class="account-grid">
            <article class="card">
                <div class="section-header">
                    <h2>Account Settings</h2>
                    <span id="account-meta" class="meta-text">Update your username and password.</span>
                </div>
                <form id="account-form" class="account-form" novalidate>
                    <div class="account-form-grid">
                        <div>
                            <label for="account_username">Username</label>
                            <input
                                id="account_username"
                                name="account_username"
                                type="text"
                                maxlength="120"
                                autocomplete="username"
                                value="<?= e($signedInUsername) ?>"
                                required
                            >
                        </div>

                        <div>
                            <label for="account_display_name">Display Name</label>
                            <input
                                id="account_display_name"
                                name="account_display_name"
                                type="text"
                                maxlength="120"
                                autocomplete="name"
                            >
                        </div>

                        <div>
                            <label for="account_email">Email</label>
                            <input
                                id="account_email"
                                name="account_email"
                                type="email"
                                maxlength="190"
                                autocomplete="email"
                            >
                        </div>

                        <div>
                            <label for="account_current_password">Current Password</label>
                            <input
                                id="account_current_password"
                                name="account_current_password"
                                type="password"
                                autocomplete="current-password"
                                required
                            >
                        </div>

                        <div>
                            <label for="account_new_password">New Password (Optional)</label>
                            <input
                                id="account_new_password"
                                name="account_new_password"
                                type="password"
                                minlength="8"
                                autocomplete="new-password"
                            >
                        </div>

                        <div>
                            <label for="account_confirm_password">Confirm New Password</label>
                            <input
                                id="account_confirm_password"
                                name="account_confirm_password"
                                type="password"
                                minlength="8"
                                autocomplete="new-password"
                            >
                        </div>
                    </div>
                    <button id="account-submit-btn" class="primary-btn" type="submit">Update Account</button>
                </form>
            </article>
        </section>
    </main>
</body>
</html>
