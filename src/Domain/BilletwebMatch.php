<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/**
 * Rapprochement d'un participant (nom, prénom, e-mail) avec son billet Billetweb.
 * Règles reprises de l'ancien portail (V1.4.6 / V2.1.9) : l'e-mail seul ne suffit jamais à reconnaître quelqu'un.
 */
final class BilletwebMatch
{
    /** Minuscules sans accents ; ne garde que a-z 0-9 @ . */
    public static function norm(string $s): string
    {
        $t = Text::normalize($s);
        return trim(preg_replace('/[^a-z0-9@.]+/', ' ', $t) ?? '');
    }

    public static function nameKey(string $s): string
    {
        return preg_replace('/[^a-z0-9]+/', '', self::norm($s)) ?? '';
    }

    public static function nameSimilarity(string $a, string $b): float
    {
        $a = self::nameKey($a);
        $b = self::nameKey($b);
        if ($a === '' || $b === '') {
            return 0.0;
        }
        if ($a === $b) {
            return 1.0;
        }
        $m = max(strlen($a), strlen($b));
        return $m ? max(0.0, 1.0 - (levenshtein($a, $b) / $m)) : 1.0;
    }

    public static function maskEmail(string $email): string
    {
        $email = trim($email);
        if (!str_contains($email, '@')) {
            return 'adresse masquée';
        }
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
        $parts = explode('.', $domain);
        $host = $parts[0] ?? '';
        $tld = count($parts) > 1 ? '.' . end($parts) : '';
        $ll = mb_strlen($local, 'UTF-8');
        $hl = mb_strlen($host, 'UTF-8');
        if ($ll <= 4) {
            $lh = mb_substr($local, 0, 1, 'UTF-8') . str_repeat('•', max(1, $ll - 1));
        } else {
            $lh = mb_substr($local, 0, min(5, max(2, (int)floor($ll / 2))), 'UTF-8') . '••••';
        }
        if ($hl <= 4) {
            $hh = mb_substr($host, 0, 1, 'UTF-8') . str_repeat('•', max(1, $hl - 1));
        } else {
            $hh = mb_substr($host, 0, min(4, max(2, (int)floor($hl / 2))), 'UTF-8') . '••••';
        }
        return $lh . '@' . $hh . $tld;
    }

    private static function tokens(string $s): array
    {
        return array_values(array_unique(preg_split('/[^a-z0-9]+/', self::norm($s), -1, PREG_SPLIT_NO_EMPTY) ?: []));
    }

    public static function componentSimilarity(string $a, string $b): float
    {
        $sa = self::nameSimilarity($a, $b);
        $ta = self::tokens($a);
        $tb = self::tokens($b);
        if (!$ta || !$tb) {
            return $sa;
        }
        $common = array_intersect($ta, $tb);
        if ($common) {
            // « Leana » <-> « Leana Margot », « Jean Pierre » <-> « Jean-Pierre »
            $contain = count($common) / max(1, min(count($ta), count($tb)));
            if ($contain >= 1.0) {
                $sa = max($sa, .97);
            } elseif ($contain >= .5) {
                $sa = max($sa, .90);
            }
        }
        $ca = implode('', $ta);
        $cb = implode('', $tb);
        if ($ca !== '' && $cb !== '' && ($ca === $cb || str_contains($ca, $cb) || str_contains($cb, $ca))) {
            $sa = max($sa, .97);
        }
        return min(1.0, $sa);
    }

    public static function nameCandidate(array $r, string $nom, string $prenom): bool
    {
        return self::componentSimilarity((string)($r['name'] ?? ''), $nom) >= .94 && self::componentSimilarity((string)($r['firstname'] ?? ''), $prenom) >= .90;
    }

    public static function score(array $r, string $email, string $nom, string $prenom): float
    {
        $mail = self::norm((string)($r['email'] ?? '')) === self::norm($email) && trim($email) !== '';
        $sn = self::componentSimilarity((string)($r['name'] ?? ''), $nom);
        $sp = self::componentSimilarity((string)($r['firstname'] ?? ''), $prenom);
        $score = ($mail ? 50 : 0) + 30 * $sn + 20 * $sp;
        if (!$mail && $sn < .90) {
            $score -= 25;
        }
        if (!$mail && $sp < .88) {
            $score -= 20;
        }
        return max(0, min(100, $score));
    }

    public static function keywords(?string $s, array $fallback): array
    {
        $a = array_filter(array_map('trim', explode(',', (string)$s)));
        return $a ?: $fallback;
    }

