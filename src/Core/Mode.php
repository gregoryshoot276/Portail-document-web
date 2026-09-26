<?php
declare(strict_types=1);

namespace JC\Core;

/**
 * Mode lecture seule / écriture.
 * Valeur par défaut : config.php. Un super administrateur peut la modifier ;
 * le choix est mémorisé dans storage/state/mode.json (aucune écriture en base).
 */
final class Mode
{
    private static function file(): string
    {
        return App::path('storage/state/mode.json');
    }

    public static function readOnly(): bool
    {
        $f = self::file();
        if (is_file($f)) {
            $j = json_decode((string)@file_get_contents($f), true);
            if (is_array($j) && array_key_exists('read_only', $j)) {
                return (bool)$j['read_only'];
            }
        }
        return (bool)App::config('read_only', true);
    }

    /** Refuse une action qui a un effet en dehors de la base (fichiers, e-mails) quand on est en lecture seule. */
    public static function assertWritable(): void
    {
        if (self::readOnly()) {
            throw new ReadOnlyException();
        }
    }

    public static function info(): array
    {
        $f = self::file();
        $j = is_file($f) ? json_decode((string)@file_get_contents($f), true) : null;
        return [
            'read_only'  => self::readOnly(),
            'changed_at' => is_array($j) ? (string)($j['changed_at'] ?? '') : '',
            'changed_by' => is_array($j) ? (string)($j['changed_by'] ?? '') : '',
        ];
    }

    public static function set(bool $readOnly, string $by): void
    {
        $data = ['read_only' => $readOnly, 'changed_at' => date('Y-m-d H:i:s'), 'changed_by' => $by];
        $ok = @file_put_contents(self::file(), json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        if ($ok === false) {
            throw new \RuntimeException('Impossible d\'écrire storage/state/mode.json (droits du dossier storage).');
        }
        Logger::info('mode', ($readOnly ? 'lecture seule' : 'ECRITURE') . ' activé par ' . $by);
    }
}
