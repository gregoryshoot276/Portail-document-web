<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Audit;
use JC\Core\Auth;
use JC\Core\Http;
use JC\Core\Mode;

final class SettingsController extends BaseController
{
    public function index(): void
    {
        $this->view('settings', [
            'title' => 'Réglages',
            'mode'  => Mode::info(),
            'admins' => $this->db()->all('SELECT id,display_name,email,role,is_active,last_login_at FROM admins ORDER BY is_active DESC, display_name'),
            'roles' => Auth::ROLE_LABELS,
        ]);
    }

    /** Bascule lecture seule / écriture. Le passage en écriture demande de taper un mot de confirmation. */
    public function mode(): void
    {
        $toReadOnly = Http::str('target') !== 'write';
        if (!$toReadOnly && mb_strtoupper(Http::str('confirm')) !== 'ECRITURE') {
            flash('warn', 'Pour activer l’écriture, tapez le mot ECRITURE dans la case de confirmation.');
            Http::redirect('/settings');
        }
        Mode::set($toReadOnly, Auth::label());
        Audit::log('v3.mode', null, ['read_only' => $toReadOnly]);
        flash('ok', $toReadOnly ? 'Mode lecture seule activé : plus aucune écriture en base.' : 'Mode écriture activé : les modifications sont maintenant enregistrées.');
        Http::redirect('/settings');
    }
}
