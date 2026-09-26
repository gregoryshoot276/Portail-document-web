<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Domain\Events;

final class DashboardController extends BaseController
{
    public function index(): void
    {
        $db = $this->db();
        $g = Events::groups($db);
        $today = date('Y-m-d');
        // Prochaines journées : 6 maximum, à partir d'aujourd'hui (les 15 derniers jours restent accessibles dans la liste).
        $upcoming = array_slice(array_values(array_filter(array_merge($g['visible'], $g['future']), fn($e) => $e['event_date'] >= $today)), 0, 6);
        $recent = array_values(array_filter($g['visible'], fn($e) => $e['event_date'] < $today));

        $cards = [];
        foreach (array_merge($upcoming, array_slice(array_reverse($recent), 0, 2)) as $e) {
            $eid = (int)$e['id'];
            $status = [];
            foreach ($db->all('SELECT global_status,COUNT(*) n FROM participants WHERE event_id=? AND archived_at IS NULL GROUP BY global_status', [$eid]) as $r) {
                $status[(string)$r['global_status']] = (int)$r['n'];
            }
            $cards[] = [
                'event'    => $e,
                'entries'  => (int)$db->val('SELECT COUNT(*) FROM listing_entries le LEFT JOIN listing_entry_hidden h ON h.listing_entry_id=le.id WHERE le.event_id=? AND h.listing_entry_id IS NULL', [$eid]),
                'dossiers' => array_sum($status),
                'status'   => $status,
                'waivers'  => (int)$db->val('SELECT COUNT(*) FROM waivers w JOIN participants p ON p.id=w.participant_id WHERE p.event_id=? AND p.archived_at IS NULL', [$eid]),
                'starter'  => $db->all('SELECT status,COUNT(*) n FROM listing_starter_checks WHERE event_id=? GROUP BY status', [$eid]),
                'past'     => $e['event_date'] < $today,
            ];
        }
        $toReview = (int)$db->val("SELECT COUNT(*) FROM participants WHERE archived_at IS NULL AND global_status IN ('to_review','pending') AND event_id IN (SELECT id FROM events WHERE is_active=1 AND event_date>=CURDATE())");

        $this->view('dashboard', ['title' => 'Tableau de bord', 'cards' => $cards, 'toReview' => $toReview]);
    }
}
