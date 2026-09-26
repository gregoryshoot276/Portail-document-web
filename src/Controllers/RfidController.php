<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Http;
use JC\Domain\Events;
use JC\Domain\Formats;
use JC\Domain\ListingBuilder;
use JC\Domain\Text;
use JC\Domain\Urtime;

final class RfidController extends BaseController
{
    /** Voitures de roulage (pilotes, format Journée / Matin / Après-midi) avec leur puce. */
    private function carRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['type'] !== 'pilot' || !Formats::isRolling($r['duration'])) {
                continue;
            }
            $out[] = ['id' => $r['id'], 'ref' => trim($r['reference']), 'vehicle' => $r['vehicle'], 'format' => $r['duration'], 'tag' => $r['rfid'], 'name' => $r['name'], 'cancelled' => $r['cancelled']];
        }
        usort($out, fn($a, $b) => strnatcasecmp($a['ref'], $b['ref']));
        return $out;
    }

    public function index(): void
    {
        $db = $this->db();
        $event = $this->eventFromQuery();
        $eid = (int)$event['id'];
        $mode = Http::str('mode', 'assign');
        if (!in_array($mode, ['assign', 'log', 'roulage'], true)) {
            $mode = 'assign';
        }
        $cars = $this->carRows((new ListingBuilder($db, $event))->build());
        $byId = [];
        foreach ($cars as $c) {
            $byId[$c['id']] = $c;
        }
        $total = (int)$db->val('SELECT COUNT(*) FROM listing_rfid_passages WHERE event_id=?', [$eid]);
        $log = [];
        if ($mode === 'log') {
            foreach ($db->all('SELECT id,listing_entry_id,tag_uid,scanned_at,source FROM listing_rfid_passages WHERE event_id=? ORDER BY id DESC LIMIT 250', [$eid]) as $x) {
                $log[] = $x + ($byId[(int)$x['listing_entry_id']] ?? ['ref' => '', 'vehicle' => '', 'format' => '', 'name' => '']);
            }
        }
        $this->view('rfid/index', [
            'title'  => 'RFID roulage',
            'event'  => $event,
            'groups' => Events::groups($db),
            'mode'   => $mode,
            'cars'   => $cars,
            'log'    => $log,
            'total'  => $total,
            'urtime' => (new Urtime($db))->hasKey(),
            'page'      => 'rfid',
            'mainClass' => 'wide',
            'boot'      => ['event' => $eid],
        ]);
    }

    public function api(int $eventId): void
    {
        $this->event($eventId);
        $w = $this->writer();
        switch (Http::str('action')) {
            case 'set_tag':
                $w->rfidSet($eventId, Http::int('entry_id'), Http::str('tag_uid'));
                Http::json(['ok' => true]);
            case 'scan':
                $r = $w->rfidLogTag($eventId, Http::str('tag_uid'));
                Http::json($r + ['ok' => (bool)($r['ok'] ?? false)]);
            case 'scan_ref':
                $r = $w->rfidLogReference($eventId, Http::str('reference'));
                Http::json($r + ['ok' => (bool)($r['ok'] ?? false)]);
        }
        Http::json(['ok' => false, 'message' => 'Action inconnue.'], 400);
    }

    /** Interrogation de l'API URTime (lecture seule côté URTime). */
    public function urtimeApi(int $eventId): void
    {
        $event = $this->event($eventId);
        $db = $this->db();
        $ur = new Urtime($db);
        try {
            switch (Http::str('action')) {
                case 'events':
                    Http::json(['ok' => true, 'data' => $ur->events()]);
                case 'checkpoints':
                    Http::json(['ok' => true, 'data' => $ur->checkpoints(Http::int('event_id'))]);
                case 'sessions':
                    $hubEvent = Http::int('event_id');
                    if ($hubEvent <= 0) {
                        throw new \RuntimeException('Événement URTime invalide.');
                    }
                    $tagMap = [];
                    foreach ((new ListingBuilder($db, $event))->build() as $r) {
                        if ($r['rfid'] !== '') {
                            $tagMap[strtoupper($r['rfid'])] = ['reference' => trim($r['reference']), 'participant' => $r['name'], 'vehicle' => $r['vehicle']];
                        }
                    }
                    $detections = $ur->export($hubEvent, Http::str('start'), Http::str('end'));
                    $cars = Urtime::sessions($detections, $tagMap);
                    Http::json(['ok' => true, 'cars' => $cars, 'detections' => count($detections)]);
            }
        } catch (\RuntimeException $e) {
            Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
        }
        Http::json(['ok' => false, 'message' => 'Action inconnue.'], 400);
    }
}
