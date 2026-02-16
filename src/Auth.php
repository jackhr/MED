<?php

declare(strict_types=1);

final class Auth
{
    private const SESSION_KEY = 'medicine_log_auth';

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

    public static function login(int $userId, string $username, ?string $displayName = null): void
    {
        self::startSession();
        session_regenerate_id(true);
        $cleanDisplayName = trim((string) $displayName);
        $_SESSION[self::SESSION_KEY] = [
            'id' => $userId,
            'username' => trim($username),
            'display_name' => $cleanDisplayName !== '' ? $cleanDisplayName : null,
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
        $_SESSION[self::SESSION_KEY] = [
            'id' => $userId,
            'username' => trim($username),
            'display_name' => $displayName !== '' ? $displayName : null,
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

        $cleanDisplayName = trim((string) $displayName);
        $_SESSION[self::SESSION_KEY] = [
            'id' => $userId,
            'username' => $username,
            'display_name' => $cleanDisplayName !== '' ? $cleanDisplayName : null,
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

        return $userId > 0 && $username !== '';
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
