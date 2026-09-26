<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Http;
use JC\Domain\Bilan;
use JC\Domain\Events;
use JC\Domain\Pricing;
use JC\Domain\Text;

final class BilanController extends BaseController
{
    public function index(): void
    {
        $db = $this->db();
        $lines = [];
        $cum = 0.0;
        $objCum = 0.0;
        foreach (Events::all($db) as $e) {
            $l = Bilan::line($db, $e);
            $cum += $l['result'];
            $objCum += $l['obj'];
            $l += ['cum' => $cum, 'obj_cum' => $objCum, 'gap' => $l['result'] - $l['obj'], 'gap_cum' => $cum - $objCum];
            $lines[] = $l;
        }
        $focusId = Http::int('event');
        $focus = null;
        foreach ($lines as $l) {
            if ((int)$l['event']['id'] === $focusId) {
                $focus = $l;
            }
        }
        $this->view('bilan', ['title' => 'Bilan', 'lines' => $lines, 'focus' => $focus, 'page' => 'bilan', 'mainClass' => 'wide']);
    }

    public function save(): void
    {
        $this->writer()->financeSet(Http::int('event_id'), Http::str('field'), Text::float(Http::input('value', 0)));
        Http::json(['ok' => true]);
    }

    public function tarifs(): void
    {
        $db = $this->db();
        $events = Events::all($db);
        $maps = [];
        foreach ($events as $e) {
            $maps[(int)$e['id']] = Pricing::forEvent($db, (int)$e['id']);
        }
        $this->view('tarifs', ['title' => 'Tarifs', 'events' => $events, 'maps' => $maps, 'codes' => Pricing::CODES]);
    }

    public function tarifsSave(): void
    {
        $w = $this->writer();
        $n = 0;
        foreach ((array)Http::input('tariff', []) as $eventId => $codes) {
            foreach ((array)$codes as $code => $raw) {
                $s = trim(str_replace(',', '.', (string)$raw));
                $w->tariffSet((int)$eventId, (string)$code, $s === '' ? null : (float)$s);
                $n++;
            }
        }
        flash('ok', 'Tarifs enregistrés.');
        Http::redirect('/tarifs');
    }
}
