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

    public static function login(int $userId, string $username): void
    {
        self::startSession();
        session_regenerate_id(true);
        $_SESSION[self::SESSION_KEY] = [
            'id' => $userId,
            'username' => $username,
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
