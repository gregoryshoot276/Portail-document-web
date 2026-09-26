<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/** Accès Starter sans identifiants : un seul lien secret (jeton dans l'URL) + code PIN optionnel choisi par
 * l'admin, verrouillé sur une seule journée à la fois. Réglages globaux (app_settings), volontairement simple :
 * repris du fonctionnement d'un outil précédent qui convenait bien à l'usage réel. */
final class StarterPublicAccess
{
    public static function enabled(Db $db): bool
    {
        return Settings::get($db, 'starter_public_enabled', '0') === '1';
    }

    public static function token(Db $db): string
    {
        $t = Settings::get($db, 'starter_public_token', '');
        if ($t === '') {
            $t = bin2hex(random_bytes(24));
            Settings::set($db, 'starter_public_token', $t);
        }
        return $t;
    }

    public static function eventId(Db $db): int
    {
        return (int)Settings::get($db, 'starter_public_event_id', '0');
    }

    public static function hasPin(Db $db): bool
    {
        return Settings::get($db, 'starter_public_pin_hash', '') !== '';
    }

    public static function verifyPin(Db $db, string $pin): bool
    {
        $hash = Settings::get($db, 'starter_public_pin_hash', '');
        return $hash !== '' && password_verify($pin, $hash);
    }

    /** $pin === null : ne pas toucher au code déjà enregistré. */
    public static function save(Db $db, bool $enabled, int $eventId, ?string $pin): void
    {
        Settings::set($db, 'starter_public_enabled', $enabled ? '1' : '0');
        Settings::set($db, 'starter_public_event_id', (string)$eventId);
        if ($pin !== null && $pin !== '') {
            Settings::set($db, 'starter_public_pin_hash', password_hash($pin, PASSWORD_DEFAULT));
        }
    }

    public static function regenerateToken(Db $db): string
    {
        $t = bin2hex(random_bytes(24));
        Settings::set($db, 'starter_public_token', $t);
        return $t;
    }

    public static function disable(Db $db): void
    {
        Settings::set($db, 'starter_public_enabled', '0');
    }
}
