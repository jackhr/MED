<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC');

$selfPath = (string) ($_SERVER['PHP_SELF'] ?? '/index.php');
$perPage = 10;
$allowedDosageUnits = ['mg', 'ml', 'g', 'mcg', 'tablet', 'drop'];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function formatDateTime(string $value): string
{
    try {
        return (new DateTimeImmutable($value))->format('M j, Y g:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

function formatDateTimeLocal(string $value): string
{
    try {
        return (new DateTimeImmutable($value))->format('Y-m-d\TH:i');
    } catch (Throwable $exception) {
        return $value;
    }
}

function formatDateOnly(string $value): string
{
    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function formatTimeOnly(string $value): string
{
    try {
        return (new DateTimeImmutable($value))->format('g:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

function formatDosageValue(string $value): string
{
    if (!is_numeric($value)) {
        return $value;
    }

    $formatted = number_format((float) $value, 2, '.', '');
    $trimmed = rtrim(rtrim($formatted, '0'), '.');

    return $trimmed !== '' ? $trimmed : '0';
}

function ratingStars(int $rating): string
{
    $safeRating = max(1, min(5, $rating));
    return str_repeat('★', $safeRating) . str_repeat('☆', 5 - $safeRating);
}

function jsonResponse(array $payload, int $statusCode = 200): void
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
}

function requestPayload(): array
{
    $contentType = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (str_contains($contentType, 'application/json')) {
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false || trim($rawBody) === '') {
            return [];
        }

        $decoded = json_decode($rawBody, true);
        return is_array($decoded) ? $decoded : [];
    }

    return $_POST;
}

function parseTakenAt(string $value): ?DateTimeImmutable
{
    $formats = ['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'];
    foreach ($formats as $format) {
        $date = DateTimeImmutable::createFromFormat($format, $value);
        if ($date instanceof DateTimeImmutable) {
            return $date;
        }
    }

    try {
        return new DateTimeImmutable($value);
    } catch (Throwable $exception) {
        return null;
    }
}

function normalizeIntakePayload(array $payload): array
{
    return [
        'medicine_mode' => trim((string) ($payload['medicine_mode'] ?? 'existing')),
        'medicine_id' => (int) ($payload['medicine_id'] ?? 0),
        'medicine_name' => trim((string) ($payload['medicine_name'] ?? '')),
        'dosage_value' => trim((string) ($payload['dosage_value'] ?? '20')),
        'dosage_unit' => strtolower(trim((string) ($payload['dosage_unit'] ?? 'mg'))),
        'rating' => trim((string) ($payload['rating'] ?? '3')),
        'taken_at' => trim((string) ($payload['taken_at'] ?? '')),
        'notes' => trim((string) ($payload['notes'] ?? '')),
    ];
}

function resolveMedicineId(PDO $pdo, array &$data, array &$errors): ?int
{
    $mode = $data['medicine_mode'] === 'new' ? 'new' : 'existing';

    if ($mode === 'existing') {
        if ($data['medicine_id'] <= 0) {
            $errors[] = 'Please select an existing medicine.';
            return null;
        }

        $statement = $pdo->prepare('SELECT id, name FROM medicines WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $data['medicine_id']]);
        $medicine = $statement->fetch();

        if (!is_array($medicine)) {
            $errors[] = 'The selected medicine no longer exists.';
            return null;
        }

        $data['medicine_name'] = (string) $medicine['name'];
        return (int) $medicine['id'];
    }

    if ($data['medicine_name'] === '') {
        $errors[] = 'New medicine name is required.';
        return null;
    }

    if (strlen($data['medicine_name']) > 120) {
        $errors[] = 'Medicine name must be 120 characters or less.';
        return null;
    }

    $existingStatement = $pdo->prepare('SELECT id, name FROM medicines WHERE name = :name LIMIT 1');
    $existingStatement->execute([':name' => $data['medicine_name']]);
    $existingMedicine = $existingStatement->fetch();
    if (is_array($existingMedicine)) {
        $data['medicine_name'] = (string) $existingMedicine['name'];
        return (int) $existingMedicine['id'];
    }

    try {
        $insertStatement = $pdo->prepare('INSERT INTO medicines (name) VALUES (:name)');
        $insertStatement->execute([':name' => $data['medicine_name']]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $exception) {
        $retryStatement = $pdo->prepare('SELECT id, name FROM medicines WHERE name = :name LIMIT 1');
        $retryStatement->execute([':name' => $data['medicine_name']]);
        $retryMedicine = $retryStatement->fetch();

        if (!is_array($retryMedicine)) {
            $errors[] = 'Unable to create medicine type.';
            return null;
        }

        $data['medicine_name'] = (string) $retryMedicine['name'];
        return (int) $retryMedicine['id'];
    }
}

function validateIntake(array $payload, PDO $pdo, array $allowedDosageUnits): array
{
    $data = normalizeIntakePayload($payload);
    $errors = [];
    $medicineId = null;

    $data['medicine_mode'] = $data['medicine_mode'] === 'new' ? 'new' : 'existing';
    if ($data['medicine_mode'] === 'existing') {
        if ($data['medicine_id'] <= 0) {
            $errors[] = 'Please select an existing medicine.';
        }
    } elseif ($data['medicine_name'] === '') {
        $errors[] = 'New medicine name is required.';
    } elseif (strlen($data['medicine_name']) > 120) {
        $errors[] = 'Medicine name must be 120 characters or less.';
    }

    $dosageValueForDb = null;
    if ($data['dosage_value'] === '') {
        $errors[] = 'Dosage amount is required.';
    } elseif (!is_numeric($data['dosage_value'])) {
        $errors[] = 'Dosage amount must be a number.';
    } else {
        $dosageNumber = (float) $data['dosage_value'];
        if ($dosageNumber <= 0) {
            $errors[] = 'Dosage amount must be greater than zero.';
        } elseif ($dosageNumber > 10000) {
            $errors[] = 'Dosage amount is too large.';
        } else {
            $dosageValueForDb = number_format($dosageNumber, 2, '.', '');
        }
    }

    if (!in_array($data['dosage_unit'], $allowedDosageUnits, true)) {
        $errors[] = 'Dosage unit is invalid.';
    }

    $ratingForDb = null;
    if ($data['rating'] === '') {
        $errors[] = 'Rating is required.';
    } elseif (!ctype_digit($data['rating'])) {
        $errors[] = 'Rating must be a whole number between 1 and 5.';
    } else {
        $rating = (int) $data['rating'];
        if ($rating < 1 || $rating > 5) {
            $errors[] = 'Rating must be between 1 and 5.';
        } else {
            $ratingForDb = $rating;
        }
    }

    if ($data['taken_at'] === '') {
        $errors[] = 'Date and time taken is required.';
    }

    if (strlen($data['notes']) > 255) {
        $errors[] = 'Notes must be 255 characters or less.';
    }

    $takenAtForDb = null;
    if ($data['taken_at'] !== '') {
        $takenAtDate = parseTakenAt($data['taken_at']);
        if (!$takenAtDate instanceof DateTimeImmutable) {
            $errors[] = 'Date and time taken is invalid.';
        } else {
            $takenAtForDb = $takenAtDate->format('Y-m-d H:i:s');
        }
    }

    if ($errors === []) {
        $medicineId = resolveMedicineId($pdo, $data, $errors);
    }

    return [
        'errors' => $errors,
        'data' => $data,
        'medicine_id' => $medicineId,
        'dosage_value_for_db' => $dosageValueForDb,
        'rating_for_db' => $ratingForDb,
        'taken_at_for_db' => $takenAtForDb,
    ];
}

function serializeEntry(array $entry): array
{
    $takenAt = (string) ($entry['taken_at'] ?? '');
    $notes = $entry['notes'] ?? '';
    $createdAt = (string) ($entry['created_at'] ?? '');
    $dosageValue = (string) ($entry['dosage_value'] ?? '0');
    $dosageUnit = (string) ($entry['dosage_unit'] ?? 'mg');
    $rating = (int) ($entry['rating'] ?? 3);
    $formattedDosageValue = formatDosageValue($dosageValue);

    return [
        'id' => (int) $entry['id'],
        'medicine_id' => (int) $entry['medicine_id'],
        'medicine_name' => (string) $entry['medicine_name'],
        'dosage_value' => $formattedDosageValue,
        'dosage_unit' => $dosageUnit,
        'dosage_display' => $formattedDosageValue . ' ' . $dosageUnit,
        'rating' => $rating,
        'rating_display' => ratingStars($rating),
        'taken_at' => $takenAt,
        'taken_day_key' => substr($takenAt, 0, 10),
        'taken_day_display' => formatDateOnly($takenAt),
        'taken_time_display' => formatTimeOnly($takenAt),
        'taken_at_display' => formatDateTime($takenAt),
        'taken_at_input' => formatDateTimeLocal($takenAt),
        'notes' => (string) ($notes ?? ''),
        'created_at' => $createdAt,
    ];
}

function findEntry(PDO $pdo, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT l.id,
                l.medicine_id,
                m.name AS medicine_name,
                l.dosage_value,
                l.dosage_unit,
                l.rating,
                l.taken_at,
                l.notes,
                l.created_at
         FROM medicine_intake_logs l
         INNER JOIN medicines m ON m.id = l.medicine_id
         WHERE l.id = :id
         LIMIT 1'
    );
    $statement->execute([':id' => $id]);
    $entry = $statement->fetch();

    return is_array($entry) ? $entry : null;
}

function loadEntriesPage(PDO $pdo, int $page, int $perPage): array
{
    $totalEntries = (int) $pdo->query('SELECT COUNT(*) FROM medicine_intake_logs')->fetchColumn();
    $totalPages = max(1, (int) ceil($totalEntries / $perPage));
    $safePage = min(max($page, 1), $totalPages);
    $offset = ($safePage - 1) * $perPage;

    $statement = $pdo->prepare(
        'SELECT l.id,
                l.medicine_id,
                m.name AS medicine_name,
                l.dosage_value,
                l.dosage_unit,
                l.rating,
                l.taken_at,
                l.notes,
                l.created_at
         FROM medicine_intake_logs l
         INNER JOIN medicines m ON m.id = l.medicine_id
         ORDER BY l.taken_at DESC, l.id DESC
         LIMIT :limit OFFSET :offset'
    );
    $statement->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $statement->bindValue(':offset', $offset, PDO::PARAM_INT);
    $statement->execute();
    $rows = $statement->fetchAll();

    $entries = array_map(static fn(array $row): array => serializeEntry($row), $rows);

    return [
        'entries' => $entries,
        'pagination' => [
            'page' => $safePage,
            'per_page' => $perPage,
            'total_entries' => $totalEntries,
            'total_pages' => $totalPages,
        ],
    ];
}

function loadMetrics(PDO $pdo): array
{
    $entriesToday = (int) $pdo
        ->query('SELECT COUNT(*) FROM medicine_intake_logs WHERE DATE(taken_at) = CURDATE()')
        ->fetchColumn();
    $entriesThisWeek = (int) $pdo
        ->query('SELECT COUNT(*) FROM medicine_intake_logs WHERE YEARWEEK(taken_at, 1) = YEARWEEK(CURDATE(), 1)')
        ->fetchColumn();
    $totalMedicines = (int) $pdo
        ->query('SELECT COUNT(*) FROM medicines')
        ->fetchColumn();

    return [
        'entries_today' => $entriesToday,
        'entries_this_week' => $entriesThisWeek,
        'unique_medicines' => $totalMedicines,
    ];
}

function loadMedicineOptions(PDO $pdo): array
{
    $statement = $pdo->query(
        'SELECT id, name
         FROM medicines
         ORDER BY name ASC'
    );
    $rows = $statement->fetchAll();

    return array_values(array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ],
        $rows
    ));
}

$dbError = null;
try {
    $pdo = Database::connection();
} catch (Throwable $exception) {
    $pdo = null;
    $dbError = $exception->getMessage();
}

$apiAction = (string) ($_GET['api'] ?? '');
if ($apiAction !== '') {
    if (!$pdo instanceof PDO) {
        jsonResponse([
            'ok' => false,
            'error' => 'Database connection failed. Check your .env settings.',
        ], 500);
        exit;
    }

    try {
        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'dashboard') {
            jsonResponse([
                'ok' => true,
                'metrics' => loadMetrics($pdo),
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'medicines') {
            jsonResponse([
                'ok' => true,
                'medicines' => loadMedicineOptions($pdo),
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'entries') {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $entriesPage = loadEntriesPage($pdo, $page, $perPage);

            jsonResponse([
                'ok' => true,
                'entries' => $entriesPage['entries'],
                'pagination' => $entriesPage['pagination'],
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'create') {
            $payload = requestPayload();
            $validated = validateIntake($payload, $pdo, $allowedDosageUnits);

            if ($validated['errors'] !== []) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Validation failed.',
                    'errors' => $validated['errors'],
                ], 422);
                exit;
            }

            $data = $validated['data'];
            $statement = $pdo->prepare(
                'INSERT INTO medicine_intake_logs (medicine_id, dosage_value, dosage_unit, rating, taken_at, notes)
                 VALUES (:medicine_id, :dosage_value, :dosage_unit, :rating, :taken_at, :notes)'
            );
            $statement->execute([
                ':medicine_id' => $validated['medicine_id'],
                ':dosage_value' => $validated['dosage_value_for_db'],
                ':dosage_unit' => $data['dosage_unit'],
                ':rating' => $validated['rating_for_db'],
                ':taken_at' => $validated['taken_at_for_db'],
                ':notes' => $data['notes'] !== '' ? $data['notes'] : null,
            ]);

            $entryId = (int) $pdo->lastInsertId();
            $entry = findEntry($pdo, $entryId);

            jsonResponse([
                'ok' => true,
                'message' => 'Entry added successfully.',
                'entry' => is_array($entry) ? serializeEntry($entry) : null,
            ], 201);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'update') {
            $payload = requestPayload();
            $entryId = (int) ($payload['id'] ?? 0);

            if ($entryId <= 0) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'A valid entry ID is required for updates.',
                ], 422);
                exit;
            }

            $existingEntry = findEntry($pdo, $entryId);
            if (!is_array($existingEntry)) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'The selected entry no longer exists.',
                ], 404);
                exit;
            }

            $validated = validateIntake($payload, $pdo, $allowedDosageUnits);
            if ($validated['errors'] !== []) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Validation failed.',
                    'errors' => $validated['errors'],
                ], 422);
                exit;
            }

            $data = $validated['data'];
            $statement = $pdo->prepare(
                'UPDATE medicine_intake_logs
                 SET medicine_id = :medicine_id,
                     dosage_value = :dosage_value,
                     dosage_unit = :dosage_unit,
                     rating = :rating,
                     taken_at = :taken_at,
                     notes = :notes
                 WHERE id = :id'
            );
            $statement->execute([
                ':medicine_id' => $validated['medicine_id'],
                ':dosage_value' => $validated['dosage_value_for_db'],
                ':dosage_unit' => $data['dosage_unit'],
                ':rating' => $validated['rating_for_db'],
                ':taken_at' => $validated['taken_at_for_db'],
                ':notes' => $data['notes'] !== '' ? $data['notes'] : null,
                ':id' => $entryId,
            ]);

            $updatedEntry = findEntry($pdo, $entryId);
            jsonResponse([
                'ok' => true,
                'message' => 'Entry updated successfully.',
                'entry' => is_array($updatedEntry) ? serializeEntry($updatedEntry) : serializeEntry($existingEntry),
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'delete') {
            $payload = requestPayload();
            $entryId = (int) ($payload['id'] ?? 0);

            if ($entryId <= 0) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'A valid entry ID is required for deletion.',
                ], 422);
                exit;
            }

            $existingEntry = findEntry($pdo, $entryId);
            if (!is_array($existingEntry)) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'The selected entry no longer exists.',
                ], 404);
                exit;
            }

            $statement = $pdo->prepare('DELETE FROM medicine_intake_logs WHERE id = :id');
            $statement->execute([':id' => $entryId]);

            jsonResponse([
                'ok' => true,
                'message' => 'Entry deleted successfully.',
            ]);
            exit;
        }

        jsonResponse([
            'ok' => false,
            'error' => 'Unsupported endpoint or method.',
        ], 404);
        exit;
    } catch (Throwable $exception) {
        jsonResponse([
            'ok' => false,
            'error' => 'Server error: ' . $exception->getMessage(),
        ], 500);
        exit;
    }
}

