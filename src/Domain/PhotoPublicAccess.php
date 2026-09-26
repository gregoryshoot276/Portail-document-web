<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/** Accès Photographe sans identifiants : un lien secret (jeton dans l'URL) + code PIN optionnel choisi par
 * l'admin — même principe que l'accès Starter public (cf. StarterPublicAccess), mais sans verrouillage sur une
 * seule journée : le photographe garde la main pour changer de journée comme sur la page Photo normale. */
final class PhotoPublicAccess
{
    public static function enabled(Db $db): bool
    {
        return Settings::get($db, 'photo_public_enabled', '0') === '1';
    }

    public static function token(Db $db): string
    {
        $t = Settings::get($db, 'photo_public_token', '');
        if ($t === '') {
            $t = bin2hex(random_bytes(24));
            Settings::set($db, 'photo_public_token', $t);
        }
        return $t;
    }

    public static function hasPin(Db $db): bool
    {
        return Settings::get($db, 'photo_public_pin_hash', '') !== '';
    }

    public static function verifyPin(Db $db, string $pin): bool
    {
        $hash = Settings::get($db, 'photo_public_pin_hash', '');
        return $hash !== '' && password_verify($pin, $hash);
    }

    /** $pin === null : ne pas toucher au code déjà enregistré. */
    public static function save(Db $db, bool $enabled, ?string $pin): void
    {
        Settings::set($db, 'photo_public_enabled', $enabled ? '1' : '0');
        if ($pin !== null && $pin !== '') {
            Settings::set($db, 'photo_public_pin_hash', password_hash($pin, PASSWORD_DEFAULT));
        }
    }

    public static function regenerateToken(Db $db): string
    {
        $t = bin2hex(random_bytes(24));
        Settings::set($db, 'photo_public_token', $t);
        return $t;
    }

    public static function disable(Db $db): void
    {
        Settings::set($db, 'photo_public_enabled', '0');
    }
}
