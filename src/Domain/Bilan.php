<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/** Bilan financier d'une journée, calculé à partir des lignes du listing. */
final class Bilan
{
    public const METHODS = ['AVR', 'CB', 'ESP', 'DIV', 'VIR'];

    /** @param array<int,array> $rows lignes de ListingBuilder::build() */
    public static function payments(Db $db, int $eventId, array $rows): array
    {
        $p = array_fill_keys(self::METHODS, 0.0);
        foreach ($rows as $r) {
            if ($r['cancelled']) {
                continue;
            }
            $adv = (float)$r['bw_paid'];
            if ($adv <= 0) {
                continue;
            }
            $m = Text::normalize((string)$r['bw_method']);
            if (str_contains($m, 'avoir')) {
                $p['AVR'] += $adv;
            } elseif (str_contains($m, 'cb') || str_contains($m, 'carte') || str_contains($m, 'stripe')) {
                $p['CB'] += $adv;
            } elseif (str_contains($m, 'esp')) {
                $p['ESP'] += $adv;
            } elseif (str_contains($m, 'vir')) {
                $p['VIR'] += $adv;
            } elseif (in_array(strtolower((string)$r['source']), ['billetweb', 'post'], true)) {
                $p['CB'] += $adv; // paiement en ligne sans mode explicite
            } else {
                $p['DIV'] += $adv;
            }
        }
        foreach ($db->all('SELECT p.payment_method,SUM(p.amount) amount FROM listing_entry_payments p JOIN listing_entries e ON e.id=p.listing_entry_id WHERE e.event_id=? GROUP BY p.payment_method', [$eventId]) as $r) {
            $m = Text::normalize((string)$r['payment_method']);
            $v = (float)$r['amount'];
            if (str_contains($m, 'avoir') || str_contains($m, 'avr')) {
                $p['AVR'] += $v;
            } elseif (str_contains($m, 'cb') || str_contains($m, 'carte')) {
                $p['CB'] += $v;
            } elseif (str_contains($m, 'esp')) {
                $p['ESP'] += $v;
            } elseif (str_contains($m, 'vir')) {
                $p['VIR'] += $v;
            } else {
                $p['DIV'] += $v;
            }
        }
        return $p;
    }

    public static function remainingTotal(array $rows): float
    {
        $t = 0.0;
        foreach ($rows as $r) {
            if ($r['cancelled']) {
                continue;
            }
            $t += max(0.0, (float)$r['remaining']);
        }
        return $t;
    }

    public static function finance(Db $db, int $eventId): array
    {
        return $db->row('SELECT * FROM listing_event_finance WHERE event_id=?', [$eventId])
            ?? ['event_id' => $eventId, 'piste' => 0, 'assurance' => 0, 'rh' => 0, 'service' => 0, 'deplacement' => 0, 'autres' => 0, 'objectif' => 0];
    }

    /** Ligne du bilan annuel (montants HT = TTC / 1,2). */
    public static function line(Db $db, array $event): array
    {
        $rows = (new ListingBuilder($db, $event))->build();
        $pay = self::payments($db, (int)$event['id'], $rows);
        $rest = self::remainingTotal($rows);
        $fin = self::finance($db, (int)$event['id']);
        $ht = fn(float $v) => $v / 1.2;
        $rev = [];
        foreach (self::METHODS as $m) {
            $rev[$m] = $ht($pay[$m]);
        }
        $rev['Reste'] = $ht($rest);
        $cost = (float)$fin['piste'] + (float)$fin['assurance'] + (float)$fin['rh'] + (float)$fin['service'] + (float)$fin['deplacement'] + (float)$fin['autres'];
        $cash = $rev['CB'] + $rev['ESP'] + $rev['DIV'] + $rev['VIR']; // l'avoir n'entre pas dans les recettes
        $revenue = $cash + $rev['Reste'];
        return [
            'event'   => $event,
            'finance' => $fin,
            'rev'     => $rev,
            'cost'    => $cost,
            'revenue' => $revenue,
            'result'  => $revenue - $cost,
            'obj'     => (float)$fin['objectif'],
        ];
    }
}
