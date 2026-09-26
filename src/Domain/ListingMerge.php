<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Audit;
use JC\Core\Db;

/**
 * Fusion de deux lignes du listing (doublons). Une fusion est une vérité persistante :
 * une synchronisation ne recrée jamais la ligne masquée (tables listing_entry_links / listing_merge_identities).
 */
final class ListingMerge
{
    private static function norm(string $v): string
    {
        return trim(preg_replace('/[^a-z0-9@._+-]+/', ' ', Text::normalize($v)) ?? '');
    }

    public static function nameKey(string $first, string $last): string
    {
        $parts = array_values(array_filter(preg_split('/\s+/', self::norm($first . ' ' . $last)) ?: []));
        sort($parts, SORT_STRING);
        return implode('|', $parts);
    }

    public static function emailKey(string $email): string
    {
        $e = mb_strtolower(trim($email));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    private static function storeIdentity(Db $db, int $eventId, int $canonical, array $entry, string $emailOverride = '', string $display = ''): void
    {
        $email = self::emailKey($emailOverride !== '' ? $emailOverride : (string)($entry['email'] ?? ''));
        $name = self::nameKey((string)($entry['firstname'] ?? ''), (string)($entry['lastname'] ?? ''));
        if ($display !== '') {
            $name = self::nameKey($display, '');
        }
        if ($email === '' && $name === '') {
            return;
        }
        $db->run(
            'INSERT IGNORE INTO listing_merge_identities(event_id,canonical_entry_id,participant_type,email_key,name_key,created_at) VALUES(?,?,?,?,?,NOW())',
            [$eventId, $canonical, (string)($entry['participant_type'] ?? ''), $email ?: null, $name ?: null]
        );
    }

    public static function linkParticipant(Db $db, int $eventId, int $participantId, int $canonical): void
    {
        if ($participantId <= 0) {
            return;
        }
        $db->run(
            'INSERT INTO listing_entry_links(event_id,participant_id,canonical_entry_id,created_at,updated_at) VALUES(?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE canonical_entry_id=VALUES(canonical_entry_id),updated_at=NOW()',
            [$eventId, $participantId, $canonical]
        );
    }

    public static function hide(Db $db, int $eventId, int $entryId, ?int $adminId): void
    {
        $db->run(
            'INSERT INTO listing_entry_hidden(listing_entry_id,event_id,hidden_by,hidden_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),hidden_by=COALESCE(VALUES(hidden_by),hidden_by)',
            [$entryId, $eventId, $adminId]
        );
    }

    /**
     * Deux lignes -> une seule. La ligne la plus « commerciale » est conservée (Billetweb > post-inscription > portail > manuelle).
     * @param array $chosen valeurs choisies par l'administrateur : email, phone, display_name, vehicle, duration
     * @return array{kept_entry_id:int,hidden_entry_id:int,participant_id:int}
     */
    public static function execute(Db $db, int $eventId, int $a, int $b, array $chosen, ?int $adminId, string $adminLabel): array
    {
        $rows = $db->all('SELECT * FROM listing_entries WHERE event_id=? AND id IN (?,?) ORDER BY id', [$eventId, $a, $b]);
        if (count($rows) !== 2) {
            throw new \RuntimeException('Les deux lignes doivent appartenir au même événement.');
        }
        $by = [];
        foreach ($rows as $r) {
            $by[(int)$r['id']] = $r;
        }
        $ea = $by[$a];
        $eb = $by[$b];
        $rank = fn(array $x): int => ((string)$x['source'] === 'billetweb' ? 40 : ((string)$x['source'] === 'post' ? 30 : ((string)$x['source'] === 'manual' ? 10 : 0)))
            + (!empty($x['billetweb_attendee_id']) ? 8 : 0) + (!empty($x['participant_id']) ? 4 : 0);
        $keep = $rank($ea) >= $rank($eb) ? $ea : $eb;
        $hide = ((int)$keep['id'] === $a) ? $eb : $ea;
        $keepId = (int)$keep['id'];
        $hideId = (int)$hide['id'];
        $participantId = (int)($keep['participant_id'] ?? 0) ?: (int)($hide['participant_id'] ?? 0);

        $db->beginTransaction();
        try {
            // On détache d'abord toutes les lignes concurrentes (contrainte d'unicité sur participant_id).
            if ($participantId > 0) {
                $db->run('UPDATE listing_entries SET participant_id=NULL,updated_at=NOW() WHERE event_id=? AND participant_id=? AND id<>?', [$eventId, $participantId, $keepId]);
                $db->run('UPDATE listing_entries SET participant_id=?,updated_at=NOW() WHERE id=? AND event_id=?', [$participantId, $keepId, $eventId]);
                self::linkParticipant($db, $eventId, $participantId, $keepId);
            }
            $up = 'INSERT INTO listing_entry_overrides(listing_entry_id,field_name,field_value,updated_by,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE field_value=VALUES(field_value),updated_by=VALUES(updated_by),updated_at=NOW()';
            $fields = ['email', 'phone', 'display_name', 'vehicle'];
            // Le format commercial Billetweb/post reste la source de vérité : une fusion ne crée jamais d'override de format.
            if (!in_array((string)$keep['source'], ['billetweb', 'post'], true)) {
                $fields[] = 'duration';
            }
            foreach ($fields as $f) {
                $v = trim((string)($chosen[$f] ?? ''));
                if ($v !== '') {
                    $db->run($up, [$keepId, $f, $v, $adminId]);
                }
            }
            // La REF n'est jamais perdue ; deux REF différentes bloquent la fusion pour arbitrage humain.
            $refs = [];
            foreach ($db->all("SELECT listing_entry_id,field_value FROM listing_entry_overrides WHERE listing_entry_id IN (?,?) AND field_name='reference'", [$keepId, $hideId]) as $rr) {
                $v = trim((string)$rr['field_value']);
                if ($v !== '') {
                    $refs[(int)$rr['listing_entry_id']] = $v;
                }
            }
            $rk = $refs[$keepId] ?? '';
            $rh = $refs[$hideId] ?? '';
            if ($rk !== '' && $rh !== '' && strcasecmp($rk, $rh) !== 0) {
                throw new \RuntimeException('Fusion bloquée : les deux lignes ont des REF différentes (' . $rk . ' / ' . $rh . '). Choisissez la bonne REF avant de fusionner.');
            }
            if ($rk === '' && $rh !== '') {
                $db->run($up, [$keepId, 'reference', $rh, $adminId]);
            }
            // Paiements et notes de la ligne masquée : rattachés à la ligne conservée pour ne rien perdre.
            $db->run('UPDATE listing_entry_payments SET listing_entry_id=? WHERE listing_entry_id=?', [$keepId, $hideId]);
            self::storeIdentity($db, $eventId, $keepId, $ea, (string)($chosen['email'] ?? ''), (string)($chosen['display_name'] ?? ''));
            self::storeIdentity($db, $eventId, $keepId, $eb, (string)($chosen['email'] ?? ''), (string)($chosen['display_name'] ?? ''));
            self::hide($db, $eventId, $hideId, $adminId);
            $db->run(
                'INSERT INTO listing_entry_merges(event_id,kept_entry_id,hidden_entry_id,kept_email,kept_phone,admin_id,admin_label,created_at) VALUES(?,?,?,?,?,?,?,NOW())',
                [$eventId, $keepId, $hideId, (string)($chosen['email'] ?? ''), (string)($chosen['phone'] ?? ''), $adminId, $adminLabel]
            );
            $db->commit();
        } catch (\Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $t;
        }
        Audit::log('v3.listing.merge', $participantId ?: null, ['event' => $eventId, 'kept' => $keepId, 'hidden' => $hideId]);
        return ['kept_entry_id' => $keepId, 'hidden_entry_id' => $hideId, 'participant_id' => $participantId];
    }

    private static function findCanonical(Db $db, int $eventId, array $p): int
    {
        $pid = (int)($p['id'] ?? 0);
        if ($pid > 0) {
            $x = (int)$db->val('SELECT canonical_entry_id FROM listing_entry_links WHERE event_id=? AND participant_id=? LIMIT 1', [$eventId, $pid]);
            if ($x) {
                return $x;
            }
        }
        $email = self::emailKey((string)($p['email'] ?? ''));
        $name = self::nameKey((string)($p['prenom'] ?? ''), (string)($p['nom'] ?? ''));
        $type = (string)($p['participant_type'] ?? '');
        $c = [];
        if ($email !== '') {
            $c = array_map('intval', $db->col('SELECT DISTINCT canonical_entry_id FROM listing_merge_identities WHERE event_id=? AND email_key=? AND (participant_type=? OR participant_type IS NULL OR participant_type=\'\')', [$eventId, $email, $type]));
        }
        if (count(array_unique($c)) !== 1 && $name !== '') {
            $c = array_map('intval', $db->col('SELECT DISTINCT canonical_entry_id FROM listing_merge_identities WHERE event_id=? AND name_key=? AND (participant_type=? OR participant_type IS NULL OR participant_type=\'\')', [$eventId, $name, $type]));
        }
        $c = array_values(array_unique(array_filter($c)));
        return count($c) === 1 ? $c[0] : 0;
    }

    /** Applique les fusions déjà décidées aux dossiers (avant et après chaque synchronisation). */
    public static function reconcileEvent(Db $db, int $eventId, ?int $adminId = null): array
    {
        $linked = 0;
        $hidden = 0;
        foreach ($db->all('SELECT * FROM participants WHERE event_id=? AND archived_at IS NULL ORDER BY id', [$eventId]) as $p) {
            $canonical = self::findCanonical($db, $eventId, $p);
            if (!$canonical || !$db->val('SELECT id FROM listing_entries WHERE id=? AND event_id=?', [$canonical, $eventId])) {
                continue;
            }
            $pid = (int)$p['id'];
            foreach ($db->col('SELECT id FROM listing_entries WHERE event_id=? AND participant_id=? AND id<>?', [$eventId, $pid, $canonical]) as $oid) {
                $db->run('UPDATE listing_entries SET participant_id=NULL,updated_at=NOW() WHERE id=?', [(int)$oid]);
                self::hide($db, $eventId, (int)$oid, $adminId);
                $hidden++;
            }
            $db->run('UPDATE listing_entries SET participant_id=?,updated_at=NOW() WHERE id=? AND event_id=?', [$pid, $canonical, $eventId]);
            self::linkParticipant($db, $eventId, $pid, $canonical);
            $linked++;
        }
        return ['linked' => $linked, 'hidden' => $hidden];
    }
}
