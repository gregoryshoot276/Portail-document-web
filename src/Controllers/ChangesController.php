<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Audit;
use JC\Core\Auth;
use JC\Core\Http;
use JC\Core\Mode;
use JC\Core\View;
use JC\Domain\Changes;
use JC\Domain\ListingSync;
use JC\Domain\Mailer;
use JC\Domain\MailTemplates;
use JC\Domain\ParticipantLookup;

/** Annulation / remplacement : formulaire public (le participant identifie son inscription et déclare une
 * annulation ou un remplaçant) + validation admin (traitement, rafraîchissement Billetweb). */
final class ChangesController extends BaseController
{
    private function render(string $tpl, array $vars): void
    {
        View::render($tpl, $vars + ['pageTitle' => 'Annulation / remplacement'], 'layout_public');
    }

    private function futureEvents(): array
    {
        return $this->db()->all(
            "SELECT e.id,e.event_date,c.name circuit_name FROM events e JOIN circuits c ON c.id=e.circuit_id
             WHERE e.is_active=1 AND e.event_date>=CURDATE() ORDER BY e.event_date"
        );
    }

    public function form(): void
    {
        Changes::ensureTable($this->db());
        $this->render('changes/form', ['events' => $this->futureEvents(), 'ok' => '', 'err' => '']);
    }

    public function submit(): void
    {
        $db = $this->db();
        Changes::ensureTable($db);
        $ok = '';
        $err = '';
        try {
            if (Mode::readOnly()) {
                throw new \RuntimeException('Le site est actuellement en maintenance. Merci de réessayer plus tard.');
            }
            $eventId = Http::int('event_id');
            $nom = trim(Http::str('nom'));
            $prenom = trim(Http::str('prenom'));
            $email = trim(Http::str('email'));
            $hasReplacement = Http::str('has_replacement') === '1';
            if (!$eventId || $nom === '' || $prenom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('Merci de compléter votre identité et la journée concernée.');
            }
            if (Http::str('certification') === '') {
                throw new \RuntimeException('La certification est obligatoire.');
            }
            $id = ParticipantLookup::identify($db, $eventId, $nom, $prenom, $email);
            if (!$id['found']) {
                throw new \RuntimeException("Nous n'avons pas retrouvé cette inscription. Vérifiez le nom, le prénom, l'e-mail et la journée.");
            }
            $rn = trim(Http::str('replacement_nom'));
            $rp = trim(Http::str('replacement_prenom'));
            $re = trim(Http::str('replacement_email'));
            if ($hasReplacement && ($rn === '' || $rp === '' || !filter_var($re, FILTER_VALIDATE_EMAIL))) {
                throw new \RuntimeException('Merci de renseigner le nom, le prénom et l’e-mail du remplaçant.');
            }
            $db->run(
                'INSERT INTO participant_change_requests(event_id,participant_id,request_type,nom,prenom,email,replacement_nom,replacement_prenom,replacement_email,replacement_phone,replacement_vehicle)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $eventId, $id['participant_id'], $hasReplacement ? 'replacement' : 'cancellation', $nom, $prenom, $email,
                    $hasReplacement ? $rn : null, $hasReplacement ? $rp : null, $hasReplacement ? $re : null,
                    $hasReplacement ? trim(Http::str('replacement_phone')) : null, $hasReplacement ? trim(Http::str('replacement_vehicle')) : null,
                ]
            );
            Audit::log('v3.change.create', $id['participant_id'], ['event' => $eventId, 'type' => $hasReplacement ? 'replacement' : 'cancellation']);
            $ok = 'Votre demande a bien été enregistrée. Notre équipe va la traiter.';
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        $this->render('changes/form', ['events' => $this->futureEvents(), 'ok' => $ok, 'err' => $err]);
    }

    public function index(): void
    {
        $db = $this->db();
        Changes::ensureTable($db);
        $changes = $db->all(
            "SELECT r.*,e.event_date,e.billetweb_event_id,c.name circuit_name FROM participant_change_requests r
             JOIN events e ON e.id=r.event_id JOIN circuits c ON c.id=e.circuit_id
             ORDER BY (r.status='pending') DESC, r.created_at DESC LIMIT 300"
        );
        $this->view('changes/index', ['title' => 'Annulations / remplacements', 'changes' => $changes, 'page' => 'changes']);
    }

    public function decision(int $id): void
    {
        $db = $this->db();
        $action = Http::str('action');
        $r = $db->row(
            'SELECT r.*,e.circuit_id,e.event_date,c.name circuit_name FROM participant_change_requests r
             JOIN events e ON e.id=r.event_id JOIN circuits c ON c.id=e.circuit_id WHERE r.id=?',
            [$id]
        );
        if (!$r) {
            Http::abort(404, 'Demande introuvable.');
        }
        try {
            if ($action === 'process') {
                $note = trim(Http::str('admin_note'));
                $db->run("UPDATE participant_change_requests SET status='processed',admin_note=?,processed_by=?,processed_at=NOW() WHERE id=?", [$note, Auth::id(), $id]);
                if ($r['request_type'] === 'replacement' && filter_var($r['replacement_email'], FILTER_VALIDATE_EMAIL)) {
                    $vars = MailTemplates::participantVars(
                        ['prenom' => $r['replacement_prenom'], 'nom' => $r['replacement_nom']],
                        ['circuit_name' => $r['circuit_name'], 'event_date' => $r['event_date']],
                        ''
                    );
                    $mail = MailTemplates::render($db, 'replacement_registered', $vars, (int)($r['circuit_id'] ?? 0));
                    Mailer::send($db, (string)$r['replacement_email'], $mail['subject'], $mail['body']);
                }
                flash('ok', 'Demande marquée comme traitée.');
                Audit::log('v3.change.process', (int)($r['participant_id'] ?? 0) ?: null, ['change' => $id]);
            } elseif ($action === 'reopen') {
                $db->run("UPDATE participant_change_requests SET status='pending',processed_by=NULL,processed_at=NULL WHERE id=?", [$id]);
                flash('ok', 'Demande repassée à traiter.');
                Audit::log('v3.change.reopen', null, ['change' => $id]);
            } elseif ($action === 'note') {
                $db->run('UPDATE participant_change_requests SET admin_note=? WHERE id=?', [trim(Http::str('admin_note')), $id]);
                flash('ok', 'Note enregistrée.');
            } elseif ($action === 'sync') {
                $res = ListingSync::runThrottled($db, (int)$r['event_id'], Auth::id(), true);
                $note = (string)($res['message'] ?? ('Resynchronisé : ' . ($res['after'] ?? '?') . ' ligne(s).'));
                $db->run('UPDATE participant_change_requests SET sync_note=? WHERE id=?', [mb_substr($note, 0, 255), $id]);
                flash(($res['ok'] ?? false) ? 'ok' : 'warn', 'BilletWeb : ' . $note);
            }
        } catch (\Throwable $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/changements');
    }
}
