<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Domain\Events;
use JC\Domain\Formats;
use JC\Domain\ListingBuilder;

/**
 * Contrôle des données d'une journée : repère les anomalies à corriger avant le jour J
 * (REF manquante ou en double, prix inconnu, trop-perçu, ligne sans dossier...).
 */
final class ControleController extends BaseController
{
    public function index(): void
    {
        $db = $this->db();
        $event = $this->eventFromQuery();
        $rows = (new ListingBuilder($db, $event))->build();
        $active = array_values(array_filter($rows, fn($r) => !$r['cancelled']));

        $refs = [];
        foreach ($active as $r) {
            $k = mb_strtoupper(trim($r['reference']), 'UTF-8');
            if ($k !== '' && $r['type'] === 'pilot') {
                $refs[$k][] = $r;
            }
        }
        $dupRows = [];
        foreach ($refs as $group) {
            if (count($group) > 1) {
                array_push($dupRows, ...$group);
            }
        }
        $checks = [
            ['id' => 'no_ref', 'title' => 'REF manquante (pilote en roulage)', 'level' => 'warn',
                'rows' => array_values(array_filter($active, fn($r) => $r['type'] === 'pilot' && Formats::isRolling($r['duration']) && trim($r['reference']) === ''))],
            ['id' => 'dup_ref', 'title' => 'REF utilisée plusieurs fois', 'level' => 'ko',
                'rows' => $dupRows],
            ['id' => 'no_price', 'title' => 'Prix inconnu (aucun tarif configuré pour ce format)', 'level' => 'warn',
                'rows' => array_values(array_filter($active, fn($r) => !$r['price_known']))],
            ['id' => 'overpaid', 'title' => 'Trop-perçu (reste négatif)', 'level' => 'warn',
                'rows' => array_values(array_filter($active, fn($r) => $r['price_known'] && $r['remaining'] < -0.005))],
            ['id' => 'no_dossier', 'title' => 'Pas de dossier participant rattaché', 'level' => 'info',
                'rows' => array_values(array_filter($active, fn($r) => $r['progress']['state'] === 'none'))],
            ['id' => 'incomplete', 'title' => 'Dossier incomplet (décharge / permis)', 'level' => 'info',
                'rows' => array_values(array_filter($active, fn($r) => in_array($r['progress']['state'], ['missing', 'partial', 'review', 'correction'], true)))],
            ['id' => 'cancelled_paid', 'title' => 'Ligne annulée avec règlement saisi', 'level' => 'warn',
                'rows' => array_values(array_filter($rows, fn($r) => $r['cancelled'] && ($r['advance'] > 0 || $r['onsite'] > 0)))],
            ['id' => 'no_email', 'title' => 'Adresse e-mail absente ou invalide', 'level' => 'info',
                'rows' => array_values(array_filter($active, fn($r) => !filter_var(trim($r['email']), FILTER_VALIDATE_EMAIL)))],
        ];
        $this->view('controle', ['title' => 'Contrôle', 'event' => $event, 'groups' => Events::groups($db), 'checks' => $checks, 'total' => count($active)]);
    }
}