$dbReady = $pdo instanceof PDO;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Intake Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
    <script>
        window.MEDICINE_LOG_CONFIG = {
            apiPath: <?= json_encode($selfPath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            perPage: <?= $perPage ?>
        };
    </script>
    <script src="assets/app.js" defer></script>
</head>
<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard">
        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Daily Intake Dashboard</h1>
            <p>Monitor progress, log doses quickly, and edit records in place.</p>
        </header>

        <?php if ($dbError !== null): ?>
            <div class="alert alert-error">
                <strong>Database connection failed:</strong> <?= e($dbError) ?>
            </div>
        <?php endif; ?>

        <div id="status-banner" class="alert" hidden></div>

        <section class="metrics-grid" aria-label="Dashboard Metrics">
            <article class="metric-card">
                <p class="metric-label">Entries Today</p>
                <p id="metric-today" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Entries This Week</p>
                <p id="metric-week" class="metric-value">--</p>
            </article>
            <article class="metric-card">
                <p class="metric-label">Medicine Types</p>
                <p id="metric-medicines" class="metric-value">--</p>
            </article>
        </section>

        <section class="workspace-grid">
            <article class="card">
                <h2>Add Intake</h2>
                <form id="create-intake-form" novalidate>
                    <label>Medicine</label>
                    <div id="create-medicine-picker" class="medicine-picker" data-mode="existing">
                        <div class="medicine-tabs">
                            <button type="button" class="medicine-tab is-active" data-tab-mode="existing">Select Existing</button>
                            <button type="button" class="medicine-tab" data-tab-mode="new">Add New</button>
                        </div>
                        <div class="medicine-panel" data-panel-mode="existing">
                            <select id="medicine_select" name="medicine_select" required></select>
                        </div>
                        <div class="medicine-panel" data-panel-mode="new" hidden>
                            <input id="medicine_custom" name="medicine_custom" type="text" maxlength="120" placeholder="Type a new medicine name">
                        </div>
                    </div>

                    <label for="dosage_value">Dosage</label>
                    <div class="dosage-fields">
                        <input id="dosage_value" name="dosage_value" type="number" min="0.01" step="0.01" value="20" required>
                        <select id="dosage_unit" name="dosage_unit" required>
                            <option value="mg" selected>mg</option>
                            <option value="ml">ml</option>
                            <option value="g">g</option>
                            <option value="mcg">mcg</option>
                            <option value="tablet">tablet</option>
                            <option value="drop">drop</option>
                        </select>
                    </div>

                    <label for="rating">Rating</label>
                    <div id="create-rating-widget" class="star-rating" role="radiogroup" aria-label="Choose rating">
                        <button type="button" class="star-btn is-active" data-star="1" role="radio" aria-label="1 star" aria-checked="false">★</button>
                        <button type="button" class="star-btn is-active" data-star="2" role="radio" aria-label="2 stars" aria-checked="false">★</button>
                        <button type="button" class="star-btn is-active" data-star="3" role="radio" aria-label="3 stars" aria-checked="true">★</button>
                        <button type="button" class="star-btn" data-star="4" role="radio" aria-label="4 stars" aria-checked="false">★</button>
                        <button type="button" class="star-btn" data-star="5" role="radio" aria-label="5 stars" aria-checked="false">★</button>
                    </div>
                    <input id="rating" name="rating" type="hidden" value="3" required>

                    <label for="taken_at">Date & Time Taken</label>
                    <input id="taken_at" name="taken_at" type="datetime-local" required>

                    <label for="notes">Notes (Optional)</label>
                    <textarea id="notes" name="notes" maxlength="255"></textarea>

                    <button class="primary-btn" type="submit">Save Intake</button>
                </form>
            </article>

            <article class="card">
                <div class="section-header">
                    <h2>Recent Entries</h2>
                    <span id="table-meta" class="meta-text">Loading entries...</span>
                </div>

                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>When Taken</th>
                                <th>Medicine</th>
                                <th>Dosage</th>
                                <th>Rating</th>
                                <th>Notes</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="entries-body">
                            <tr>
                                <td class="empty-cell" colspan="6">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav id="pagination" class="pagination" aria-label="Entries pagination"></nav>
            </article>
        </section>
    </main>

    <section id="edit-modal" class="modal" hidden>
        <div class="modal-backdrop" data-close-modal="true"></div>
        <div class="modal-panel" role="dialog" aria-modal="true" aria-labelledby="edit-modal-title">
            <div class="modal-header">
                <h3 id="edit-modal-title">Edit Intake</h3>
                <button type="button" class="ghost-btn" data-close-modal="true">Close</button>
            </div>

            <form id="edit-intake-form" novalidate>
                <input id="edit_id" name="id" type="hidden">

                <label>Medicine</label>
                <div id="edit-medicine-picker" class="medicine-picker" data-mode="existing">
                    <div class="medicine-tabs">
                        <button type="button" class="medicine-tab is-active" data-tab-mode="existing">Select Existing</button>
                        <button type="button" class="medicine-tab" data-tab-mode="new">Add New</button>
                    </div>
                    <div class="medicine-panel" data-panel-mode="existing">
                        <select id="edit_medicine_select" name="edit_medicine_select" required></select>
                    </div>
                    <div class="medicine-panel" data-panel-mode="new" hidden>
                        <input id="edit_medicine_custom" name="edit_medicine_custom" type="text" maxlength="120" placeholder="Type a new medicine name">
                    </div>
                </div>

                <label for="edit_dosage_value">Dosage</label>
                <div class="dosage-fields">
                    <input id="edit_dosage_value" name="edit_dosage_value" type="number" min="0.01" step="0.01" value="20" required>
                    <select id="edit_dosage_unit" name="edit_dosage_unit" required>
                        <option value="mg" selected>mg</option>
                        <option value="ml">ml</option>
                        <option value="g">g</option>
                        <option value="mcg">mcg</option>
                        <option value="tablet">tablet</option>
                        <option value="drop">drop</option>
                    </select>
                </div>

                <label for="edit_rating">Rating</label>
                <div id="edit-rating-widget" class="star-rating" role="radiogroup" aria-label="Choose rating">
                    <button type="button" class="star-btn is-active" data-star="1" role="radio" aria-label="1 star" aria-checked="false">★</button>
                    <button type="button" class="star-btn is-active" data-star="2" role="radio" aria-label="2 stars" aria-checked="false">★</button>
                    <button type="button" class="star-btn is-active" data-star="3" role="radio" aria-label="3 stars" aria-checked="true">★</button>
                    <button type="button" class="star-btn" data-star="4" role="radio" aria-label="4 stars" aria-checked="false">★</button>
                    <button type="button" class="star-btn" data-star="5" role="radio" aria-label="5 stars" aria-checked="false">★</button>
                </div>
                <input id="edit_rating" name="rating" type="hidden" value="3" required>

                <label for="edit_taken_at">Date & Time Taken</label>
                <input id="edit_taken_at" name="taken_at" type="datetime-local" required>

                <label for="edit_notes">Notes (Optional)</label>
                <textarea id="edit_notes" name="notes" maxlength="255"></textarea>

                <div class="modal-actions">
                    <button id="delete-entry-btn" type="button" class="danger-btn">Delete Entry</button>
                    <button type="button" class="ghost-btn" data-close-modal="true">Cancel</button>
                    <button type="submit" class="primary-btn">Save Changes</button>
                </div>
            </form>
        </div>
    </section>

    <noscript>
        <div class="alert alert-error noscript-alert">
            JavaScript is required for the asynchronous dashboard and modal editing.
        </div>
    </noscript>
</body>
</html>
