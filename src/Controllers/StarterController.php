<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Auth;
use JC\Core\Http;
use JC\Domain\Events;
use JC\Domain\Formats;
use JC\Domain\ListingBuilder;
use JC\Domain\Settings;
use JC\Domain\StarterLog;

final class StarterController extends BaseController
{
    /** Voitures à contrôler : pilotes, non annulés, format de roulage. */
    public static function starterRows(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            if ($r['cancelled'] || $r['type'] !== 'pilot' || !Formats::isRolling($r['duration'])) {
                continue;
            }
            $out[] = [
                'id'      => $r['id'],
                'ref'     => trim($r['reference']),
                'vehicle' => $r['vehicle'],
                'format'  => $r['duration'],
                'status'  => $r['starter'],
                'checked' => trim(((string)($r['starter_info']['checked_label'] ?? '')) . ' ' . ((string)($r['starter_info']['checked_at'] ?? ''))),
            ];
        }
        usort($out, function ($a, $b) {
            if ($a['ref'] === '' && $b['ref'] !== '') {
                return 1;
            }
            if ($b['ref'] === '' && $a['ref'] !== '') {
                return -1;
            }
            return strnatcasecmp($a['ref'], $b['ref']);
        });
        return $out;
    }

    public function index(): void
    {
        $db = $this->db();
        $groups = Events::groups($db);
        if (Http::int('event') <= 0) {
            $this->view('starter/events', ['title' => 'Starter', 'groups' => $groups]);
            return;
        }
        $event = $this->event(Http::int('event'));
        $rows = self::starterRows((new ListingBuilder($db, $event))->build());
        $count = [];
        foreach ($rows as $r) {
            if ($r['ref'] !== '') {
                $k = mb_strtoupper($r['ref'], 'UTF-8');
                $count[$k] = ($count[$k] ?? 0) + 1;
            }
        }
        $this->view('starter/index', [
            'title'     => 'Starter',
            'event'     => $event,
            'groups'    => $groups,
            'rows'      => $rows,
            'dups'      => array_keys(array_filter($count, fn($n) => $n > 1)),
            'log'       => StarterLog::history($db, (int)$event['id']),
            'bracelets' => [
                'pilot'      => Settings::get($db, 'bracelet_color_pilot', '#e5e500'),
                'pilot_f'    => Settings::get($db, 'bracelet_color_pilot_f', '#ff8fd6'),
                'passenger'  => Settings::get($db, 'bracelet_color_passenger', '#2f7bd1'),
            ],
            'page'      => 'starter',
            'mainClass' => 'wide',
            'boot'      => ['event' => (int)$event['id'], 'apiBase' => '/starter/' . (int)$event['id'] . '/api'],
        ]);
    }

    public function api(int $eventId): void
    {
        $this->event($eventId);
        $w = $this->writer();
        $action = Http::str('action');
        $entryId = Http::int('entry_id');
        if ($action === 'check') {
            $w->starterSet($eventId, $entryId, Http::str('status', 'pending'));
            Http::json(['ok' => true]);
        }
        if ($action === 'reference') {
            $w->saveField($entryId, 'reference', Http::str('reference'));
            Http::json(['ok' => true]);
        }
        if ($action === 'log') {
            $u = Auth::user();
            $by = $u !== null ? (string)($u['name'] ?? '') : '';
            $entry = StarterLog::log($this->db(), $eventId, Http::str('status'), Http::str('reason'), $by);
            Http::json(['ok' => true, 'entry' => $entry]);
        }
        if ($action === 'bracelet') {
            $type = Http::str('type');
            if (!in_array($type, ['pilot', 'pilot_f', 'passenger'], true)) {
                Http::json(['ok' => false, 'message' => 'Type de bracelet inconnu.'], 400);
            }
            $color = Http::str('color');
            if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
                Http::json(['ok' => false, 'message' => 'Couleur invalide.'], 400);
            }
            Settings::set($this->db(), 'bracelet_color_' . $type, $color);
            Http::json(['ok' => true]);
        }
        Http::json(['ok' => false, 'message' => 'Action inconnue.'], 400);
    }
}
