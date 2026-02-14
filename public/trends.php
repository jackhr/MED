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
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Intake Trends</title>
    <link rel="stylesheet" href="assets/style.css">
    <script>
        window.MEDICINE_TRENDS_CONFIG = {
            apiPath: "index.php"
        };
    </script>
    <script src="assets/trends.js" defer></script>
</head>
<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard trends-dashboard">
        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Trends</h1>
            <p>Long-term patterns and summaries to help spot progress over time.</p>
            <div class="hero-actions">
                <a class="ghost-btn nav-link" href="index.php">Back To Dashboard</a>
            </div>
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
        </section>
    </main>
</body>
</html>
