<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Db;
use JC\Core\Http;
use JC\Domain\BilletwebClient;
use JC\Domain\Events;
use JC\Domain\Settings;
use JC\Domain\Text;

/**
 * Contrôle Billetweb : interroge l'API en direct et la compare à ce que le portail a en base, pour une journée
 * donnée. Sert à distinguer un problème de synchronisation locale d'un problème côté Billetweb (billet annulé,
 * coordonnées changées...) sans naviguer entre plusieurs écrans. Ne modifie jamais la base, même en mode écriture :
 * c'est un outil de diagnostic, utilisable aussi bien en lecture seule.
 */
final class BilletwebControlController extends BaseController
{
    public function index(): void
    {
        $db = $this->db();
        $event = $this->eventFromQuery();
        $this->view('billetweb-control/index', [
            'title'  => 'Contrôle Billetweb',
            'event'  => $event,
            'groups' => Events::groups($db),
            'local'  => $this->localSnapshot($event),
            'page'   => 'billetweb-control',
            'boot'   => ['event' => (int)$event['id']],
        ]);
    }

    private function localSnapshot(array $event): array
    {
        $db = $this->db();
        $eid = (int)$event['id'];
        $date = (string)$event['event_date'];
        return [
            'attendees_count'    => (int)$db->val('SELECT COUNT(*) FROM billetweb_attendees WHERE event_id=? AND disabled=0 AND order_paid=1', [$eid]),
            'attendees_total'    => (int)$db->val('SELECT COUNT(*) FROM billetweb_attendees WHERE event_id=?', [$eid]),
            'post_count'         => (int)$db->val('SELECT COUNT(*) FROM billetweb_post_attendees WHERE disabled=0 AND order_paid=1 AND (session_date=? OR DATE(session_start)=?)', [$date, $date]),
            'last_sync'          => (string)($event['billetweb_last_sync'] ?? ''),
            'post_last_sync'     => Settings::get($db, 'billetweb_post_last_sync'),
            'billetweb_event_id' => (string)($event['billetweb_event_id'] ?? ''),
        ];
    }

    /** Interrogation en direct de l'API Billetweb (aucune écriture en base, quel que soit le mode). */
    public function live(int $eventId): void
    {
        $event = $this->event($eventId);
        $db = $this->db();
        $api = new BilletwebClient($db);
        if (!$api->configured()) {
            Http::json(['ok' => false, 'message' => 'Identifiants Billetweb non configurés (réglages de l’ancien portail).']);
        }
        try {
            $out = ['ok' => true, 'attendees' => null, 'post' => null];
            if (!empty($event['billetweb_event_id'])) {
                $liveRows = $api->request('/api/event/' . rawurlencode((string)$event['billetweb_event_id']) . '/attendees', ['disabled' => 1]);
                $out['attendees'] = $this->compareMain($db, $eventId, $liveRows);
            }
            $postId = trim(Settings::get($db, 'billetweb_post_event_id'));
            if ($postId !== '') {
                $liveRows = $api->request('/api/event/' . rawurlencode($postId) . '/attendees', ['disabled' => 1, 'futur_sessions' => 1]);
                $out['post'] = $this->comparePost($db, (string)$event['event_date'], $liveRows);
            }
            Http::json($out);
        } catch (\Throwable $t) {
            Http::json(['ok' => false, 'message' => $t->getMessage()]);
        }
    }

    private static function active(array $r): bool
    {
        return (int)($r['order_paid'] ?? 0) === 1 && (int)($r['disabled'] ?? 0) === 0;
    }

    private static function summarize(array $r): array
    {
        return [
            'name'   => trim((string)($r['firstname'] ?? '') . ' ' . (string)($r['name'] ?? '')),
            'email'  => (string)($r['email'] ?? ''),
            'ticket' => (string)($r['ticket'] ?? ''),
        ];
    }

    private function compareMain(Db $db, int $eventId, array $liveRows): array
    {
        $live = [];
        foreach ($liveRows as $r) {
            if (is_array($r) && !empty($r['id'])) {
                $live[(string)$r['id']] = $r;
            }
        }
        $local = [];
        foreach ($db->all('SELECT * FROM billetweb_attendees WHERE event_id=?', [$eventId]) as $r) {
            $local[(string)$r['attendee_id']] = $r;
        }
        $missingLocally = [];
        $changed = [];
        foreach ($live as $id => $r) {
            if (!isset($local[$id])) {
                if (self::active($r)) {
                    $missingLocally[] = self::summarize($r);
                }
                continue;
            }
            $l = $local[$id];
            $diffs = [];
            if (Text::normalize((string)($l['firstname'] ?? '')) !== Text::normalize((string)($r['firstname'] ?? ''))) { $diffs[] = 'prénom'; }
            if (Text::normalize((string)($l['name'] ?? '')) !== Text::normalize((string)($r['name'] ?? ''))) { $diffs[] = 'nom'; }
            if (mb_strtolower(trim((string)($l['email'] ?? '')), 'UTF-8') !== mb_strtolower(trim((string)($r['email'] ?? '')), 'UTF-8')) { $diffs[] = 'e-mail'; }
            if ((int)($l['order_paid'] ?? 0) !== (int)($r['order_paid'] ?? 0)) { $diffs[] = 'payé'; }
            if ((int)($l['disabled'] ?? 0) !== (int)($r['disabled'] ?? 0)) { $diffs[] = 'désactivé'; }
            if ($diffs) {
                $item = self::summarize($r);
                $item['ticket'] = 'Changé : ' . implode(', ', $diffs);
                $changed[] = $item;
            }
        }
        $missingLive = [];
        foreach ($local as $id => $l) {
            if (!isset($live[$id]) && self::active($l)) {
                $missingLive[] = self::summarize($l);
            }
        }
        return [
            'live_count'      => count(array_filter($live, fn($r) => self::active($r))),
            'missing_locally' => $missingLocally,
            'missing_live'    => $missingLive,
            'changed'         => $changed,
        ];
    }

    private function comparePost(Db $db, string $date, array $liveRows): array
    {
        $live = [];
        foreach ($liveRows as $r) {
            if (!is_array($r) || empty($r['id'])) {
                continue;
            }
            $sessDate = '';
            if (!empty($r['session_start']) && ($ts = strtotime((string)$r['session_start'])) !== false) {
                $sessDate = date('Y-m-d', $ts);
            }
            if ($sessDate !== '' && $sessDate !== $date) {
                continue;
            }
            $live[(string)$r['id']] = $r;
        }
        $local = [];
        foreach ($db->all('SELECT * FROM billetweb_post_attendees WHERE (session_date=? OR DATE(session_start)=?)', [$date, $date]) as $r) {
            $local[(string)$r['attendee_id']] = $r;
        }
        $missingLocally = [];
        foreach ($live as $id => $r) {
            if (!isset($local[$id]) && self::active($r)) {
                $missingLocally[] = self::summarize($r);
            }
        }
        $missingLive = [];
        foreach ($local as $id => $l) {
            if (!isset($live[$id]) && self::active($l)) {
                $missingLive[] = self::summarize($l);
            }
        }
        return [
            'live_count'      => count(array_filter($live, fn($r) => self::active($r))),
            'missing_locally' => $missingLocally,
            'missing_live'    => $missingLive,
        ];
    }
}