    public static function containsKeyword(string $hay, array $keywords): bool
    {
        $h = self::norm($hay);
        foreach ($keywords as $k) {
            $k = self::norm((string)$k);
            if ($k !== '' && str_contains($h, $k)) {
                return true;
            }
        }
        return false;
    }

    /** Code d'un billet post-inscription : ASSURANCE, PSFP, PSP, PAP. */
    public static function postTicketCode(string $ticket): string
    {
        $t = self::norm($ticket);
        if (str_contains($t, 'assurance') || str_contains($t, 'responsabilite civile') || preg_match('/\brc\b/', $t)) {
            return 'ASSURANCE';
        }
        if (str_contains($t, 'pilote supplementaire') && (str_contains($t, 'femme') || str_contains($t, 'feminin'))) {
            return 'PSFP';
        }
        if (str_contains($t, 'pilote supplementaire')) {
            return 'PSP';
        }
        if (str_contains($t, 'passager') || str_contains($t, 'passenger')) {
            return 'PAP';
        }
        return '';
    }

    public static function rowRole(array $r, array $passengerKeywords): string
    {
        $code = (string)($r['post_code'] ?? '');
        if ($code === '') {
            $code = self::postTicketCode((string)($r['ticket'] ?? ''));
            if ($code === '') {
                $raw = json_decode((string)($r['raw_json'] ?? ''), true);
                if (is_array($raw)) {
                    $code = self::postTicketCode((string)($raw['ticket'] ?? ''));
                }
            }
        }
        if ($code === 'PAP') {
            return 'passenger';
        }
        if (in_array($code, ['PSP', 'PSFP', 'PS', 'PSF'], true)) {
            return 'supplemental_driver';
        }
        if ($code === 'ASSURANCE') {
            return 'insurance';
        }
        $txt = (string)($r['ticket'] ?? '') . ' ' . (string)($r['category'] ?? '') . ' ' . (string)($r['raw_json'] ?? '');
        return self::containsKeyword($txt, $passengerKeywords) ? 'passenger' : 'pilot';
    }

    public static function typeLabel(string $t): string
    {
        return match ($t) {
            'passenger' => 'Passager',
            'supplemental_driver' => 'Pilote supplémentaire',
            default => 'Pilote',
        };
    }

    private static function best(array $rows, string $email, string $nom, string $prenom, callable $accept): array
    {
        $best = null;
        $bestScore = -1.0;
        $second = -1.0;
        foreach ($rows as $r) {
            if (!$accept($r)) {
                continue;
            }
            $score = self::score($r, $email, $nom, $prenom);
            if ($score > $bestScore) {
                $second = $bestScore;
                $bestScore = $score;
                $best = $r;
            } elseif ($score > $second) {
                $second = $score;
            }
        }
        if (!$best) {
            return ['row' => null, 'score' => 0.0];
        }
        $mail = self::norm((string)($best['email'] ?? '')) === self::norm($email) && trim($email) !== '';
        $sn = self::nameSimilarity((string)($best['name'] ?? ''), $nom);
        $sp = self::nameSimilarity((string)($best['firstname'] ?? ''), $prenom);
        // L'e-mail seul (ou e-mail + même nom de famille) ne suffit jamais : un proche partage souvent la même adresse.
        $namesOk = ($sn >= .75 && $sp >= .65);
        $safe = $namesOk && (($bestScore >= 80 && ($bestScore - $second) >= 6) || ($mail && $bestScore >= 78));
        return ['row' => $safe ? $best : null, 'score' => max(0.0, $bestScore)];
    }

    private static function strongest(array $rows, string $email, string $nom, string $prenom): array
    {
        $best = null;
        $score = -1.0;
        foreach ($rows as $r) {
            if (self::nameSimilarity((string)($r['name'] ?? ''), $nom) < .75 || self::nameSimilarity((string)($r['firstname'] ?? ''), $prenom) < .65) {
                continue;
            }
            $x = self::score($r, $email, $nom, $prenom);
            if ($x > $score) {
                $score = $x;
                $best = $r;
            }
        }
        return ['row' => $best, 'score' => max(0.0, $score)];
    }

    /** Charge les billets de la journée : [billetweb_attendees, billetweb_post_attendees]. */
    public static function load(Db $db, array $event): array
    {
        $rows = [];
        if (!empty($event['billetweb_event_id'])) {
            $rows = $db->all('SELECT * FROM billetweb_attendees WHERE event_id=? AND disabled=0 AND order_paid=1', [(int)$event['id']]);
        }
        $post = [];
        $d = (string)($event['event_date'] ?? '');
        if ($d !== '') {
            $post = $db->all(
                "SELECT * FROM billetweb_post_attendees WHERE disabled=0 AND order_paid=1 AND (session_date=? OR DATE(session_start)=? OR DATE(JSON_UNQUOTE(JSON_EXTRACT(raw_json,'$.session_start')))=?)",
                [$d, $d, $d]
            );
        }
        return [$rows, $post];
    }

