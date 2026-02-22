#!/usr/bin/env php
<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only.\n";
    exit(1);
}

$projectRoot = dirname(__DIR__);
$indexPath = $projectRoot . '/public/index.php';
$envPath = $projectRoot . '/.env';

if (!is_file($indexPath)) {
    fwrite(STDERR, "Missing public/index.php\n");
    exit(1);
}

require_once $projectRoot . '/src/Env.php';
Env::load($envPath);

$token = trim((string) (Env::get('REMINDER_CRON_TOKEN', '') ?? ''));
if ($token === '') {
    fwrite(STDERR, "REMINDER_CRON_TOKEN is required in .env\n");
    exit(2);
}

// Reuse the existing API route and reminder logic in public/index.php without HTTP.
$_GET['api'] = 'process_reminders';
$_GET['token'] = $token;
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/index.php';
$_SERVER['REMOTE_ADDR'] = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$_SERVER['HTTP_USER_AGENT'] = $_SERVER['HTTP_USER_AGENT'] ?? 'medicine-cli-cron/1.0';

require $indexPath;
