<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/** Réglages partagés avec l'ancien portail (table app_settings : Billetweb, SMTP, e-mails...). */
final class Settings
{
    private static array $cache = [];

    public static function get(Db $db, string $key, string $default = ''): string
    {
        if (!array_key_exists($key, self::$cache)) {
            $v = $db->val('SELECT setting_value FROM app_settings WHERE setting_key=?', [$key]);
            self::$cache[$key] = $v === null ? null : (string)$v;
        }
        return self::$cache[$key] ?? $default;
    }

    public static function set(Db $db, string $key, string $value): void
    {
        $db->run(
            'INSERT INTO app_settings(setting_key,setting_value,updated_at) VALUES(?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()',
            [$key, $value]
        );
        self::$cache[$key] = $value;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}