    public static function match(Db $db, array $event, string $type, string $email, string $nom, string $prenom, string $buyerRef = '', string $confirmedAttendeeId = ''): array
    {
        [$rows, $post] = self::load($db, $event);
        return self::matchRows($event, $rows, $post, $type, $email, $nom, $prenom, $buyerRef, $confirmedAttendeeId);
    }

    /** Cœur du rapprochement, sans accès base (testable). */
    public static function matchRows(array $event, array $rows, array $postRows, string $type, string $email, string $nom, string $prenom, string $buyerRef = '', string $confirmedAttendeeId = ''): array
    {
        $pkw = self::keywords($event['passenger_ticket_keywords'] ?? '', ['passager', 'passenger', 'bracelet passager']);
        $ikw = self::keywords($event['insurance_keywords'] ?? '', ['assurance', 'rc circuit', 'responsabilite civile']);

        if (!$rows && !$postRows) {
            return ['status' => 'unknown', 'attendee' => null, 'insurance_found' => false, 'insurance_source' => 'none', 'score' => 0, 'message' => 'Billetweb n a pas encore ete synchronise pour cet evenement.'];
        }

        $main = self::best($rows, $email, $nom, $prenom, function (array $r) use ($type, $pkw, $ikw): bool {
            $txt = (string)($r['ticket'] ?? '') . ' ' . (string)($r['category'] ?? '') . ' ' . (string)($r['raw_json'] ?? '');
            if ($type === 'passenger') {
                return self::containsKeyword($txt, $pkw);
            }
            $isSupp = self::containsKeyword($txt, ['pilote supplementaire', 'pilote supplémentaire', 'second pilote']);
            if ($type === 'supplemental_driver') {
                return $isSupp && !self::containsKeyword($txt, $ikw);
            }
            return !$isSupp && !self::containsKeyword($txt, $pkw) && !self::containsKeyword($txt, $ikw);
        });
        $post = self::best($postRows, $email, $nom, $prenom, fn(array $r): bool => self::rowRole($r, $pkw) === $type);

        // Confirmation explicite d'une inscription au même nom/prénom mais avec d'autres coordonnées.
        if ($confirmedAttendeeId !== '') {
            foreach (array_merge($rows, $postRows) as $r) {
                $rid = (string)($r['attendee_id'] ?? $r['id'] ?? '');
                if ($rid !== $confirmedAttendeeId || !self::nameCandidate($r, $nom, $prenom)) {
                    continue;
                }
                if (self::rowRole($r, $pkw) !== $type) {
                    continue;
                }
                $main = ['row' => $r, 'score' => 95.0];
                $post = ['row' => null, 'score' => 0.0];
                break;
            }
        }

        $direct = null;
        $source = 'none';
        $score = 0.0;
        if ($main['row'] && (!$post['row'] || $main['score'] >= $post['score'])) {
            $direct = $main['row'];
            $source = 'initial';
            $score = $main['score'];
        } elseif ($post['row']) {
            $direct = $post['row'];
            $source = 'post';
            $score = $post['score'];
        }

        $insurance = false;
        $insuranceSource = 'none';
        if ($type === 'pilot') {
            if ($source === 'initial' && $direct) {
                foreach ($rows as $r) {
                    if ((string)($r['order_id'] ?? '') === (string)($direct['order_id'] ?? '')
                        && self::containsKeyword((string)($r['ticket'] ?? '') . ' ' . (string)($r['category'] ?? '') . ' ' . (string)($r['raw_json'] ?? ''), $ikw)) {
                        $insurance = true;
                        $insuranceSource = 'initial';
                        break;
                    }
                }
            }
            foreach ($postRows as $r) {
                if ((string)($r['post_code'] ?? '') !== 'ASSURANCE') {
                    continue;
                }
                $sameOrder = $direct && !empty($direct['order_id']) && (string)$r['order_id'] === (string)$direct['order_id'];
                if ($sameOrder || self::score($r, $email, $nom, $prenom) >= 78) {
                    $insurance = true;
                    $insuranceSource = 'post';
                    break;
                }
            }
        }

        // Aucune correspondance compatible : une identité forte dans une AUTRE catégorie est signalée.
        if (!$direct) {
            $wrongCandidates = [];
            foreach (array_merge($rows, $postRows) as $r) {
                $role = self::rowRole($r, $pkw);
                if ($role === 'insurance' || $role === $type) {
                    continue;
                }
                $wrongCandidates[] = $r;
            }
            $wrong = self::strongest($wrongCandidates, $email, $nom, $prenom);
            if ($wrong['row'] && $wrong['score'] >= 78) {
                $wr = $wrong['row'];
                $foundRole = self::rowRole($wr, $pkw);
                $ticketLabel = trim((string)($wr['ticket'] ?? ''));
                $msg = 'Participant retrouvé, mais catégorie incompatible : billet ' . self::typeLabel($foundRole);
                if ($ticketLabel !== '') {
                    $msg .= ' « ' . $ticketLabel . ' »';
                }
                $msg .= '. Vous avez sélectionné ' . self::typeLabel($type) . '. Vérifiez votre statut avant de poursuivre.';
                return ['status' => 'wrong_type', 'attendee' => null, 'insurance_found' => $insurance, 'insurance_source' => $insuranceSource, 'score' => $wrong['score'], 'message' => $msg, 'wrong_type_ticket' => $ticketLabel, 'wrong_type_role' => $foundRole];
            }
        }

        if (!$direct) {
            $candidates = [];
            foreach (array_merge($rows, $postRows) as $r) {
                if (!self::nameCandidate($r, $nom, $prenom) || self::rowRole($r, $pkw) !== $type) {
                    continue;
                }
                $rid = (string)($r['attendee_id'] ?? $r['id'] ?? '');
                if ($rid === '') {
                    continue;
                }
                $candidates[$rid] = $r;
            }
            if (count($candidates) === 1) {
                $cand = array_values($candidates)[0];
                return [
                    'status' => 'confirm_needed', 'attendee' => null, 'insurance_found' => $insurance, 'insurance_source' => $insuranceSource,
                    'score' => max($main['score'], $post['score']),
                    'message' => 'Une inscription correspondant à votre nom et prénom a été trouvée avec d’autres coordonnées.',
                    'candidate_id' => (string)($cand['attendee_id'] ?? $cand['id'] ?? ''),
                    'candidate_email_hint' => self::maskEmail((string)($cand['email'] ?? $cand['order_email'] ?? '')),
                ];
            }
        }

        if ($type === 'pilot') {
            if ($direct) {
                $label = $source === 'post' ? 'Inscription post-inscription retrouvée' : 'Inscription Billetweb retrouvée';
                return ['status' => 'found', 'attendee' => $direct, 'insurance_found' => $insurance, 'insurance_source' => $insuranceSource, 'score' => $score, 'message' => $label . ' (correspondance ' . round($score) . '%).'];
            }
            return ['status' => 'not_found', 'attendee' => null, 'insurance_found' => $insurance, 'insurance_source' => $insuranceSource, 'score' => max($main['score'], $post['score']), 'message' => 'Inscription Billetweb non retrouvée automatiquement.'];
        }

        if ($direct) {
            return ['status' => 'found', 'attendee' => $direct, 'insurance_found' => false, 'insurance_source' => 'none', 'score' => $score, 'message' => $source === 'post' ? 'Billet passager post-inscription retrouvé.' : 'Billet passager retrouvé.'];
        }

        $br = self::norm($buyerRef);
        if ($br !== '') {
            foreach (array_merge($rows, $postRows) as $r) {
                $code = (string)($r['post_code'] ?? '');
                $txt = (string)($r['ticket'] ?? '') . ' ' . (string)($r['category'] ?? '') . ' ' . (string)($r['raw_json'] ?? '');
                $buyer = self::norm((string)($r['order_email'] ?? '') . ' ' . (string)($r['order_name'] ?? '') . ' ' . (string)($r['order_firstname'] ?? ''));
                if (($code === 'PAP' || self::containsKeyword($txt, $pkw)) && str_contains($buyer, $br)) {
                    return ['status' => 'unassigned', 'attendee' => $r, 'insurance_found' => false, 'insurance_source' => 'none', 'score' => 0, 'message' => 'Un billet passager a été trouvé sur la commande de l acheteur indiqué, sans correspondance nominative certaine.'];
                }
            }
        }
        return ['status' => 'not_found', 'attendee' => null, 'insurance_found' => false, 'insurance_source' => 'none', 'score' => max($main['score'], $post['score']), 'message' => 'Aucun billet passager nominatif n a été retrouvé. Si le pilote principal a acheté plusieurs billets sans renseigner les noms, vous pouvez poursuivre.'];
    }
}
