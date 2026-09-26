<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/** Tarifs du listing : une valeur explicite par journée et par code (table listing_tariffs). */
final class Pricing
{
    public const CODES = [
        'J'    => 'Journée',
        'M'    => 'Matin',
        'A'    => 'Après-midi',
        'PS'   => 'Pilote suppl. inscription',
        'PSP'  => 'Pilote suppl. post-inscription',
        'PSF'  => 'Pilote suppl. femme inscription',
        'PSFP' => 'Pilote suppl. femme post-inscription',
        'PA'   => 'Passager inscription',
        'PAP'  => 'Passager post-inscription',
        'AC'   => 'Accompagnant inscription',
        'ACP'  => 'Accompagnant post-inscription',
        'O'    => 'Assurance à l’inscription',
        'R'    => 'Assurance post-inscription',
    ];

    public static function normalizeCode(string $code): string
    {
        $c = mb_strtoupper(trim($code), 'UTF-8');
        $map = ['JOURNÉE' => 'J', 'JOURNEE' => 'J', 'MATIN' => 'M', 'APRÈS-MIDI' => 'A', 'APRES-MIDI' => 'A', 'APRÈS MIDI' => 'A', 'APRES MIDI' => 'A'];
        return $map[$c] ?? $c;
    }

    public static function codeFromDuration(string $duration, string $postCode = ''): string
    {
        $pc = self::normalizeCode($postCode);
        if (in_array($pc, ['PS', 'PSP', 'PSF', 'PSFP', 'PA', 'PAP', 'AC', 'ACP'], true)) {
            return $pc;
        }
        $d = Text::normalize($duration);
        if (str_contains($d, 'journee')) {
            return 'J';
        }
        if (str_contains($d, 'matin')) {
            return 'M';
        }
        if (str_contains($d, 'apres')) {
            return 'A';
        }
        if (str_contains($d, 'pilote') && str_contains($d, 'supp')) {
            return str_contains($d, 'femme') ? 'PSF' : 'PS';
        }
        if (str_contains($d, 'passager')) {
            return 'PA';
        }
        if (str_contains($d, 'accompagn')) {
            return 'AC';
        }
        return '';
    }

    /** @param array<string,float> $map code => montant (tarifs configurés pour la journée) */
    public static function total(array $map, string $duration, string $insuranceCode = '', string $postCode = ''): ?float
    {
        $code = self::codeFromDuration($duration, $postCode);
        $base = $code !== '' ? ($map[$code] ?? null) : null;
        if ($base === null) {
            return null;
        }
        $ins = self::normalizeCode($insuranceCode);
        if (in_array($ins, ['O', 'R'], true) && isset($map[$ins])) {
            $base += $map[$ins];
        }
        return (float)$base;
    }

    /** @return array<string,float> */
    public static function forEvent(Db $db, int $eventId): array
    {
        $out = [];
        foreach ($db->all('SELECT code,amount FROM listing_tariffs WHERE event_id=? AND amount IS NOT NULL', [$eventId]) as $r) {
            $out[(string)$r['code']] = (float)$r['amount'];
        }
        return $out;
    }
}
