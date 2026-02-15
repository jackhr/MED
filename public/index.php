<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Auth.php';
require_once dirname(__DIR__) . '/src/PushNotifications.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC');

$selfPath = (string) ($_SERVER['PHP_SELF'] ?? '/index.php');
$apiAction = (string) ($_GET['api'] ?? '');
$perPage = 10;
$allowedDosageUnits = ['mg', 'ml', 'g', 'mcg', 'tablet', 'drop'];

Auth::startSession();
if ($apiAction === 'process_reminders') {
    // Cron calls can use a token on this endpoint without an active session.
} elseif ($apiAction !== '') {
    Auth::requireAuthForApi();
} else {
    Auth::requireAuthForPage('login.php');
}

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

function normalizeFilterDate(string $value): ?string
{
    $cleanValue = trim($value);
    if ($cleanValue === '') {
        return null;
    }

    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $cleanValue);
    $errors = DateTimeImmutable::getLastErrors();
    $hasParseErrors = is_array($errors)
        && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

    if (!$date instanceof DateTimeImmutable || $hasParseErrors) {
        return null;
    }

    return $date->format('Y-m-d');
}

function normalizeEntryFilters(array $filters): array
{
    $search = trim((string) ($filters['search'] ?? ''));
    if (strlen($search) > 120) {
        $search = substr($search, 0, 120);
    }

    $medicineId = (int) ($filters['medicine_id'] ?? 0);
    if ($medicineId <= 0) {
        $medicineId = null;
    }

    $rating = (int) ($filters['rating'] ?? 0);
    if ($rating < 1 || $rating > 5) {
        $rating = null;
    }

    $fromDate = normalizeFilterDate((string) ($filters['from_date'] ?? ''));
    $toDate = normalizeFilterDate((string) ($filters['to_date'] ?? ''));

    if ($fromDate !== null && $toDate !== null && $fromDate > $toDate) {
        [$fromDate, $toDate] = [$toDate, $fromDate];
    }

    return [
        'search' => $search,
        'medicine_id' => $medicineId,
        'rating' => $rating,
        'from_date' => $fromDate,
        'to_date' => $toDate,
    ];
}

function buildEntriesWhereSql(array $normalizedFilters): array
{
    $whereParts = [];
    $bindings = [];

    if ($normalizedFilters['search'] !== '') {
        $whereParts[] = '(m.name LIKE :search OR COALESCE(l.notes, "") LIKE :search)';
        $bindings[':search'] = '%' . $normalizedFilters['search'] . '%';
    }

    if ($normalizedFilters['medicine_id'] !== null) {
        $whereParts[] = 'l.medicine_id = :medicine_id';
        $bindings[':medicine_id'] = $normalizedFilters['medicine_id'];
    }

    if ($normalizedFilters['rating'] !== null) {
        $whereParts[] = 'l.rating = :rating';
        $bindings[':rating'] = $normalizedFilters['rating'];
    }

    if ($normalizedFilters['from_date'] !== null) {
        $whereParts[] = 'l.taken_at >= :from_date';
        $bindings[':from_date'] = $normalizedFilters['from_date'] . ' 00:00:00';
    }

    if ($normalizedFilters['to_date'] !== null) {
        $toDateEnd = (new DateTimeImmutable($normalizedFilters['to_date'] . ' 00:00:00'))
            ->modify('+1 day')
            ->format('Y-m-d H:i:s');
        $whereParts[] = 'l.taken_at < :to_date';
        $bindings[':to_date'] = $toDateEnd;
    }

    return [
        'where_sql' => $whereParts !== [] ? (' WHERE ' . implode(' AND ', $whereParts)) : '',
        'bindings' => $bindings,
    ];
}

function bindSqlValues(PDOStatement $statement, array $bindings): void
{
    foreach ($bindings as $bindingKey => $bindingValue) {
        $paramType = is_int($bindingValue) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $statement->bindValue($bindingKey, $bindingValue, $paramType);
    }
}

function filteredEntriesQuerySql(string $whereSql, string $suffixSql = ''): string
{
    return 'SELECT l.id,
                   l.medicine_id,
                   m.name AS medicine_name,
                   l.dosage_value,
                   l.dosage_unit,
                   l.rating,
                   l.taken_at,
                   l.notes,
                   l.created_at
            FROM medicine_intake_logs l
            INNER JOIN medicines m ON m.id = l.medicine_id'
        . $whereSql
        . ' '
        . trim($suffixSql);
}

function loadEntriesPage(PDO $pdo, int $page, int $perPage, array $filters): array
{
    $normalizedFilters = normalizeEntryFilters($filters);
    $whereData = buildEntriesWhereSql($normalizedFilters);
    $whereSql = $whereData['where_sql'];
    $bindings = $whereData['bindings'];
    $totalAllEntries = (int) $pdo->query('SELECT COUNT(*) FROM medicine_intake_logs')->fetchColumn();

    $countStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM medicine_intake_logs l
         INNER JOIN medicines m ON m.id = l.medicine_id'
        . $whereSql
    );
    bindSqlValues($countStatement, $bindings);
    $countStatement->execute();
    $totalEntries = (int) $countStatement->fetchColumn();
    $totalPages = max(1, (int) ceil($totalEntries / $perPage));
    $safePage = min(max($page, 1), $totalPages);
    $offset = ($safePage - 1) * $perPage;

    $statement = $pdo->prepare(filteredEntriesQuerySql(
        $whereSql,
        'ORDER BY l.taken_at DESC, l.id DESC LIMIT :limit OFFSET :offset'
    ));
    bindSqlValues($statement, $bindings);
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
            'total_all_entries' => $totalAllEntries,
            'total_pages' => $totalPages,
        ],
        'filters' => [
            'search' => $normalizedFilters['search'],
            'medicine_id' => $normalizedFilters['medicine_id'],
            'rating' => $normalizedFilters['rating'],
            'from_date' => $normalizedFilters['from_date'],
            'to_date' => $normalizedFilters['to_date'],
            'active' => $normalizedFilters['search'] !== ''
                || $normalizedFilters['medicine_id'] !== null
                || $normalizedFilters['rating'] !== null
                || $normalizedFilters['from_date'] !== null
                || $normalizedFilters['to_date'] !== null,
        ],
    ];
}

