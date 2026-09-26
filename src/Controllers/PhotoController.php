<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Http;
use JC\Domain\Events;
use JC\Domain\Formats;
use JC\Domain\ListingBuilder;

/**
 * Espace photographe : uniquement les véhicules réellement en roulage (Journée/Matin/Après-midi avec un véhicule
 * renseigné) — pas les passagers, pilotes supplémentaires ni accompagnants. Repris de l'ancien portail (photographe.php).
 */
final class PhotoController extends BaseController
{
    private function rows(array $event): array
    {
        $rows = (new ListingBuilder($this->db(), $event))->build();
        return array_values(array_filter($rows, fn($r) => !$r['cancelled'] && Formats::isRolling($r['duration']) && trim((string)$r['vehicle']) !== ''));
    }

    public function index(): void
    {
        $db = $this->db();
        $event = $this->eventFromQuery();
        $rows = $this->rows($event);
        if (Http::str('export') === 'csv') {
            $safe = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$event['circuit_name']) ?: 'journee';
            header('Content-Type: text/csv; charset=UTF-8');
            header('Content-Disposition: attachment; filename="photographe-' . $safe . '-' . $event['event_date'] . '.csv"');
            header('Cache-Control: no-store, no-cache, must-revalidate');
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Participant', 'Véhicule', 'REF', 'Format', 'Email'], ';');
            foreach ($rows as $r) {
                fputcsv($out, [$r['name'], $r['vehicle'], $r['reference'], $r['duration'], $r['email']], ';');
            }
            fclose($out);
            exit;
        }
        $this->view('photo/index', [
            'title'  => 'Photographe',
            'event'  => $event,
            'groups' => Events::groups($db),
            'rows'   => $rows,
            'page'   => 'photo',
        ]);
    }
}
