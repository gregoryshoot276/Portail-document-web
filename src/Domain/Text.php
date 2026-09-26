<?php
declare(strict_types=1);

namespace JC\Domain;

/** Normalisation de textes (sans dépendre d'iconv/intl : résultat identique sur tous les hébergements). */
final class Text
{
    private const ACCENTS = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'æ' => 'ae',
        'ç' => 'c', 'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ñ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'œ' => 'oe',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss',
        "\u{2019}" => "'", "\u{2013}" => '-', "\u{2014}" => '-',
    ];

    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /** minuscules, sans accents, espaces uniques. */
    public static function normalize(string $s): string
    {
        $s = strtr(self::lower(trim($s)), self::ACCENTS);
        return preg_replace('/\s+/u', ' ', $s) ?? $s;
    }

    /** minuscules, sans accents, tout ce qui n'est pas [a-z0-9] devient un espace. */
    public static function key(string $s): string
    {
        $s = strtr(self::lower($s), self::ACCENTS);
        return preg_replace('/[^a-z0-9]+/', ' ', trim($s)) ?? '';
    }

    /** Comme key() mais sans aucun séparateur : sert à comparer des noms. */
    public static function compact(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', strtr(self::lower(trim($s)), self::ACCENTS)) ?? '';
    }

    public static function contains(string $haystack, array $needles): bool
    {
        $h = self::normalize($haystack);
        foreach ($needles as $n) {
            $n = self::normalize((string)$n);
            if ($n !== '' && str_contains($h, $n)) {
                return true;
            }
        }
        return false;
    }

    public static function float(mixed $v): float
    {
        if (is_int($v) || is_float($v)) {
            return (float)$v;
        }
        $s = trim((string)$v);
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace(["\u{00A0}", "\u{202F}", ' ', '€'], '', $s);
        $s = str_replace(',', '.', $s);
        return is_numeric($s) ? (float)$s : 0.0;
    }
}
