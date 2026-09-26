<?php
declare(strict_types=1);

namespace JC\Domain;

/**
 * Lecture des lignes Billetweb (tables billetweb_attendees / billetweb_post_attendees).
 * Reprend les règles métier de l'ancien listing.
 * Une ligne préparée par prepare() porte la clé '_raw' (JSON déjà décodé).
 */
final class Bw
{
    private const INSURANCE_WORDS = ['assurance', 'rc circuit', 'responsabilite civile'];

    public static function prepare(array $row): array
    {
        $j = json_decode((string)($row['raw_json'] ?? ''), true);
        $row['_raw'] = is_array($j) ? $j : [];
        return $row;
    }

    public static function raw(array $row): array
    {
        return $row['_raw'] ?? (is_array($j = json_decode((string)($row['raw_json'] ?? ''), true)) ? $j : []);
    }

    public static function vehicle(array $raw): string
    {
        foreach (['Marque, modèle & Couleur du véhicule', 'Marque, modèle & Couleur du vehicule', 'Véhicule', 'Vehicule'] as $k) {
            if (!isset($raw['custom'][$k])) {
                continue;
            }
            $v = trim((string)$raw['custom'][$k]);
            // Certains événements ont un champ « Véhicule » Billetweb mal configuré (réponse brute purement
            // numérique, ex. « 1 ») : une vraie description contient toujours au moins une lettre.
            if ($v !== '' && preg_match('/[a-zA-ZÀ-ÖØ-öø-ÿ]/', $v)) {
                return $v;
            }
        }
        return '';
    }

    public static function firstTime(array $raw): string
    {
        foreach (["Est-ce votre première expérience sur circuit ?", "Est-ce votre premiere experience sur circuit ?"] as $k) {
            if (isset($raw['custom'][$k])) {
                $v = Text::normalize((string)$raw['custom'][$k]);
                return in_array($v, ['oui', 'yes', '1', 'true'], true) ? 'OUI' : 'NON';
            }
        }
        return '';
    }

    public static function phone(array $raw): string
    {
        foreach (['Portable', 'Téléphone', 'Telephone'] as $k) {
            if (isset($raw['custom_order'][$k])) {
                return trim((string)$raw['custom_order'][$k]);
            }
            if (isset($raw['custom'][$k])) {
                return trim((string)$raw['custom'][$k]);
            }
        }
        return '';
    }

    public static function duration(array $row): string
    {
        return trim((string)($row['ticket'] ?? $row['post_code'] ?? ''));
    }

    public static function paymentMethod(array $raw): string
    {
        foreach (['payment_method', 'payment', 'method'] as $k) {
            if (!empty($raw[$k])) {
                return trim((string)$raw[$k]);
            }
        }
        return '';
    }

    /** Billetweb ajoute sa commission au prix public : on manipule le net revenant à Journée Circuit. */
    public static function removeFee(float $gross): float
    {
        if ($gross <= 0) {
            return 0.0;
        }
        return (float)round(max(0, ($gross - 0.29) / 1.01), 0);
    }

    public static function price(array $row): float
    {
        $raw = self::raw($row);
        foreach ([$row['price'] ?? null, $raw['price'] ?? null, $row['amount'] ?? null, $raw['amount'] ?? null] as $v) {
            $n = Text::float($v);
            if ($n > 0) {
                return self::removeFee($n);
            }
        }
        return 0.0;
    }

    public static function originalPrice(array $row): float
    {
        $raw = self::raw($row);
        foreach ([$row['original_price'] ?? null, $raw['original_price'] ?? null] as $v) {
            $n = Text::float($v);
            if ($n > 0) {
                return self::removeFee($n);
            }
        }
        return self::price($row);
    }

    public static function isInsuranceTicket(array $row): bool
    {
        $txt = (string)($row['ticket'] ?? '') . ' ' . (string)($row['category'] ?? '') . ' ' . (string)($row['raw_json'] ?? '');
        return Text::contains($txt, self::INSURANCE_WORDS);
    }

    /** Rôle d'un billet : pilot / passenger / supplemental_driver / insurance. */
    public static function role(array $row): string
    {
        $code = strtoupper(trim((string)($row['post_code'] ?? '')));
        if (in_array($code, ['ASSURANCE', 'ASS', 'RC'], true)) {
            return 'insurance';
        }
        if (in_array($code, ['PAP', 'PA', 'ACP', 'ACNP'], true)) {
            return 'passenger';
        }
        if (in_array($code, ['PS', 'PSP', 'PSF', 'PSFP'], true)) {
            return 'supplemental_driver';
        }
        $txt = (string)($row['ticket'] ?? '') . ' ' . (string)($row['category'] ?? '') . ' ' . (string)($row['raw_json'] ?? '');
        if (Text::contains($txt, self::INSURANCE_WORDS)) {
            return 'insurance';
        }
        if (Text::contains($txt, ['passager', 'passenger', 'accompagnant'])) {
            return 'passenger';
        }
        if (Text::contains($txt, ['pilote supplementaire', 'pilote supplémentaire', 'second pilote'])) {
            return 'supplemental_driver';
        }
        return 'pilot';
    }

