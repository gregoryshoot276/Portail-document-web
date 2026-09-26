<?php
declare(strict_types=1);

namespace JC\Domain;

/** Formats de participation : Journée, Matin, Après-midi, Passager, Pilote supplémentaire... */
final class Formats
{
    public const ROLLING = ['Journée', 'Matin', 'Après-midi'];
    public const OPTION_CODES = ['PA', 'PAP', 'PS', 'PSP', 'PSF', 'PSFP', 'AC', 'ACP'];

    public static function canonical(string $label): string
    {
        $raw = trim($label);
        if ($raw === '') {
            return '';
        }
        $n = str_replace(['–', '—', '_'], '-', Text::normalize($raw));
        if (str_contains($n, 'assurance') || $n === 'ass' || $n === 'rc') {
            return 'Assurance';
        }
        // Le pilote supplémentaire femme a un tarif distinct : cette règle doit passer AVANT la règle générale.
        if (str_contains($n, 'pilote') && (str_contains($n, 'supplement') || str_contains($n, 'second'))
            && (str_contains($n, 'femme') || str_contains($n, 'female') || str_contains($n, 'feminin'))) {
            return 'Pilote supplémentaire femme';
        }
        if (str_contains($n, 'pilote') && (str_contains($n, 'supplement') || str_contains($n, 'second'))) {
            return 'Pilote supplémentaire';
        }
        if (str_contains($n, 'passager') || str_contains($n, 'passenger')) {
            return 'Passager';
        }
        if (str_contains($n, 'accompagn')) {
            return 'Accompagnant';
        }
        if (str_contains($n, 'apres') && str_contains($n, 'midi')) {
            return 'Après-midi';
        }
        if ($n === 'aprem' || str_contains($n, 'aprem')) {
            return 'Après-midi';
        }
        if (str_contains($n, 'matin')) {
            return 'Matin';
        }
        if (str_contains($n, 'journee') || $n === 'jour' || $n === 'j') {
            return 'Journée';
        }
        if (str_contains($n, 'comment')) {
            return 'Commentaire';
        }
        return $raw;
    }

    public static function short(string $format): string
    {
        return match (self::canonical($format)) {
            'Journée' => 'J',
            'Matin' => 'M',
            'Après-midi' => 'A',
            'Passager' => 'P',
            'Pilote supplémentaire' => 'PS',
            'Pilote supplémentaire femme' => 'PSF',
            'Accompagnant' => 'ACC',
            default => $format !== '' ? $format : '—',
        };
    }

    public static function isRolling(string $canonical): bool
    {
        return in_array($canonical, self::ROLLING, true);
    }

    /** Passager / pilote supplémentaire / accompagnant : ce sont des options, pas des inscriptions principales. */
    public static function isOption(string $duration): bool
    {
        return in_array(self::canonical($duration), ['Passager', 'Pilote supplémentaire', 'Pilote supplémentaire femme', 'Accompagnant'], true);
    }

    /** Code d'option (PA, PS, PSF...) d'un billet post-inscription ou d'un billet d'option. */
    public static function optionCode(array $att, string $fallbackDuration = ''): string
    {
        $pc = mb_strtoupper(trim((string)($att['post_code'] ?? '')));
        if (in_array($pc, self::OPTION_CODES, true)) {
            return $pc;
        }
        $label = trim((string)($att['ticket'] ?? '') . ' ' . (string)($att['category'] ?? ''));
        if ($label === '') {
            $raw = $att['_raw'] ?? json_decode((string)($att['raw_json'] ?? ''), true);
            if (is_array($raw)) {
                $label = trim((string)($raw['ticket'] ?? '') . ' ' . (string)($raw['category'] ?? ''));
            }
        }
        $n = Text::key($label . ' ' . $fallbackDuration);
        if (str_contains($n, 'pilote supplementaire femme') || str_contains($n, 'pilotes supplementaires femme') || str_contains($n, 'supplementaire femme')) {
            return 'PSF';
        }
        if (str_contains($n, 'pilote supplementaire') || str_contains($n, 'pilotes supplementaires') || str_contains($n, 'supplemental driver')) {
            return 'PS';
        }
        if (str_contains($n, 'passager') || str_contains($n, 'passenger')) {
            return 'PA';
        }
        if (str_contains($n, 'accompagn')) {
            return 'AC';
        }
        return '';
    }

    public static function postFormat(string $postCode, string $fallback): string
    {
        return match (mb_strtoupper(trim($postCode))) {
            'PA', 'PAP' => 'Passager',
            'PS', 'PSP' => 'Pilote supplémentaire',
            'PSF', 'PSFP' => 'Pilote supplémentaire femme',
            'AC', 'ACP' => 'Accompagnant',
            default => $fallback,
        };
    }

    public static function displayCode(string $duration, string $optionCode): string
    {
        if (in_array($optionCode, self::OPTION_CODES, true)) {
            return $optionCode;
        }
        return match (self::canonical($duration)) {
            'Journée' => 'J', 'Matin' => 'M', 'Après-midi' => 'A', 'Passager' => 'PA',
            'Pilote supplémentaire' => 'PS', 'Pilote supplémentaire femme' => 'PSF', 'Accompagnant' => 'AC',
            default => self::short($duration),
        };
    }

    /** Formats proposés dans les listes déroulantes du listing. */
    public static function choices(array $extra = []): array
    {
        $out = ['Journée', 'Matin', 'Après-midi', 'Passager', 'Pilote supplémentaire', 'Pilote supplémentaire femme', 'Accompagnant'];
        foreach ($extra as $v) {
            $c = self::canonical((string)$v);
            if ($c !== '' && !in_array($c, $out, true) && !in_array($c, ['Assurance', 'Commentaire'], true)) {
                $out[] = $c;
            }
        }
        return $out;
    }
}
