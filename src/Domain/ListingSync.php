<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Audit;
use JC\Core\Db;
use JC\Core\Logger;
use JC\Core\Mode;

/**
 * Construit et met à jour les lignes du listing (listing_entries) d'une journée à partir des dossiers,
 * des billets Billetweb et des billets post-inscription. Écrit en base : à lancer explicitement.
 */
final class ListingSync
{
    private const POST_CODES = ['PA', 'PAP', 'PS', 'PSP', 'PSF', 'PSFP', 'AC', 'ACP', 'ACNP'];

    public function __construct(private Db $db)
    {
    }

    /** Synchronisation Billetweb -> listing, au plus toutes les 90 s (sauf $force), une seule à la fois (verrou
     * MySQL) : logique partagée entre le Listing et tout autre écran qui a besoin de rafraîchir une journée
     * (ex. Annulations / remplacements, avant de traiter une demande). */
    public static function runThrottled(Db $db, int $eventId, ?int $adminId, bool $force = false): array
    {
        Mode::assertWritable();
        $ev = Events::find($db, $eventId);
        if (!$ev || empty($ev['billetweb_event_id'])) {
            return ['ok' => true, 'changed' => false, 'skipped' => true, 'message' => 'Aucune billetterie Billetweb liée à cette journée.'];
        }
        $key = 'listing_live_sync_' . $eventId;
        $last = trim(Settings::get($db, $key));
        $lastTs = $last !== '' ? strtotime($last) : 0;
        if (!$force && $lastTs && time() - $lastTs < 90) {
            return ['ok' => true, 'changed' => false, 'skipped' => true, 'last_sync' => $last];
        }
        $lock = 'jc_listing_sync_' . $eventId;
        if ((int)$db->val('SELECT GET_LOCK(?,1)', [$lock]) !== 1) {
            return ['ok' => true, 'changed' => false, 'skipped' => true, 'message' => 'Synchronisation déjà en cours.'];
        }
        try {
            $before = (int)$db->val('SELECT COUNT(*) FROM listing_entries WHERE event_id=?', [$eventId]);
            $r = (new self($db))->run($eventId, $adminId, true);
            Settings::flush();
            Settings::set($db, $key, date('Y-m-d H:i:s'));
            Audit::log('v3.listing.sync', null, ['event' => $eventId, 'entries' => $r['entries'], 'errors' => $r['errors']]);
            return [
                'ok' => true, 'changed' => (int)$r['entries'] !== $before, 'before' => $before, 'after' => (int)$r['entries'],
                'participants' => (int)($r['billetweb']['count'] ?? 0), 'post' => (int)($r['post']['count'] ?? 0),
                'errors' => $r['errors'], 'last_sync' => date('Y-m-d H:i:s'),
            ];
        } finally {
            $db->val('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * Synchronisation complète d'une journée : Billetweb (si demandé) puis lignes du listing.
     * @return array<string,mixed>
     */
    public function run(int $eventId, ?int $adminId = null, bool $refreshBilletweb = true): array
    {
        $out = ['event' => $eventId, 'billetweb' => null, 'post' => null, 'reconcile' => null, 'entries' => 0, 'errors' => []];
        $event = Events::find($this->db, $eventId);
        if (!$event) {
            throw new \RuntimeException('Journée introuvable.');
        }
        if ($refreshBilletweb && !empty($event['billetweb_event_id'])) {
            $bw = new BilletwebSync($this->db);
            try {
                $out['billetweb'] = $bw->syncEvent($eventId);
                $out['reconcile'] = $bw->reconcileParticipants($eventId);
            } catch (\Throwable $t) {
                $out['errors'][] = 'Billetweb : ' . $t->getMessage();
                Logger::error('sync', 'event ' . $eventId . ' : ' . $t->getMessage());
            }
            try {
                $out['post'] = $bw->syncPost();
            } catch (\Throwable $t) {
                $out['errors'][] = 'Post-inscription : ' . $t->getMessage();
            }
        }
        // Les fusions décidées à la main sont matérialisées AVANT et APRÈS, pour qu'aucune ligne masquée ne renaisse.
        ListingMerge::reconcileEvent($this->db, $eventId, $adminId);
        $sync = $this->syncEntries($event);
        $out['entries'] = $sync['count'];
        foreach ($sync['errors'] as $msg) {
            $out['errors'][] = $msg;
        }
        ListingMerge::reconcileEvent($this->db, $eventId, $adminId);
        $this->applyLinks($eventId, $adminId);
        $this->dedupeParticipants($eventId, $adminId);
        return $out;
    }

    /** @return array{count:int,errors:array<int,string>} */
    private function syncEntries(array $event): array
    {
        $eid = (int)$event['id'];
        $errors = [];
        // Une ligne dont le rapprochement échoue (ex. attendee_id resté accroché à une autre ligne après une
        // fusion) ne doit jamais faire échouer la synchro de TOUTES les autres lignes de la journée : on
        // journalise et on continue, plutôt que de tout bloquer pour une seule inscription en cause.
        foreach ($this->db->all('SELECT * FROM participants WHERE event_id=? AND archived_at IS NULL', [$eid]) as $p) {
            try {
                $this->upsert($eid, (int)$p['id'], !empty($p['billetweb_attendee_id']) ? (string)$p['billetweb_attendee_id'] : null, 'portal', (string)$p['participant_type'], (string)$p['prenom'], (string)$p['nom'], (string)$p['email']);
            } catch (\Throwable $t) {
                $errors[] = 'Dossier ' . $p['nom'] . ' ' . $p['prenom'] . ' : ' . $t->getMessage();
                Logger::error('sync', 'participant ' . $p['id'] . ' (event ' . $eid . ') : ' . $t->getMessage());
            }
        }
        foreach ($this->db->all('SELECT * FROM billetweb_attendees WHERE event_id=? AND disabled=0 AND order_paid=1 ORDER BY id', [$eid]) as $r) {
            $role = Bw::role($r);
            if ($role === 'insurance') {
                continue;
            }
            $id = Bw::identity($r, $role);
            $pid = $this->findParticipant($eid, $r, $role, $id);
            try {
                $this->upsert($eid, $pid, (string)$r['attendee_id'], 'billetweb', $role, (string)$id['firstname'], (string)$id['lastname'], (string)$r['email']);
            } catch (\Throwable $t) {
                $errors[] = 'Billet ' . $r['attendee_id'] . ' : ' . $t->getMessage();
                Logger::error('sync', 'attendee ' . $r['attendee_id'] . ' (event ' . $eid . ') : ' . $t->getMessage());
            }
        }
        $date = (string)$event['event_date'];
        foreach ($this->db->all('SELECT * FROM billetweb_post_attendees WHERE disabled=0 AND order_paid=1 AND (session_date=? OR DATE(session_start)=?) ORDER BY id', [$date, $date]) as $r) {
            $role = Bw::role($r);
            if ($role === 'insurance') {
                continue;
            }
            $code = strtoupper((string)($r['post_code'] ?? ''));
            if ($code !== '' && !in_array($code, self::POST_CODES, true)) {
                continue;
            }
            $id = Bw::identity($r, $role);
            $pid = $this->findParticipant($eid, $r, $role, $id);
            try {
                $this->upsert($eid, $pid, (string)$r['attendee_id'], 'post', $role, (string)$id['firstname'], (string)$id['lastname'], (string)$r['email']);
            } catch (\Throwable $t) {
                $errors[] = 'Billet post-inscription ' . $r['attendee_id'] . ' : ' . $t->getMessage();
                Logger::error('sync', 'post attendee ' . $r['attendee_id'] . ' (event ' . $eid . ') : ' . $t->getMessage());
            }
        }
        return ['count' => (int)$this->db->val('SELECT COUNT(*) FROM listing_entries WHERE event_id=?', [$eid]), 'errors' => $errors];
    }

    /** Rattache un billet à un dossier : par identifiant de billet, sinon par Nom + Prénom + type (jamais par e-mail seul). */
    private function findParticipant(int $eventId, array $r, string $role, array $identity): ?int
    {
        $pre = trim((string)($identity['firstname'] ?? ''));
        $nom = trim((string)($identity['lastname'] ?? ''));
        $aid = trim((string)($r['attendee_id'] ?? ''));
        if ($aid !== '') {
            $p = $this->db->row('SELECT id,participant_type FROM participants WHERE event_id=? AND billetweb_attendee_id=? AND archived_at IS NULL LIMIT 1', [$eventId, $aid]);
            if ($p && ($role === '' || (string)$p['participant_type'] === $role)) {
                return (int)$p['id'];
            }
        }
        if ($nom === '' || $pre === '') {
            return null;
        }
        $matches = [];
        foreach ($this->db->all('SELECT id,participant_type,nom,prenom FROM participants WHERE event_id=? AND archived_at IS NULL ORDER BY id DESC', [$eventId]) as $p) {
            if ($role !== '' && (string)$p['participant_type'] !== $role) {
                continue;
            }
            if (Text::normalize((string)$p['nom']) !== Text::normalize($nom) || Text::normalize((string)$p['prenom']) !== Text::normalize($pre)) {
                continue;
            }
            $matches[] = (int)$p['id'];
        }
        return count($matches) === 1 ? $matches[0] : null;
    }

    private function upsert(int $eventId, ?int $participantId, ?string $attendeeId, string $source, string $type, string $firstname, string $lastname, string $email): int
    {
        $db = $this->db;
        // Une fusion humaine est une vérité persistante : une synchro ne recrée jamais une ligne portail.
        if ($participantId) {
            $canonical = (int)$db->val('SELECT canonical_entry_id FROM listing_entry_links WHERE event_id=? AND participant_id=? LIMIT 1', [$eventId, $participantId]);
            if ($canonical > 0) {
                $ce = $db->row('SELECT id,source,billetweb_attendee_id,participant_id FROM listing_entries WHERE id=? AND event_id=? LIMIT 1', [$canonical, $eventId]);
                if ($ce) {
                    if ($source === 'portal') {
                        if (empty($ce['participant_id'])) {
                            $db->run('UPDATE listing_entries SET participant_id=?,updated_at=NOW() WHERE id=?', [$participantId, $canonical]);
                        }
                        return $canonical;
                    }
                    if ($attendeeId) {
                        // Ce billet peut être resté accroché à une AUTRE ligne (ex. l'ancienne, masquée par la
                        // fusion) : sans le détacher d'abord, la contrainte d'unicité (event_id, attendee_id)
                        // bloquerait cette mise à jour, et donc toute la synchro, indéfiniment.
                        $stale = (int)$db->val('SELECT id FROM listing_entries WHERE event_id=? AND billetweb_attendee_id=? AND id<>? LIMIT 1', [$eventId, $attendeeId, $canonical]);
                        if ($stale > 0) {
                            $db->run('UPDATE listing_entries SET billetweb_attendee_id=NULL,updated_at=NOW() WHERE id=?', [$stale]);
                        }
                        $db->run('UPDATE listing_entries SET billetweb_attendee_id=COALESCE(?,billetweb_attendee_id),source=?,participant_type=?,firstname=?,lastname=?,email=?,updated_at=NOW() WHERE id=?', [$attendeeId, $source, $type, $firstname, $lastname, $email, $canonical]);
                        return $canonical;
                    }
                }
            }
        }
        $byAttendee = 0;
        $byParticipant = 0;
        if ($attendeeId) {
            $byAttendee = (int)$db->val('SELECT id FROM listing_entries WHERE event_id=? AND billetweb_attendee_id=? LIMIT 1', [$eventId, $attendeeId]);
        }
        if ($participantId) {
            // uq_listing_participant est GLOBAL : ne pas limiter cette recherche à l'événement.
            $byParticipant = (int)$db->val('SELECT id FROM listing_entries WHERE participant_id=? LIMIT 1', [$participantId]);
        }
        if ($byAttendee && $byParticipant && $byAttendee !== $byParticipant) {
            // Mauvais rattachement historique : on libère uniquement participant_id, sans supprimer le billet.
            $db->run('UPDATE listing_entries SET participant_id=NULL,updated_at=NOW() WHERE id=?', [$byParticipant]);
            $byParticipant = 0;
        }
        $id = $byAttendee ?: $byParticipant;

        // Un dossier déposé pour une inscription saisie à la main : on rattache s'il n'y a aucune ambiguïté.
        if (!$id && $participantId && $source === 'portal') {
            $candidates = [];
            if (trim($email) !== '') {
                $candidates = array_map('intval', $db->col("SELECT e.id FROM listing_entries e LEFT JOIN listing_entry_overrides o ON o.listing_entry_id=e.id AND o.field_name='email' WHERE e.event_id=? AND e.source='manual' AND e.participant_id IS NULL AND LOWER(COALESCE(NULLIF(o.field_value,''),e.email))=LOWER(?)", [$eventId, trim($email)]));
            }
            if (count($candidates) !== 1) {
                $full = Text::normalize(trim($lastname . ' ' . $firstname));
                $matches = [];
                foreach ($db->all("SELECT e.id,COALESCE(NULLIF(o.field_value,''),TRIM(CONCAT(e.lastname,' ',e.firstname))) display_name FROM listing_entries e LEFT JOIN listing_entry_overrides o ON o.listing_entry_id=e.id AND o.field_name='display_name' WHERE e.event_id=? AND e.source='manual' AND e.participant_id IS NULL", [$eventId]) as $mr) {
                    if ($full !== '' && Text::normalize((string)$mr['display_name']) === $full) {
                        $matches[] = (int)$mr['id'];
                    }
                }
                if (count($matches) === 1) {
                    $candidates = $matches;
                }
            }
            if (count($candidates) === 1) {
                $id = (int)$candidates[0];
            }
        }
        if ($id) {
            $db->run('UPDATE listing_entries SET participant_id=?,billetweb_attendee_id=COALESCE(?,billetweb_attendee_id),source=?,participant_type=?,firstname=?,lastname=?,email=?,updated_at=NOW() WHERE id=?', [$participantId, $attendeeId, $source, $type, $firstname, $lastname, $email, $id]);
            return $id;
        }
        $db->run('INSERT INTO listing_entries(event_id,participant_id,billetweb_attendee_id,source,participant_type,firstname,lastname,email,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,NOW(),NOW())', [$eventId, $participantId, $attendeeId, $source, $type, $firstname, $lastname, $email]);
        return (int)$db->lastInsertId();
    }

    /** Anciens liens participant -> billet (V2.2) : appliqués comme fusions canoniques. */
    private function applyLinks(int $eventId, ?int $adminId): void
    {
        $links = [];
        foreach ($this->db->all('SELECT participant_id,canonical_entry_id FROM listing_entry_links WHERE event_id=? ORDER BY participant_id', [$eventId]) as $r) {
            $links[(int)$r['participant_id']] = (int)$r['canonical_entry_id'];
        }
        foreach ($this->db->all('SELECT * FROM listing_participant_links WHERE event_id=? ORDER BY participant_id', [$eventId]) as $ln) {
            $pid = (int)$ln['participant_id'];
            if (isset($links[$pid]) || trim((string)$ln['attendee_id']) === '') {
                continue;
            }
            $t = (int)$this->db->val('SELECT id FROM listing_entries WHERE event_id=? AND billetweb_attendee_id=? ORDER BY id LIMIT 1', [$eventId, trim((string)$ln['attendee_id'])]);
            if ($t) {
                $links[$pid] = $t;
            }
        }
        foreach ($links as $pid => $target) {
            if ($pid <= 0 || $target <= 0 || !$this->db->val('SELECT id FROM listing_entries WHERE id=? AND event_id=? LIMIT 1', [$target, $eventId])) {
                continue;
            }
            foreach ($this->db->col('SELECT id FROM listing_entries WHERE event_id=? AND participant_id=? AND id<>?', [$eventId, $pid, $target]) as $oid) {
                $this->db->run('UPDATE listing_entries SET participant_id=NULL,updated_at=NOW() WHERE id=?', [(int)$oid]);
                ListingMerge::hide($this->db, $eventId, (int)$oid, $adminId);
            }
            $this->db->run('UPDATE listing_entries SET participant_id=COALESCE(participant_id,?),updated_at=NOW() WHERE id=? AND event_id=?', [$pid, $target, $eventId]);
        }
    }

    /** Un dossier ne doit apparaître que sur une seule ligne : on garde la plus « commerciale ». */
    private function dedupeParticipants(int $eventId, ?int $adminId): void
    {
        foreach ($this->db->all('SELECT participant_id FROM listing_entries WHERE event_id=? AND participant_id IS NOT NULL GROUP BY participant_id HAVING COUNT(*)>1', [$eventId]) as $g) {
            $rows = $this->db->all("SELECT id FROM listing_entries WHERE event_id=? AND participant_id=? ORDER BY (source='billetweb') DESC,(source='post') DESC,(billetweb_attendee_id IS NOT NULL) DESC,id ASC", [$eventId, (int)$g['participant_id']]);
            foreach (array_slice($rows, 1) as $r) {
                $this->db->run('UPDATE listing_entries SET participant_id=NULL,updated_at=NOW() WHERE id=?', [(int)$r['id']]);
                ListingMerge::hide($this->db, $eventId, (int)$r['id'], $adminId);
            }
        }
    }
}
