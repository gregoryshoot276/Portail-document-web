<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

final class Events
{
    /** Journées actives réparties en archives (> 15 jours), visibles, futures (> 2 mois). */
    public static function groups(Db $db): array
    {
        $past = date('Y-m-d', strtotime('-15 days'));
        $future = date('Y-m-d', strtotime('+2 months'));
        $rows = $db->all('SELECT e.id,e.event_date,e.name,e.billetweb_event_id,e.sync_enabled,e.billetweb_last_sync,c.name circuit_name,c.slug
                          FROM events e JOIN circuits c ON c.id=e.circuit_id WHERE e.is_active=1 ORDER BY e.event_date, e.id');
        $g = ['archive' => [], 'visible' => [], 'future' => []];
        foreach ($rows as $r) {
            $d = (string)$r['event_date'];
            if ($d < $past) {
                $g['archive'][] = $r;
            } elseif ($d <= $future) {
                $g['visible'][] = $r;
            } else {
                $g['future'][] = $r;
            }
        }
        return $g;
    }

    public static function all(Db $db): array
    {
        $g = self::groups($db);
        return array_merge($g['archive'], $g['visible'], $g['future']);
    }

    public static function find(Db $db, int $id): ?array
    {
        return $db->row('SELECT e.*,c.name circuit_name,c.slug FROM events e JOIN circuits c ON c.id=e.circuit_id WHERE e.id=?', [$id]);
    }

    /** Journée à ouvrir par défaut : aujourd'hui, sinon la prochaine, sinon la dernière visible. */
    public static function defaultId(Db $db): int
    {
        $g = self::groups($db);
        $today = date('Y-m-d');
        foreach ($g['visible'] as $e) {
            if ($e['event_date'] === $today) {
                return (int)$e['id'];
            }
        }
        foreach ($g['visible'] as $e) {
            if ($e['event_date'] > $today) {
                return (int)$e['id'];
            }
        }
        if ($g['visible']) {
            return (int)end($g['visible'])['id'];
        }
        foreach ($g['future'] as $e) {
            return (int)$e['id'];
        }
        return 0;
    }
}
