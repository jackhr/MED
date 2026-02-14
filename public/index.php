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

function loadEntriesPage(PDO $pdo, int $page, int $perPage, array $filters): array
{
    $normalizedFilters = normalizeEntryFilters($filters);

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

    $whereSql = $whereParts !== [] ? (' WHERE ' . implode(' AND ', $whereParts)) : '';
    $totalAllEntries = (int) $pdo->query('SELECT COUNT(*) FROM medicine_intake_logs')->fetchColumn();

    $countStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM medicine_intake_logs l
         INNER JOIN medicines m ON m.id = l.medicine_id'
        . $whereSql
    );
    foreach ($bindings as $bindingKey => $bindingValue) {
        $paramType = is_int($bindingValue) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $countStatement->bindValue($bindingKey, $bindingValue, $paramType);
    }
    $countStatement->execute();
    $totalEntries = (int) $countStatement->fetchColumn();
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
         ' . $whereSql . '
         ORDER BY l.taken_at DESC, l.id DESC
         LIMIT :limit OFFSET :offset'
    );
    foreach ($bindings as $bindingKey => $bindingValue) {
        $paramType = is_int($bindingValue) ? PDO::PARAM_INT : PDO::PARAM_STR;
        $statement->bindValue($bindingKey, $bindingValue, $paramType);
    }
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
            <div class="hero-actions">
                <a class="ghost-btn nav-link" href="trends.php">View Trends</a>
                <a class="ghost-btn nav-link" href="calendar.php">View Calendar</a>
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
