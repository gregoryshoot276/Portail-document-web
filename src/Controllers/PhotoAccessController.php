<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\App;
use JC\Core\Db;
use JC\Core\Http;
use JC\Core\Throttle;
use JC\Core\View;
use JC\Domain\Events;
use JC\Domain\Formats;
use JC\Domain\ListingBuilder;
use JC\Domain\PhotoPublicAccess as PPA;

/** Réglages admin + page publique de l'accès Photographe sans identifiants (lien secret + code PIN optionnel),
 * même principe que l'accès Starter public (cf. StarterAccessController). Volontairement simple : la page Photo
 * est en lecture seule (pas d'action à protéger), donc pas de sous-API comme pour le Starter. */
final class PhotoAccessController extends BaseController
{
    // ---------- Admin ----------

    public function settingsForm(): void
    {
        $db = $this->db();
        $this->view('photo/access_settings', [
            'title'   => 'Accès Photographe',
            'enabled' => PPA::enabled($db),
            'hasPin'  => PPA::hasPin($db),
            'link'    => rtrim((string)App::config('base_url', ''), '/') . '/photo-public?k=' . PPA::token($db),
        ]);
    }

    public function settingsSave(): void
    {
        $db = $this->db();
        $action = Http::str('action', 'save');
        if ($action === 'regen') {
            PPA::regenerateToken($db);
            flash('ok', 'Lien secret régénéré. L’ancien lien ne fonctionne plus.');
        } elseif ($action === 'disable') {
            PPA::disable($db);
            flash('ok', 'Accès Photographe public désactivé.');
        } else {
            $pin = Http::str('pin');
            if ($pin !== '' && !preg_match('/^\d{6}$/', $pin)) {
                flash('ko', 'Le code PIN doit contenir exactement 6 chiffres.');
                Http::redirect('/photo/acces');
            }
            PPA::save($db, !empty(Http::input('enabled')), $pin !== '' ? $pin : null);
            flash('ok', 'Accès Photographe enregistré.');
        }
        Http::redirect('/photo/acces');
    }

    // ---------- Public (sans connexion) ----------

    private function sessionKey(): string
    {
        return 'photo_public_unlocked';
    }

    public function publicForm(): void
    {
        $db = $this->db();
        $this->checkAccess($db);
        if (PPA::hasPin($db) && empty($_SESSION[$this->sessionKey()])) {
            View::render('photo/public_pin', ['error' => null, 'k' => Http::str('k'), 'pageTitle' => 'Photographe', 'appTitle' => 'JC Photo'], 'layout_starter_pin');
            return;
        }
        $this->showPhoto($db, Http::str('k'));
    }

    public function publicUnlock(): void
    {
        $db = $this->db();
        $this->checkAccess($db);
        $token = Http::str('k');
        $keys = ['ip:' . Http::ip(), 'photopublic'];
        foreach ($keys as $tk) {
            $wait = Throttle::lockedFor($tk);
            if ($wait > 0) {
                View::render('photo/public_pin', ['error' => 'Trop de tentatives. Réessayez dans ' . (int)ceil($wait / 60) . ' minute(s).', 'k' => $token, 'pageTitle' => 'Photographe', 'appTitle' => 'JC Photo'], 'layout_starter_pin');
                return;
            }
        }
        if (PPA::verifyPin($db, Http::str('pin'))) {
            foreach ($keys as $tk) {
                Throttle::clear($tk);
            }
            $_SESSION[$this->sessionKey()] = true;
            Http::redirect('/photo-public?k=' . $token);
        }
        foreach ($keys as $tk) {
            Throttle::fail($tk, 10, 15);
        }
        View::render('photo/public_pin', ['error' => 'Code incorrect.', 'k' => $token, 'pageTitle' => 'Photographe', 'appTitle' => 'JC Photo'], 'layout_starter_pin');
    }

    /** Lien activé et jeton correct : accès autorisé, sinon 404. */
    private function checkAccess(Db $db): void
    {
        if (!PPA::enabled($db)) {
            Http::abort(404);
        }
        $k = Http::str('k');
        if ($k === '' || !hash_equals(PPA::token($db), $k)) {
            Http::abort(404);
        }
    }

    private function rows(array $event): array
    {
        $rows = (new ListingBuilder($this->db(), $event))->build();
        return array_values(array_filter($rows, fn($r) => !$r['cancelled'] && Formats::isRolling($r['duration']) && trim((string)$r['vehicle']) !== ''));
    }

    private function showPhoto(Db $db, string $k): void
    {
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
        View::render('photo/public_view', [
            'event'    => $event,
            'groups'   => Events::groups($db),
            'rows'     => $rows,
            'k'        => $k,
            'page'     => 'photo',
            'pageTitle' => 'Photographe',
            'appTitle'  => 'JC Photo',
        ], 'layout_starter_pin');
    }
}
