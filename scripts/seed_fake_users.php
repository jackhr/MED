<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/Env.php';
require_once dirname(__DIR__) . '/src/Database.php';

Env::load(dirname(__DIR__) . '/.env');

function randomPassword(int $length = 14): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*';
    $maxIndex = strlen($alphabet) - 1;
    $password = '';

    for ($index = 0; $index < $length; $index += 1) {
        $password .= $alphabet[random_int(0, $maxIndex)];
    }

    return $password;
}

function tableExists(PDO $pdo, string $databaseName, string $tableName): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = :database_name
           AND table_name = :table_name'
    );
    $statement->execute([
        ':database_name' => $databaseName,
        ':table_name' => $tableName,
    ]);

    return (int) $statement->fetchColumn() > 0;
}

try {
    $pdo = Database::connection();
    $databaseName = (string) (Env::get('DB_NAME', 'medicine_log') ?? 'medicine_log');

    foreach (['workspaces', 'workspace_users', 'app_users'] as $requiredTable) {
        if (!tableExists($pdo, $databaseName, $requiredTable)) {
            throw new RuntimeException(
                'Required table "' . $requiredTable . '" is missing. Run migrations first.'
            );
        }
    }

    $workspaceStatement = $pdo->query('SELECT id, name FROM workspaces ORDER BY id ASC LIMIT 1');
    $workspace = $workspaceStatement->fetch();
    if (!is_array($workspace)) {
        throw new RuntimeException('No workspace found. Run migrations and create a workspace first.');
    }

    $workspaceId = (int) ($workspace['id'] ?? 0);
    if ($workspaceId <= 0) {
        throw new RuntimeException('Invalid workspace ID.');
    }

    $fakeUsers = [
        [
            'username' => 'viewer_demo_1',
            'display_name' => 'Viewer Demo One',
            'email' => 'viewer_demo_1@example.test',
        ],
        [
            'username' => 'viewer_demo_2',
            'display_name' => 'Viewer Demo Two',
            'email' => 'viewer_demo_2@example.test',
        ],
    ];

    $existingUserStatement = $pdo->prepare(
        'SELECT id
         FROM app_users
         WHERE username = :username
         LIMIT 1'
    );
    $insertUserStatement = $pdo->prepare(
        'INSERT INTO app_users (username, display_name, email, password_hash, is_active)
         VALUES (:username, :display_name, :email, :password_hash, 1)'
    );
    $upsertWorkspaceMembershipStatement = $pdo->prepare(
        'INSERT INTO workspace_users (workspace_id, user_id, role, is_active)
         VALUES (:workspace_id, :user_id, :role, 1)
         ON DUPLICATE KEY UPDATE
            role = VALUES(role),
            is_active = 1,
            updated_at = CURRENT_TIMESTAMP'
    );

    $results = [];
    $pdo->beginTransaction();
    foreach ($fakeUsers as $fakeUser) {
        $username = (string) $fakeUser['username'];
        $existingUserStatement->execute([':username' => $username]);
        $existingUser = $existingUserStatement->fetch();
        $userId = is_array($existingUser) ? (int) ($existingUser['id'] ?? 0) : 0;

        $generatedPassword = null;
        if ($userId <= 0) {
            $generatedPassword = randomPassword();
            $passwordHash = password_hash($generatedPassword, PASSWORD_DEFAULT);
            if (!is_string($passwordHash) || $passwordHash === '') {
                throw new RuntimeException('Could not hash password for ' . $username . '.');
            }

            $insertUserStatement->execute([
                ':username' => $username,
                ':display_name' => (string) $fakeUser['display_name'],
                ':email' => (string) $fakeUser['email'],
                ':password_hash' => $passwordHash,
            ]);
            $userId = (int) $pdo->lastInsertId();
        }

        if ($userId <= 0) {
            throw new RuntimeException('Could not resolve user ID for ' . $username . '.');
        }

        $upsertWorkspaceMembershipStatement->execute([
            ':workspace_id' => $workspaceId,
            ':user_id' => $userId,
            ':role' => 'viewer',
        ]);

        $results[] = [
            'username' => $username,
            'workspace_id' => $workspaceId,
            'role' => 'viewer',
            'created' => $generatedPassword !== null,
            'password' => $generatedPassword,
        ];
    }
    $pdo->commit();

    echo json_encode([
        'ok' => true,
        'workspace' => [
            'id' => $workspaceId,
            'name' => (string) ($workspace['name'] ?? ''),
        ],
        'users' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} catch (Throwable $exception) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    fwrite(STDERR, 'Error: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
