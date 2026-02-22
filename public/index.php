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
if (in_array($apiAction, ['process_reminders', 'push_message'], true)) {
    // These API endpoints can be resolved without an active session.
} elseif ($apiAction !== '') {
    Auth::requireAuthForApi();
} else {
    Auth::requireAuthForPage('login.php');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function utf8Length(string $value): int
{
    if (function_exists('mb_strlen')) {
        $length = mb_strlen($value, 'UTF-8');
        if ($length !== false) {
            return $length;
        }
    }

    return strlen($value);
}

function utf8Truncate(string $value, int $maxLength): string
{
    if ($maxLength <= 0) {
        return '';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        $length = mb_strlen($value, 'UTF-8');
        if ($length !== false && $length > $maxLength) {
            $trimmed = mb_substr($value, 0, $maxLength, 'UTF-8');
            return $trimmed === false ? $value : $trimmed;
        }

        return $value;
    }

    return strlen($value) > $maxLength
        ? substr($value, 0, $maxLength)
        : $value;
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

function ordinal(int $number): string
{
    $absolute = abs($number);
    $mod100 = $absolute % 100;
    if ($mod100 >= 11 && $mod100 <= 13) {
        return $number . 'th';
    }

    return match ($absolute % 10) {
        1 => $number . 'st',
        2 => $number . 'nd',
        3 => $number . 'rd',
        default => $number . 'th',
    };
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
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        $json = '{"ok":false,"error":"Unable to encode response payload."}';
    }

    echo $json;
}

function envFlag(string $key, bool $default = false): bool
{
    $raw = Env::get($key, $default ? '1' : '0');
    if ($raw === null) {
        return $default;
    }

    $normalized = strtolower(trim($raw));
    if (in_array($normalized, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($normalized, ['0', 'false', 'no', 'off', ''], true)) {
        return false;
    }

    return $default;
}

function normalizeWorkspaceRole(?string $role): string
{
    $normalized = strtolower(trim((string) $role));
    if (in_array($normalized, ['owner', 'editor', 'viewer'], true)) {
        return $normalized;
    }

    return 'viewer';
}

function workspaceCanWrite(?string $role): bool
{
    $normalizedRole = normalizeWorkspaceRole($role);
    return in_array($normalizedRole, ['owner', 'editor'], true);
}

function appLogEnabled(): bool
{
    return envFlag('APP_LOG_ENABLED', true);
}

function resolveAppPath(string $configuredPath, string $defaultRelativePath): string
{
    $normalizedConfiguredPath = trim($configuredPath);
    if ($normalizedConfiguredPath === '') {
        return dirname(__DIR__) . '/' . ltrim($defaultRelativePath, '/');
    }

    if (str_starts_with($normalizedConfiguredPath, '/')) {
        return $normalizedConfiguredPath;
    }

    return dirname(__DIR__) . '/' . ltrim($normalizedConfiguredPath, '/');
}

function appLogTextPath(): string
{
    $configuredPath = (string) (Env::get('APP_LOG_FILE', '') ?? '');
    return resolveAppPath($configuredPath, 'logs/medicine.log');
}

function appLogJsonPath(): string
{
    $configuredPath = (string) (Env::get('APP_LOG_JSON_FILE', '') ?? '');
    if (trim($configuredPath) !== '') {
        return resolveAppPath($configuredPath, 'logs/medicine.json');
    }

    $textPath = appLogTextPath();
    if (str_ends_with($textPath, '.log')) {
        return substr($textPath, 0, -4) . '.json';
    }

    return $textPath . '.json';
}

function appendToFileWithFallback(string $primaryPath, string $fallbackFileName, string $line): bool
{
    $fallbackPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fallbackFileName;
    $paths = [$primaryPath, $fallbackPath];

    foreach ($paths as $path) {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }

        if (!is_dir($directory) || !is_writable($directory)) {
            continue;
        }

        $bytesWritten = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
        if ($bytesWritten !== false) {
            return true;
        }
    }

    return false;
}

function appendLogLine(string $line): bool
{
    return appendToFileWithFallback(appLogTextPath(), 'medicine.log', $line);
}

function appendJsonLogLine(string $line): bool
{
    return appendToFileWithFallback(appLogJsonPath(), 'medicine.json', $line);
}

function reminderRequestProbeEnabled(): bool
{
    return envFlag('REMINDER_REQUEST_INFO_ENABLED', true);
}

function reminderRequestInfoPath(): string
{
    $configuredPath = (string) (Env::get('REMINDER_REQUEST_INFO_FILE', '') ?? '');
    return resolveAppPath($configuredPath, 'logs/process_reminders.info');
}

function appendReminderRequestInfoLine(string $line): bool
{
    return appendToFileWithFallback(reminderRequestInfoPath(), 'process_reminders.info', $line);
}

function logReminderRequestProbe(string $phase, array $context = []): void
{
    if (!reminderRequestProbeEnabled()) {
        return;
    }

    $timezoneName = Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC';
    $timezone = new DateTimeZone($timezoneName);
    $record = [
        'timestamp' => (new DateTimeImmutable('now', $timezone))->format(DateTimeInterface::ATOM),
        'phase' => trim($phase) !== '' ? trim($phase) : 'received',
        'context' => $context,
    ];

    $json = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($json)) {
        appendReminderRequestInfoLine($json . PHP_EOL);
        return;
    }

    appendReminderRequestInfoLine('{"timestamp":"unknown","phase":"encode_failure","context":{}}' . PHP_EOL);
}

function requestIp(): string
{
    $forwardedFor = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwardedFor !== '') {
        $parts = array_map('trim', explode(',', $forwardedFor));
        if (isset($parts[0]) && $parts[0] !== '') {
            return substr($parts[0], 0, 120);
        }
    }

    $remoteAddr = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($remoteAddr !== '') {
        return substr($remoteAddr, 0, 120);
    }

    return 'unknown';
}

