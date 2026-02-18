<?php

declare(strict_types=1);

final class Auth
{
    private const SESSION_KEY = 'medicine_log_auth';
    private const WRITABLE_ROLES = ['owner', 'editor'];

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionName = trim((string) Env::get('AUTH_SESSION_NAME', 'medicine_log_session'));
        if ($sessionName !== '') {
            session_name($sessionName);
        }

        session_start();
    }

    private static function normalizeRole(?string $role): string
    {
        $normalized = strtolower(trim((string) $role));
        if (in_array($normalized, ['owner', 'editor', 'viewer'], true)) {
            return $normalized;
        }

        return 'viewer';
    }

    public static function login(
        int $userId,
        string $username,
        ?string $displayName = null,
        int $workspaceId = 0,
        ?string $workspaceRole = null
    ): void
    {
        self::startSession();
        session_regenerate_id(true);
        $cleanDisplayName = trim((string) $displayName);
        $role = self::normalizeRole($workspaceRole);
        $_SESSION[self::SESSION_KEY] = [
            'id' => $userId,
            'username' => trim($username),
            'display_name' => $cleanDisplayName !== '' ? $cleanDisplayName : null,
            'workspace_id' => $workspaceId > 0 ? $workspaceId : null,
            'workspace_role' => $role,
        ];
    }

    public static function updateUsername(string $username): void
    {
        self::startSession();

        $user = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($user)) {
            return;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $displayName = trim((string) ($user['display_name'] ?? ''));
        $workspaceId = (int) ($user['workspace_id'] ?? 0);
        $workspaceRole = self::normalizeRole((string) ($user['workspace_role'] ?? 'viewer'));
        $_SESSION[self::SESSION_KEY] = [
            'id' => $userId,
            'username' => trim($username),
            'display_name' => $displayName !== '' ? $displayName : null,
            'workspace_id' => $workspaceId > 0 ? $workspaceId : null,
            'workspace_role' => $workspaceRole,
        ];
    }

    public static function updateDisplayName(?string $displayName): void
    {
        self::startSession();

        $user = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($user)) {
            return;
        }

        $userId = (int) ($user['id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        $username = trim((string) ($user['username'] ?? ''));
        if ($username === '') {
            return;
        }

        $workspaceId = (int) ($user['workspace_id'] ?? 0);
        $workspaceRole = self::normalizeRole((string) ($user['workspace_role'] ?? 'viewer'));
        $cleanDisplayName = trim((string) $displayName);
        $_SESSION[self::SESSION_KEY] = [
            'id' => $userId,
            'username' => $username,
            'display_name' => $cleanDisplayName !== '' ? $cleanDisplayName : null,
            'workspace_id' => $workspaceId > 0 ? $workspaceId : null,
            'workspace_role' => $workspaceRole,
        ];
    }

    public static function isAuthenticated(): bool
    {
        self::startSession();

        $user = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_array($user)) {
            return false;
        }

        $userId = (int) ($user['id'] ?? 0);
        $username = trim((string) ($user['username'] ?? ''));
        $workspaceId = (int) ($user['workspace_id'] ?? 0);
        $workspaceRole = self::normalizeRole((string) ($user['workspace_role'] ?? 'viewer'));

        return $userId > 0
            && $username !== ''
            && $workspaceId > 0
            && in_array($workspaceRole, ['owner', 'editor', 'viewer'], true);
    }

    public static function userId(): ?int
    {
        if (!self::isAuthenticated()) {
            return null;
        }

        $user = $_SESSION[self::SESSION_KEY] ?? null;
        $userId = (int) (($user['id'] ?? 0));

        return $userId > 0 ? $userId : null;
    }

    public static function username(): ?string
    {
        if (!self::isAuthenticated()) {
            return null;
        }

        $user = $_SESSION[self::SESSION_KEY] ?? null;
        $username = trim((string) ($user['username'] ?? ''));

        return $username !== '' ? $username : null;
    }

    public static function displayName(): ?string
    {
        if (!self::isAuthenticated()) {
            return null;
        }

        $user = $_SESSION[self::SESSION_KEY] ?? null;
        $displayName = trim((string) ($user['display_name'] ?? ''));

        return $displayName !== '' ? $displayName : null;
    }

    public static function displayLabel(): ?string
    {
        $displayName = self::displayName();
        if ($displayName !== null) {
            return $displayName;
        }

        return self::username();
    }

    public static function workspaceId(): ?int
    {
        if (!self::isAuthenticated()) {
            return null;
        }

        $user = $_SESSION[self::SESSION_KEY] ?? null;
        $workspaceId = (int) (($user['workspace_id'] ?? 0));

        return $workspaceId > 0 ? $workspaceId : null;
    }

    public static function workspaceRole(): ?string
    {
        if (!self::isAuthenticated()) {
            return null;
        }

        $user = $_SESSION[self::SESSION_KEY] ?? null;
        $workspaceRole = self::normalizeRole((string) ($user['workspace_role'] ?? 'viewer'));

        return in_array($workspaceRole, ['owner', 'editor', 'viewer'], true)
            ? $workspaceRole
            : null;
    }

    public static function canWrite(): bool
    {
        $role = self::workspaceRole();
        if ($role === null) {
            return false;
        }

        return in_array($role, self::WRITABLE_ROLES, true);
    }

    public static function requireAuthForPage(string $loginPath = 'login.php'): void
    {
        if (self::isAuthenticated()) {
            return;
        }

        header('Location: ' . $loginPath);
        exit;
    }

    public static function requireAuthForApi(): void
    {
        if (self::isAuthenticated()) {
            return;
        }

        http_response_code(401);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'error' => 'Authentication required.',
        ], JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function logout(): void
    {
        self::startSession();
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool) ($params['secure'] ?? false),
                (bool) ($params['httponly'] ?? false)
            );
        }

        session_destroy();
    }
}
