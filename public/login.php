<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';
require_once dirname(__DIR__) . '/src/Auth.php';

Env::load(dirname(__DIR__) . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'UTC') ?? 'UTC');

Auth::startSession();

if (Auth::isAuthenticated()) {
    header('Location: index.php');
    exit;
}

$dbError = null;
$pdo = null;
try {
    $pdo = Database::connection();
} catch (Throwable $exception) {
    $dbError = $exception->getMessage();
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if (!$pdo instanceof PDO) {
        $error = 'Database connection failed. Check your .env DB settings.';
    } elseif ($username === '' || $password === '') {
        $error = 'Username and password are required.';
    } else {
        try {
            $statement = $pdo->prepare(
                'SELECT id, username, password_hash, is_active
                 FROM app_users
                 WHERE username = :username
                 LIMIT 1'
            );
            $statement->execute([':username' => $username]);
            $user = $statement->fetch();

            $isActive = (int) ($user['is_active'] ?? 0) === 1;
            $passwordHash = (string) ($user['password_hash'] ?? '');
            $passwordValid = $passwordHash !== '' && password_verify($password, $passwordHash);

            if (!is_array($user) || !$isActive || !$passwordValid) {
                $error = 'Invalid username or password.';
            } else {
                Auth::login((int) $user['id'], (string) $user['username']);

                $updateStatement = $pdo->prepare('UPDATE app_users SET last_login_at = NOW() WHERE id = :id');
                $updateStatement->execute([':id' => (int) $user['id']]);

                header('Location: index.php');
                exit;
            }
        } catch (Throwable $exception) {
            $error = 'Unable to authenticate. Ensure the user table migration has been applied.';
        }
    }
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Medicine Tracker Login</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
    <main class="auth-shell">
        <section class="card auth-card">
            <p class="eyebrow">Medicine Tracker</p>
            <h1>Log In</h1>
            <p class="auth-subtitle">Use your configured account credentials to access the dashboard.</p>

            <?php if ($error !== null): ?>
                <div class="alert alert-error"><?= e($error) ?></div>
            <?php endif; ?>

            <?php if ($dbError !== null): ?>
                <div class="alert alert-error">
                    <strong>Database connection failed:</strong> <?= e($dbError) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="login.php" class="auth-form" novalidate>
                <label for="username">Username</label>
                <input id="username" name="username" type="text" autocomplete="username" required>

                <label for="password">Password</label>
                <input id="password" name="password" type="password" autocomplete="current-password" required>

                <button class="primary-btn" type="submit"<?= $pdo instanceof PDO ? '' : ' disabled' ?>>Log In</button>
            </form>
        </section>
    </main>
</body>
</html>
