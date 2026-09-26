<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Auth;
use JC\Core\Http;
use JC\Core\Mode;
use JC\Core\View;
use JC\Domain\Events;
use JC\Domain\Formats;
use JC\Domain\Labels;
use JC\Domain\ListingBuilder;
use JC\Domain\ListingMerge;
use JC\Domain\ListingSync;
use JC\Domain\Notifier;
use JC\Domain\Text;

final class ListingController extends BaseController
{
    public function index(): void
    {
        $db = $this->db();
        $event = $this->eventFromQuery();
        $rows = (new ListingBuilder($db, $event))->build();

        $active = array_filter($rows, fn($r) => !$r['cancelled']);
        $byFormat = [];
        foreach ($active as $r) {
            $c = Formats::canonical((string)$r['duration']);
            if ($c !== '') {
                $byFormat[$c] = ($byFormat[$c] ?? 0) + 1;
            }
        }
        $stats = [
            'total'      => count($rows),
            'active'     => count($active),
            'cancelled'  => count($rows) - count($active),
            'ready'      => count(array_filter($active, fn($r) => $r['ready'])),
            'remaining'  => array_sum(array_map(fn($r) => max(0.0, (float)$r['remaining']), $active)),
            'no_ref'     => count(array_filter($active, fn($r) => Formats::isRolling($r['duration']) && trim($r['reference']) === '' && $r['type'] === 'pilot')),
            'no_price'   => count(array_filter($active, fn($r) => !$r['price_known'])),
            'no_ticket'  => count(array_filter($active, fn($r) => empty($r['entry']['billetweb_attendee_id']))),
            'by_format'  => $byFormat,
        ];
        $this->view('listing/index', [
            'title'   => 'Listing',
            'event'   => $event,
            'groups'  => Events::groups($db),
            'rows'    => $rows,
            'stats'   => $stats,
            'formats' => Formats::choices(array_column($rows, 'duration')),
            'dupRefs' => $this->duplicateRefs($rows),
            'dupNames' => $this->duplicateNames($rows),
            'page'      => 'listing',
            'mainClass' => 'wide',
            'boot'      => [
                'event'   => (int)$event['id'],
                'formats' => Formats::choices(array_column($rows, 'duration')),
                'now'     => (int)$db->val('SELECT UNIX_TIMESTAMP()'),
            ],
        ]);
    }

