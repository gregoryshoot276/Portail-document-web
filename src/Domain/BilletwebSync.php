<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;
use JC\Core\Logger;

/**
 * Synchronisation Billetweb : billets de la journée (billetweb_attendees), billets post-inscription (options :
 * passager, pilote supplémentaire, assurance) et rapprochement des dossiers déjà déposés.
 */
final class BilletwebSync
{
    private BilletwebClient $api;

    public function __construct(private Db $db)
    {
        $this->api = new BilletwebClient($db);
    }

    private static function json(mixed $v): string
    {
        return (string)json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function categoryMatches(array $event, array $row): bool
    {
        $filter = trim((string)($event['billetweb_category_filter'] ?? ''));
        if ($filter === '') {
            return true;
        }
        $needle = BilletwebMatch::norm($filter);
        return $needle === '' || str_contains(BilletwebMatch::norm((string)($row['category'] ?? '')), $needle);
    }

    /** @return array{count:int} */
    public function syncEvent(int $eventId): array
    {
        $e = $this->db->row('SELECT * FROM events WHERE id=?', [$eventId]);
        if (!$e || empty($e['billetweb_event_id'])) {
            throw new \RuntimeException('ID Billetweb absent pour cet événement.');
        }
        $query = ['disabled' => 1];
        if (!empty($e['billetweb_last_sync'])) {
            $query['last_update'] = max(0, strtotime((string)$e['billetweb_last_sync']) - 300);
        }
        $rows = $this->api->request('/api/event/' . rawurlencode((string)$e['billetweb_event_id']) . '/attendees', $query);
        $st = $this->db->prepare(
            'INSERT INTO billetweb_attendees(event_id,billetweb_event_id,attendee_id,ext_id,order_id,order_ext_id,firstname,name,email,order_firstname,order_name,order_email,ticket,ticket_id,category,order_paid,disabled,raw_json,remote_last_update,synced_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE ext_id=VALUES(ext_id),order_id=VALUES(order_id),order_ext_id=VALUES(order_ext_id),firstname=VALUES(firstname),name=VALUES(name),email=VALUES(email),order_firstname=VALUES(order_firstname),order_name=VALUES(order_name),order_email=VALUES(order_email),ticket=VALUES(ticket),ticket_id=VALUES(ticket_id),category=VALUES(category),order_paid=VALUES(order_paid),disabled=VALUES(disabled),raw_json=VALUES(raw_json),remote_last_update=VALUES(remote_last_update),synced_at=NOW()'
        );
        $count = 0;
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['id']) || !self::categoryMatches($e, $r)) {
                continue;
            }
            $st->execute([
                $eventId, (string)$e['billetweb_event_id'], (string)$r['id'], $r['ext_id'] ?? null, $r['order_id'] ?? null, $r['order_ext_id'] ?? null,
                $r['firstname'] ?? null, $r['name'] ?? null, $r['email'] ?? null, $r['order_firstname'] ?? null, $r['order_name'] ?? null, $r['order_email'] ?? null,
                $r['ticket'] ?? null, $r['ticket_id'] ?? null, $r['category'] ?? null, (int)($r['order_paid'] ?? 0), (int)($r['disabled'] ?? 0),
                self::json($r), $r['last_update'] ?? null,
            ]);
            $count++;
        }
        $this->db->run('UPDATE events SET billetweb_last_sync=NOW(),updated_at=NOW() WHERE id=?', [$eventId]);
        return ['count' => $count];
    }

    /** Date et heure d'une séance dans une ligne Billetweb (champs directs, sinon recherche dans tout le JSON). */
    private static function sessionDatetime(array $row): ?string
    {
        foreach (['session_start', 'order_session_start', 'session_date', 'date_start', 'event_start', 'start', 'date'] as $k) {
            if (isset($row[$k]) && is_string($row[$k]) && $row[$k] !== '') {
                $ts = strtotime($row[$k]);
                if ($ts !== false && (int)date('Y', $ts) >= 2020) {
                    return date('Y-m-d H:i:s', $ts);
                }
            }
        }
        $stack = [$row];
        while ($stack) {
            $cur = array_pop($stack);
            foreach ($cur as $k => $v) {
                if (is_array($v)) {
                    $stack[] = $v;
                    continue;
                }
                if (!is_string($v)) {
                    continue;
                }
                $key = BilletwebMatch::norm((string)$k);
                if ((str_contains($key, 'session') || str_contains($key, 'seance') || str_contains($key, 'start') || str_contains($key, 'date'))
                    && preg_match('/20\d{2}[-\/]\d{2}[-\/]\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?/', $v, $mm)) {
                    $ts = strtotime(str_replace('/', '-', $mm[0]));
                    if ($ts !== false) {
                        return date('Y-m-d H:i:s', $ts);
                    }
                }
            }
        }
        return null;
    }

    private static function resolveSession(array $row, array $sessionMap): array
    {
        $sid = '';
        foreach (['order_session', 'session_id', 'session'] as $k) {
            if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
                $sid = (string)$row[$k];
                break;
            }
        }
        $dt = self::sessionDatetime($row);
        if ($sid !== '' && isset($sessionMap[$sid])) {
            $d = $sessionMap[$sid];
            if (!$dt && !empty($d['start']) && ($ts = strtotime((string)$d['start'])) !== false) {
                $dt = date('Y-m-d H:i:s', $ts);
            }
            return [$sid, $d, $dt];
        }
        if ($dt) {
            $day = date('Y-m-d', strtotime($dt));
            foreach ($sessionMap as $mapSid => $d) {
                if (!empty($d['start']) && ($ts = strtotime((string)$d['start'])) !== false && date('Y-m-d', $ts) === $day) {
                    return [(string)$mapSid, $d, $dt];
                }
            }
        }
        return [$sid, [], $dt];
    }

    /** @return array{count:int,sessions:int,skipped:bool} */
    public function syncPost(): array
    {
        $postId = trim(Settings::get($this->db, 'billetweb_post_event_id'));
        if ($postId === '') {
            return ['count' => 0, 'sessions' => 0, 'skipped' => true];
        }
        $dates = $this->api->request('/api/event/' . rawurlencode($postId) . '/dates', ['past' => 0, 'start_from' => strtotime('-1 day')]);
        $sessionMap = [];
        $qs = $this->db->prepare(
            'INSERT INTO billetweb_post_sessions(billetweb_event_id,session_id,session_start,session_end,session_name,session_place,disabled,total_sales,raw_json,synced_at)
             VALUES(?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE session_start=VALUES(session_start),session_end=VALUES(session_end),session_name=VALUES(session_name),session_place=VALUES(session_place),disabled=VALUES(disabled),total_sales=VALUES(total_sales),raw_json=VALUES(raw_json),synced_at=NOW()'
        );
        $sessions = 0;
        foreach ($dates as $d) {
            if (!is_array($d) || empty($d['id'])) {
                continue;
            }
            $sid = (string)$d['id'];
            $sessionMap[$sid] = $d;
            $qs->execute([$postId, $sid, $d['start'] ?? null, $d['end'] ?? null, $d['name'] ?? null, $d['place'] ?? null, (int)($d['disabled'] ?? 0), isset($d['total_sales']) ? (int)$d['total_sales'] : null, self::json($d)]);
            $sessions++;
        }
        $rows = $this->api->request('/api/event/' . rawurlencode($postId) . '/attendees', ['disabled' => 1, 'futur_sessions' => 1]);
        $q = $this->db->prepare(
            'INSERT INTO billetweb_post_attendees(billetweb_event_id,attendee_id,ext_id,order_id,order_ext_id,firstname,name,email,order_firstname,order_name,order_email,order_session,session_start,session_date,session_name,session_place,post_code,ticket,ticket_id,category,order_paid,disabled,raw_json,remote_last_update,synced_at)
             VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE ext_id=VALUES(ext_id),order_id=VALUES(order_id),order_ext_id=VALUES(order_ext_id),firstname=VALUES(firstname),name=VALUES(name),email=VALUES(email),order_firstname=VALUES(order_firstname),order_name=VALUES(order_name),order_email=VALUES(order_email),order_session=VALUES(order_session),session_start=VALUES(session_start),session_date=VALUES(session_date),session_name=VALUES(session_name),session_place=VALUES(session_place),post_code=VALUES(post_code),ticket=VALUES(ticket),ticket_id=VALUES(ticket_id),category=VALUES(category),order_paid=VALUES(order_paid),disabled=VALUES(disabled),raw_json=VALUES(raw_json),remote_last_update=VALUES(remote_last_update),synced_at=NOW()'
        );
        $count = 0;
        $seen = [];
        foreach ($rows as $r) {
            if (!is_array($r) || empty($r['id'])) {
                continue;
            }
            $seen[] = (string)$r['id'];
            $sid = !empty($r['order_session']) ? (string)$r['order_session'] : '';
            $start = null;
            if (!empty($r['session_start']) && ($ts = strtotime((string)$r['session_start'])) !== false) {
                $start = date('Y-m-d H:i:s', $ts);
            }
            [$fallbackSid, $d, $fallbackStart] = self::resolveSession($r, $sessionMap);
            if ($sid === '') {
                $sid = $fallbackSid;
            }
            if (!$start) {
                $start = $fallbackStart;
            }
            $q->execute([
                $postId, (string)$r['id'], $r['ext_id'] ?? null, $r['order_id'] ?? null, $r['order_ext_id'] ?? null,
                $r['firstname'] ?? null, $r['name'] ?? null, $r['email'] ?? null, $r['order_firstname'] ?? null, $r['order_name'] ?? null, $r['order_email'] ?? null,
                $sid ?: null, $start, $start ? date('Y-m-d', strtotime($start)) : null, $d['name'] ?? null, $d['place'] ?? null,
                BilletwebMatch::postTicketCode((string)($r['ticket'] ?? '')) ?: null,
                $r['ticket'] ?? null, $r['ticket_id'] ?? null, $r['category'] ?? null, (int)($r['order_paid'] ?? 0), (int)($r['disabled'] ?? 0),
                self::json($r), $r['last_update'] ?? null,
            ]);
            $count++;
        }
        // Contrairement à l'événement principal, une commande post-inscription (ex. Pilote supplémentaire seul)
        // entièrement supprimée dans BilletWeb n'est plus jamais renvoyée par l'API (pas de disabled=1 persistant
        // comme sur le billet principal) : sans ça, sa ligne locale reste active pour toujours et n'apparaît
        // jamais « Annulé ». On la marque donc désactivée si elle n'est plus dans cette réponse — en restant
        // strictement borné à la fenêtre couverte par futur_sessions=1 (comme le fait déjà le fetch des dates
        // juste au-dessus, avec 1 jour de marge) pour ne jamais toucher l'historique que cet appel ne couvre
        // de toute façon pas, et donc ne jamais présumer à tort qu'un vieux billet est « annulé ».
        $notIn = $seen ? ' AND attendee_id NOT IN (' . Db::marks(count($seen)) . ')' : '';
        $this->db->run(
            "UPDATE billetweb_post_attendees SET disabled=1,synced_at=NOW()
             WHERE billetweb_event_id=? AND disabled=0 AND session_date>=DATE_SUB(CURDATE(),INTERVAL 1 DAY){$notIn}",
            array_merge([$postId], $seen)
        );
        Settings::set($this->db, 'billetweb_post_last_sync', date('Y-m-d H:i:s'));
        return ['count' => $count, 'sessions' => $sessions, 'skipped' => false];
    }

    /**
     * Rapproche les dossiers pilotes déjà déposés d'un billet retrouvé ensuite : met à jour le dossier,
     * corrige l'assurance (l'assurance Billetweb prime sur un justificatif manquant / refusé) et recalcule le statut.
     * @return array{checked:int,matched:int,insurance_fixed:int,documents_fixed:int}
     */
    public function reconcileParticipants(int $eventId): array
    {
        $stats = ['checked' => 0, 'matched' => 0, 'insurance_fixed' => 0, 'documents_fixed' => 0];
        $event = Events::find($this->db, $eventId);
        if (!$event) {
            return $stats;
        }
        // Les pilotes supplémentaires sont souvent achetés en post-inscription : ils doivent aussi être revérifiés,
        // pas seulement les pilotes principaux (sinon leur billet retrouvé après coup ne met jamais leur dossier à jour).
        foreach ($this->db->all("SELECT * FROM participants WHERE event_id=? AND participant_type IN ('pilot','supplemental_driver') AND archived_at IS NULL", [$eventId]) as $p) {
            $stats['checked']++;
            try {
                $pType = (string)$p['participant_type'];
                $previous = (string)($p['global_status'] ?? 'pending');
                $match = BilletwebMatch::match($this->db, $event, $pType, (string)$p['email'], (string)$p['nom'], (string)$p['prenom']);
                if (($match['status'] ?? '') !== 'found' || empty($match['attendee'])) {
                    continue;
                }
                $att = $match['attendee'];
                $stats['matched']++;
                $insFound = $pType === 'pilot' && !empty($match['insurance_found']);
                $insSource = in_array((string)($match['insurance_source'] ?? 'none'), ['initial', 'post'], true) ? (string)$match['insurance_source'] : 'none';
                $this->db->run(
                    "UPDATE participants SET billetweb_attendee_id=?,billetweb_order_id=?,billetweb_order_ref=?,ticket_status='found',ticket_message=?,billetweb_match_score=?,insurance_billetweb_source=?,updated_at=NOW() WHERE id=?",
                    [$att['attendee_id'] ?? null, $att['order_id'] ?? null, $att['order_ext_id'] ?? null, (string)($match['message'] ?? 'Inscription Billetweb retrouvée.'), (float)($match['score'] ?? 0), $insFound ? $insSource : 'none', (int)$p['id']]
                );
                $fixedDoc = 0;
                if ($pType === 'pilot' && $insFound && $insSource !== 'none') {
                    $newSource = $insSource === 'post' ? 'billetweb_post' : 'billetweb_initial';
                    $cur = $this->db->row("SELECT id,status,source FROM documents WHERE participant_id=? AND document_type='assurance' ORDER BY id DESC LIMIT 1", [(int)$p['id']]);
                    if ($cur && ((string)$cur['status'] !== 'validated' || !in_array((string)$cur['source'], ['billetweb_initial', 'billetweb_post', 'team_insurance'], true))) {
                        $n = $this->db->run("UPDATE documents SET status='validated',source=?,rejection_reason=NULL,updated_at=NOW() WHERE id=?", [$newSource, (int)$cur['id']]);
                        if ($n > 0) {
                            $stats['documents_fixed'] += $n;
                            $stats['insurance_fixed']++;
                            $fixedDoc = (int)$cur['id'];
                        }
                    }
                }
                $global = Dossiers::globalStatus(array_map('strval', $this->db->col('SELECT status FROM documents WHERE participant_id=?', [(int)$p['id']])));
                $this->db->run('UPDATE participants SET global_status=?,updated_at=NOW() WHERE id=?', [$global, (int)$p['id']]);
                // Le dossier vient de devenir complet grâce à l'assurance retrouvée : e-mail « dossier validé ».
                if ($fixedDoc > 0 && $global === 'validated' && $previous !== 'validated') {
                    Notifier::validatedFinal($this->db, (int)$p['id']);
                }
            } catch (\Throwable $t) {
                Logger::error('billetweb', 'rapprochement participant ' . (int)$p['id'] . ' : ' . $t->getMessage());
            }
        }
        return $stats;
    }
}