function fetchEntriesForExport(PDO $pdo, array $filters): array
{
    $normalizedFilters = normalizeEntryFilters($filters);
    $whereData = buildEntriesWhereSql($normalizedFilters);
    $whereSql = $whereData['where_sql'];
    $bindings = $whereData['bindings'];

    $statement = $pdo->prepare(filteredEntriesQuerySql(
        $whereSql,
        'ORDER BY l.taken_at DESC, l.id DESC'
    ));
    bindSqlValues($statement, $bindings);
    $statement->execute();
    $rows = $statement->fetchAll();
    $entries = array_map(static fn(array $row): array => serializeEntry($row), $rows);

    return [
        'entries' => $entries,
        'filters' => $normalizedFilters,
    ];
}

function downloadEntriesCsv(PDO $pdo, array $filters): void
{
    $exportData = fetchEntriesForExport($pdo, $filters);
    $entries = $exportData['entries'];
    $filtersData = $exportData['filters'];
    $filtersActive = $filtersData['search'] !== ''
        || $filtersData['medicine_id'] !== null
        || $filtersData['rating'] !== null
        || $filtersData['from_date'] !== null
        || $filtersData['to_date'] !== null;

    $scope = $filtersActive ? 'filtered' : 'all';
    $filename = sprintf(
        'medicine_entries_%s_%s.csv',
        $scope,
        date('Ymd_His')
    );

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');

    $output = fopen('php://output', 'wb');
    if ($output === false) {
        throw new RuntimeException('Unable to open CSV output stream.');
    }

    fputcsv($output, [
        'ID',
        'Date',
        'Time',
        'Medicine',
        'Dosage Value',
        'Dosage Unit',
        'Dosage',
        'Rating',
        'Notes',
        'Taken At',
        'Created At',
    ]);

    foreach ($entries as $entry) {
        fputcsv($output, [
            (string) $entry['id'],
            (string) $entry['taken_day_key'],
            (string) $entry['taken_time_display'],
            (string) $entry['medicine_name'],
            (string) $entry['dosage_value'],
            (string) $entry['dosage_unit'],
            (string) $entry['dosage_display'],
            (string) $entry['rating'],
            (string) $entry['notes'],
            (string) $entry['taken_at'],
            (string) $entry['created_at'],
        ]);
    }

    fclose($output);
}

function sqlBackupValue(mixed $value): string
{
    if ($value === null) {
        return 'NULL';
    }

    return "'" . str_replace("'", "''", (string) $value) . "'";
}

