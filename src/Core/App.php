<?php
declare(strict_types=1);

namespace JC\Core;

/** Registre central : chemins, configuration, connexion base de données. */
final class App
{
    private static string $root = '';
    private static array $config = [];
    private static ?Db $db = null;

    public static function boot(string $root): void
    {
        self::$root = rtrim($root, '/\\');
        // JC_CONFIG : chemin d'un autre fichier de configuration (utilisé par les tests automatisés).
        $file = (string)(getenv('JC_CONFIG') ?: self::$root . '/config/config.php');
        if (!is_file($file)) {
            http_response_code(503);
            exit('Configuration absente : copiez config/config.example.php en config/config.php.');
        }
        self::$config = require $file;
        date_default_timezone_set((string)(self::$config['timezone'] ?? 'Europe/Paris'));
        if (empty(self::$config['debug'])) {
            ini_set('display_errors', '0');
        }
        ini_set('log_errors', '1');
        ini_set('error_log', self::path('storage/logs/php-error.log'));
    }

    public static function path(string $rel = ''): string
    {
        return self::$root . ($rel === '' ? '' : '/' . ltrim($rel, '/\\'));
    }

    public static function config(string $key, mixed $default = null): mixed
    {
        $v = self::$config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($v) || !array_key_exists($part, $v)) {
                return $default;
            }
            $v = $v[$part];
        }
        return $v;
    }

    public static function db(): Db
    {
        if (self::$db === null) {
            self::$db = Db::open((array)self::config('db'), Mode::readOnly());
        }
        return self::$db;
    }

    /** Pour les tests : injecte une connexion. */
    public static function useDb(Db $db): void
    {
        self::$db = $db;
    }

    public static function setConfig(array $config): void
    {
        self::$config = $config;
    }

    public static function setRoot(string $root): void
    {
        self::$root = rtrim($root, '/\\');
    }
}
