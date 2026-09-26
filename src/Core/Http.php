<?php
declare(strict_types=1);

namespace JC\Core;

final class Http
{
    private static ?array $body = null;

    public static function method(): string
    {
        return strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    }

    public static function path(): string
    {
        $p = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $p = '/' . trim($p, '/');
        return $p === '/index.php' ? '/' : $p;
    }

    public static function ip(): string
    {
        return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    }

    public static function wantsJson(): bool
    {
        return str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')
            || (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch');
    }

    /** Corps de la requête : JSON décodé ou $_POST. */
    public static function body(): array
    {
        if (self::$body !== null) {
            return self::$body;
        }
        if (str_contains((string)($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $j = json_decode((string)file_get_contents('php://input'), true);
            self::$body = is_array($j) ? $j : [];
        } else {
            self::$body = $_POST;
        }
        return self::$body;
    }

    public static function input(string $key, mixed $default = null): mixed
    {
        $b = self::body();
        return array_key_exists($key, $b) ? $b[$key] : ($_GET[$key] ?? $default);
    }

    public static function str(string $key, string $default = ''): string
    {
        $v = self::input($key, $default);
        return is_scalar($v) ? trim((string)$v) : $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $v = self::input($key, $default);
        return is_numeric($v) ? (int)$v : $default;
    }

    public static function redirect(string $to): never
    {
        header('Location: ' . $to);
        exit;
    }

    public static function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function abort(int $code, string $message = ''): never
    {
        http_response_code($code);
        if (self::wantsJson()) {
            self::json(['ok' => false, 'message' => $message ?: 'Erreur ' . $code], $code);
        }
        View::render('error', ['code' => $code, 'message' => $message ?: self::defaultMessage($code)]);
        exit;
    }

    private static function defaultMessage(int $code): string
    {
        return match ($code) {
            403 => 'Vous n\'avez pas accès à cette page avec votre rôle.',
            404 => 'Page introuvable.',
            405 => 'Méthode non autorisée.',
            419 => 'Votre session a expiré. Rechargez la page puis recommencez.',
            423 => 'Mode lecture seule.',
            default => 'Une erreur est survenue.',
        };
    }

    public static function securityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: same-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self'; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000');
        }
    }
}
