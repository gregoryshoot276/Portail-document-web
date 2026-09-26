<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Audit;
use JC\Core\Auth;
use JC\Core\Http;
use JC\Core\Mode;
use JC\Core\ReadOnlyException;
use JC\Domain\Dossiers;
use JC\Domain\Events;
use JC\Domain\Notifier;

final class DossiersController extends BaseController
{
    public function index(): void
    {
        $db = $this->db();
        // Un clic sur le menu (ou un lien externe) arrive sans aucun paramètre : on reprend alors la dernière
        // journée/filtre choisis dans cette session, pour que le sélecteur ne « saute » pas à chaque rafraîchissement.
        // Un lien de LA PAGE elle-même (vignette de journée, formulaire de filtres) transmet toujours au moins
        // `type`, donc $_GET n'est vide que sur une vraie arrivée « fraîche ».
        if ($_GET !== []) {
            $f = [
                'q'        => Http::str('q'),
                'sort'     => Http::str('sort', 'oldest'),
                'status'   => Http::str('status'),
                'circuit'  => Http::int('circuit'),
                'event'    => Http::int('event'),
                'reviewer' => Http::int('reviewer'),
                'type'     => in_array(Http::str('type', 'pilot'), ['pilot', 'passenger'], true) ? Http::str('type', 'pilot') : 'pilot',
                'ticket'   => Http::str('ticket'),
                'archived' => Http::int('archived'),
            ];
            $_SESSION['dossiers_filters'] = $f;
        } elseif (!empty($_SESSION['dossiers_filters']) && is_array($_SESSION['dossiers_filters'])) {
            $f = $_SESSION['dossiers_filters'];
        } else {
            $f = ['q' => '', 'sort' => 'oldest', 'status' => '', 'circuit' => 0, 'event' => 0, 'reviewer' => 0, 'type' => 'pilot', 'ticket' => '', 'archived' => 0];
        }
        $circuits = $db->all('SELECT id,name FROM circuits WHERE is_active=1 ORDER BY name');
        $admins = $db->all('SELECT id,display_name FROM admins WHERE is_active=1 ORDER BY display_name');
        // Filtres mémorisés en session d'une visite à l'autre (cf. commentaire ci-dessus) : un champ qui ne
        // correspond plus à rien de sélectionnable (circuit incompatible avec la journée choisie, valideur
        // désactivé depuis...) doit s'auto-corriger silencieusement plutôt que de figer le tableau à 0 dossier
        // sans que le menu déroulant ne laisse deviner qu'un filtre invisible est encore actif.
        $corrected = false;
        // Une journée précise détermine déjà son circuit : les deux ne doivent jamais se combiner, sous peine
        // de contredire le résultat et d'afficher 0 dossier sans explication.
        if ($f['event'] && $f['circuit']) {
            $f['circuit'] = 0;
            $corrected = true;
        }
        if ($f['reviewer'] && !in_array((int)$f['reviewer'], array_map('intval', array_column($admins, 'id')), true)) {
            $f['reviewer'] = 0;
            $corrected = true;
        }
        if ($f['circuit'] && !in_array((int)$f['circuit'], array_map('intval', array_column($circuits, 'id')), true)) {
            $f['circuit'] = 0;
            $corrected = true;
        }
        if ($corrected) {
            $_SESSION['dossiers_filters'] = $f;
        }
        $res = Dossiers::list($db, $f);
        $this->view('dossiers/index', [
            'title'    => 'Dossiers',
            'f'        => $f,
            'todo'     => $res['todo'],
            'done'     => $res['done'],
            'circuits' => $circuits,
            'events'   => Events::groups($db),
            'admins'   => $admins,
            'staleInsurance' => Dossiers::staleInsuranceOverrides($db),
        ]);
    }