    /** Export « MONO » : personnes ayant déclaré leur première fois sur circuit (repris de l'ancien portail). */
    public function mono(int $eventId): void
    {
        $event = $this->event($eventId);
        $rows = array_values(array_filter((new ListingBuilder($this->db(), $event))->build(), fn($r) => !$r['cancelled'] && $r['first_time'] === 'OUI'));
        if (Http::str('format') === 'csv') {
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="mono-' . $event['event_date'] . '.csv"');
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['REF', 'Participant', 'Véhicule'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['reference'], $r['name'], $r['vehicle']], ';');
            }
            fclose($out);
            exit;
        }
        $this->view('listing/mono', ['title' => 'Export MONO', 'event' => $event, 'rows' => $rows, 'page' => 'listing']);
    }

    /** REF utilisées plusieurs fois (hors lignes annulées). */
    private function duplicateRefs(array $rows): array
    {
        $count = [];
        foreach ($rows as $r) {
            $k = mb_strtoupper(trim($r['reference']), 'UTF-8');
            if ($k !== '' && !$r['cancelled']) {
                $count[$k] = ($count[$k] ?? 0) + 1;
            }
        }
        return array_keys(array_filter($count, fn($n) => $n > 1));
    }

    /** Noms utilisés plusieurs fois (hors lignes annulées) : signale un doublon probable à fusionner. */
    private function duplicateNames(array $rows): array
    {
        $count = [];
        foreach ($rows as $r) {
            $k = Text::normalize((string)$r['name']);
            if ($k !== '' && !$r['cancelled']) {
                $count[$k] = ($count[$k] ?? 0) + 1;
            }
        }
        return array_keys(array_filter($count, fn($n) => $n > 1));
    }

    /** Actions d'édition (JSON). */
    public function api(int $eventId): void
    {
        $event = $this->event($eventId);
        $w = $this->writer();
        $in = Http::body();
        $action = (string)($in['action'] ?? '');
        $entryId = (int)($in['entry_id'] ?? 0);

        switch ($action) {
            case 'save':
                $w->saveField($entryId, (string)($in['field'] ?? ''), (string)($in['value'] ?? ''));
                break;
            case 'waiver_override':
                $w->waiverOverride($entryId, (string)($in['status'] ?? ''));
                break;
            case 'no_remind':
                Mode::assertWritable();
                $w->setNoRemind($entryId, !empty($in['value']));
                break;
            case 'payment_add':
                $w->addPayment($entryId, (string)($in['context'] ?? 'onsite'), (string)($in['method'] ?? ''), Text::float($in['amount'] ?? 0), (string)($in['note'] ?? ''));
                break;
            case 'payment_replace':
                $w->replacePayment($entryId, (string)($in['context'] ?? ''), (string)($in['method'] ?? ''), Text::float($in['amount'] ?? 0));
                break;
            case 'payment_delete':
                $w->deletePayment((int)($in['id'] ?? 0));
                break;
            case 'payment_list':
                $one = (new ListingBuilder($this->db(), $event))->build([$entryId]);
                Http::json(['ok' => true, 'rows' => $one ? $one[0]['payments'] : [], 'auto' => $one ? round((float)$one[0]['auto_advance'], 2) : 0]);
            case 'create_row':
                $w->createRow($eventId, $in);
                Http::json(['ok' => true, 'reload' => true]);
            case 'delete_row':
                $w->hideOrDelete($eventId, $entryId);
                Http::json(['ok' => true, 'removed' => $entryId]);
            case 'merge_rows':
                Mode::assertWritable();
                $res = ListingMerge::execute($this->db(), $eventId, (int)($in['entry_a'] ?? 0), (int)($in['entry_b'] ?? 0), $in, Auth::id(), Auth::label());
                ListingMerge::reconcileEvent($this->db(), $eventId, Auth::id());
                Http::json(['ok' => true, 'reload' => true] + $res);
            case 'reminder_mail':
                Mode::assertWritable();
                $rows = (new ListingBuilder($this->db(), $event))->build([$entryId]);
                if (!$rows) {
                    Http::json(['ok' => false, 'message' => 'Inscription introuvable.'], 404);
                }
                try {
                    $to = Notifier::reminderForEntry($this->db(), $rows[0], $event, Auth::id());
                } catch (\RuntimeException $e) {
                    Http::json(['ok' => false, 'message' => $e->getMessage()], 400);
                }
                Http::json(['ok' => true, 'message' => 'Relance envoyée à ' . $to]);
            case 'sync_remote':
                Http::json($this->syncRemote($eventId, !empty($in['force'])));
            default:
                Http::json(['ok' => false, 'message' => 'Action inconnue.'], 400);
        }
        Http::json(['ok' => true, 'html' => $this->rowHtml($event, $entryId)]);
    }

    /** Synchronisation Billetweb -> listing (logique partagée, cf. ListingSync::runThrottled). */
    private function syncRemote(int $eventId, bool $force): array
    {
        return ListingSync::runThrottled($this->db(), $eventId, Auth::id(), $force);
    }

    private function rowHtml(array $event, int $entryId): string
    {
        $rows = (new ListingBuilder($this->db(), $event))->build([$entryId]);
        return $rows ? View::fetch('listing/_row', ['r' => $rows[0], 'dupRefs' => [], 'ro' => read_only()]) : '';
    }

    /** Lignes recalculées (HTML) pour une liste d'identifiants. */
    public function rows(int $eventId): void
    {
        $event = $this->event($eventId);
        $ids = array_slice(array_filter(array_map('intval', explode(',', Http::str('ids')))), 0, 60);
        $out = [];
        if ($ids) {
            foreach ((new ListingBuilder($this->db(), $event))->build($ids) as $r) {
                $out[(string)$r['id']] = View::fetch('listing/_row', ['r' => $r, 'dupRefs' => [], 'ro' => read_only()]);
            }
        }
        Http::json(['ok' => true, 'rows' => $out]);
    }

    /** Sondage : quelles lignes ont changé depuis un instant donné (autre poste ou autre écran). */
    public function poll(int $eventId): void
    {
        $this->event($eventId);
        $db = $this->db();
        $since = max(0, Http::int('since'));
        $ids = $db->col(
            'SELECT o.listing_entry_id FROM listing_entry_overrides o JOIN listing_entries e ON e.id=o.listing_entry_id WHERE e.event_id=? AND o.updated_at>FROM_UNIXTIME(?)
             UNION SELECT p.listing_entry_id FROM listing_entry_payments p JOIN listing_entries e ON e.id=p.listing_entry_id WHERE e.event_id=? AND p.created_at>FROM_UNIXTIME(?)
             UNION SELECT s.listing_entry_id FROM listing_starter_checks s WHERE s.event_id=? AND s.checked_at>FROM_UNIXTIME(?)
             UNION SELECT r.listing_entry_id FROM listing_rfid_tags r WHERE r.event_id=? AND r.updated_at>FROM_UNIXTIME(?)',
            [$eventId, $since, $eventId, $since, $eventId, $since, $eventId, $since]
        );
        $new = (int)$db->val('SELECT COUNT(*) FROM listing_entries WHERE event_id=? AND created_at>FROM_UNIXTIME(?)', [$eventId, $since]);
        Http::json(['ok' => true, 'ids' => array_map('intval', $ids), 'new_rows' => $new > 0, 'now' => (int)$db->val('SELECT UNIX_TIMESTAMP()')]);
    }

    public function exportCsv(int $eventId): void
    {
        $event = $this->event($eventId);
        $rows = (new ListingBuilder($this->db(), $event))->build();
        $cell = static function ($v): string {
            $s = (string)$v;
            // Protection contre l'injection de formules dans Excel / LibreOffice.
            return ($s !== '' && str_contains('=+-@', $s[0])) ? "'" . $s : $s;
        };
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="listing-' . $event['event_date'] . '.csv"');
        header('Cache-Control: private, no-store');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['VAL', 'Participant', 'Véhicule / pilote', 'REF', 'A', 'D', 'Format', 'Prix dû', 'Réduc', 'Avoir', 'Avance', 'Sur place', 'Reste', 'Notes', 'Tél', 'Mail', '1ère fois', 'Annulé'], ';');
        foreach ($rows as $r) {
            fputcsv($out, array_map($cell, [
                $r['validator'], $r['name'], $r['vehicle'], $r['reference'], $r['insurance'], $r['decharge'], $r['code'],
                number_format($r['amount_due'], 2, ',', ''), number_format($r['discount'], 2, ',', ''), number_format($r['credit'], 2, ',', ''),
                number_format($r['advance'], 2, ',', ''), number_format($r['onsite'], 2, ',', ''), number_format($r['remaining'], 2, ',', ''),
                $r['notes'], $r['phone'], $r['email'], $r['first_time'], $r['cancelled'] ? 'OUI' : '',
            ]), ';');
        }
        fclose($out);
        exit;
    }

    /** PDF d'étiquettes : mêmes lignes que le listing. ?ids=1,2,3 pour se limiter à une sélection. */
    public function labels(int $eventId): void
    {
        $event = $this->event($eventId);
        $rows = (new ListingBuilder($this->db(), $event))->build();
        $ids = array_filter(array_map('intval', explode(',', Http::str('ids'))));
        if ($ids) {
            $rows = array_values(array_filter($rows, fn($r) => in_array($r['id'], $ids, true)));
        }
        usort($rows, fn($a, $b) => strnatcasecmp($a['reference'], $b['reference']));
        Labels::render($rows);
    }
}