function downloadBackupJson(PDO $pdo): void
{
    $medicines = $pdo
        ->query('SELECT id, name, created_at FROM medicines ORDER BY id ASC')
        ->fetchAll();
    $intakes = $pdo
        ->query(
            'SELECT id, medicine_id, dosage_value, dosage_unit, rating, taken_at, notes, created_at
             FROM medicine_intake_logs
             ORDER BY id ASC'
        )
        ->fetchAll();

    $payload = [
        'generated_at' => date('c'),
        'tables' => [
            'medicines' => is_array($medicines) ? $medicines : [],
            'medicine_intake_logs' => is_array($intakes) ? $intakes : [],
        ],
    ];

    $filename = 'medicine_backup_' . date('Ymd_His') . '.json';
    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function downloadBackupSql(PDO $pdo): void
{
    $medicines = $pdo
        ->query('SELECT id, name, created_at FROM medicines ORDER BY id ASC')
        ->fetchAll();
    $intakes = $pdo
        ->query(
            'SELECT id, medicine_id, dosage_value, dosage_unit, rating, taken_at, notes, created_at
             FROM medicine_intake_logs
             ORDER BY id ASC'
        )
        ->fetchAll();

    $lines = [];
    $lines[] = '-- Medicine Log backup generated at ' . date('c');
    $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $lines[] = 'DELETE FROM medicine_intake_logs;';
    $lines[] = 'DELETE FROM medicines;';
    $lines[] = '';

    if (is_array($medicines) && $medicines !== []) {
        $medicineValues = [];
        foreach ($medicines as $row) {
            $medicineValues[] = sprintf(
                '(%s, %s, %s)',
                sqlBackupValue($row['id'] ?? null),
                sqlBackupValue($row['name'] ?? null),
                sqlBackupValue($row['created_at'] ?? null)
            );
        }

        $lines[] = 'INSERT INTO medicines (id, name, created_at) VALUES';
        $lines[] = implode(",\n", $medicineValues) . ';';
        $lines[] = '';
    }

    if (is_array($intakes) && $intakes !== []) {
        $intakeValues = [];
        foreach ($intakes as $row) {
            $intakeValues[] = sprintf(
                '(%s, %s, %s, %s, %s, %s, %s, %s)',
                sqlBackupValue($row['id'] ?? null),
                sqlBackupValue($row['medicine_id'] ?? null),
                sqlBackupValue($row['dosage_value'] ?? null),
                sqlBackupValue($row['dosage_unit'] ?? null),
                sqlBackupValue($row['rating'] ?? null),
                sqlBackupValue($row['taken_at'] ?? null),
                sqlBackupValue($row['notes'] ?? null),
                sqlBackupValue($row['created_at'] ?? null)
            );
        }

        $lines[] = 'INSERT INTO medicine_intake_logs (id, medicine_id, dosage_value, dosage_unit, rating, taken_at, notes, created_at) VALUES';
        $lines[] = implode(",\n", $intakeValues) . ';';
        $lines[] = '';
    }

    $lines[] = 'SET FOREIGN_KEY_CHECKS=1;';

    $filename = 'medicine_backup_' . date('Ymd_His') . '.sql';
    header('Content-Type: application/sql; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Pragma: no-cache');
    echo implode("\n", $lines);
}

function loadMetrics(PDO $pdo): array
{
    $entriesToday = (int) $pdo
        ->query('SELECT COUNT(*) FROM medicine_intake_logs WHERE DATE(taken_at) = CURDATE()')
        ->fetchColumn();
    $entriesThisWeek = (int) $pdo
        ->query('SELECT COUNT(*) FROM medicine_intake_logs WHERE YEARWEEK(taken_at, 1) = YEARWEEK(CURDATE(), 1)')
        ->fetchColumn();
    $avgRatingThisWeekRaw = $pdo
        ->query('SELECT AVG(rating) FROM medicine_intake_logs WHERE YEARWEEK(taken_at, 1) = YEARWEEK(CURDATE(), 1)')
        ->fetchColumn();
    $avgRatingThisWeek = $avgRatingThisWeekRaw !== null ? round((float) $avgRatingThisWeekRaw, 2) : null;

    return [
        'entries_today' => $entriesToday,
        'entries_this_week' => $entriesThisWeek,
        'average_rating_this_week' => $avgRatingThisWeek,
    ];
}

function loadTrends(PDO $pdo): array
{
    $summaryStatement = $pdo->query(
        'SELECT SUM(CASE WHEN taken_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS entries_last_30_days,
                COUNT(DISTINCT CASE WHEN taken_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN DATE(taken_at) END) AS active_days_last_30_days,
                AVG(CASE WHEN YEARWEEK(taken_at, 1) = YEARWEEK(CURDATE(), 1) THEN rating END) AS avg_rating_this_week,
                AVG(CASE WHEN taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN rating END) AS avg_rating_last_90_days
         FROM medicine_intake_logs
         WHERE taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)'
    );
    $summaryRow = $summaryStatement->fetch();
    $entriesLast30Days = (int) ($summaryRow['entries_last_30_days'] ?? 0);
    $activeDaysLast30Days = (int) ($summaryRow['active_days_last_30_days'] ?? 0);
    $avgRatingThisWeek = isset($summaryRow['avg_rating_this_week']) && $summaryRow['avg_rating_this_week'] !== null
        ? round((float) $summaryRow['avg_rating_this_week'], 2)
        : null;
    $avgRatingLast90Days = isset($summaryRow['avg_rating_last_90_days']) && $summaryRow['avg_rating_last_90_days'] !== null
        ? round((float) $summaryRow['avg_rating_last_90_days'], 2)
        : null;

    $monthlyStatement = $pdo->query(
        'SELECT DATE_FORMAT(MIN(taken_at), "%b %Y") AS label,
                AVG(rating) AS avg_rating,
                COUNT(*) AS entries
         FROM medicine_intake_logs
         WHERE taken_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
         GROUP BY YEAR(taken_at), MONTH(taken_at)
         ORDER BY MIN(taken_at)'
    );
    $monthlyRows = $monthlyStatement->fetchAll();
    $monthlyAverageRating = array_values(array_map(
        static fn(array $row): array => [
            'label' => (string) ($row['label'] ?? ''),
            'avg_rating' => isset($row['avg_rating']) && $row['avg_rating'] !== null
                ? round((float) $row['avg_rating'], 2)
                : null,
            'entries' => (int) ($row['entries'] ?? 0),
        ],
        $monthlyRows
    ));

    $weeklyStatement = $pdo->query(
        'SELECT MIN(DATE(taken_at)) AS week_start,
                COUNT(*) AS entries,
                AVG(rating) AS avg_rating
         FROM medicine_intake_logs
         WHERE taken_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
         GROUP BY YEARWEEK(taken_at, 1)
         ORDER BY YEARWEEK(taken_at, 1)'
    );
    $weeklyRows = $weeklyStatement->fetchAll();
    $weeklyEntries = array_values(array_map(
        static fn(array $row): array => [
            'label' => isset($row['week_start']) && $row['week_start'] !== null
                ? ('Week of ' . formatDateOnly((string) $row['week_start']))
                : 'Unknown',
            'entries' => (int) ($row['entries'] ?? 0),
            'avg_rating' => isset($row['avg_rating']) && $row['avg_rating'] !== null
                ? round((float) $row['avg_rating'], 2)
                : null,
        ],
        $weeklyRows
    ));

    $topMedicinesStatement = $pdo->query(
        'SELECT m.name AS medicine_name,
                COUNT(*) AS entries,
                AVG(l.rating) AS avg_rating
         FROM medicine_intake_logs l
         INNER JOIN medicines m ON m.id = l.medicine_id
         WHERE l.taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
         GROUP BY l.medicine_id, m.name
         ORDER BY entries DESC, m.name ASC
         LIMIT 5'
    );
    $topMedicinesRows = $topMedicinesStatement->fetchAll();
    $topMedicines = array_values(array_map(
        static fn(array $row): array => [
            'medicine_name' => (string) ($row['medicine_name'] ?? ''),
            'entries' => (int) ($row['entries'] ?? 0),
            'avg_rating' => isset($row['avg_rating']) && $row['avg_rating'] !== null
                ? round((float) $row['avg_rating'], 2)
                : null,
        ],
        $topMedicinesRows
    ));

    $weekdayStatement = $pdo->query(
        'SELECT WEEKDAY(taken_at) AS weekday_index,
                COUNT(*) AS entries,
                AVG(rating) AS avg_rating
         FROM medicine_intake_logs
         WHERE taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
         GROUP BY WEEKDAY(taken_at)
         ORDER BY WEEKDAY(taken_at)'
    );
    $weekdayRows = $weekdayStatement->fetchAll();
    $weekdayNames = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $weekdayPatterns = [];
    foreach ($weekdayRows as $row) {
        $weekdayIndex = (int) ($row['weekday_index'] ?? -1);
        if (!isset($weekdayNames[$weekdayIndex])) {
            continue;
        }

        $weekdayPatterns[] = [
            'label' => $weekdayNames[$weekdayIndex],
            'entries' => (int) ($row['entries'] ?? 0),
            'avg_rating' => isset($row['avg_rating']) && $row['avg_rating'] !== null
                ? round((float) $row['avg_rating'], 2)
                : null,
        ];
    }

    return [
        'summary' => [
            'entries_last_30_days' => $entriesLast30Days,
            'active_days_last_30_days' => $activeDaysLast30Days,
            'active_day_ratio_last_30_days' => round(($activeDaysLast30Days / 30) * 100, 1),
            'avg_rating_this_week' => $avgRatingThisWeek,
            'avg_rating_last_90_days' => $avgRatingLast90Days,
        ],
        'monthly_average_rating' => $monthlyAverageRating,
        'weekly_entries' => $weeklyEntries,
        'top_medicines_90_days' => $topMedicines,
        'weekday_patterns_90_days' => $weekdayPatterns,
    ];
}