function logEvent(string $level, string $event, array $context = []): void
{
    if (!appLogEnabled()) {
        return;
    }

    $timezoneName = Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC';
    $timezone = new DateTimeZone($timezoneName);
    $timestamp = (new DateTimeImmutable('now', $timezone))->format(DateTimeInterface::ATOM);
    $record = [
        'timestamp' => $timestamp,
        'level' => strtolower(trim($level)) !== '' ? strtolower(trim($level)) : 'info',
        'event' => trim($event) !== '' ? trim($event) : 'app.event',
        'context' => $context,
    ];

    $contextJson = json_encode($record['context'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($contextJson)) {
        $contextJson = '{}';
    }

    $logLine = sprintf(
        '[%s] %s %s context=%s',
        $timestamp,
        strtoupper((string) $record['level']),
        (string) $record['event'],
        $contextJson
    );
    appendLogLine($logLine . PHP_EOL);

    $jsonLine = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (is_string($jsonLine)) {
        appendJsonLogLine($jsonLine . PHP_EOL);
    } else {
        $fallbackRecord = [
            'timestamp' => $timestamp,
            'level' => (string) $record['level'],
            'event' => (string) $record['event'],
            'context' => new stdClass(),
        ];
        $fallbackJson = json_encode($fallbackRecord, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($fallbackJson)) {
            appendJsonLogLine($fallbackJson . PHP_EOL);
        } else {
            appendJsonLogLine('{"timestamp":"unknown","level":"error","event":"log.encode_failure","context":{}}' . PHP_EOL);
        }
    }
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

function resolveMedicineId(PDO $pdo, int $workspaceId, array &$data, array &$errors): ?int
{
    $mode = $data['medicine_mode'] === 'new' ? 'new' : 'existing';

    if ($mode === 'existing') {
        if ($data['medicine_id'] <= 0) {
            $errors[] = 'Please select an existing medicine.';
            return null;
        }

        $statement = $pdo->prepare(
            'SELECT id, name
             FROM medicines
             WHERE id = :id
               AND workspace_id = :workspace_id
             LIMIT 1'
        );
        $statement->execute([
            ':id' => $data['medicine_id'],
            ':workspace_id' => $workspaceId,
        ]);
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

    if (utf8Length($data['medicine_name']) > 120) {
        $errors[] = 'Medicine name must be 120 characters or less.';
        return null;
    }

    $existingStatement = $pdo->prepare(
        'SELECT id, name
         FROM medicines
         WHERE workspace_id = :workspace_id
           AND name = :name
         LIMIT 1'
    );
    $existingStatement->execute([
        ':workspace_id' => $workspaceId,
        ':name' => $data['medicine_name'],
    ]);
    $existingMedicine = $existingStatement->fetch();
    if (is_array($existingMedicine)) {
        $data['medicine_name'] = (string) $existingMedicine['name'];
        return (int) $existingMedicine['id'];
    }

    try {
        $insertStatement = $pdo->prepare(
            'INSERT INTO medicines (workspace_id, name)
             VALUES (:workspace_id, :name)'
        );
        $insertStatement->execute([
            ':workspace_id' => $workspaceId,
            ':name' => $data['medicine_name'],
        ]);
        return (int) $pdo->lastInsertId();
    } catch (Throwable $exception) {
        $retryStatement = $pdo->prepare(
            'SELECT id, name
             FROM medicines
             WHERE workspace_id = :workspace_id
               AND name = :name
             LIMIT 1'
        );
        $retryStatement->execute([
            ':workspace_id' => $workspaceId,
            ':name' => $data['medicine_name'],
        ]);
        $retryMedicine = $retryStatement->fetch();

        if (!is_array($retryMedicine)) {
            $errors[] = 'Unable to create medicine type.';
            return null;
        }

        $data['medicine_name'] = (string) $retryMedicine['name'];
        return (int) $retryMedicine['id'];
    }
}

function validateIntake(array $payload, PDO $pdo, int $workspaceId, array $allowedDosageUnits): array
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
    } elseif (utf8Length($data['medicine_name']) > 120) {
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

    if (utf8Length($data['notes']) > 255) {
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
        $medicineId = resolveMedicineId($pdo, $workspaceId, $data, $errors);
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
    $loggedByUserId = isset($entry['logged_by_user_id']) ? (int) $entry['logged_by_user_id'] : 0;
    $loggedByUsername = trim((string) ($entry['logged_by_username'] ?? ''));
    $dosageValue = (string) ($entry['dosage_value'] ?? '0');
    $dosageUnit = (string) ($entry['dosage_unit'] ?? 'mg');
    $rating = (int) ($entry['rating'] ?? 3);
    $formattedDosageValue = formatDosageValue($dosageValue);

    return [
        'id' => (int) $entry['id'],
        'medicine_id' => (int) $entry['medicine_id'],
        'medicine_name' => (string) $entry['medicine_name'],
        'logged_by_user_id' => $loggedByUserId > 0 ? $loggedByUserId : null,
        'logged_by_username' => $loggedByUsername !== '' ? $loggedByUsername : null,
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

function findEntry(PDO $pdo, int $workspaceId, int $id): ?array
{
    $statement = $pdo->prepare(
        'SELECT l.id,
                l.medicine_id,
                m.name AS medicine_name,
                l.logged_by_user_id,
                COALESCE(NULLIF(TRIM(u.display_name), ""), u.username) AS logged_by_username,
                l.dosage_value,
                l.dosage_unit,
                l.rating,
                l.taken_at,
                l.notes,
                l.created_at
         FROM medicine_intake_logs l
         INNER JOIN medicines m ON m.id = l.medicine_id
                              AND m.workspace_id = l.workspace_id
         LEFT JOIN app_users u ON u.id = l.logged_by_user_id
         WHERE l.id = :id
           AND l.workspace_id = :workspace_id
         LIMIT 1'
    );
    $statement->execute([
        ':id' => $id,
        ':workspace_id' => $workspaceId,
    ]);
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
    $search = utf8Truncate(trim((string) ($filters['search'] ?? '')), 120);

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

function buildEntriesWhereSql(array $normalizedFilters, int $workspaceId): array
{
    $whereParts = ['l.workspace_id = :workspace_id'];
    $bindings = [
        ':workspace_id' => $workspaceId,
    ];

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
                   l.logged_by_user_id,
                   COALESCE(NULLIF(TRIM(u.display_name), ""), u.username) AS logged_by_username,
                   l.dosage_value,
                   l.dosage_unit,
                   l.rating,
                   l.taken_at,
                   l.notes,
                   l.created_at
            FROM medicine_intake_logs l
            INNER JOIN medicines m ON m.id = l.medicine_id
                                 AND m.workspace_id = l.workspace_id
            LEFT JOIN app_users u ON u.id = l.logged_by_user_id'
        . $whereSql
        . ' '
        . trim($suffixSql);
}

function loadEntriesPage(PDO $pdo, int $workspaceId, int $page, int $perPage, array $filters): array
{
    $normalizedFilters = normalizeEntryFilters($filters);
    $whereData = buildEntriesWhereSql($normalizedFilters, $workspaceId);
    $whereSql = $whereData['where_sql'];
    $bindings = $whereData['bindings'];
    $totalAllEntriesStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM medicine_intake_logs
         WHERE workspace_id = :workspace_id'
    );
    $totalAllEntriesStatement->execute([':workspace_id' => $workspaceId]);
    $totalAllEntries = (int) $totalAllEntriesStatement->fetchColumn();

    $countStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM medicine_intake_logs l
         INNER JOIN medicines m ON m.id = l.medicine_id
                              AND m.workspace_id = l.workspace_id'
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

function fetchEntriesForExport(PDO $pdo, int $workspaceId, array $filters): array
{
    $normalizedFilters = normalizeEntryFilters($filters);
    $whereData = buildEntriesWhereSql($normalizedFilters, $workspaceId);
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

function downloadEntriesCsv(PDO $pdo, int $workspaceId, array $filters): void
{
    $exportData = fetchEntriesForExport($pdo, $workspaceId, $filters);
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
        'Logged By',
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
            (string) ($entry['logged_by_username'] ?? ''),
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

function downloadBackupJson(PDO $pdo, int $workspaceId): void
{
    $workspaceIdSql = max(1, $workspaceId);
    $medicinesStatement = $pdo->prepare(
        'SELECT id, workspace_id, name, created_at
         FROM medicines
         WHERE workspace_id = :workspace_id
         ORDER BY id ASC'
    );
    $medicinesStatement->execute([':workspace_id' => $workspaceIdSql]);
    $medicines = $medicinesStatement->fetchAll();

    $intakesStatement = $pdo->prepare(
        'SELECT id,
                workspace_id,
                medicine_id,
                logged_by_user_id,
                dosage_value,
                dosage_unit,
                rating,
                taken_at,
                notes,
                created_at
         FROM medicine_intake_logs
         WHERE workspace_id = :workspace_id
         ORDER BY id ASC'
    );
    $intakesStatement->execute([':workspace_id' => $workspaceIdSql]);
    $intakes = $intakesStatement->fetchAll();

    $payload = [
        'generated_at' => date('c'),
        'workspace_id' => $workspaceIdSql,
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
    $json = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    if (!is_string($json)) {
        throw new RuntimeException('Unable to encode JSON backup payload.');
    }

    echo $json;
}

function downloadBackupSql(PDO $pdo, int $workspaceId): void
{
    $workspaceIdSql = max(1, $workspaceId);
    $medicinesStatement = $pdo->prepare(
        'SELECT id, workspace_id, name, created_at
         FROM medicines
         WHERE workspace_id = :workspace_id
         ORDER BY id ASC'
    );
    $medicinesStatement->execute([':workspace_id' => $workspaceIdSql]);
    $medicines = $medicinesStatement->fetchAll();

    $intakesStatement = $pdo->prepare(
        'SELECT id,
                workspace_id,
                medicine_id,
                logged_by_user_id,
                dosage_value,
                dosage_unit,
                rating,
                taken_at,
                notes,
                created_at
         FROM medicine_intake_logs
         WHERE workspace_id = :workspace_id
         ORDER BY id ASC'
    );
    $intakesStatement->execute([':workspace_id' => $workspaceIdSql]);
    $intakes = $intakesStatement->fetchAll();

    $lines = [];
    $lines[] = '-- Medicine Log backup generated at ' . date('c');
    $lines[] = '-- Workspace ID: ' . $workspaceIdSql;
    $lines[] = 'SET FOREIGN_KEY_CHECKS=0;';
    $lines[] = 'DELETE FROM medicine_intake_logs WHERE workspace_id = ' . $workspaceIdSql . ';';
    $lines[] = 'DELETE FROM medicines WHERE workspace_id = ' . $workspaceIdSql . ';';
    $lines[] = '';

    if (is_array($medicines) && $medicines !== []) {
        $medicineValues = [];
        foreach ($medicines as $row) {
            $medicineValues[] = sprintf(
                '(%s, %s, %s, %s)',
                sqlBackupValue($row['id'] ?? null),
                sqlBackupValue($row['workspace_id'] ?? null),
                sqlBackupValue($row['name'] ?? null),
                sqlBackupValue($row['created_at'] ?? null)
            );
        }

        $lines[] = 'INSERT INTO medicines (id, workspace_id, name, created_at) VALUES';
        $lines[] = implode(",\n", $medicineValues) . ';';
        $lines[] = '';
    }

    if (is_array($intakes) && $intakes !== []) {
        $intakeValues = [];
        foreach ($intakes as $row) {
            $intakeValues[] = sprintf(
                '(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
                sqlBackupValue($row['id'] ?? null),
                sqlBackupValue($row['workspace_id'] ?? null),
                sqlBackupValue($row['medicine_id'] ?? null),
                sqlBackupValue($row['logged_by_user_id'] ?? null),
                sqlBackupValue($row['dosage_value'] ?? null),
                sqlBackupValue($row['dosage_unit'] ?? null),
                sqlBackupValue($row['rating'] ?? null),
                sqlBackupValue($row['taken_at'] ?? null),
                sqlBackupValue($row['notes'] ?? null),
                sqlBackupValue($row['created_at'] ?? null)
            );
        }

        $lines[] = 'INSERT INTO medicine_intake_logs (id, workspace_id, medicine_id, logged_by_user_id, dosage_value, dosage_unit, rating, taken_at, notes, created_at) VALUES';
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

function loadMetrics(PDO $pdo, int $workspaceId): array
{
    $workspaceIdSql = max(1, $workspaceId);

    $entriesTodayStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM medicine_intake_logs
         WHERE workspace_id = :workspace_id
           AND DATE(taken_at) = CURDATE()'
    );
    $entriesTodayStatement->execute([':workspace_id' => $workspaceIdSql]);
    $entriesToday = (int) $entriesTodayStatement->fetchColumn();

    $entriesThisWeekStatement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM medicine_intake_logs
         WHERE workspace_id = :workspace_id
           AND YEARWEEK(taken_at, 1) = YEARWEEK(CURDATE(), 1)'
    );
    $entriesThisWeekStatement->execute([':workspace_id' => $workspaceIdSql]);
    $entriesThisWeek = (int) $entriesThisWeekStatement->fetchColumn();

    $avgRatingThisWeekStatement = $pdo->prepare(
        'SELECT AVG(rating)
         FROM medicine_intake_logs
         WHERE workspace_id = :workspace_id
           AND YEARWEEK(taken_at, 1) = YEARWEEK(CURDATE(), 1)'
    );
    $avgRatingThisWeekStatement->execute([':workspace_id' => $workspaceIdSql]);
    $avgRatingThisWeekRaw = $avgRatingThisWeekStatement->fetchColumn();
    $avgRatingThisWeek = $avgRatingThisWeekRaw !== null ? round((float) $avgRatingThisWeekRaw, 2) : null;

    return [
        'entries_today' => $entriesToday,
        'entries_this_week' => $entriesThisWeek,
        'average_rating_this_week' => $avgRatingThisWeek,
    ];
}

function loadTrends(PDO $pdo, int $workspaceId): array
{
    $workspaceIdSql = max(1, $workspaceId);

    $summaryStatement = $pdo->query(
        'SELECT SUM(CASE WHEN taken_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) AS entries_last_30_days,
                COUNT(DISTINCT CASE WHEN taken_at >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN DATE(taken_at) END) AS active_days_last_30_days,
                AVG(CASE WHEN YEARWEEK(taken_at, 1) = YEARWEEK(CURDATE(), 1) THEN rating END) AS avg_rating_this_week,
                AVG(CASE WHEN taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY) THEN rating END) AS avg_rating_last_90_days
         FROM medicine_intake_logs
         WHERE workspace_id = ' . $workspaceIdSql . '
           AND taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)'
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
         WHERE workspace_id = ' . $workspaceIdSql . '
           AND taken_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
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
         WHERE workspace_id = ' . $workspaceIdSql . '
           AND taken_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
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
         WHERE l.workspace_id = ' . $workspaceIdSql . '
           AND m.workspace_id = ' . $workspaceIdSql . '
           AND l.taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
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
         WHERE workspace_id = ' . $workspaceIdSql . '
           AND taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)
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

    $doseIntervalSql = 'SELECT l1.id,
                               l1.taken_at,
                               TIMESTAMPDIFF(
                                   MINUTE,
                                   (
                                       SELECT l2.taken_at
                                       FROM medicine_intake_logs l2
                                       WHERE l2.workspace_id = l1.workspace_id
                                         AND DATE(l2.taken_at) = DATE(l1.taken_at)
                                         AND (
                                             l2.taken_at < l1.taken_at
                                             OR (l2.taken_at = l1.taken_at AND l2.id < l1.id)
                                         )
                                       ORDER BY l2.taken_at DESC, l2.id DESC
                                       LIMIT 1
                                   ),
                                   l1.taken_at
                               ) AS interval_minutes
                        FROM medicine_intake_logs l1
                        WHERE l1.workspace_id = ' . $workspaceIdSql . '
                          AND l1.taken_at >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)';

    $doseIntervalSummaryStatement = $pdo->query(
        'SELECT AVG(
                    CASE
                        WHEN YEARWEEK(intervals.taken_at, 1) = YEARWEEK(CURDATE(), 1)
                        THEN intervals.interval_minutes
                        ELSE NULL
                    END
                ) AS avg_interval_this_week,
                SUM(
                    CASE
                        WHEN YEARWEEK(intervals.taken_at, 1) = YEARWEEK(CURDATE(), 1)
                        THEN 1
                        ELSE 0
                    END
                ) AS samples_this_week,
                AVG(
                    CASE
                        WHEN intervals.taken_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        THEN intervals.interval_minutes
                        ELSE NULL
                    END
                ) AS avg_interval_last_7_days,
                SUM(
                    CASE
                        WHEN intervals.taken_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                        THEN 1
                        ELSE 0
                    END
                ) AS samples_last_7_days,
                AVG(intervals.interval_minutes) AS avg_interval_last_90_days,
                COUNT(*) AS samples_last_90_days
         FROM (' . $doseIntervalSql . ') intervals
         WHERE intervals.interval_minutes IS NOT NULL
          AND intervals.interval_minutes >= 0'
    );
    $doseIntervalSummaryRow = $doseIntervalSummaryStatement->fetch();
    $avgDoseIntervalThisWeek = isset($doseIntervalSummaryRow['avg_interval_this_week'])
        && $doseIntervalSummaryRow['avg_interval_this_week'] !== null
        ? round((float) $doseIntervalSummaryRow['avg_interval_this_week'], 2)
        : null;
    $doseIntervalThisWeekSamples = isset($doseIntervalSummaryRow['samples_this_week'])
        ? (int) $doseIntervalSummaryRow['samples_this_week']
        : 0;
    $avgDoseIntervalLast7Days = isset($doseIntervalSummaryRow['avg_interval_last_7_days'])
        && $doseIntervalSummaryRow['avg_interval_last_7_days'] !== null
        ? round((float) $doseIntervalSummaryRow['avg_interval_last_7_days'], 2)
        : null;
    $doseIntervalLast7DaysSamples = isset($doseIntervalSummaryRow['samples_last_7_days'])
        ? (int) $doseIntervalSummaryRow['samples_last_7_days']
        : 0;
    $avgDoseIntervalLast90Days = isset($doseIntervalSummaryRow['avg_interval_last_90_days'])
        && $doseIntervalSummaryRow['avg_interval_last_90_days'] !== null
        ? round((float) $doseIntervalSummaryRow['avg_interval_last_90_days'], 2)
        : null;
    $doseIntervalLast90DaysSamples = isset($doseIntervalSummaryRow['samples_last_90_days'])
        ? (int) $doseIntervalSummaryRow['samples_last_90_days']
        : 0;

    $doseIntervalWeeklyStatement = $pdo->query(
        'SELECT MIN(DATE(intervals.taken_at)) AS week_start,
                AVG(intervals.interval_minutes) AS avg_interval_minutes,
                COUNT(*) AS samples
         FROM (' . $doseIntervalSql . ') intervals
         WHERE intervals.interval_minutes IS NOT NULL
           AND intervals.interval_minutes >= 0
           AND intervals.taken_at >= DATE_SUB(CURDATE(), INTERVAL 12 WEEK)
         GROUP BY YEARWEEK(intervals.taken_at, 1)
         ORDER BY YEARWEEK(intervals.taken_at, 1)'
    );
    $doseIntervalWeeklyRows = $doseIntervalWeeklyStatement->fetchAll();
    $doseIntervalWeekly = array_values(array_map(
        static fn(array $row): array => [
            'week_start' => isset($row['week_start']) ? (string) $row['week_start'] : null,
            'label' => isset($row['week_start']) && $row['week_start'] !== null
                ? ('Week of ' . formatDateOnly((string) $row['week_start']))
                : 'Unknown',
            'avg_interval_minutes' => isset($row['avg_interval_minutes']) && $row['avg_interval_minutes'] !== null
                ? round((float) $row['avg_interval_minutes'], 2)
                : null,
            'samples' => (int) ($row['samples'] ?? 0),
        ],
        $doseIntervalWeeklyRows
    ));

    $doseIntervalWeekdayStatement = $pdo->query(
        'SELECT WEEKDAY(intervals.taken_at) AS weekday_index,
                AVG(intervals.interval_minutes) AS avg_interval_minutes,
                COUNT(*) AS samples
         FROM (' . $doseIntervalSql . ') intervals
         WHERE intervals.interval_minutes IS NOT NULL
           AND intervals.interval_minutes >= 0
         GROUP BY WEEKDAY(intervals.taken_at)
         ORDER BY WEEKDAY(intervals.taken_at)'
    );
    $doseIntervalWeekdayRows = $doseIntervalWeekdayStatement->fetchAll();
    $doseIntervalWeekdayPatterns = [];
    foreach ($doseIntervalWeekdayRows as $row) {
        $weekdayIndex = (int) ($row['weekday_index'] ?? -1);
        if (!isset($weekdayNames[$weekdayIndex])) {
            continue;
        }

        $doseIntervalWeekdayPatterns[] = [
            'weekday_index' => $weekdayIndex,
            'weekday_label' => $weekdayNames[$weekdayIndex],
            'avg_interval_minutes' => isset($row['avg_interval_minutes']) && $row['avg_interval_minutes'] !== null
                ? round((float) $row['avg_interval_minutes'], 2)
                : null,
            'samples' => (int) ($row['samples'] ?? 0),
        ];
    }

    $dailyDoseIntervalStatement = $pdo->query(
        'SELECT DATE(intervals.taken_at) AS day_date,
                AVG(intervals.interval_minutes) AS avg_interval_minutes,
                COUNT(*) AS samples
         FROM (' . $doseIntervalSql . ') intervals
         WHERE intervals.interval_minutes IS NOT NULL
           AND intervals.interval_minutes >= 0
           AND intervals.taken_at >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
         GROUP BY DATE(intervals.taken_at)
         ORDER BY DATE(intervals.taken_at)'
    );
    $dailyDoseIntervalRows = $dailyDoseIntervalStatement->fetchAll();

    $dailyIntakeCountStatement = $pdo->query(
        'SELECT DATE(l.taken_at) AS day_date,
                COUNT(*) AS intake_count
         FROM medicine_intake_logs l
         WHERE l.workspace_id = ' . $workspaceIdSql . '
           AND l.taken_at >= DATE_SUB(CURDATE(), INTERVAL 45 DAY)
         GROUP BY DATE(l.taken_at)'
    );
    $dailyIntakeCountRows = $dailyIntakeCountStatement->fetchAll();

    $dailyDoseIntervalsByDate = [];
    foreach ($dailyDoseIntervalRows as $row) {
        $dayDate = isset($row['day_date']) ? (string) $row['day_date'] : '';
        if ($dayDate === '') {
            continue;
        }

        $avgIntervalMinutes = isset($row['avg_interval_minutes']) && $row['avg_interval_minutes'] !== null
            ? (float) $row['avg_interval_minutes']
            : null;
        $samples = (int) ($row['samples'] ?? 0);
        if ($avgIntervalMinutes === null || $samples <= 0) {
            continue;
        }

        $dailyDoseIntervalsByDate[$dayDate] = [
            'avg_interval_minutes' => $avgIntervalMinutes,
            'samples' => $samples,
        ];
    }

    $dailyIntakeCountsByDate = [];
    foreach ($dailyIntakeCountRows as $row) {
        $dayDate = isset($row['day_date']) ? (string) $row['day_date'] : '';
        if ($dayDate === '') {
            continue;
        }

        $dailyIntakeCountsByDate[$dayDate] = (int) ($row['intake_count'] ?? 0);
    }

    $rollingDoseIntervalRows = [];
    $rollingStartDate = (new DateTimeImmutable('today'))->modify('-29 days');
    for ($dayOffset = 0; $dayOffset < 30; $dayOffset += 1) {
        $currentDay = $rollingStartDate->modify('+' . $dayOffset . ' days');
        $currentDayKey = $currentDay->format('Y-m-d');
        $currentDayIntakeCount = (int) ($dailyIntakeCountsByDate[$currentDayKey] ?? 0);
        $windowWeightedMinutes = 0.0;
        $windowSamples = 0;

        for ($windowOffset = 6; $windowOffset >= 0; $windowOffset -= 1) {
            $windowDay = $currentDay->modify('-' . $windowOffset . ' days')->format('Y-m-d');
            if (!isset($dailyDoseIntervalsByDate[$windowDay])) {
                continue;
            }

            $windowDayStats = $dailyDoseIntervalsByDate[$windowDay];
            $samples = (int) ($windowDayStats['samples'] ?? 0);
            $avgIntervalMinutes = (float) ($windowDayStats['avg_interval_minutes'] ?? 0);
            if ($samples <= 0) {
                continue;
            }

            $windowSamples += $samples;
            $windowWeightedMinutes += $avgIntervalMinutes * $samples;
        }

        // A day with fewer than 2 intakes cannot have a same-day gap.
        $dayStats = $dailyDoseIntervalsByDate[$currentDayKey] ?? null;
        $dayAverageMinutes = is_array($dayStats) && isset($dayStats['avg_interval_minutes'])
            ? round((float) $dayStats['avg_interval_minutes'], 2)
            : null;
        $dayGapSamples = is_array($dayStats)
            ? (int) ($dayStats['samples'] ?? 0)
            : 0;

        if ($currentDayIntakeCount < 2) {
            $rollingAverageMinutes = 0.0;
            $displaySamples = 0;
        } else {
            $rollingAverageMinutes = $windowSamples > 0
                ? round($windowWeightedMinutes / $windowSamples, 2)
                : null;
            $displaySamples = $windowSamples;
        }

        $rollingDoseIntervalRows[] = [
            'date' => $currentDayKey,
            'label' => formatDateOnly($currentDayKey),
            'avg_interval_minutes' => $rollingAverageMinutes,
            'samples' => $displaySamples,
            'window_samples' => $windowSamples,
            'intake_count' => $currentDayIntakeCount,
            'day_avg_interval_minutes' => $dayAverageMinutes,
            'day_gap_samples' => $dayGapSamples,
        ];
    }

    $doseHighlightsLookbackDays = 6;

    $rankedDoseSql = 'SELECT l1.id,
                             l1.taken_at,
                             l1.medicine_id,
                             COUNT(l2.id) AS dose_order
                      FROM medicine_intake_logs l1
                      INNER JOIN medicine_intake_logs l2
                          ON DATE(l2.taken_at) = DATE(l1.taken_at)
                          AND l2.workspace_id = l1.workspace_id
                          AND l2.taken_at >= DATE_SUB(CURDATE(), INTERVAL ' . $doseHighlightsLookbackDays . ' DAY)
                          AND (
                              l2.taken_at < l1.taken_at
                              OR (l2.taken_at = l1.taken_at AND l2.id <= l1.id)
                          )
                      WHERE l1.workspace_id = ' . $workspaceIdSql . '
                        AND l1.taken_at >= DATE_SUB(CURDATE(), INTERVAL ' . $doseHighlightsLookbackDays . ' DAY)
                      GROUP BY l1.id, l1.taken_at, l1.medicine_id';

    $doseWeekdayStatement = $pdo->query(
        'SELECT ranked.dose_order,
                WEEKDAY(ranked.taken_at) AS weekday_index,
                AVG(TIME_TO_SEC(TIME(ranked.taken_at)) / 60) AS avg_minute_of_day,
                COUNT(*) AS samples
         FROM (' . $rankedDoseSql . ') ranked
         GROUP BY ranked.dose_order, WEEKDAY(ranked.taken_at)
         ORDER BY ranked.dose_order, WEEKDAY(ranked.taken_at)'
    );
    $doseWeekdayRows = $doseWeekdayStatement->fetchAll();
    $medicineDoseWeekdayStatement = $pdo->query(
        'SELECT ranked.dose_order,
                ranked.medicine_id,
                m.name AS medicine_name,
                WEEKDAY(ranked.taken_at) AS weekday_index,
                AVG(TIME_TO_SEC(TIME(ranked.taken_at)) / 60) AS avg_minute_of_day,
                COUNT(*) AS samples
         FROM (' . $rankedDoseSql . ') ranked
         INNER JOIN medicines m ON m.id = ranked.medicine_id
                               AND m.workspace_id = ' . $workspaceIdSql . '
         GROUP BY ranked.dose_order, ranked.medicine_id, m.name, WEEKDAY(ranked.taken_at)
         ORDER BY ranked.dose_order, ranked.medicine_id, WEEKDAY(ranked.taken_at)'
    );
    $medicineDoseWeekdayRows = $medicineDoseWeekdayStatement->fetchAll();
    $doseDosageAverageStatement = $pdo->query(
        'SELECT ranked.dose_order,
                l.dosage_unit,
                AVG(l.dosage_value) AS avg_dosage_value,
                COUNT(*) AS samples
         FROM (' . $rankedDoseSql . ') ranked
         INNER JOIN medicine_intake_logs l ON l.id = ranked.id
                                     AND l.workspace_id = ' . $workspaceIdSql . '
         GROUP BY ranked.dose_order, l.dosage_unit
         ORDER BY ranked.dose_order, l.dosage_unit'
    );
    $doseDosageAverageRows = $doseDosageAverageStatement->fetchAll();
    $medicineDoseDosageAverageStatement = $pdo->query(
        'SELECT ranked.dose_order,
                ranked.medicine_id,
                m.name AS medicine_name,
                l.dosage_unit,
                AVG(l.dosage_value) AS avg_dosage_value,
                COUNT(*) AS samples
         FROM (' . $rankedDoseSql . ') ranked
         INNER JOIN medicine_intake_logs l ON l.id = ranked.id
                                     AND l.workspace_id = ' . $workspaceIdSql . '
         INNER JOIN medicines m ON m.id = ranked.medicine_id
                               AND m.workspace_id = ' . $workspaceIdSql . '
         GROUP BY ranked.dose_order, ranked.medicine_id, m.name, l.dosage_unit
         ORDER BY ranked.dose_order, ranked.medicine_id, l.dosage_unit'
    );
    $medicineDoseDosageAverageRows = $medicineDoseDosageAverageStatement->fetchAll();
    $doseWeekdayPatterns = [];
    $doseDosageAverages = [];
    $availableDoseOrdersMap = [];
    $availableMedicinesMap = [];

    foreach ($doseWeekdayRows as $row) {
        $doseOrder = (int) ($row['dose_order'] ?? 0);
        $weekdayIndex = (int) ($row['weekday_index'] ?? -1);
        if ($doseOrder <= 0 || !isset($weekdayNames[$weekdayIndex])) {
            continue;
        }

        $availableDoseOrdersMap[$doseOrder] = true;
        $doseWeekdayPatterns[] = [
            'dose_order' => $doseOrder,
            'dose_label' => ordinal($doseOrder),
            'medicine_id' => null,
            'medicine_name' => 'All medicines',
            'weekday_index' => $weekdayIndex,
            'weekday_label' => $weekdayNames[$weekdayIndex],
            'avg_minute_of_day' => isset($row['avg_minute_of_day']) && $row['avg_minute_of_day'] !== null
                ? round((float) $row['avg_minute_of_day'], 2)
                : null,
            'samples' => (int) ($row['samples'] ?? 0),
        ];
    }

    foreach ($medicineDoseWeekdayRows as $row) {
        $doseOrder = (int) ($row['dose_order'] ?? 0);
        $medicineId = (int) ($row['medicine_id'] ?? 0);
        $medicineName = trim((string) ($row['medicine_name'] ?? ''));
        $weekdayIndex = (int) ($row['weekday_index'] ?? -1);
        if (
            $doseOrder <= 0
            || $medicineId <= 0
            || $medicineName === ''
            || !isset($weekdayNames[$weekdayIndex])
        ) {
            continue;
        }

        $availableDoseOrdersMap[$doseOrder] = true;
        $availableMedicinesMap[$medicineId] = $medicineName;
        $doseWeekdayPatterns[] = [
            'dose_order' => $doseOrder,
            'dose_label' => ordinal($doseOrder),
            'medicine_id' => $medicineId,
            'medicine_name' => $medicineName,
            'weekday_index' => $weekdayIndex,
            'weekday_label' => $weekdayNames[$weekdayIndex],
            'avg_minute_of_day' => isset($row['avg_minute_of_day']) && $row['avg_minute_of_day'] !== null
                ? round((float) $row['avg_minute_of_day'], 2)
                : null,
            'samples' => (int) ($row['samples'] ?? 0),
        ];
    }

    foreach ($doseDosageAverageRows as $row) {
        $doseOrder = (int) ($row['dose_order'] ?? 0);
        $dosageUnit = trim((string) ($row['dosage_unit'] ?? ''));
        if ($doseOrder <= 0 || $dosageUnit === '') {
            continue;
        }

        $doseDosageAverages[] = [
            'dose_order' => $doseOrder,
            'dose_label' => ordinal($doseOrder),
            'medicine_id' => null,
            'medicine_name' => 'All medicines',
            'dosage_unit' => $dosageUnit,
            'avg_dosage_value' => isset($row['avg_dosage_value']) && $row['avg_dosage_value'] !== null
                ? round((float) $row['avg_dosage_value'], 2)
                : null,
            'samples' => (int) ($row['samples'] ?? 0),
        ];
    }

    foreach ($medicineDoseDosageAverageRows as $row) {
        $doseOrder = (int) ($row['dose_order'] ?? 0);
        $medicineId = (int) ($row['medicine_id'] ?? 0);
        $medicineName = trim((string) ($row['medicine_name'] ?? ''));
        $dosageUnit = trim((string) ($row['dosage_unit'] ?? ''));
        if (
            $doseOrder <= 0
            || $medicineId <= 0
            || $medicineName === ''
            || $dosageUnit === ''
        ) {
            continue;
        }

        $doseDosageAverages[] = [
            'dose_order' => $doseOrder,
            'dose_label' => ordinal($doseOrder),
            'medicine_id' => $medicineId,
            'medicine_name' => $medicineName,
            'dosage_unit' => $dosageUnit,
            'avg_dosage_value' => isset($row['avg_dosage_value']) && $row['avg_dosage_value'] !== null
                ? round((float) $row['avg_dosage_value'], 2)
                : null,
            'samples' => (int) ($row['samples'] ?? 0),
        ];
    }

    $availableDoseOrders = array_map(
        static fn(string|int $value): int => (int) $value,
        array_keys($availableDoseOrdersMap)
    );
    sort($availableDoseOrders);

    asort($availableMedicinesMap, SORT_NATURAL | SORT_FLAG_CASE);
    $availableMedicines = [];
    foreach ($availableMedicinesMap as $medicineId => $medicineName) {
        $availableMedicines[] = [
            'id' => (int) $medicineId,
            'name' => (string) $medicineName,
        ];
    }

    return [
        'summary' => [
            'entries_last_30_days' => $entriesLast30Days,
            'active_days_last_30_days' => $activeDaysLast30Days,
            'active_day_ratio_last_30_days' => round(($activeDaysLast30Days / 30) * 100, 1),
            'avg_rating_this_week' => $avgRatingThisWeek,
            'avg_rating_last_90_days' => $avgRatingLast90Days,
            'avg_dose_interval_this_week_minutes' => $avgDoseIntervalThisWeek,
            'avg_dose_interval_last_7_days_minutes' => $avgDoseIntervalLast7Days,
            'avg_dose_interval_last_90_days_minutes' => $avgDoseIntervalLast90Days,
            'dose_interval_this_week_samples' => $doseIntervalThisWeekSamples,
            'dose_interval_last_7_days_samples' => $doseIntervalLast7DaysSamples,
            'dose_interval_last_90_days_samples' => $doseIntervalLast90DaysSamples,
        ],
        'monthly_average_rating' => $monthlyAverageRating,
        'weekly_entries' => $weeklyEntries,
        'top_medicines_90_days' => $topMedicines,
        'weekday_patterns_90_days' => $weekdayPatterns,
        'dose_interval_weekly_12_weeks' => $doseIntervalWeekly,
        'dose_interval_weekday_90_days' => $doseIntervalWeekdayPatterns,
        'dose_interval_rolling_7_days_30_days' => $rollingDoseIntervalRows,
        'dose_weekday_patterns_7_days' => [
            'available_orders' => $availableDoseOrders,
            'available_medicines' => $availableMedicines,
            'rows' => $doseWeekdayPatterns,
            'dosage_averages' => $doseDosageAverages,
        ],
        // Backward-compatible alias for clients that still expect the old key.
        'dose_weekday_patterns_90_days' => [
            'available_orders' => $availableDoseOrders,
            'available_medicines' => $availableMedicines,
            'rows' => $doseWeekdayPatterns,
            'dosage_averages' => $doseDosageAverages,
        ],
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

function loadCalendarMonth(PDO $pdo, int $workspaceId, string $monthInput): array
{
    $monthStart = parseCalendarMonth($monthInput);
    $monthEnd = $monthStart->modify('+1 month');

    $statement = $pdo->prepare(
        'SELECT l.id,
                l.medicine_id,
                m.name AS medicine_name,
                l.logged_by_user_id,
                COALESCE(NULLIF(TRIM(u.display_name), ""), u.username) AS logged_by_username,
                l.dosage_value,
                l.dosage_unit,
                l.rating,
                l.taken_at,
                l.notes,
                l.created_at
         FROM medicine_intake_logs l
         INNER JOIN medicines m ON m.id = l.medicine_id
                              AND m.workspace_id = l.workspace_id
         LEFT JOIN app_users u ON u.id = l.logged_by_user_id
         WHERE l.workspace_id = :workspace_id
           AND l.taken_at >= :start_date
           AND l.taken_at < :end_date
         ORDER BY l.taken_at ASC, l.id ASC'
    );
    $statement->execute([
        ':workspace_id' => $workspaceId,
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

function loadMedicineOptions(PDO $pdo, int $workspaceId): array
{
    $statement = $pdo->prepare(
        'SELECT id, name
         FROM medicines
         WHERE workspace_id = :workspace_id
         ORDER BY name ASC'
    );
    $statement->execute([':workspace_id' => $workspaceId]);
    $rows = $statement->fetchAll();

    return array_values(array_map(
        static fn(array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ],
        $rows
    ));
}

function loadAccount(PDO $pdo, int $userId): ?array
{
    $statement = $pdo->prepare(
        'SELECT id, username, display_name, email, last_login_at, created_at
         FROM app_users
         WHERE id = :id
           AND is_active = 1
         LIMIT 1'
    );
    $statement->execute([':id' => $userId]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    return [
        'id' => (int) ($row['id'] ?? 0),
        'username' => (string) ($row['username'] ?? ''),
        'display_name' => isset($row['display_name']) ? trim((string) $row['display_name']) : null,
        'email' => isset($row['email']) ? trim((string) $row['email']) : null,
        'last_login_at' => isset($row['last_login_at']) ? (string) $row['last_login_at'] : null,
        'created_at' => isset($row['created_at']) ? (string) $row['created_at'] : null,
    ];
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
        'reminder_message' => utf8Truncate(trim((string) ($payload['reminder_message'] ?? '')), 255),
        'is_active' => isset($payload['is_active']) ? (int) $payload['is_active'] : 1,
    ];
}

function validateSchedulePayload(array $payload, PDO $pdo, int $workspaceId, array $allowedDosageUnits): array
{
    $data = normalizeSchedulePayload($payload);
    $errors = [];

    $medicineName = '';
    if ($data['medicine_id'] <= 0) {
        $errors[] = 'Please choose a medicine for this schedule.';
    } else {
        $statement = $pdo->prepare(
            'SELECT id, name
             FROM medicines
             WHERE id = :id
               AND workspace_id = :workspace_id
             LIMIT 1'
        );
        $statement->execute([
            ':id' => $data['medicine_id'],
            ':workspace_id' => $workspaceId,
        ]);
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

    if (utf8Length($data['reminder_message']) > 255) {
        $errors[] = 'Reminder message must be 255 characters or less.';
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
    $reminderMessage = trim((string) ($row['reminder_message'] ?? ''));

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
        'reminder_message' => $reminderMessage !== '' ? $reminderMessage : null,
        'is_active' => (int) ($row['is_active'] ?? 0) === 1,
        'created_at' => (string) ($row['created_at'] ?? ''),
        'updated_at' => (string) ($row['updated_at'] ?? ''),
    ];
}

function loadDoseSchedules(PDO $pdo, int $workspaceId, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT s.id,
                s.user_id,
                s.medicine_id,
                m.name AS medicine_name,
                s.dosage_value,
                s.dosage_unit,
                s.time_of_day,
                s.reminder_message,
                s.is_active,
                s.created_at,
                s.updated_at
         FROM dose_schedules s
         INNER JOIN medicines m ON m.id = s.medicine_id
                              AND m.workspace_id = s.workspace_id
         WHERE s.workspace_id = :workspace_id
           AND s.user_id = :user_id
         ORDER BY s.time_of_day ASC, m.name ASC, s.id ASC'
    );
    $statement->execute([
        ':workspace_id' => $workspaceId,
        ':user_id' => $userId,
    ]);
    $rows = $statement->fetchAll();

    return array_values(array_map(static fn(array $row): array => serializeSchedule($row), $rows));
}

function savePushSubscription(PDO $pdo, int $workspaceId, int $userId, array $payload): array
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
        'INSERT INTO push_subscriptions (workspace_id, user_id, endpoint, endpoint_hash, p256dh, auth_key, user_agent, is_active, last_seen_at)
         VALUES (:workspace_id, :user_id, :endpoint, :endpoint_hash, :p256dh, :auth_key, :user_agent, 1, NOW())
         ON DUPLICATE KEY UPDATE
            workspace_id = VALUES(workspace_id),
            user_id = VALUES(user_id),
            endpoint = VALUES(endpoint),
            p256dh = VALUES(p256dh),
            auth_key = VALUES(auth_key),
            user_agent = VALUES(user_agent),
            is_active = 1,
            last_seen_at = NOW()'
    );
    $statement->execute([
        ':workspace_id' => $workspaceId,
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

function removePushSubscription(PDO $pdo, int $workspaceId, int $userId, array $payload): void
{
    $endpoint = trim((string) ($payload['endpoint'] ?? ''));
    if ($endpoint === '') {
        return;
    }

    $statement = $pdo->prepare(
        'UPDATE push_subscriptions
         SET is_active = 0, updated_at = CURRENT_TIMESTAMP
         WHERE workspace_id = :workspace_id
           AND user_id = :user_id
           AND endpoint_hash = :endpoint_hash'
    );
    $statement->execute([
        ':workspace_id' => $workspaceId,
        ':user_id' => $userId,
        ':endpoint_hash' => hash('sha256', $endpoint),
    ]);
}

function activePushSubscriptions(PDO $pdo, int $workspaceId, int $userId): array
{
    $statement = $pdo->prepare(
        'SELECT id, endpoint, endpoint_hash
         FROM push_subscriptions
         WHERE workspace_id = :workspace_id
           AND user_id = :user_id
           AND is_active = 1'
    );
    $statement->execute([
        ':workspace_id' => $workspaceId,
        ':user_id' => $userId,
    ]);
    $rows = $statement->fetchAll();

    return is_array($rows) ? $rows : [];
}

function dispatchPushForUser(PDO $pdo, int $workspaceId, int $userId, string $source = 'manual', array $context = []): array
{
    $subscriptions = activePushSubscriptions($pdo, $workspaceId, $userId);
    $attempted = count($subscriptions);
    $sent = 0;
    $failed = 0;
    $deactivated = 0;
    $statusBreakdown = [];
    $failureDetails = [];

    $baseContext = array_merge([
        'source' => $source,
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
    ], $context);

    if ($attempted === 0) {
        logEvent('warning', 'push.dispatch.no_subscriptions', $baseContext);
    }

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

        logEvent('error', 'push.dispatch.failed', array_merge($baseContext, [
            'subscription_id' => (int) ($subscription['id'] ?? 0),
            'status' => $status,
            'transport' => (string) ($result['transport'] ?? 'unknown'),
            'host' => $host !== '' ? $host : 'unknown',
            'error' => substr(trim((string) ($result['error'] ?? '')), 0, 220),
        ]));

        if ($status === 404 || $status === 410) {
            $deactivateStatement = $pdo->prepare(
                'UPDATE push_subscriptions
                 SET is_active = 0, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND workspace_id = :workspace_id'
            );
            $deactivateStatement->execute([
                ':id' => (int) ($subscription['id'] ?? 0),
                ':workspace_id' => $workspaceId,
            ]);
            $deactivated += 1;
            logEvent('warning', 'push.subscription.deactivated', array_merge($baseContext, [
                'subscription_id' => (int) ($subscription['id'] ?? 0),
                'status' => $status,
            ]));
        }
    }

    $summary = [
        'attempted' => $attempted,
        'sent' => $sent,
        'failed' => $failed,
        'deactivated' => $deactivated,
        'status_breakdown' => $statusBreakdown,
        'failures' => $failureDetails,
    ];

    logEvent($failed > 0 ? 'warning' : 'info', 'push.dispatch.completed', array_merge(
        $baseContext,
        [
            'attempted' => $attempted,
            'sent' => $sent,
            'failed' => $failed,
            'deactivated' => $deactivated,
        ]
    ));

    return $summary;
}

function processDueReminders(PDO $pdo, ?int $workspaceId = null, ?int $userId = null, array $sourceContext = []): array
{
    $timezoneName = Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC';
    $timezone = new DateTimeZone($timezoneName);
    $now = new DateTimeImmutable('now', $timezone);
    $todayStart = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i:s',
        $now->format('Y-m-d') . ' 00:00:00',
        $timezone
    );
    if (!$todayStart instanceof DateTimeImmutable) {
        $todayStart = $now->setTime(0, 0, 0);
    }
    $tomorrowStart = $todayStart->add(new DateInterval('P1D'));

    $query = 'SELECT s.id,
                     s.workspace_id,
                     s.user_id,
                     s.medicine_id,
                     m.name AS medicine_name,
                     s.dosage_value,
                     s.dosage_unit,
                     s.time_of_day,
                     s.reminder_message,
                     s.is_active
              FROM dose_schedules s
              INNER JOIN medicines m ON m.id = s.medicine_id
                                   AND m.workspace_id = s.workspace_id
              WHERE s.is_active = 1';
    $params = [];

    if ($workspaceId !== null && $workspaceId > 0) {
        $query .= ' AND s.workspace_id = :workspace_id';
        $params[':workspace_id'] = $workspaceId;
    }

    if ($userId !== null) {
        $query .= ' AND s.user_id = :user_id';
        $params[':user_id'] = $userId;
    }

    $query .= ' ORDER BY s.user_id ASC, s.time_of_day ASC, s.id ASC';
    $scheduleStatement = $pdo->prepare($query);
    $scheduleStatement->execute($params);
    $schedules = $scheduleStatement->fetchAll();

    $dispatchedTodayQuery = 'SELECT DISTINCT d.schedule_id
                             FROM reminder_dispatches d
                             INNER JOIN dose_schedules s ON s.id = d.schedule_id
                             WHERE d.scheduled_for >= :today_start
                               AND d.scheduled_for < :tomorrow_start';
    $dispatchedTodayParams = [
        ':today_start' => $todayStart->format('Y-m-d H:i:s'),
        ':tomorrow_start' => $tomorrowStart->format('Y-m-d H:i:s'),
    ];
    if ($workspaceId !== null && $workspaceId > 0) {
        $dispatchedTodayQuery .= ' AND s.workspace_id = :workspace_id';
        $dispatchedTodayParams[':workspace_id'] = $workspaceId;
    }
    if ($userId !== null) {
        $dispatchedTodayQuery .= ' AND s.user_id = :user_id';
        $dispatchedTodayParams[':user_id'] = $userId;
    }
    $dispatchedTodayStatement = $pdo->prepare($dispatchedTodayQuery);
    $dispatchedTodayStatement->execute($dispatchedTodayParams);
    $dispatchedTodayRows = $dispatchedTodayStatement->fetchAll();
    $dispatchedTodayMap = [];
    foreach ($dispatchedTodayRows as $row) {
        $existingScheduleId = (int) ($row['schedule_id'] ?? 0);
        if ($existingScheduleId > 0) {
            $dispatchedTodayMap[$existingScheduleId] = true;
        }
    }

    $processed = 0;
    $due = 0;
    $sent = 0;
    $skipped = 0;
    $skippedNotDueYet = 0;
    $skippedAlreadyDispatchedToday = 0;
    $pushAttempted = 0;
    $pushFailed = 0;
    $pushDeactivated = 0;
    $failureCount = 0;

    logEvent('info', 'reminders.process.started', array_merge($sourceContext, [
        'workspace_scope' => $workspaceId,
        'user_scope' => $userId,
        'today' => $now->format('Y-m-d'),
        'schedule_count' => count($schedules),
        'already_dispatched_today_count' => count($dispatchedTodayMap),
        'now' => $now->format(DateTimeInterface::ATOM),
    ]));

    foreach ($schedules as $schedule) {
        $processed += 1;
        $scheduleId = (int) ($schedule['id'] ?? 0);
        if ($scheduleId <= 0) {
            $skipped += 1;
            continue;
        }

        if (isset($dispatchedTodayMap[$scheduleId])) {
            $skipped += 1;
            $skippedAlreadyDispatchedToday += 1;
            continue;
        }

        $scheduleTime = substr((string) ($schedule['time_of_day'] ?? '00:00:00'), 0, 5);
        $scheduledForToday = DateTimeImmutable::createFromFormat(
            'Y-m-d H:i',
            $now->format('Y-m-d') . ' ' . $scheduleTime,
            $timezone
        );
        if (!$scheduledForToday instanceof DateTimeImmutable) {
            $skipped += 1;
            logEvent('warning', 'reminders.schedule.invalid_time', array_merge($sourceContext, [
                'schedule_id' => (int) ($schedule['id'] ?? 0),
                'time_of_day' => $scheduleTime,
            ]));
            continue;
        }

        if ($scheduledForToday > $now) {
            $skipped += 1;
            $skippedNotDueYet += 1;
            continue;
        }

        $scheduledForDb = $scheduledForToday->format('Y-m-d H:i:s');
        $dispatchId = null;

        try {
            $insertStatement = $pdo->prepare(
                'INSERT INTO reminder_dispatches (schedule_id, scheduled_for, status)
                 VALUES (:schedule_id, :scheduled_for, :status)'
            );
            $insertStatement->execute([
                ':schedule_id' => $scheduleId,
                ':scheduled_for' => $scheduledForDb,
                ':status' => 'pending',
            ]);
            $dispatchId = (int) $pdo->lastInsertId();
            $due += 1;
        } catch (Throwable $exception) {
            // Concurrent runs may attempt the same schedule in the same minute.
            $skipped += 1;
            $skippedAlreadyDispatchedToday += 1;
            $dispatchedTodayMap[$scheduleId] = true;
            logEvent('info', 'reminders.schedule.duplicate_or_insert_error', array_merge($sourceContext, [
                'schedule_id' => $scheduleId,
                'scheduled_for' => $scheduledForDb,
                'message' => substr($exception->getMessage(), 0, 220),
            ]));
            continue;
        }

        $dispatchResult = dispatchPushForUser(
            $pdo,
            (int) ($schedule['workspace_id'] ?? 0),
            (int) $schedule['user_id'],
            'schedule',
            [
                'schedule_id' => $scheduleId,
                'scheduled_for' => $scheduledForDb,
                'medicine_id' => (int) ($schedule['medicine_id'] ?? 0),
            ]
        );
        $sent += (int) ($dispatchResult['sent'] ?? 0);
        $pushAttempted += (int) ($dispatchResult['attempted'] ?? 0);
        $pushFailed += (int) ($dispatchResult['failed'] ?? 0);
        $pushDeactivated += (int) ($dispatchResult['deactivated'] ?? 0);
        $failureCount += count((array) ($dispatchResult['failures'] ?? []));
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

        $dispatchedTodayMap[$scheduleId] = true;

        if ($status !== 'sent') {
            logEvent('warning', 'reminders.schedule.dispatch_not_sent', array_merge($sourceContext, [
                'schedule_id' => $scheduleId,
                'scheduled_for' => $scheduledForDb,
                'dispatch' => [
                    'attempted' => (int) ($dispatchResult['attempted'] ?? 0),
                    'sent' => (int) ($dispatchResult['sent'] ?? 0),
                    'failed' => (int) ($dispatchResult['failed'] ?? 0),
                    'deactivated' => (int) ($dispatchResult['deactivated'] ?? 0),
                ],
            ]));
        }
    }

    $result = [
        'processed' => $processed,
        'due' => $due,
        'sent' => $sent,
        'skipped' => $skipped,
        'today' => $now->format('Y-m-d'),
        'skipped_not_due_yet' => $skippedNotDueYet,
        'skipped_already_dispatched_today' => $skippedAlreadyDispatchedToday,
        'push_attempted' => $pushAttempted,
        'push_failed' => $pushFailed,
        'push_deactivated' => $pushDeactivated,
        'failure_count' => $failureCount,
        'processed_at' => $now->format(DateTimeInterface::ATOM),
    ];

    logEvent(($pushFailed > 0 || $due > 0 && $sent === 0) ? 'warning' : 'info', 'reminders.process.completed', array_merge(
        $sourceContext,
        $result
    ));

    return $result;
}

function loadLatestPushNotificationForUser(PDO $pdo, int $workspaceId, int $userId): array
{
    $defaultNotification = [
        'title' => 'Medicine reminder',
        'body' => 'It is time to log a scheduled dose.',
        'url' => '/index.php',
    ];

    $timezoneName = Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC';
    $timezone = new DateTimeZone($timezoneName);
    $threshold = (new DateTimeImmutable('now', $timezone))
        ->setTime(0, 0, 0)
        ->format('Y-m-d H:i:s');

    $statement = $pdo->prepare(
        'SELECT d.scheduled_for,
                s.reminder_message
         FROM reminder_dispatches d
         INNER JOIN dose_schedules s ON s.id = d.schedule_id
         WHERE s.workspace_id = :workspace_id
           AND s.user_id = :user_id
           AND d.scheduled_for >= :threshold
         ORDER BY d.scheduled_for DESC, d.id DESC
         LIMIT 1'
    );
    $statement->execute([
        ':workspace_id' => $workspaceId,
        ':user_id' => $userId,
        ':threshold' => $threshold,
    ]);
    $row = $statement->fetch();

    if (!is_array($row)) {
        return $defaultNotification;
    }

    $customMessage = trim((string) ($row['reminder_message'] ?? ''));
    if ($customMessage === '') {
        return $defaultNotification;
    }

    $defaultNotification['body'] = $customMessage;
    return $defaultNotification;
}

function resolvePushNotificationTargetFromEndpoint(PDO $pdo, string $endpoint): ?array
{
    $cleanEndpoint = trim($endpoint);
    if ($cleanEndpoint === '') {
        return null;
    }

    $statement = $pdo->prepare(
        'SELECT workspace_id, user_id
         FROM push_subscriptions
         WHERE endpoint_hash = :endpoint_hash
           AND is_active = 1
         ORDER BY updated_at DESC, id DESC
         LIMIT 1'
    );
    $statement->execute([
        ':endpoint_hash' => hash('sha256', $cleanEndpoint),
    ]);
    $row = $statement->fetch();
    if (!is_array($row)) {
        return null;
    }

    $workspaceId = (int) ($row['workspace_id'] ?? 0);
    $userId = (int) ($row['user_id'] ?? 0);
    if ($workspaceId <= 0 || $userId <= 0) {
        return null;
    }

    return [
        'workspace_id' => $workspaceId,
        'user_id' => $userId,
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

    $currentUserId = null;
    $currentWorkspaceId = null;
    $currentWorkspaceRole = null;

    try {
        $currentUserId = Auth::userId();
        $currentWorkspaceId = Auth::workspaceId();
        $currentWorkspaceRole = normalizeWorkspaceRole(Auth::workspaceRole());

        if (!in_array($apiAction, ['process_reminders', 'push_message'], true) && $currentWorkspaceId === null) {
            jsonResponse([
                'ok' => false,
                'error' => 'No active workspace is assigned to this session.',
            ], 403);
            exit;
        }

        $writeApiActions = [
            'schedule_create',
            'schedule_update',
            'schedule_toggle',
            'schedule_delete',
            'create',
            'update',
            'delete',
        ];
        if (
            $_SERVER['REQUEST_METHOD'] === 'POST'
            && in_array($apiAction, $writeApiActions, true)
            && !workspaceCanWrite($currentWorkspaceRole)
        ) {
            jsonResponse([
                'ok' => false,
                'error' => 'Read-only access. This action requires editor permissions.',
            ], 403);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'account') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $account = loadAccount($pdo, $currentUserId);
            if (!is_array($account)) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Account not found.',
                ], 404);
                exit;
            }

            jsonResponse([
                'ok' => true,
                'account' => $account,
                'workspace' => [
                    'id' => $currentWorkspaceId,
                    'role' => $currentWorkspaceRole,
                    'can_write' => workspaceCanWrite($currentWorkspaceRole),
                ],
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'account_update') {
            if ($currentUserId === null) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            $payload = requestPayload();
            $username = utf8Truncate(trim((string) ($payload['username'] ?? '')), 120);
            $displayName = utf8Truncate(trim((string) ($payload['display_name'] ?? '')), 120);
            $email = strtolower(utf8Truncate(trim((string) ($payload['email'] ?? '')), 190));
            $currentPassword = (string) ($payload['current_password'] ?? '');
            $newPassword = (string) ($payload['new_password'] ?? '');
            $confirmPassword = (string) ($payload['confirm_password'] ?? '');
            $errors = [];

            if ($username === '') {
                $errors[] = 'Username is required.';
            } elseif (utf8Length($username) > 120) {
                $errors[] = 'Username must be 120 characters or less.';
            }

            if ($currentPassword === '') {
                $errors[] = 'Current password is required.';
            }

            if ($displayName !== '' && utf8Length($displayName) > 120) {
                $errors[] = 'Display name must be 120 characters or less.';
            }

            if ($email !== '') {
                if (utf8Length($email) > 190) {
                    $errors[] = 'Email must be 190 characters or less.';
                } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $errors[] = 'Email format is invalid.';
                }
            }

            $hasPasswordUpdate = $newPassword !== '' || $confirmPassword !== '';
            if ($hasPasswordUpdate) {
                if ($newPassword === '') {
                    $errors[] = 'New password is required when updating password.';
                } elseif (utf8Length($newPassword) < 8) {
                    $errors[] = 'New password must be at least 8 characters.';
                } elseif (utf8Length($newPassword) > 255) {
                    $errors[] = 'New password must be 255 characters or less.';
                }

                if ($confirmPassword === '') {
                    $errors[] = 'Please confirm the new password.';
                } elseif (!hash_equals($newPassword, $confirmPassword)) {
                    $errors[] = 'New password confirmation does not match.';
                }
            }

            if ($errors !== []) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Validation failed.',
                    'errors' => $errors,
                ], 422);
                exit;
            }

            $userStatement = $pdo->prepare(
                'SELECT id, username, display_name, email, password_hash, is_active
                 FROM app_users
                 WHERE id = :id
                 LIMIT 1'
            );
            $userStatement->execute([':id' => $currentUserId]);
            $user = $userStatement->fetch();

            if (!is_array($user)) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Account not found.',
                ], 404);
                exit;
            }

            $isActive = (int) ($user['is_active'] ?? 0) === 1;
            if (!$isActive) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'This account is inactive.',
                ], 403);
                exit;
            }

            $storedPasswordHash = (string) ($user['password_hash'] ?? '');
            if ($storedPasswordHash === '' || !password_verify($currentPassword, $storedPasswordHash)) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Current password is incorrect.',
                ], 422);
                exit;
            }

            $existingUsername = (string) ($user['username'] ?? '');
            $existingDisplayName = trim((string) ($user['display_name'] ?? ''));
            $existingEmail = strtolower(trim((string) ($user['email'] ?? '')));
            $updates = [];
            $bindings = [
                ':id' => $currentUserId,
            ];
            $usernameChanged = false;
            $displayNameChanged = false;
            $emailChanged = false;
            $passwordChanged = false;

            if (!hash_equals($existingUsername, $username)) {
                $usernameCheck = $pdo->prepare(
                    'SELECT id
                     FROM app_users
                     WHERE username = :username
                       AND id <> :id
                     LIMIT 1'
                );
                $usernameCheck->execute([
                    ':username' => $username,
                    ':id' => $currentUserId,
                ]);
                if (is_array($usernameCheck->fetch())) {
                    jsonResponse([
                        'ok' => false,
                        'error' => 'That username is already in use.',
                    ], 422);
                    exit;
                }

                $updates[] = 'username = :username';
                $bindings[':username'] = $username;
                $usernameChanged = true;
            }

            if (!hash_equals($existingDisplayName, $displayName)) {
                $updates[] = 'display_name = :display_name';
                $bindings[':display_name'] = $displayName !== '' ? $displayName : null;
                $displayNameChanged = true;
            }

            if (!hash_equals($existingEmail, $email)) {
                if ($email !== '') {
                    $emailCheck = $pdo->prepare(
                        'SELECT id
                         FROM app_users
                         WHERE email = :email
                           AND id <> :id
                         LIMIT 1'
                    );
                    $emailCheck->execute([
                        ':email' => $email,
                        ':id' => $currentUserId,
                    ]);
                    if (is_array($emailCheck->fetch())) {
                        jsonResponse([
                            'ok' => false,
                            'error' => 'That email is already in use.',
                        ], 422);
                        exit;
                    }
                }

                $updates[] = 'email = :email';
                $bindings[':email'] = $email !== '' ? $email : null;
                $emailChanged = true;
            }

            if ($hasPasswordUpdate) {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                if (!is_string($hashedPassword) || $hashedPassword === '') {
                    throw new RuntimeException('Could not hash the new password.');
                }

                $updates[] = 'password_hash = :password_hash';
                $bindings[':password_hash'] = $hashedPassword;
                $passwordChanged = true;
            }

            if ($updates === []) {
                $account = loadAccount($pdo, $currentUserId);
                jsonResponse([
                    'ok' => true,
                    'message' => 'No account changes were needed.',
                    'account' => $account,
                    'workspace' => [
                        'id' => $currentWorkspaceId,
                        'role' => $currentWorkspaceRole,
                        'can_write' => workspaceCanWrite($currentWorkspaceRole),
                    ],
                ]);
                exit;
            }

            $updates[] = 'updated_at = CURRENT_TIMESTAMP';

            $updateStatement = $pdo->prepare(
                'UPDATE app_users
                 SET ' . implode(', ', $updates) . '
                 WHERE id = :id'
            );
            $updateStatement->execute($bindings);

            if ($usernameChanged) {
                Auth::updateUsername($username);
            }
            if ($displayNameChanged) {
                Auth::updateDisplayName($displayName !== '' ? $displayName : null);
            }

            $account = loadAccount($pdo, $currentUserId);
            $changeSummary = [];
            if ($usernameChanged) {
                $changeSummary[] = 'username';
            }
            if ($displayNameChanged) {
                $changeSummary[] = 'display name';
            }
            if ($emailChanged) {
                $changeSummary[] = 'email';
            }
            if ($passwordChanged) {
                $changeSummary[] = 'password';
            }

            jsonResponse([
                'ok' => true,
                'message' => 'Updated: ' . implode(' and ', $changeSummary) . '.',
                'account' => $account,
                'workspace' => [
                    'id' => $currentWorkspaceId,
                    'role' => $currentWorkspaceRole,
                    'can_write' => workspaceCanWrite($currentWorkspaceRole),
                ],
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'dashboard') {
            jsonResponse([
                'ok' => true,
                'metrics' => loadMetrics($pdo, $currentWorkspaceId),
                'access' => [
                    'role' => $currentWorkspaceRole,
                    'can_write' => workspaceCanWrite($currentWorkspaceRole),
                ],
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'trends') {
            jsonResponse([
                'ok' => true,
                'trends' => loadTrends($pdo, $currentWorkspaceId),
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'calendar') {
            $monthInput = (string) ($_GET['month'] ?? '');
            jsonResponse([
                'ok' => true,
                'calendar' => loadCalendarMonth($pdo, $currentWorkspaceId, $monthInput),
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'medicines') {
            jsonResponse([
                'ok' => true,
                'medicines' => loadMedicineOptions($pdo, $currentWorkspaceId),
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
                'schedules' => loadDoseSchedules($pdo, $currentWorkspaceId, $currentUserId),
                'access' => [
                    'role' => $currentWorkspaceRole,
                    'can_write' => workspaceCanWrite($currentWorkspaceRole),
                ],
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
            $entriesPage = loadEntriesPage($pdo, $currentWorkspaceId, $page, $perPage, $entriesFilters);

            jsonResponse([
                'ok' => true,
                'entries' => $entriesPage['entries'],
                'pagination' => $entriesPage['pagination'],
                'filters' => $entriesPage['filters'],
                'access' => [
                    'role' => $currentWorkspaceRole,
                    'can_write' => workspaceCanWrite($currentWorkspaceRole),
                ],
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
            downloadEntriesCsv($pdo, $currentWorkspaceId, $exportFilters);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'backup_json') {
            downloadBackupJson($pdo, $currentWorkspaceId);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'GET' && $apiAction === 'backup_sql') {
            downloadBackupSql($pdo, $currentWorkspaceId);
            exit;
        }

        if (in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true) && $apiAction === 'push_message') {
            $targetWorkspaceId = $currentWorkspaceId;
            $targetUserId = $currentUserId;
            $authMode = 'session';

            if ($targetUserId === null || $targetWorkspaceId === null) {
                $payload = $_SERVER['REQUEST_METHOD'] === 'POST' ? requestPayload() : $_GET;
                $endpoint = trim((string) ($payload['endpoint'] ?? ''));
                if ($endpoint !== '') {
                    $resolvedTarget = resolvePushNotificationTargetFromEndpoint($pdo, $endpoint);
                    if (is_array($resolvedTarget)) {
                        $targetWorkspaceId = (int) ($resolvedTarget['workspace_id'] ?? 0);
                        $targetUserId = (int) ($resolvedTarget['user_id'] ?? 0);
                        $authMode = 'subscription_endpoint';
                    }
                }
            }

            if ($targetUserId === null || $targetWorkspaceId === null || $targetWorkspaceId <= 0 || $targetUserId <= 0) {
                logEvent('warning', 'push.message.unauthorized', [
                    'api' => 'push_message',
                    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'),
                    'remote_ip' => requestIp(),
                    'auth_mode' => $authMode,
                    'has_session_user' => $currentUserId !== null,
                ]);
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            jsonResponse([
                'ok' => true,
                'notification' => loadLatestPushNotificationForUser($pdo, $targetWorkspaceId, $targetUserId),
            ]);
            exit;
        }

        if (
            in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)
            && $apiAction === 'process_reminders'
        ) {
            $expectedToken = trim((string) (Env::get('REMINDER_CRON_TOKEN', '') ?? ''));
            $providedToken = trim((string) ($_GET['token'] ?? $_POST['token'] ?? ''));
            $tokenSource = $providedToken !== '' ? 'query_or_body' : 'none';

            if ($providedToken === '') {
                $headerToken = trim((string) ($_SERVER['HTTP_X_REMINDER_TOKEN'] ?? ''));
                if ($headerToken !== '') {
                    $providedToken = $headerToken;
                    $tokenSource = 'x_reminder_token';
                }
            }

            if ($providedToken === '') {
                $authorizationHeader = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
                if (preg_match('/^Bearer\s+(.+)$/i', $authorizationHeader, $matches) === 1) {
                    $providedToken = trim((string) ($matches[1] ?? ''));
                    if ($providedToken !== '') {
                        $tokenSource = 'authorization_bearer';
                    }
                }
            }

            $isCronAuthorized = $expectedToken !== ''
                && $providedToken !== ''
                && hash_equals($expectedToken, $providedToken);

            $queryParamKeys = array_keys($_GET);
            $queryParamKeys = array_values(array_filter($queryParamKeys, static fn($key): bool => $key !== 'token'));
            $tokenLength = strlen($providedToken);
            $requestContext = [
                'api' => 'process_reminders',
                'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'),
                'remote_ip' => requestIp(),
                'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 220),
                'auth_mode' => $isCronAuthorized ? 'cron_token' : 'session',
                'token_source' => $tokenSource,
                'token_length' => $tokenLength,
                'workspace_id' => $currentWorkspaceId,
                'workspace_role' => $currentWorkspaceRole,
                'user_id' => $currentUserId,
            ];
            logReminderRequestProbe('request_received', array_merge($requestContext, [
                'query_param_keys' => $queryParamKeys,
                'has_query_or_body_token' => $tokenSource === 'query_or_body',
                'has_header_token' => $tokenSource === 'x_reminder_token',
                'has_bearer_token' => $tokenSource === 'authorization_bearer',
            ]));

            if (!$isCronAuthorized && $currentUserId === null) {
                logEvent('warning', 'reminders.process.unauthorized', $requestContext);
                logReminderRequestProbe('request_rejected', array_merge($requestContext, [
                    'reason' => 'unauthorized',
                    'status' => 401,
                ]));
                jsonResponse([
                    'ok' => false,
                    'error' => 'Authentication required.',
                ], 401);
                exit;
            }

            if (!$isCronAuthorized && !workspaceCanWrite($currentWorkspaceRole)) {
                logEvent('warning', 'reminders.process.forbidden', $requestContext);
                logReminderRequestProbe('request_rejected', array_merge($requestContext, [
                    'reason' => 'forbidden',
                    'status' => 403,
                ]));
                jsonResponse([
                    'ok' => false,
                    'error' => 'Read-only access. Reminder processing requires editor permissions.',
                ], 403);
                exit;
            }

            logEvent('info', 'reminders.process.requested', $requestContext);
            $result = processDueReminders(
                $pdo,
                $isCronAuthorized ? null : $currentWorkspaceId,
                $isCronAuthorized ? null : $currentUserId,
                $requestContext
            );
            logReminderRequestProbe('request_completed', array_merge($requestContext, [
                'status' => 200,
                'result' => [
                    'processed' => (int) ($result['processed'] ?? 0),
                    'due' => (int) ($result['due'] ?? 0),
                    'sent' => (int) ($result['sent'] ?? 0),
                    'skipped' => (int) ($result['skipped'] ?? 0),
                    'push_attempted' => (int) ($result['push_attempted'] ?? 0),
                    'push_failed' => (int) ($result['push_failed'] ?? 0),
                    'push_deactivated' => (int) ($result['push_deactivated'] ?? 0),
                    'failure_count' => (int) ($result['failure_count'] ?? 0),
                ],
            ]));
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
            $validated = validateSchedulePayload($payload, $pdo, $currentWorkspaceId, $allowedDosageUnits);
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
                'INSERT INTO dose_schedules (workspace_id, user_id, medicine_id, dosage_value, dosage_unit, time_of_day, reminder_message, is_active)
                 VALUES (:workspace_id, :user_id, :medicine_id, :dosage_value, :dosage_unit, :time_of_day, :reminder_message, :is_active)'
            );
            $statement->execute([
                ':workspace_id' => $currentWorkspaceId,
                ':user_id' => $currentUserId,
                ':medicine_id' => $data['medicine_id'],
                ':dosage_value' => $validated['dosage_value_for_db'],
                ':dosage_unit' => $data['dosage_unit'],
                ':time_of_day' => $validated['time_of_day_for_db'],
                ':reminder_message' => $data['reminder_message'] !== '' ? $data['reminder_message'] : null,
                ':is_active' => $data['is_active'] === 0 ? 0 : 1,
            ]);

            jsonResponse([
                'ok' => true,
                'message' => 'Schedule created.',
            ], 201);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'schedule_update') {
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

            $existsStatement = $pdo->prepare(
                'SELECT id
                 FROM dose_schedules
                 WHERE id = :id
                   AND workspace_id = :workspace_id
                   AND user_id = :user_id
                 LIMIT 1'
            );
            $existsStatement->execute([
                ':id' => $scheduleId,
                ':workspace_id' => $currentWorkspaceId,
                ':user_id' => $currentUserId,
            ]);
            if (!is_array($existsStatement->fetch())) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'Schedule not found.',
                ], 404);
                exit;
            }

            $validated = validateSchedulePayload($payload, $pdo, $currentWorkspaceId, $allowedDosageUnits);
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
                'UPDATE dose_schedules
                 SET medicine_id = :medicine_id,
                     dosage_value = :dosage_value,
                     dosage_unit = :dosage_unit,
                     time_of_day = :time_of_day,
                     reminder_message = :reminder_message,
                     is_active = :is_active,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND workspace_id = :workspace_id
                   AND user_id = :user_id'
            );
            $statement->execute([
                ':medicine_id' => $data['medicine_id'],
                ':dosage_value' => $validated['dosage_value_for_db'],
                ':dosage_unit' => $data['dosage_unit'],
                ':time_of_day' => $validated['time_of_day_for_db'],
                ':reminder_message' => $data['reminder_message'] !== '' ? $data['reminder_message'] : null,
                ':is_active' => $data['is_active'] === 0 ? 0 : 1,
                ':id' => $scheduleId,
                ':workspace_id' => $currentWorkspaceId,
                ':user_id' => $currentUserId,
            ]);

            jsonResponse([
                'ok' => true,
                'message' => 'Schedule updated.',
            ]);
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
                 WHERE id = :id
                   AND workspace_id = :workspace_id
                   AND user_id = :user_id'
            );
            $statement->execute([
                ':is_active' => $isActive,
                ':id' => $scheduleId,
                ':workspace_id' => $currentWorkspaceId,
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

            $statement = $pdo->prepare(
                'DELETE FROM dose_schedules
                 WHERE id = :id
                   AND workspace_id = :workspace_id
                   AND user_id = :user_id'
            );
            $statement->execute([
                ':id' => $scheduleId,
                ':workspace_id' => $currentWorkspaceId,
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
            $saved = savePushSubscription($pdo, $currentWorkspaceId, $currentUserId, $payload);
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
            removePushSubscription($pdo, $currentWorkspaceId, $currentUserId, $payload);
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

            $dispatch = dispatchPushForUser($pdo, $currentWorkspaceId, $currentUserId, 'push_test', [
                'api' => 'push_test',
                'remote_ip' => requestIp(),
            ]);
            jsonResponse([
                'ok' => true,
                'dispatch' => $dispatch,
            ]);
            exit;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && $apiAction === 'create') {
            $payload = requestPayload();
            $validated = validateIntake($payload, $pdo, $currentWorkspaceId, $allowedDosageUnits);

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
                'INSERT INTO medicine_intake_logs (workspace_id, medicine_id, logged_by_user_id, dosage_value, dosage_unit, rating, taken_at, notes)
                 VALUES (:workspace_id, :medicine_id, :logged_by_user_id, :dosage_value, :dosage_unit, :rating, :taken_at, :notes)'
            );
            $statement->execute([
                ':workspace_id' => $currentWorkspaceId,
                ':medicine_id' => $validated['medicine_id'],
                ':logged_by_user_id' => $currentUserId,
                ':dosage_value' => $validated['dosage_value_for_db'],
                ':dosage_unit' => $data['dosage_unit'],
                ':rating' => $validated['rating_for_db'],
                ':taken_at' => $validated['taken_at_for_db'],
                ':notes' => $data['notes'] !== '' ? $data['notes'] : null,
            ]);

            $entryId = (int) $pdo->lastInsertId();
            $entry = findEntry($pdo, $currentWorkspaceId, $entryId);

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

            $existingEntry = findEntry($pdo, $currentWorkspaceId, $entryId);
            if (!is_array($existingEntry)) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'The selected entry no longer exists.',
                ], 404);
                exit;
            }

            $validated = validateIntake($payload, $pdo, $currentWorkspaceId, $allowedDosageUnits);
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
                 WHERE id = :id
                   AND workspace_id = :workspace_id'
            );
            $statement->execute([
                ':medicine_id' => $validated['medicine_id'],
                ':dosage_value' => $validated['dosage_value_for_db'],
                ':dosage_unit' => $data['dosage_unit'],
                ':rating' => $validated['rating_for_db'],
                ':taken_at' => $validated['taken_at_for_db'],
                ':notes' => $data['notes'] !== '' ? $data['notes'] : null,
                ':id' => $entryId,
                ':workspace_id' => $currentWorkspaceId,
            ]);

            $updatedEntry = findEntry($pdo, $currentWorkspaceId, $entryId);
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

            $existingEntry = findEntry($pdo, $currentWorkspaceId, $entryId);
            if (!is_array($existingEntry)) {
                jsonResponse([
                    'ok' => false,
                    'error' => 'The selected entry no longer exists.',
                ], 404);
                exit;
            }

            $statement = $pdo->prepare(
                'DELETE FROM medicine_intake_logs
                 WHERE id = :id
                   AND workspace_id = :workspace_id'
            );
            $statement->execute([
                ':id' => $entryId,
                ':workspace_id' => $currentWorkspaceId,
            ]);

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
        logEvent('error', 'api.exception', [
            'api' => $apiAction,
            'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN'),
            'remote_ip' => requestIp(),
            'workspace_id' => $currentWorkspaceId ?? null,
            'workspace_role' => $currentWorkspaceRole ?? null,
            'user_id' => $currentUserId ?? null,
            'message' => substr($exception->getMessage(), 0, 320),
        ]);
        jsonResponse([
            'ok' => false,
            'error' => 'Server error: ' . $exception->getMessage(),
        ], 500);
        exit;
    }
}