    // --- Identité d'un passager / pilote supplémentaire saisie dans les champs personnalisés ----------

    /** Valeur d'un champ Prénom (137402) ou Nom (137405) dans les champs personnalisés. */
    public static function customIdentity(array $raw, string $kind): string
    {
        $wantedId = $kind === 'firstname' ? '137402' : '137405';
        $wantedLabel = $kind === 'firstname' ? 'prenom' : 'nom';

        $extract = function ($node) use (&$extract, $wantedId, $wantedLabel): string {
            if (!is_array($node)) {
                return '';
            }
            foreach ($node as $k => $v) {
                if (!is_scalar($v)) {
                    continue;
                }
                $key = Text::normalize((string)$k);
                if (str_contains((string)$k, $wantedId) || $key === $wantedLabel) {
                    $val = trim((string)$v);
                    if ($val !== '') {
                        return $val;
                    }
                }
            }
            $id = '';
            foreach (['id', 'field_id', 'fieldId', 'form_id', 'formId'] as $k) {
                if (isset($node[$k]) && is_scalar($node[$k])) {
                    $id = (string)$node[$k];
                    break;
                }
            }
            $label = '';
            foreach (['label', 'question', 'title', 'field', 'key'] as $k) {
                if (isset($node[$k]) && is_scalar($node[$k])) {
                    $label = Text::normalize((string)$node[$k]);
                    break;
                }
            }
            $isWanted = ($id !== '' && str_contains($id, $wantedId)) || $label === $wantedLabel || str_contains($label, $wantedId);
            if ($isWanted) {
                foreach (['value', 'answer', 'response', 'text'] as $k) {
                    if (isset($node[$k]) && is_scalar($node[$k])) {
                        $val = trim((string)$node[$k]);
                        if ($val !== '') {
                            return $val;
                        }
                    }
                }
            }
            foreach ($node as $v) {
                if (is_array($v)) {
                    $x = $extract($v);
                    if ($x !== '') {
                        return $x;
                    }
                }
            }
            return '';
        };

        foreach (['custom', 'custom_fields', 'answers', 'fields', 'form'] as $k) {
            if (isset($raw[$k]) && is_array($raw[$k])) {
                $v = $extract($raw[$k]);
                if ($v !== '') {
                    return $v;
                }
            }
        }
        return '';
    }

    /** Identité d'une ligne : pour passager / pilote supplémentaire, on préfère le couple Nom + Prénom saisi. */
    public static function identity(array $row, string $role): array
    {
        $firstname = trim((string)($row['firstname'] ?? ''));
        $lastname = trim((string)($row['name'] ?? ''));
        if (!in_array($role, ['passenger', 'supplemental_driver'], true)) {
            return ['firstname' => $firstname, 'lastname' => $lastname, 'custom' => false];
        }
        $raw = self::raw($row);
        $cf = self::customIdentity($raw, 'firstname');
        $cl = self::customIdentity($raw, 'lastname');
        if ($cf !== '' && $cl !== '') {
            return ['firstname' => $cf, 'lastname' => $cl, 'custom' => true];
        }
        return ['firstname' => $firstname, 'lastname' => $lastname, 'custom' => false];
    }

    // --- Identité des billets d'option (post-inscription) ---------------------------------------------