function parseCalendarMonth(string $monthInput): DateTimeImmutable
{
    $cleanMonth = trim($monthInput);
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $cleanMonth) === 1) {
        $parsedMonth = DateTimeImmutable::createFromFormat('!Y-m', $cleanMonth);
        $errors = DateTimeImmutable::getLastErrors();
        $hasParseErrors = is_array($errors)
            && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0);

        if (
            $parsedMonth instanceof DateTimeImmutable
            && !$hasParseErrors
        ) {
            return $parsedMonth->setTime(0, 0, 0);
        }
    }

    return new DateTimeImmutable('first day of this month 00:00:00');
}

function loadCalendarMonth(PDO $pdo, string $monthInput): array
{
    $monthStart = parseCalendarMonth($monthInput);
    $monthEnd = $monthStart->modify('+1 month');

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
         WHERE l.taken_at >= :start_date
           AND l.taken_at < :end_date
         ORDER BY l.taken_at ASC, l.id ASC'
    );
    $statement->execute([
        ':start_date' => $monthStart->format('Y-m-d H:i:s'),
        ':end_date' => $monthEnd->format('Y-m-d H:i:s'),
    ]);
    $rows = $statement->fetchAll();

    $daysByDate = [];
    foreach ($rows as $row) {
        $entry = serializeEntry($row);
        $dayKey = (string) ($entry['taken_day_key'] ?? '');
        if ($dayKey === '') {
            continue;
        }

        if (!isset($daysByDate[$dayKey])) {
            $daysByDate[$dayKey] = [
                'date' => $dayKey,
                'day' => (int) substr($dayKey, 8, 2),
                'entry_count' => 0,
                'avg_rating' => null,
                'entries' => [],
                '_rating_sum' => 0,
            ];
        }

        $daysByDate[$dayKey]['entries'][] = $entry;
        $daysByDate[$dayKey]['entry_count'] += 1;
        $daysByDate[$dayKey]['_rating_sum'] += (int) ($entry['rating'] ?? 0);
    }

    foreach ($daysByDate as $dayKey => $dayData) {
        $entryCount = (int) $dayData['entry_count'];
        $ratingSum = (int) $dayData['_rating_sum'];
        $daysByDate[$dayKey]['avg_rating'] = $entryCount > 0
            ? round($ratingSum / $entryCount, 2)
            : null;
        unset($daysByDate[$dayKey]['_rating_sum']);
    }

    ksort($daysByDate);

    return [
        'month' => $monthStart->format('Y-m'),
        'month_label' => $monthStart->format('F Y'),
        'year' => (int) $monthStart->format('Y'),
        'month_number' => (int) $monthStart->format('n'),
        'first_weekday' => (int) $monthStart->format('w'),
        'days_in_month' => (int) $monthStart->format('t'),
        'today' => (new DateTimeImmutable('now'))->format('Y-m-d'),
        'prev_month' => $monthStart->modify('-1 month')->format('Y-m'),
        'next_month' => $monthStart->modify('+1 month')->format('Y-m'),
        'days' => array_values($daysByDate),
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

function normalizeTimeOfDay(string $value): ?string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $match = [];
    if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $trimmed, $match) !== 1) {
        return null;
    }

    $hour = $match[1];
    $minute = $match[2];
    $second = $match[3] ?? '00';

    return sprintf('%s:%s:%s', $hour, $minute, $second);
}