$dbReady = $pdo instanceof PDO;
$signedInUsername = Auth::displayLabel() ?? '';
$signedInWorkspaceRole = normalizeWorkspaceRole(Auth::workspaceRole());
$canWriteWorkspaceData = workspaceCanWrite($signedInWorkspaceRole);
$entryTableColumnCount = $canWriteWorkspaceData ? 7 : 6;
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Intake Dashboard</title>
    <link rel="icon" href="favicon.svg" type="image/svg+xml">
    <link rel="shortcut icon" href="favicon.svg">
    <link rel="stylesheet" href="assets/min/style.css">
    <script>
        window.MEDICINE_LOG_CONFIG = {
            apiPath: <?= json_encode($selfPath, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            perPage: <?= $perPage ?>,
            pushPublicKey: <?= json_encode(PushNotifications::publicKey(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            pushConfigured: <?= PushNotifications::isConfigured() ? 'true' : 'false' ?>,
            workspaceRole: <?= json_encode($signedInWorkspaceRole, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            canWrite: <?= $canWriteWorkspaceData ? 'true' : 'false' ?>
        };
    </script>
    <script src="assets/min/nav.js" defer></script>
    <script src="assets/min/app.js" defer></script>
</head>

<body data-db-ready="<?= $dbReady ? '1' : '0' ?>">
    <main class="dashboard">
        <nav class="hamburger-nav" aria-label="Primary Navigation">
            <button
                type="button"
                class="ghost-btn hamburger-toggle"
                aria-expanded="false"
                aria-controls="primary-menu">
                <span class="hamburger-icon" aria-hidden="true"></span>
                Menu
            </button>
            <div id="primary-menu" class="hamburger-panel" hidden>
                <a class="hamburger-link is-active" href="index.php">Dashboard</a>
                <a class="hamburger-link" href="trends.php">Trends</a>
                <a class="hamburger-link" href="calendar.php">Calendar</a>
                <?php if ($canWriteWorkspaceData): ?>
                    <a class="hamburger-link" href="schedules.php">Schedules</a>
                <?php endif; ?>
                <a class="hamburger-link" href="settings.php">Settings</a>
                <a class="hamburger-link" href="logout.php">Log Out</a>
            </div>
        </nav>

        <header class="hero card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Daily Intake Dashboard</h1>
            <p>Monitor progress, log doses quickly, and edit records in place.</p>
            <?php if ($signedInUsername !== ''): ?>
                <p class="meta-text">Signed in as <strong><?= e($signedInUsername) ?></strong></p>
            <?php endif; ?>
            <p class="meta-text">Workspace role: <strong><?= e(ucfirst($signedInWorkspaceRole)) ?></strong></p>
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
                <div class="section-header">
                    <h2>Recent Entries</h2>
                    <div class="history-header-controls">
                        <div class="history-header-actions">
                            <?php if ($canWriteWorkspaceData): ?>
                                <button
                                    id="open-create-modal-btn"
                                    type="button"
                                    class="primary-btn add-intake-btn">
                                    Add Intake
                                </button>
                            <?php endif; ?>
                            <button
                                id="history-filter-toggle"
                                type="button"
                                class="ghost-btn history-toggle-btn"
                                aria-controls="history-tools-panel"
                                aria-expanded="false">
                                Show Filters
                            </button>
                            <button
                                id="data-tools-toggle"
                                type="button"
                                class="ghost-btn history-toggle-btn"
                                aria-controls="data-tools-panel"
                                aria-expanded="false">
                                Show Data Tools
                            </button>
                        </div>
                        <span id="table-meta" class="meta-text">Loading entries...</span>
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

                <div id="data-tools-panel" class="data-tools" hidden>
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
                                <th>Logged By</th>
                                <th>Dosage</th>
                                <th>Rating</th>
                                <th class="notes-column">Notes</th>
                                <?php if ($canWriteWorkspaceData): ?>
                                    <th>Actions</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody id="entries-body">
                            <tr>
                                <td class="empty-cell" colspan="<?= $entryTableColumnCount ?>">Loading...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <nav id="pagination" class="pagination" aria-label="Entries pagination"></nav>
            </article>
        </section>

    </main>

    <?php if ($canWriteWorkspaceData): ?>
        <section id="create-modal" class="modal" hidden>
            <div class="modal-backdrop" data-close-create-modal="true"></div>
            <div class="modal-panel create-modal-panel" role="dialog" aria-modal="true" aria-labelledby="create-modal-title">
                <div class="modal-header">
                    <h3 id="create-modal-title">Add Intake</h3>
                    <button type="button" class="ghost-btn" data-close-create-modal="true">Close</button>
                </div>

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

                    <div class="modal-actions">
                        <button type="button" class="ghost-btn" data-close-create-modal="true">Cancel</button>
                        <button class="primary-btn" type="submit">Save Intake</button>
                    </div>
                </form>
            </div>
        </section>

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
    <?php endif; ?>

    <noscript>
        <div class="alert alert-error noscript-alert">
            JavaScript is required for the asynchronous dashboard and modal editing.
        </div>
    </noscript>
</body>

</html>