    private static function flat(array $data, string $prefix = ''): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . (string)$k;
            if (is_array($v)) {
                $out = array_merge($out, self::flat($v, $key));
            } elseif (is_scalar($v) && trim((string)$v) !== '') {
                $out[$key] = trim((string)$v);
            }
        }
        return $out;
    }

    private static function labelPairs(array $node, array &$out): void
    {
        $label = '';
        $value = '';
        foreach (['label', 'name', 'title', 'question', 'field', 'key', 'libelle', 'libellé'] as $k) {
            if (isset($node[$k]) && is_scalar($node[$k]) && trim((string)$node[$k]) !== '') {
                $label = trim((string)$node[$k]);
                break;
            }
        }
        foreach (['value', 'answer', 'val', 'text', 'response', 'reponse', 'réponse'] as $k) {
            if (isset($node[$k]) && is_scalar($node[$k]) && trim((string)$node[$k]) !== '') {
                $value = trim((string)$node[$k]);
                break;
            }
        }
        if ($label !== '' && $value !== '') {
            $out[] = [$label, $value];
        }
        foreach ($node as $v) {
            if (is_array($v)) {
                self::labelPairs($v, $out);
            }
        }
    }

    private static function contextScore(string $label, string $postCode): int
    {
        $k = Text::key($label);
        $pc = mb_strtoupper(trim($postCode));
        $score = 0;
        if (in_array($pc, ['PA', 'PAP'], true)) {
            if (str_contains($k, 'passager') || str_contains($k, 'passenger')) {
                $score += 30;
            }
            if (str_contains($k, 'supplement') || str_contains($k, 'pilote')) {
                $score -= 12;
            }
        } elseif (in_array($pc, ['PSF', 'PSFP'], true)) {
            if (str_contains($k, 'supplement')) {
                $score += 22;
            }
            if (str_contains($k, 'femme') || str_contains($k, 'feminin') || str_contains($k, 'female')) {
                $score += 24;
            }
            if (str_contains($k, 'passager')) {
                $score -= 15;
            }
        } elseif (in_array($pc, ['PS', 'PSP'], true)) {
            if (str_contains($k, 'supplement')) {
                $score += 24;
            }
            if (str_contains($k, 'pilote') || str_contains($k, 'driver')) {
                $score += 8;
            }
            if (str_contains($k, 'femme') || str_contains($k, 'feminin') || str_contains($k, 'female')) {
                $score -= 18;
            }
            if (str_contains($k, 'passager')) {
                $score -= 15;
            }
        }
        if (str_contains($k, 'participant') || str_contains($k, 'identite') || str_contains($k, 'identity')) {
            $score += 6;
        }
        if (str_contains($k, 'order') || str_contains($k, 'buyer') || str_contains($k, 'acheteur') || str_contains($k, 'commande')) {
            $score -= 40;
        }
        if (str_contains($k, 'email') || str_contains($k, 'mail') || str_contains($k, 'telephone') || str_contains($k, 'phone')) {
            $score -= 40;
        }
        return $score;
    }

    /** @return array{0:string,1:string} [prénom, nom] à partir d'un champ « Prénom Nom ». */
    private static function splitIdentity(string $value): array
    {
        $v = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
        if ($v === '' || str_contains($v, '@') || preg_match('/^\+?[0-9 .()-]{6,}$/', $v)) {
            return ['', ''];
        }
        $parts = preg_split('/\s+/u', $v) ?: [];
        if (count($parts) < 2) {
            return ['', ''];
        }
        $last = array_pop($parts);
        return [trim(implode(' ', $parts)), trim($last)];
    }

    /** @return array{first:string,last:string,source:string} */
    public static function postIdentity(array $att, string $postCode): array
    {
        $raw = self::raw($att);
        if (!$raw) {
            return ['first' => '', 'last' => '', 'source' => ''];
        }
        $pairs = [];
        self::labelPairs($raw, $pairs);
        foreach (self::flat($raw) as $k => $v) {
            $pairs[] = [$k, $v];
        }
        $first = [];
        $last = [];
        $combined = [];
        foreach ($pairs as [$label, $value]) {
            $nk = Text::key((string)$label);
            $score = self::contextScore((string)$label, $postCode);
            if ($score < 0) {
                continue;
            }
            if (preg_match('/(^| )prenom( |$)|firstname|first name/', $nk)) {
                $first[] = [$score + 20, (string)$value, (string)$label];
            }
            if (preg_match('/(^| )nom( |$)|lastname|last name|surname/', $nk) && !str_contains($nk, 'prenom')) {
                $last[] = [$score + 20, (string)$value, (string)$label];
            }
            if ((str_contains($nk, 'identite') || str_contains($nk, 'identity') || str_contains($nk, 'nom prenom') || str_contains($nk, 'nom et prenom') || $score >= 20)
                && !preg_match('/prenom|firstname|last name|lastname|surname|(^| )nom( |$)/', $nk)) {
                [$fn, $ln] = self::splitIdentity((string)$value);
                if ($fn !== '' && $ln !== '') {
                    $combined[] = [$score + 10, $fn, $ln, (string)$label];
                }
            }
        }
        usort($first, fn($a, $b) => $b[0] <=> $a[0]);
        usort($last, fn($a, $b) => $b[0] <=> $a[0]);
        usort($combined, fn($a, $b) => $b[0] <=> $a[0]);
        $fn = trim((string)($first[0][1] ?? ''));
        $ln = trim((string)($last[0][1] ?? ''));
        $src = trim((string)($first[0][2] ?? $last[0][2] ?? ''));
        if (($fn === '' || $ln === '') && !empty($combined[0])) {
            $fn = (string)$combined[0][1];
            $ln = (string)$combined[0][2];
            $src = (string)$combined[0][3];
        }
        if ($fn === '' || $ln === '') {
            return ['first' => '', 'last' => '', 'source' => ''];
        }
        // Si l'identité trouvée est celle de l'acheteur, ce n'est pas celle de la personne de l'option.
        if (Text::key($fn) === Text::key((string)($att['firstname'] ?? '')) && Text::key($ln) === Text::key((string)($att['name'] ?? ''))) {
            return ['first' => '', 'last' => '', 'source' => ''];
        }
        return ['first' => $fn, 'last' => $ln, 'source' => $src];
    }
}