function normalizeSchedulePayload(array $payload): array
{
    return [
        'medicine_id' => (int) ($payload['medicine_id'] ?? 0),
        'dosage_value' => trim((string) ($payload['dosage_value'] ?? '20')),
        'dosage_unit' => strtolower(trim((string) ($payload['dosage_unit'] ?? 'mg'))),
        'time_of_day' => trim((string) ($payload['time_of_day'] ?? '')),
        'is_active' => isset($payload['is_active']) ? (int) $payload['is_active'] : 1,
    ];
}

function validateSchedulePayload(array $payload, PDO $pdo, array $allowedDosageUnits): array
{
    $data = normalizeSchedulePayload($payload);
    $errors = [];

    $medicineName = '';
    if ($data['medicine_id'] <= 0) {
        $errors[] = 'Please choose a medicine for this schedule.';
    } else {
        $statement = $pdo->prepare('SELECT id, name FROM medicines WHERE id = :id LIMIT 1');
        $statement->execute([':id' => $data['medicine_id']]);
        $medicine = $statement->fetch();
        if (!is_array($medicine)) {
            $errors[] = 'The selected medicine does not exist.';
        } else {
            $medicineName = (string) $medicine['name'];
        }
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

    $timeOfDayForDb = normalizeTimeOfDay($data['time_of_day']);
    if ($timeOfDayForDb === null) {
        $errors[] = 'Reminder time must be in HH:MM format.';
    }

    return [
        'errors' => $errors,
        'medicine_name' => $medicineName,
        'dosage_value_for_db' => $dosageValueForDb,
        'time_of_day_for_db' => $timeOfDayForDb,
        'data' => $data,
    ];
}

function serializeSchedule(array $row): array
{
    $timeOfDay = (string) ($row['time_of_day'] ?? '00:00:00');

    return [
        'id' => (int) ($row['id'] ?? 0),
        'user_id' => (int) ($row['user_id'] ?? 0),
        'medicine_id' => (int) ($row['medicine_id'] ?? 0),
        'medicine_name' => (string) ($row['medicine_name'] ?? ''),
        'dosage_value' => formatDosageValue((string) ($row['dosage_value'] ?? '0')),
        'dosage_unit' => (string) ($row['dosage_unit'] ?? 'mg'),
        'dosage_display' => formatDosageValue((string) ($row['dosage_value'] ?? '0'))
            . ' '
            . (string) ($row['dosage_unit'] ?? 'mg'),
        'time_of_day' => $timeOfDay,
        'time_of_day_input' => substr($timeOfDay, 0, 5),
        'time_label' => formatTimeOnly('1970-01-01 ' . $timeOfDay),
        'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function loadDoseSchedules(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT s.id,
                s.user_id,
                s.medicine_id,
                m.name AS medicine_name,
                s.dosage_value,
                s.dosage_unit,
                s.time_of_day,
                s.is_active,
                s.created_at,
                s.updated_at
         FROM dose_schedules s
         INNER JOIN medicines m ON m.id = s.medicine_id
         WHERE s.user_id = :user_id
         ORDER BY s.time_of_day ASC, m.name ASC, s.id ASC'
    );
    $statement->execute([':user_id' => $userId]);
    $rows = $statement->fetchAll();

    return array_values(array_map(static fn(array $row): array => serializeSchedule($row), $rows));
}

function savePushSubscription(PDO $pdo, int $userId, array $payload): array
{
    $endpoint = trim((string) ($payload['endpoint'] ?? ''));
    $keys = is_array($payload['keys'] ?? null) ? $payload['keys'] : [];
    $p256dh = trim((string) ($keys['p256dh'] ?? ''));
    $authKey = trim((string) ($keys['auth'] ?? ''));
    $userAgent = trim((string) ($payload['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? '')));

    if ($endpoint === '' || $p256dh === '' || $authKey === '') {
        return [
            'ok' => false,
            'error' => 'Invalid push subscription payload.',
        ];
    }

    $endpointHash = hash('sha256', $endpoint);

    $statement = $pdo->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint, endpoint_hash, p256dh, auth_key, user_agent, is_active, last_seen_at)
         VALUES (:user_id, :endpoint, :endpoint_hash, :p256dh, :auth_key, :user_agent, 1, NOW())
         ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            endpoint = VALUES(endpoint),
            p256dh = VALUES(p256dh),
            auth_key = VALUES(auth_key),
            user_agent = VALUES(user_agent),
            is_active = 1,
            last_seen_at = NOW()'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':endpoint' => $endpoint,
        ':endpoint_hash' => $endpointHash,
        ':p256dh' => $p256dh,
        ':auth_key' => $authKey,
        ':user_agent' => $userAgent !== '' ? substr($userAgent, 0, 255) : null,
    ]);

    return [
        'ok' => true,
        'endpoint_hash' => $endpointHash,
    ];
}

function removePushSubscription(PDO $pdo, int $userId, array $payload): void
{
    $endpoint = trim((string) ($payload['endpoint'] ?? ''));
    if ($endpoint === '') {
        return;
    }

    $statement = $pdo->prepare(
        'UPDATE push_subscriptions
         SET is_active = 0, updated_at = CURRENT_TIMESTAMP
         WHERE user_id = :user_id
           AND endpoint_hash = :endpoint_hash'
    );
    $statement->execute([
        ':user_id' => $userId,
        ':endpoint_hash' => hash('sha256', $endpoint),
    ]);
}

function activePushSubscriptions(PDO $pdo, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, endpoint, endpoint_hash
         FROM push_subscriptions
         WHERE user_id = :user_id
           AND is_active = 1'
    );
    $statement->execute([':user_id' => $userId]);
    $rows = $statement->fetchAll();

    return is_array($rows) ? $rows : [];
}

