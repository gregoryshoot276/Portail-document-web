<?php
declare(strict_types=1);

namespace JC\Core;

/** Limitation des tentatives de connexion, stockée dans des fichiers (aucune écriture en base). */
final class Throttle
{
    private static function file(string $key): string
    {
        $dir = App::path('storage/state/throttle');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        return $dir . '/' . hash('sha256', $key) . '.json';
    }

    private static function load(string $key): array
    {
        $f = self::file($key);
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return is_array($j) ? $j : ['count' => 0, 'first' => 0, 'locked_until' => 0];
    }

    /** Secondes restantes avant déblocage (0 = pas bloqué). */
    public static function lockedFor(string $key): int
    {
        $s = self::load($key);
        return max(0, (int)$s['locked_until'] - time());
    }

    public static function fail(string $key, int $max, int $lockMinutes): void
    {
        $s = self::load($key);
        $window = 900;
        if (time() - (int)$s['first'] > $window) {
            $s = ['count' => 0, 'first' => time(), 'locked_until' => 0];
        }
        $s['count'] = (int)$s['count'] + 1;
        if ((int)$s['first'] === 0) {
            $s['first'] = time();
        }
        if ($s['count'] >= $max) {
            $s['locked_until'] = time() + $lockMinutes * 60;
            $s['count'] = 0;
            $s['first'] = 0;
        }
        @file_put_contents(self::file($key), json_encode($s), LOCK_EX);
    }

    /** Compteur glissant : autorise au plus $max appels par fenêtre de $windowSeconds (false = limite atteinte). */
    public static function allow(string $key, int $max, int $windowSeconds): bool
    {
        $f = self::file('rate:' . $key);
        $now = time();
        $hits = [];
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            $hits = is_array($j) ? array_values(array_filter($j, fn($t) => is_int($t) && $t > $now - $windowSeconds)) : [];
        }
        if (count($hits) >= $max) {
            return false;
        }
        $hits[] = $now;
        @file_put_contents($f, json_encode($hits), LOCK_EX);
        return true;
    }

    public static function clear(string $key): void
    {
        $f = self::file($key);
        if (is_file($f)) {
            @unlink($f);
        }
    }
}
