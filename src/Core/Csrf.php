<?php
declare(strict_types=1);

namespace JC\Core;

final class Csrf
{
    public static function token(): string
    {
        if (empty($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
        return (string)$_SESSION['csrf'];
    }

    public static function verify(): void
    {
        $sent = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['_csrf'] ?? ''));
        if ($sent === '') {
            $b = Http::body();
            $sent = is_string($b['_csrf'] ?? null) ? (string)$b['_csrf'] : '';
        }
        if ($sent === '' || !hash_equals(self::token(), $sent)) {
            Http::abort(419);
        }
    }
}