function dispatchPushForUser(PDO $pdo, int $userId): array
{
    $subscriptions = activePushSubscriptions($pdo, $userId);
    $attempted = count($subscriptions);
    $sent = 0;
    $failed = 0;
    $deactivated = 0;
    $statusBreakdown = [];
    $failureDetails = [];

    foreach ($subscriptions as $subscription) {
        $endpoint = (string) ($subscription['endpoint'] ?? '');
        if ($endpoint === '') {
            continue;
        }

        $result = PushNotifications::sendToEndpoint($endpoint);
        $status = (int) ($result['status'] ?? 0);
        $statusKey = (string) $status;
        $statusBreakdown[$statusKey] = (int) ($statusBreakdown[$statusKey] ?? 0) + 1;

        if (($result['ok'] ?? false) === true) {
            $sent += 1;
            continue;
        }

        $failed += 1;
        $host = (string) (parse_url($endpoint, PHP_URL_HOST) ?? '');
        $failureDetails[] = [
            'subscription_id' => (int) ($subscription['id'] ?? 0),
            'host' => $host !== '' ? $host : 'unknown',
            'status' => $status,
            'transport' => (string) ($result['transport'] ?? 'unknown'),
            'error' => substr(trim((string) ($result['error'] ?? '')), 0, 220),
        ];

        if ($status === 404 || $status === 410) {
            $deactivateStatement = $pdo->prepare(
                'UPDATE push_subscriptions
                 SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $deactivateStatement->execute([':id' => (int) ($subscription['id'] ?? 0)]);
            $deactivated += 1;
        }
    }

    return [
        'attempted' => $attempted,
        'sent' => $sent,
        'failed' => $failed,
        'deactivated' => $deactivated,
        'status_breakdown' => $statusBreakdown,
        'failures' => $failureDetails,
    ];
}

function processDueReminders(PDO $pdo, ?int $userId = null): array
{
    $timezoneName = Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC';
    $timezone = new DateTimeZone($timezoneName);
    $now = new DateTimeImmutable('now', $timezone);
    $windowMinutesRaw = (int) (Env::get('REMINDER_WINDOW_MINUTES', '5') ?? 5);
    $windowMinutes = max(1, min(30, $windowMinutesRaw));
    $windowStart = $now->sub(new DateInterval('PT' . $windowMinutes . 'M'));

    $query = 'SELECT s.id,
                     s.user_id,
                     s.medicine_id,
                     m.name AS medicine_name,
                     s.dosage_value,
                     s.dosage_unit,
                     s.time_of_day,
                     s.is_active
              FROM dose_schedules s
              INNER JOIN medicines m ON m.id = s.medicine_id
              WHERE s.is_active = 1';
    $params = [];

    if ($userId !== null) {
        $query .= ' AND s.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $query .= ' ORDER BY s.user_id ASC, s.time_of_day ASC, s.id ASC';
    $scheduleStatement = $pdo->prepare($query);
    $scheduleStatement->execute($params);
    $schedules = $scheduleStatement->fetchAll();

    $processed = 0;
    $due = 0;
    $sent = 0;
    $skipped = 0;

    foreach ($schedules as $schedule) {
        $processed += 1;
        $scheduleTime = substr((string) ($schedule['time_of_day'] ?? '00:00:00'), 0, 5);
        $scheduledFor = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $now->format('Y-m-d') . ' ' . $scheduleTime,
            $timezone
        );
        if (!$scheduledFor instanceof DateTimeImmutable) {
            $skipped += 1;
            continue;
        }

        if ($scheduledFor < $windowStart || $scheduledFor > $now) {
            $skipped += 1;
            continue;
        }

        $scheduledForDb = $scheduledFor->format('Y-m-d H:i:s');
        $dispatchId = null;

        try {
            $insertStatement = $pdo->prepare(
                'INSERT INTO reminder_dispatches (schedule_id, scheduled_for, status)
                 VALUES (:schedule_id, :scheduled_for, :status)'
            );
            $insertStatement->execute([
                ':schedule_id' => (int) $schedule['id'],
                ':scheduled_for' => $scheduledForDb,
                ':status' => 'pending',
            ]);
            $dispatchId = (int) $pdo->lastInsertId();
            $due += 1;
        } catch (Throwable $exception) {
            // Unique index prevents duplicate sends for the same schedule occurrence.
            $skipped += 1;
            continue;
        }

        $dispatchResult = dispatchPushForUser($pdo, (int) $schedule['user_id']);
        $sent += (int) ($dispatchResult['sent'] ?? 0);
        $status = ((int) ($dispatchResult['sent'] ?? 0) > 0) ? 'sent' : 'failed';
        $errorMessage = ((int) ($dispatchResult['attempted'] ?? 0) === 0)
            ? 'No active browser subscriptions.'
            : (((int) ($dispatchResult['failed'] ?? 0) > 0) ? 'One or more push sends failed.' : null);

        if ($dispatchId !== null) {
            $updateStatement = $pdo->prepare(
                'UPDATE reminder_dispatches
                 SET sent_count = :sent_count,
                     status = :status,
                     error_message = :error_message,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $updateStatement->execute([
                ':sent_count' => (int) ($dispatchResult['sent'] ?? 0),
                ':status' => $status,
                ':error_message' => $errorMessage,
                ':id' => $dispatchId,
            ]);
        }
    }

    return [
        'processed' => $processed,
        'due' => $due,
        'sent' => $sent,
        'skipped' => $skipped,
        'window_minutes' => $windowMinutes,
        'processed_at' => $now->format(DateTimeInterface::ATOM),
    ];
}

$dbError = null;
try {
    $pdo = Database::connection();
} catch (Throwable $exception) {
    $pdo = null;
    $dbError = $exception->getMessage();
}

if ($apiAction !== '') {
    if (!$pdo instanceof PDO) {
        jsonResponse([
            'ok' => false,
            'error' => 'Database connection failed. Check your .env settings.',
        ], 500);
        exit;
    }

    try {
        $currentUserId = Auth::userId();

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'dashboard') {
            jsonResponse([
                'ok' => true,
                'metrics' => loadMetrics($pdo),
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'trends') {
            jsonResponse([
                'ok' => true,
                'trends' => loadTrends($pdo),
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'calendar') {
            $monthInput = (string) ($_GET['month'] ?? '');
            jsonResponse([
                'ok' => true,
                'calendar' => loadCalendarMonth($pdo, $monthInput),
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

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'schedules') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            jsonResponse([
                'ok' => true,
                'schedules' => loadDoseSchedules($pdo, $currentUserId),
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'entries') {
            $page = max(1, (int) ($_GET['page'] ?? 1));
            $entriesFilters = [
                'search' => (string) ($_GET['search'] ?? ''),
                'medicine_id' => (int) ($_GET['medicine_id'] ?? 0),
                'rating' => (int) ($_GET['rating'] ?? 0),
                'from_date' => (string) ($_GET['from_date'] ?? ''),
                'to_date' => (string) ($_GET['to_date'] ?? ''),
            ];
            $entriesPage = loadEntriesPage($pdo, $page, $perPage, $entriesFilters);

            jsonResponse([
                'ok' => true,
                'entries' => $entriesPage['entries'],
                'pagination' => $entriesPage['pagination'],
                'filters' => $entriesPage['filters'],
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'export_entries_csv') {
            $exportFilters = [
                'search' => (string) ($_GET['search'] ?? ''),
                'medicine_id' => (int) ($_GET['medicine_id'] ?? 0),
                'rating' => (int) ($_GET['rating'] ?? 0),
                'from_date' => (string) ($_GET['from_date'] ?? ''),
                'to_date' => (string) ($_GET['to_date'] ?? ''),
            ];
            downloadEntriesCsv($pdo, $exportFilters);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'backup_json') {
            downloadBackupJson($pdo);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'backup_sql') {
            downloadBackupSql($pdo);
            exit;
        }

        if (
            in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)
            && $apiAction === 'process_reminders'
        ) {
            $expectedToken = trim((string) (Env::get('REMINDER_CRON_TOKEN', '') ?? ''));
            $providedToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
            $isCronAuthorized = $expectedToken !== ''
                && $providedToken !== ''
                && hash_equals($expectedToken, $providedToken);

            if (!$isCronAuthorized && $currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $result = processDueReminders($pdo, $isCronAuthorized ? null : $currentUserId);
            jsonResponse([
                'ok' => true,
                'result' => $result,
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'schedule_create') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $payload = requestPayload();
            $validated = validateSchedulePayload($payload, $pdo, $allowedDosageUnits);
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
                'INSERT INTO dose_schedules (user_id, medicine_id, dosage_value, dosage_unit, time_of_day, is_active)
                 VALUES (:user_id, :medicine_id, :dosage_value, :dosage_unit, :time_of_day, :is_active)'
            );
            $statement->execute([
                ':user_id' => $currentUserId,
                ':medicine_id' => $data['medicine_id'],
                ':dosage_value' => $validated['dosage_value_for_db'],
                ':dosage_unit' => $data['dosage_unit'],
                ':time_of_day' => $validated['time_of_day_for_db'],
                ':is_active' => $data['is_active'] === 0 ? 0 : 1,
            ]);

            jsonResponse([
                'ok' => true,
                'message' => 'Schedule created.',
            ], 201);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'schedule_toggle') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $payload = requestPayload();
            $scheduleId = (int) ($payload['id'] ?? 0);
            $isActive = (int) ($payload['is_active'] ?? 0) === 1 ? 1 : 0;
            if ($scheduleId <= 0) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Valid schedule id is required.',
                ], 422);
                exit;
            }

            $statement = $pdo->prepare(
                'UPDATE dose_schedules
                 SET is_active = :is_active, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND user_id = :user_id'
            );
            $statement->execute([
                ':is_active' => $isActive,
                ':id' => $scheduleId,
                ':user_id' => $currentUserId,
            ]);

            if ($statement->rowCount() === 0) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Schedule not found.',
                ], 404);
                exit;
            }

            jsonResponse([
                'ok' => true,
                'message' => $isActive === 1 ? 'Schedule enabled.' : 'Schedule paused.',
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'schedule_delete') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $payload = requestPayload();
            $scheduleId = (int) ($payload['id'] ?? 0);
            if ($scheduleId <= 0) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Valid schedule id is required.',
                ], 422);
                exit;
            }

            $statement = $pdo->prepare('DELETE FROM dose_schedules WHERE id = :id AND user_id = :user_id');
            $statement->execute([
                ':id' => $scheduleId,
                ':user_id' => $currentUserId,
            ]);

            if ($statement->rowCount() === 0) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Schedule not found.',
                ], 404);
                exit;
            }

            jsonResponse([
                'ok' => true,
                'message' => 'Schedule deleted.',
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'push_subscribe') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $payload = requestPayload();
            $saved = savePushSubscription($pdo, $currentUserId, $payload);
            if (($saved['ok'] ?? false) !== true) {
                jsonResponse([
                    'ok' => false,
                    'error' => (string) ($saved['error'] ?? 'Could not save subscription.'),
                ], 422);
                exit;
            }

            jsonResponse([
                'ok' => true,
                'message' => 'Browser subscription saved.',
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'push_unsubscribe') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $payload = requestPayload();
            removePushSubscription($pdo, $currentUserId, $payload);
            jsonResponse([
                'ok' => true,
                'message' => 'Browser subscription removed.',
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'push_test') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $dispatch = dispatchPushForUser($pdo, $currentUserId);
            jsonResponse([
                'ok' => true,
                'dispatch' => $dispatch,
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
            perPage: <?= $perPage ?>,
            pushPublicKey: <?= json_encode(PushNotifications::publicKey(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            pushConfigured: <?= PushNotifications::isConfigured() ? 'true' : 'false' ?>
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
            <div class="hero-actions">
                <a class="ghost-btn nav-link" href="trends.php">View Trends</a>
                <a class="ghost-btn nav-link" href="calendar.php">View Calendar</a>
                <a class="ghost-btn nav-link" href="logout.php">Log Out</a>
            </div>
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
                <p class="metric-label">Avg Rating This Week</p>
                <p id="metric-rating-week" class="metric-value">--</p>
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
                    <div class="history-header-controls">
                        <span id="table-meta" class="meta-text">Loading entries...</span>
                        <button
                            id="history-filter-toggle"
                            type="button"
                            class="ghost-btn history-toggle-btn"
                            aria-controls="history-tools-panel"
                            aria-expanded="false"
                        >
                            Show Filters
                        </button>
                    </div>
                </div>

                <div id="history-tools-panel" class="history-tools" hidden>
                    <form id="history-filter-form" class="history-filter-form" novalidate>
                        <div class="history-filter-grid">
                            <div class="history-filter-field history-filter-search">
                                <label for="filter_search">Search</label>
                                <input id="filter_search" name="filter_search" type="search" placeholder="Search medicine or notes">
                            </div>

                            <div class="history-filter-field">
                                <label for="filter_medicine_id">Medicine</label>
                                <select id="filter_medicine_id" name="filter_medicine_id">
                                    <option value="">All medicines</option>
                                </select>
                            </div>

                            <div class="history-filter-field">
                                <label for="filter_rating">Rating</label>
                                <select id="filter_rating" name="filter_rating">
                                    <option value="">All ratings</option>
                                    <option value="5">5 ★</option>
                                    <option value="4">4 ★</option>
                                    <option value="3">3 ★</option>
                                    <option value="2">2 ★</option>
                                    <option value="1">1 ★</option>
                                </select>
                            </div>

                            <div class="history-filter-field">
                                <label for="filter_from_date">From</label>
                                <input id="filter_from_date" name="filter_from_date" type="date">
                            </div>

                            <div class="history-filter-field">
                                <label for="filter_to_date">To</label>
                                <input id="filter_to_date" name="filter_to_date" type="date">
                            </div>
                        </div>

                        <div class="history-filter-actions">
                            <button class="ghost-btn" type="submit">Apply Filters</button>
                            <button id="history-filter-clear" class="ghost-btn" type="button">Clear</button>
                            <button id="history-filter-last7" class="ghost-btn" type="button">Last 7 Days</button>
                            <button id="history-filter-last30" class="ghost-btn" type="button">Last 30 Days</button>
                        </div>
                    </form>

                    <p id="history-filter-summary" class="meta-text">No filters applied.</p>
                </div>

                <div class="data-tools">
                    <div class="data-tools-actions">
                        <button id="export-csv-btn" type="button" class="ghost-btn">Export CSV (Filtered)</button>
                        <button id="export-csv-all-btn" type="button" class="ghost-btn">Export CSV (All)</button>
                        <button id="backup-json-btn" type="button" class="ghost-btn">Backup JSON</button>
                        <button id="backup-sql-btn" type="button" class="ghost-btn">Backup SQL</button>
                    </div>
                    <p class="meta-text">Download entry exports and full backups for safekeeping.</p>
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

                    <button id="schedule-submit-btn" class="primary-btn" type="submit">Add Schedule</button>
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
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="schedules-body">
                            <tr>
                                <td class="empty-cell" colspan="5">Loading schedules...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
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
