<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/**
 * Client de l'API URTime Hub (chronométrage RFID) : événements, checkpoints, détections.
 * La clé API est relue dans app_settings.urtime_api_key (la même que l'ancien portail) ou dans la variable d'environnement URTIME_API_KEY.
 */
final class Urtime
{
    private const BASE = 'https://hub.urtime.net/api/v1';

    public function __construct(private Db $db)
    {
    }

    public function key(): string
    {
        $env = trim((string)getenv('URTIME_API_KEY'));
        if ($env !== '') {
            return $env;
        }
        return trim((string)$this->db->val("SELECT setting_value FROM app_settings WHERE setting_key='urtime_api_key'"));
    }

    public function hasKey(): bool
    {
        return $this->key() !== '';
    }

    public static function localToUtc(string $v): string
    {
        $t = strtotime($v);
        return $t ? gmdate('Y-m-d\TH:i:s\Z', $t) : '';
    }

    public static function utcToLocal(mixed $v): string
    {
        if (!$v) {
            return '';
        }
        $t = strtotime((string)$v);
        return $t ? date('Y-m-d H:i:s', $t) : (string)$v;
    }

    /** @throws \RuntimeException */
    public function get(string $path, array $query = []): array
    {
        $key = $this->key();
        if ($key === '') {
            throw new \RuntimeException('Clé API URTime absente (Réglages ou app_settings.urtime_api_key).');
        }
        $url = self::BASE . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => ['X-Api-Key: ' . $key, 'Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $m = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Connexion URTime : ' . $m);
        }
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode((string)$body, true);
        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('URTime HTTP ' . $status . ' : ' . mb_substr(is_array($json) ? json_encode($json, JSON_UNESCAPED_UNICODE) : (string)$body, 0, 300));
        }
        if (!is_array($json)) {
            throw new \RuntimeException('Réponse URTime non JSON.');
        }
        return $json;
    }

    public function events(): array
    {
        $j = $this->get('/events', ['per_page' => 100]);
        return $j['data'] ?? $j;
    }

    public function checkpoints(int $eventId): array
    {
        $j = $this->get('/events/' . $eventId . '/checkpoints', ['per_page' => 100]);
        return $j['data'] ?? $j;
    }

    /** Détections brutes d'un événement URTime (export complet sur la période). */
    public function export(int $eventId, string $start = '', string $end = ''): array
    {
        $q = ['filters[event_id]' => $eventId];
        if ($start !== '') {
            $q['filters[start_at]'] = self::localToUtc($start);
        }
        if ($end !== '') {
            $q['filters[end_at]'] = self::localToUtc($end);
        }
        $all = $this->get('/detections/export', $q);
        if (isset($all['data']) && is_array($all['data'])) {
            $all = $all['data'];
        }
        return is_array($all) ? $all : [];
    }

    /**
     * Regroupe les détections par puce (bib) et reconstitue les sessions de roulage
     * (une entrée puis une sortie). Chaque puce est rapprochée de sa ligne du listing.
     *
     * @param array<int,array> $detections
     * @param array<string,array> $tagMap tag_uid => ['reference','participant','vehicle']
     */
    public static function sessions(array $detections, array $tagMap): array
    {
        $byBib = [];
        foreach ($detections as $r) {
            $bib = ListingWriter::rfidNormalize((string)($r['bib'] ?? ''));
            if ($bib === '') {
                continue;
            }
            $ts = strtotime((string)($r['timestamp'] ?? ''));
            if (!$ts) {
                continue;
            }
            $r['_ts'] = $ts;
            $byBib[$bib][] = $r;
        }
        $cars = [];
        foreach ($byBib as $bib => $list) {
            usort($list, fn($a, $b) => $a['_ts'] <=> $b['_ts']);
            $sessions = [];
            $open = null;
            $total = 0.0;
            $done = 0;
            foreach ($list as $r) {
                if ($open === null) {
                    $open = $r;
                    continue;
                }
                $dur = max(0, (float)$r['_ts'] - (float)$open['_ts']);
                $sessions[] = ['entry_at' => self::utcToLocal($open['timestamp'] ?? ''), 'exit_at' => self::utcToLocal($r['timestamp'] ?? ''), 'duration_seconds' => $dur];
                $total += $dur;
                $done++;
                $open = null;
            }
            if ($open !== null) {
                $sessions[] = ['entry_at' => self::utcToLocal($open['timestamp'] ?? ''), 'exit_at' => null, 'duration_seconds' => null];
            }
            $m = $tagMap[$bib] ?? [];
            $cars[] = [
                'bib'           => $bib,
                'reference'     => $m['reference'] ?? '',
                'participant'   => $m['participant'] ?? '',
                'vehicle'       => $m['vehicle'] ?? '',
                'sessions'      => $sessions,
                'session_count' => $done,
                'total_seconds' => $total,
                'on_track'      => $open !== null,
                'current_entry_at' => $open !== null ? self::utcToLocal($open['timestamp'] ?? '') : null,
            ];
        }
        usort($cars, fn($a, $b) => strnatcasecmp((string)$a['reference'], (string)$b['reference']));
        return $cars;
    }
}
