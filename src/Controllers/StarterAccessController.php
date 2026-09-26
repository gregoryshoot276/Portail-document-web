<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\App;
use JC\Core\Db;
use JC\Core\Http;
use JC\Core\Throttle;
use JC\Core\View;
use JC\Domain\Events;
use JC\Domain\ListingBuilder;
use JC\Domain\ListingWriter;
use JC\Domain\Settings;
use JC\Domain\StarterLog;
use JC\Domain\StarterPublicAccess as SPA;

/** Réglages admin + page publique de l'accès Starter sans identifiants (lien secret + code PIN optionnel,
 * verrouillé sur une seule journée à la fois). Volontairement simple. */
final class StarterAccessController extends BaseController
{
    // ---------- Admin ----------

    public function settingsForm(): void
    {
        $db = $this->db();
        $this->view('starter/access_settings', [
            'title'    => 'Accès Starter',
            'events'   => Events::all($db),
            'enabled'  => SPA::enabled($db),
            'eventId'  => SPA::eventId($db),
            'hasPin'   => SPA::hasPin($db),
            'link'     => rtrim((string)App::config('base_url', ''), '/') . '/starter-public?k=' . SPA::token($db),
        ]);
    }

    public function settingsSave(): void
    {
        $db = $this->db();
        $action = Http::str('action', 'save');
        if ($action === 'regen') {
            SPA::regenerateToken($db);
            flash('ok', 'Lien secret régénéré. L’ancien lien ne fonctionne plus.');
        } elseif ($action === 'disable') {
            SPA::disable($db);
            flash('ok', 'Accès Starter public désactivé.');
        } else {
            $eventId = Http::int('event_id');
            if ($eventId <= 0 || !Events::find($db, $eventId)) {
                flash('ko', 'Choisissez la journée accessible au Starter.');
                Http::redirect('/starter/acces');
            }
            $pin = Http::str('pin');
            if ($pin !== '' && !preg_match('/^\d{6}$/', $pin)) {
                flash('ko', 'Le code PIN doit contenir exactement 6 chiffres.');
                Http::redirect('/starter/acces');
            }
            SPA::save($db, !empty(Http::input('enabled')), $eventId, $pin !== '' ? $pin : null);
            flash('ok', 'Accès Starter enregistré.');
        }
        Http::redirect('/starter/acces');
    }

    // ---------- Public (sans connexion) ----------

    private function sessionKey(): string
    {
        return 'starter_public_unlocked';
    }

    public function publicForm(): void
    {
        $db = $this->db();
        $eventId = $this->checkAccess($db);
        if (SPA::hasPin($db) && empty($_SESSION[$this->sessionKey()])) {
            View::render('starter/public_pin', ['error' => null, 'k' => Http::str('k')], 'layout_starter_pin');
            return;
        }
        $this->showStarter($db, $eventId);
    }

    public function publicUnlock(): void
    {
        $db = $this->db();
        $this->checkAccess($db);
        $token = Http::str('k');
        $keys = ['ip:' . Http::ip(), 'starterpublic'];
        foreach ($keys as $tk) {
            $wait = Throttle::lockedFor($tk);
            if ($wait > 0) {
                View::render('starter/public_pin', ['error' => 'Trop de tentatives. Réessayez dans ' . (int)ceil($wait / 60) . ' minute(s).', 'k' => $token], 'layout_starter_pin');
                return;
            }
        }
        if (SPA::verifyPin($db, Http::str('pin'))) {
            foreach ($keys as $tk) {
                Throttle::clear($tk);
            }
            $_SESSION[$this->sessionKey()] = true;
            Http::redirect('/starter-public?k=' . $token);
        }
        foreach ($keys as $tk) {
            Throttle::fail($tk, 10, 15);
        }
        View::render('starter/public_pin', ['error' => 'Code incorrect.', 'k' => $token], 'layout_starter_pin');
    }

    public function publicApi(): void
    {
        $db = $this->db();
        $eventId = $this->checkAccess($db);
        if (SPA::hasPin($db) && empty($_SESSION[$this->sessionKey()])) {
            Http::json(['ok' => false, 'message' => 'Accès non déverrouillé.'], 403);
        }
        $this->event($eventId);
        $w = new ListingWriter($db, 0, 'Starter (accès public)');
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
            $entry = StarterLog::log($db, $eventId, Http::str('status'), Http::str('reason'), 'Starter (accès public)');
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
            Settings::set($db, 'bracelet_color_' . $type, $color);
            Http::json(['ok' => true]);
        }
        Http::json(['ok' => false, 'message' => 'Action inconnue.'], 400);
    }

    /** Lien activé, jeton correct, journée configurée : renvoie l'id de la journée ou abandonne (404). */
    private function checkAccess(Db $db): int
    {
        if (!SPA::enabled($db)) {
            Http::abort(404);
        }
        $k = Http::str('k');
        if ($k === '' || !hash_equals(SPA::token($db), $k)) {
            Http::abort(404);
        }
        $eventId = SPA::eventId($db);
        if ($eventId <= 0 || !Events::find($db, $eventId)) {
            Http::abort(404);
        }
        return $eventId;
    }

    private function showStarter(Db $db, int $eventId): void
    {
        $event = $this->event($eventId);
        $rows = StarterController::starterRows((new ListingBuilder($db, $event))->build());
        $count = [];
        foreach ($rows as $r) {
            if ($r['ref'] !== '') {
                $c = mb_strtoupper($r['ref'], 'UTF-8');
                $count[$c] = ($count[$c] ?? 0) + 1;
            }
        }
        View::render('starter/public_view', [
            'event'     => $event,
            'rows'      => $rows,
            'dups'      => array_keys(array_filter($count, fn($n) => $n > 1)),
            'log'       => StarterLog::history($db, $eventId),
            'bracelets' => [
                'pilot'     => Settings::get($db, 'bracelet_color_pilot', '#e5e500'),
                'pilot_f'   => Settings::get($db, 'bracelet_color_pilot_f', '#ff8fd6'),
                'passenger' => Settings::get($db, 'bracelet_color_passenger', '#2f7bd1'),
            ],
            'page'      => 'starter',
            'boot'      => ['event' => $eventId, 'apiBase' => '/starter-public/api?k=' . Http::str('k')],
        ], 'layout_starter_pin');
    }
}
