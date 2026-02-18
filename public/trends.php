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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Intake Trends</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="shortcut icon" href="favicon.svg">
    <link rel="stylesheet" href="assets/style.css">
    <script>
        window.MEDICINE_TRENDS_CONFIG = {
            apiPath: "index.php"
        };
    </script>
    <script src="assets/nav.js" defer></script>
    <script src="assets/trends.js" defer></script>
</head>
<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard trends-dashboard">
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
                <a class="hamburger-link is-active" href="trends.php">Trends</a>
                <a class="hamburger-link" href="calendar.php">Calendar</a>
                <a class="hamburger-link" href="schedules.php">Schedules</a>
                <a class="hamburger-link" href="settings.php">Settings</a>
                <a class="hamburger-link" href="logout.php">Log Out</a>
            </div>
        </nav>

        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Trends</h1>
            <p>Long-term patterns and summaries to help spot progress over time.</p>
        </header>

        <?php if ($dbError !== null): ?>
            <div class="alert alert-error">
                <strong>Database connection failed:</strong> <?= e($dbError) ?>
            </div>
        <?php endif; ?>

        <div id="trends-status" class="alert" hidden></div>

        <section class="metrics-grid trends-metrics" aria-label="Trend Summary Metrics">
            <article class="metric-card">
                <p class="metric-label">Avg Rating This Week</p>
                <p id="trend-avg-week" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Avg Rating Last 90 Days</p>
                <p id="trend-avg-90" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Entries Last 30 Days</p>
                <p id="trend-entries-30" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Active Days Last 30</p>
                <p id="trend-active-days-30" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Avg Dose Gap This Week</p>
                <p id="trend-dose-gap-week" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Avg Dose Gap Last 7 Days</p>
                <p id="trend-dose-gap-7d" class="metric-value">--</p>
            </article>
        </section>

        <section class="trends-grid trends-charts-grid" aria-label="Trend Charts">
            <article class="card">
                <h2>Monthly Rating Trend</h2>
                <p class="meta-text">Average rating by month over the last 6 months.</p>
                <div id="chart-monthly-rating" class="chart-shell">
                    <p class="chart-empty">Loading chart...</p>
                </div>
            </article>

            <article class="card">
                <h2>Weekly Entry Trend</h2>
                <p class="meta-text">Total logged intakes by week over the last 12 weeks.</p>
                <div id="chart-weekly-entries" class="chart-shell">
                    <p class="chart-empty">Loading chart...</p>
                </div>
            </article>

            <article class="card">
                <h2>Top Medicines Usage</h2>
                <p class="meta-text">Relative medicine usage based on entries in the last 90 days.</p>
                <div id="chart-top-medicines" class="chart-shell">
                    <p class="chart-empty">Loading chart...</p>
                </div>
            </article>

            <article class="card">
                <h2>Weekday Intake Pattern</h2>
                <p class="meta-text">Entry volume by weekday over the last 90 days.</p>
                <div id="chart-weekday-pattern" class="chart-shell">
                    <p class="chart-empty">Loading chart...</p>
                </div>
            </article>

            <article class="card trends-card-wide">
                <h2>Dose Time Highlights</h2>
                <p id="chart-dose-order-meta" class="meta-text">Select a dose order to see average time by weekday over the last 90 days.</p>
                <div id="dose-view-controls" class="dose-order-controls" aria-label="Dose chart view selector"></div>
                <div id="dose-order-controls" class="dose-order-controls" aria-label="Dose order selector"></div>
                <div id="dose-medicine-controls" class="dose-order-controls" aria-label="Medicine selector" hidden></div>
                <div id="chart-dose-order" class="chart-shell">
                    <p class="chart-empty">Loading chart...</p>
                </div>
            </article>

            <article class="card trends-card-wide">
                <h2>Dose Gap Trend</h2>
                <p id="chart-dose-interval-meta" class="meta-text">Average time between consecutive doses by week over the last 12 weeks.</p>
                <div id="chart-dose-interval" class="chart-shell">
                    <p class="chart-empty">Loading chart...</p>
                </div>
            </article>

            <article class="card trends-card-wide">
                <h2>Rolling 7-Day Dose Gap</h2>
                <p id="chart-dose-interval-rolling-meta" class="meta-text">Rolling 7-day average time between consecutive doses over the last 30 days.</p>
                <div id="chart-dose-interval-rolling" class="chart-shell">
                    <p class="chart-empty">Loading chart...</p>
                </div>
            </article>
        </section>

        <section class="trends-grid">
            <article class="card">
                <h2>Monthly Average Rating</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Avg Rating</th>
                                <th>Entries</th>
                            </tr>
                        </thead>
                        <tbody id="trend-monthly-body">
                            <tr>
                                <td class="empty-cell" colspan="3">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="card">
                <h2>Weekly Intake Summary</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Week</th>
                                <th>Entries</th>
                                <th>Avg Rating</th>
                            </tr>
                        </thead>
                        <tbody id="trend-weekly-body">
                            <tr>
                                <td class="empty-cell" colspan="3">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="card">
                <h2>Top Medicines (90 Days)</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Medicine</th>
                                <th>Entries</th>
                                <th>Avg Rating</th>
                            </tr>
                        </thead>
                        <tbody id="trend-medicines-body">
                            <tr>
                                <td class="empty-cell" colspan="3">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="card">
                <h2>Weekday Patterns (90 Days)</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Weekday</th>
                                <th>Entries</th>
                                <th>Avg Rating</th>
                            </tr>
                        </thead>
                        <tbody id="trend-weekday-body">
                            <tr>
                                <td class="empty-cell" colspan="3">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>

            <article class="card">
                <h2>Dose Order Timing (90 Days)</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Weekday</th>
                                <th>Avg Time</th>
                                <th>Samples</th>
                            </tr>
                        </thead>
                        <tbody id="trend-dose-order-body">
                            <tr>
                                <td class="empty-cell" colspan="3">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </article>
        </section>
    </main>
</body>
</html>