    /** Nettoyage rétroactif (une fois) des cases Assurance du Listing figées par le bug corrigé le 26/09/2026. */
    public function cleanStaleInsurance(): void
    {
        try {
            Mode::assertWritable();
            $n = Dossiers::clearStaleInsuranceOverrides($this->db());
            Audit::log('v3.dossiers.clean_stale_insurance', null, ['count' => $n]);
            flash('ok', $n > 0 ? $n . ' case(s) Assurance débloquée(s) : elles affichent de nouveau le vrai statut du document.' : 'Aucune case à débloquer.');
        } catch (ReadOnlyException $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/dossiers');
    }

    public function show(int $id): void
    {
        $db = $this->db();
        $p = Dossiers::find($db, $id);
        if (!$p) {
            Http::abort(404, 'Dossier introuvable.');
        }
        $ticket = null;
        $declaredVehicle = '';
        if (!empty($p['billetweb_attendee_id'])) {
            $ticket = $db->row('SELECT ticket,category,order_paid,disabled,order_id,raw_json FROM billetweb_attendees WHERE attendee_id=? ORDER BY id DESC LIMIT 1', [$p['billetweb_attendee_id']])
                ?? $db->row('SELECT ticket,category,order_paid,disabled,order_id,post_code,raw_json FROM billetweb_post_attendees WHERE attendee_id=? ORDER BY id DESC LIMIT 1', [$p['billetweb_attendee_id']]);
            if ($ticket) {
                $declaredVehicle = \JC\Domain\Bw::vehicle(\JC\Domain\Bw::raw($ticket));
            }
        }
        // Navigation précédent/suivant : reprend la liste ordonnée transmise depuis le tableau des dossiers
        // (mêmes filtres, même tri), pour ne pas avoir à y revenir entre chaque dossier à traiter.
        $ctx = Http::str('ctx');
        $ctxIds = $ctx !== '' ? array_values(array_filter(array_map('intval', explode(',', $ctx)))) : [];
        $pos = array_search($id, $ctxIds, true);
        $prevId = $pos !== false && $pos > 0 ? $ctxIds[$pos - 1] : null;
        $nextId = $pos !== false && $pos < count($ctxIds) - 1 ? $ctxIds[$pos + 1] : null;
        $this->view('dossiers/show', [
            'title'           => trim($p['prenom'] . ' ' . $p['nom']),
            'p'               => $p,
            'docs'            => Dossiers::documents($db, $id),
            'waiver'          => Dossiers::waiver($db, $id),
            'timeline'        => Dossiers::timeline($db, $id),
            'mails'           => Dossiers::mails($db, $id),
            'ticket'          => $ticket,
            'declaredVehicle' => $declaredVehicle,
            'entries'         => $db->all('SELECT id,event_id,source,participant_type FROM listing_entries WHERE participant_id=?', [$id]),
            'canDecide'       => Auth::can('dossiers.decide'),
            'ctx'             => $ctx,
            'ctxPos'          => $pos !== false ? $pos + 1 : null,
            'ctxTotal'        => count($ctxIds),
            'prevId'          => $prevId,
            'nextId'          => $nextId,
        ]);
    }

    public function decision(int $id): void
    {
        $ids = (array)Http::input('document_ids', []);
        if (!$ids && Http::int('document_id') > 0) {
            $ids = [Http::int('document_id')];
        }
        $decision = Http::str('decision');
        $notify = $decision === 'validated_notify';
        if ($notify) {
            $decision = 'validated';
        }
        $db = $this->db();
        $previous = (string)$db->val('SELECT global_status FROM participants WHERE id=?', [$id]);
        $reason = Http::str('reason');
        $global = Dossiers::decide($db, (int)Auth::id(), $id, $ids, $decision, $reason);
        $msg = 'Décision enregistrée. Statut du dossier : ' . (Dossiers::STATUS_LABELS[$global] ?? $global) . '.';
        // Un refus, une validation avec message ou le passage du dossier à « validé » préviennent le participant par e-mail.
        try {
            Notifier::afterDecision($db, $id, $decision, $reason, $notify, $global, $previous, Auth::id());
        } catch (\Throwable $t) {
            \JC\Core\Logger::error('mail', 'notification après décision : ' . $t->getMessage());
            $msg .= ' (Le mail au participant n’a pas pu être envoyé.)';
        }
        flash('ok', $msg);
        Http::redirect('/dossiers/' . $id . self::ctxSuffix());
    }

    /** Repasse le contexte de navigation (précédent/suivant) d'un formulaire vers la redirection qui suit,
     * pour ne pas perdre le fil quand on valide un document depuis un dossier atteint via ces flèches. */
    private static function ctxSuffix(): string
    {
        $ctx = Http::str('ctx');
        return $ctx !== '' ? '?ctx=' . rawurlencode($ctx) : '';
    }

    /** Corrections manuelles d'un dossier : nom/prénom inversés, ou type (pilote / pilote suppl.) mal choisi au dépôt. */
    public function fix(int $id): void
    {
        $db = $this->db();
        $p = Dossiers::find($db, $id);
        if (!$p) {
            Http::abort(404, 'Dossier introuvable.');
        }
        $action = Http::str('action');
        try {
            Mode::assertWritable();
            if ($action === 'swap_name') {
                $db->run('UPDATE participants SET nom=?,prenom=?,updated_at=NOW() WHERE id=?', [$p['prenom'], $p['nom'], $id]);
                Audit::log('participant_name_swapped', $id, ['from' => $p['nom'] . ' ' . $p['prenom'], 'via' => 'v3']);
                flash('ok', 'Nom et prénom inversés.');
            } elseif ($action === 'set_type') {
                $type = Http::str('participant_type');
                if (!in_array($type, ['pilot', 'supplemental_driver'], true)) {
                    flash('warn', 'Type invalide.');
                } elseif ($type !== (string)$p['participant_type']) {
                    $db->run('UPDATE participants SET participant_type=?,updated_at=NOW() WHERE id=?', [$type, $id]);
                    Audit::log('participant_type_changed', $id, ['from' => $p['participant_type'], 'to' => $type, 'via' => 'v3']);
                    $msg = 'Type corrigé : ' . (Dossiers::TYPE_LABELS[$type] ?? $type) . '.';
                    // Un pilote supplémentaire n'a pas besoin de sa propre assurance : si le dossier ne contient
                    // qu'une attestation « à vérifier » sans fichier réel (créée automatiquement faute de l'avoir
                    // trouvée sur Billetweb), elle n'a plus lieu d'être et resterait bloquée pour rien.
                    if ($type === 'supplemental_driver') {
                        $removed = $db->run(
                            "DELETE FROM documents WHERE participant_id=? AND document_type='assurance' AND status!='validated' AND (stored_name IS NULL OR stored_name='')",
                            [$id]
                        );
                        if ($removed > 0) {
                            Audit::log('assurance_placeholder_removed', $id, ['via' => 'v3']);
                            $msg .= ' L’attestation d’assurance vide (sans fichier) a été retirée du dossier, elle n’est plus requise pour un pilote supplémentaire.';
                        }
                    } else {
                        $msg .= ' Cela ne modifie pas rétroactivement les exigences de documents déjà appliquées.';
                    }
                    flash('ok', $msg);
                }
            } elseif ($action === 'archive' || $action === 'unarchive') {
                $db->run('UPDATE participants SET archived_at=' . ($action === 'archive' ? 'NOW()' : 'NULL') . ' WHERE id=?', [$id]);
                Audit::log($action === 'archive' ? 'participant_archived' : 'participant_unarchived', $id, ['via' => 'v3']);
                if ($action === 'archive') {
                    flash('ok', 'Dossier archivé (doublon ou hors sujet) : il n’apparaît plus dans la liste par défaut. Réversible via le filtre « Archivés ».');
                    // Après archivage, plus utile de rester dessus : on passe directement au suivant de la même liste.
                    $ctxIds = array_values(array_filter(array_map('intval', explode(',', Http::str('ctx')))));
                    $pos = array_search($id, $ctxIds, true);
                    $next = $pos !== false && $pos < count($ctxIds) - 1 ? $ctxIds[$pos + 1] : null;
                    if ($next) {
                        Http::redirect('/dossiers/' . $next . self::ctxSuffix());
                    }
                    Http::redirect('/dossiers');
                }
                flash('ok', 'Dossier désarchivé.');
            }
        } catch (ReadOnlyException $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/dossiers/' . $id . self::ctxSuffix());
    }
}
