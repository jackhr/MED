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
$workspaceRole = Auth::workspaceRole() ?? 'viewer';
$canWriteWorkspaceData = Auth::canWrite();
$inventoryTableColumnCount = $canWriteWorkspaceData ? 9 : 8;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Inventory</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="shortcut icon" href="favicon.svg">
    <link rel="stylesheet" href="assets/min/style.css">
    <script>
        window.MEDICINE_INVENTORY_CONFIG = {
            apiPath: "index.php",
            canWrite: <?= $canWriteWorkspaceData ? 'true' : 'false' ?>
        };
    </script>
    <script src="assets/min/nav.js" defer></script>
    <script src="assets/min/inventory.js" defer></script>
</head>
<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard inventory-dashboard">
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
                <a class="hamburger-link is-active" href="inventory.php">Inventory</a>
                <?php if ($canWriteWorkspaceData): ?>
                    <a class="hamburger-link" href="schedules.php">Schedules</a>
                <?php endif; ?>
                <a class="hamburger-link" href="settings.php">Settings</a>
                <a class="hamburger-link" href="logout.php">Log Out</a>
            </div>
        </nav>

        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Medication Inventory</h1>
            <p>Track stock levels, thresholds, and adjustments to avoid running out.</p>
            <?php if ($signedInUsername !== ''): ?>
                <p class="meta-text">Signed in as <strong><?= e($signedInUsername) ?></strong></p>
            <?php endif; ?>
            <p class="meta-text">Workspace role: <strong><?= e(ucfirst($workspaceRole)) ?></strong></p>
        </header>

        <?php if ($dbError !== null): ?>
            <div class="alert alert-error">
                <strong>Database connection failed:</strong> <?= e($dbError) ?>
            </div>
        <?php endif; ?>

        <div id="inventory-status" class="alert" hidden></div>

        <section class="metrics-grid inventory-metrics" aria-label="Inventory Summary Metrics">
            <article class="metric-card">
                <p class="metric-label">Tracked Medicines</p>
                <p id="inventory-tracked-count" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Low Stock</p>
                <p id="inventory-low-stock-count" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Out Of Stock</p>
                <p id="inventory-out-stock-count" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Untracked</p>
                <p id="inventory-untracked-count" class="metric-value">--</p>
            </article>
        </section>

        <?php if ($canWriteWorkspaceData): ?>
            <section class="inventory-grid">
                <article class="card">
                    <h2>Set Inventory Levels</h2>
                    <p class="meta-text">The first save records the starting inventory. New intake logs automatically deduct from the remaining stock.</p>
                    <form id="inventory-form" class="inventory-form" novalidate>
                        <div class="inventory-form-grid">
                            <div>
                                <label for="inventory_medicine_id">Medicine</label>
                                <select id="inventory_medicine_id" name="inventory_medicine_id" required></select>
                            </div>

                            <div>
                                <label for="inventory_unit">Unit</label>
                                <select id="inventory_unit" name="inventory_unit" required>
                                    <option value="mg" selected>mg</option>
                                    <option value="ml">ml</option>
                                    <option value="g">g</option>
                                    <option value="mcg">mcg</option>
                                    <option value="tablet">tablet</option>
                                    <option value="drop">drop</option>
                                </select>
                            </div>

                            <div>
                                <label for="inventory_stock_on_hand">Current Remaining Stock</label>
                                <input
                                    id="inventory_stock_on_hand"
                                    name="inventory_stock_on_hand"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value="0"
                                    required
                                >
                            </div>

                            <div>
                                <label for="inventory_low_stock_threshold">Low Stock Threshold</label>
                                <input
                                    id="inventory_low_stock_threshold"
                                    name="inventory_low_stock_threshold"
                                    type="number"
                                    min="0"
                                    step="0.01"
                                    value="0"
                                    required
                                >
                            </div>

                            <div>
                                <label for="inventory_reorder_quantity">Reorder Quantity (Optional)</label>
                                <input
                                    id="inventory_reorder_quantity"
                                    name="inventory_reorder_quantity"
                                    type="number"
                                    min="0.01"
                                    step="0.01"
                                >
                            </div>

                            <div>
                                <label for="inventory_last_restocked_at">Last Restocked (Optional)</label>
                                <input
                                    id="inventory_last_restocked_at"
                                    name="inventory_last_restocked_at"
                                    type="datetime-local"
                                >
                            </div>
                        </div>

                        <div class="inventory-form-actions">
                            <button id="inventory-submit-btn" class="primary-btn" type="submit">Save Inventory</button>
                            <button id="inventory-clear-btn" class="ghost-btn" type="button">Clear</button>
                        </div>
                    </form>
                </article>

                <article class="card">
                    <h2>Quick Adjustment</h2>
                    <form id="inventory-adjust-form" class="inventory-form" novalidate>
                        <div class="inventory-form-grid">
                            <div>
                                <label for="inventory_adjust_medicine_id">Medicine</label>
                                <select id="inventory_adjust_medicine_id" name="inventory_adjust_medicine_id" required></select>
                            </div>

                            <div>
                                <label for="inventory_adjust_change_amount">Change Amount</label>
                                <input
                                    id="inventory_adjust_change_amount"
                                    name="inventory_adjust_change_amount"
                                    type="number"
                                    step="0.01"
                                    placeholder="e.g. +30 or -5"
                                    required
                                >
                            </div>

                            <div>
                                <label for="inventory_adjust_reason">Reason</label>
                                <select id="inventory_adjust_reason" name="inventory_adjust_reason" required>
                                    <option value="manual_adjustment" selected>Manual Adjustment</option>
                                    <option value="restock">Restock</option>
                                    <option value="waste">Waste</option>
                                    <option value="correction">Correction</option>
                                </select>
                            </div>

                            <div>
                                <label for="inventory_adjust_note">Note (Optional)</label>
                                <input
                                    id="inventory_adjust_note"
                                    name="inventory_adjust_note"
                                    type="text"
                                    maxlength="255"
                                    placeholder="Add context for this adjustment"
                                >
                            </div>
                        </div>

                        <div class="inventory-form-actions">
                            <button id="inventory-adjust-submit-btn" class="primary-btn" type="submit">Apply Adjustment</button>
                        </div>
                    </form>
                </article>
            </section>
        <?php endif; ?>

        <section class="card">
            <div class="section-header">
                <h2>Current Inventory</h2>
                <span id="inventory-meta" class="meta-text">Loading inventory...</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Medicine</th>
                            <th>Initial</th>
                            <th>Remaining</th>
                            <th>Low Threshold</th>
                            <th>Reorder Qty</th>
                            <th>Last Restocked</th>
                            <th>Est Days Left</th>
                            <th>Status</th>
                            <?php if ($canWriteWorkspaceData): ?>
                                <th>Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody id="inventory-body">
                        <tr>
                            <td class="empty-cell" colspan="<?= $inventoryTableColumnCount ?>">Loading inventory...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="card">
            <h2>Recent Inventory Adjustments</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Medicine</th>
                            <th>Change</th>
                            <th>Resulting Stock</th>
                            <th>By</th>
                            <th>Reason</th>
                            <th>Note</th>
                        </tr>
                    </thead>
                    <tbody id="inventory-adjustments-body">
                        <tr>
                            <td class="empty-cell" colspan="7">Loading adjustments...</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
