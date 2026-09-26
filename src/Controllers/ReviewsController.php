<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\App;
use JC\Core\Audit;
use JC\Core\Auth;
use JC\Core\Files;
use JC\Core\Http;
use JC\Core\Mode;
use JC\Core\View;
use JC\Domain\Mailer;
use JC\Domain\MailTemplates;
use JC\Domain\ParticipantLookup;
use JC\Domain\Reviews;

/** Avis Google/Facebook contre code promo : formulaire public (même URL et principe que l'ancien portail :
 * capture d'avis, identification du participant, validation humaine, code promo) + validation admin. */
final class ReviewsController extends BaseController
{
    private function render(string $tpl, array $vars): void
    {
        View::render($tpl, $vars + ['pageTitle' => 'Votre avis Journée Circuit'], 'layout_public');
    }

    private function pastEvents(): array
    {
        return $this->db()->all(
            "SELECT e.id,e.event_date,c.name circuit_name FROM events e JOIN circuits c ON c.id=e.circuit_id
             WHERE e.is_active=1 AND e.event_date<=CURDATE() AND e.event_date>=DATE_SUB(CURDATE(),INTERVAL 24 MONTH)
             ORDER BY e.event_date DESC"
        );
    }

    private function findByToken(string $token): ?array
    {
        return $token === '' ? null : $this->db()->row(
            'SELECT r.*,e.event_date,c.name circuit_name FROM review_requests r
             LEFT JOIN events e ON e.id=r.event_id LEFT JOIN circuits c ON c.id=e.circuit_id
             WHERE r.public_token=? LIMIT 1',
            [$token]
        );
    }

    public function form(): void
    {
        $db = $this->db();
        Reviews::ensureTable($db);
        $token = Http::str('t');
        $editing = $this->findByToken($token);
        $err = ($token !== '' && !$editing) ? 'Ce lien de correction est invalide ou a expiré.' : '';
        $this->render('reviews/form', ['token' => $token, 'editing' => $editing, 'events' => $this->pastEvents(), 'ok' => '', 'err' => $err]);
    }

    public function submit(): void
    {
        $db = $this->db();
        Reviews::ensureTable($db);
        $token = Http::str('token');
        $editing = $this->findByToken($token);
        $ok = '';
        $err = '';
        try {
            if (Mode::readOnly()) {
                throw new \RuntimeException('Le site est actuellement en maintenance. Merci de réessayer plus tard.');
            }
            if ($token !== '') {
                if (!$editing) {
                    throw new \RuntimeException('Ce lien de correction est invalide ou a expiré.');
                }
                if ((string)$editing['status'] === 'approved') {
                    throw new \RuntimeException('Cette demande est déjà validée.');
                }
                [$stored, $mime, $name] = Reviews::storeUpload($_FILES['screenshot'] ?? []);
                $old = (string)$editing['screenshot_stored'];
                $db->run(
                    "UPDATE review_requests SET screenshot_name=?,screenshot_stored=?,screenshot_mime=?,status='pending',
                     rejection_reason=NULL,processed_by=NULL,processed_at=NULL,mail_status=NULL,mail_error=NULL,updated_at=NOW() WHERE id=?",
                    [$name, $stored, $mime, (int)$editing['id']]
                );
                $oldPath = $old !== '' ? Files::resolve('review', $old) : null;
                if ($oldPath !== null) {
                    @unlink($oldPath);
                }
                Audit::log('v3.review.resubmit', null, ['review' => (int)$editing['id']]);
                $ok = 'Votre nouvelle capture a bien été envoyée. Votre demande repasse en vérification.';
                $editing['status'] = 'pending';
            } else {
                $eventId = Http::int('event_id');
                $nom = trim(Http::str('nom'));
                $prenom = trim(Http::str('prenom'));
                $email = trim(Http::str('email'));
                $platforms = array_values(array_intersect(Reviews::PLATFORMS, array_map('strval', (array)($_POST['platforms'] ?? []))));
                if (!$eventId || $nom === '' || $prenom === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !$platforms) {
                    throw new \RuntimeException('Merci de compléter tous les champs et de choisir au moins une plateforme.');
                }
                $id = ParticipantLookup::identify($db, $eventId, $nom, $prenom, $email);
                if (!$id['found']) {
                    throw new \RuntimeException("Nous n'avons pas retrouvé votre participation à cette journée (pilote ou passager).");
                }
                $db->beginTransaction();
                $created = [];
                try {
                    foreach ($platforms as $platform) {
                        [$stored, $mime, $name] = Reviews::storeUpload($_FILES['screenshot_' . $platform] ?? []);
                        $publicToken = bin2hex(random_bytes(24));
                        $db->run(
                            'INSERT INTO review_requests(event_id,participant_id,nom,prenom,email,platform,public_token,screenshot_name,screenshot_stored,screenshot_mime) VALUES(?,?,?,?,?,?,?,?,?,?)',
                            [$eventId, $id['participant_id'], $nom, $prenom, $email, $platform, $publicToken, $name, $stored, $mime]
                        );
                        $created[] = $platform;
                    }
                    $db->commit();
                } catch (\Throwable $t) {
                    if ($db->inTransaction()) {
                        $db->rollBack();
                    }
                    throw $t;
                }
                Audit::log('v3.review.create', $id['participant_id'], ['event' => $eventId, 'platforms' => $created]);
                $ok = 'Merci ! ' . count($created) . ' avis transmis (' . implode(' + ', array_map('ucfirst', $created)) . '). Ils seront vérifiés séparément par notre équipe.';
            }
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        $this->render('reviews/form', ['token' => $token, 'editing' => $editing, 'events' => $this->pastEvents(), 'ok' => $ok, 'err' => $err]);
    }

    public function index(): void
    {
        $db = $this->db();
        Reviews::ensureTable($db);
        $reviews = $db->all(
            "SELECT r.*,e.event_date,c.name circuit_name,
                    (SELECT COUNT(*) FROM review_requests x WHERE x.id<>r.id AND x.status='approved' AND x.platform=r.platform AND LOWER(x.email)=LOWER(r.email)) prior_same_platform
             FROM review_requests r LEFT JOIN events e ON e.id=r.event_id LEFT JOIN circuits c ON c.id=e.circuit_id
             ORDER BY (r.status='pending') DESC, r.created_at DESC LIMIT 300"
        );
        $this->view('reviews/index', ['title' => 'Avis & codes promo', 'reviews' => $reviews, 'page' => 'reviews']);
    }

    public function decision(int $id): void
    {
        $db = $this->db();
        $action = Http::str('action');
        $r = $db->row(
            'SELECT r.*,e.circuit_id,e.event_date,c.name circuit_name FROM review_requests r
             LEFT JOIN events e ON e.id=r.event_id LEFT JOIN circuits c ON c.id=e.circuit_id WHERE r.id=?',
            [$id]
        );
        if (!$r) {
            Http::abort(404, 'Avis introuvable.');
        }
        try {
            if (in_array($action, ['approve_10', 'approve_15'], true)) {
                $amount = $action === 'approve_15' ? 15 : 10;
                $code = $r['promo_code'] ?: Reviews::generateCode($db);
                $db->run(
                    "UPDATE review_requests SET status='approved',reward_amount=?,promo_code=?,rejection_reason=NULL,processed_by=?,processed_at=NOW() WHERE id=?",
                    [$amount, $code, Auth::id(), $id]
                );
                $vars = ['prenom' => $r['prenom'], 'nom' => $r['nom'], 'montant' => $amount, 'code_promo' => $code, 'plateforme' => ucfirst((string)$r['platform'])];
                $mail = MailTemplates::render($db, 'review_reward', $vars, (int)($r['circuit_id'] ?? 0));
                $mailOk = Mailer::send($db, (string)$r['email'], $mail['subject'], $mail['body']);
                $db->run('UPDATE review_requests SET mail_status=?,mail_error=? WHERE id=?', [$mailOk ? 'sent' : 'error', $mailOk ? null : 'Envoi impossible.', $id]);
                flash('ok', $mailOk ? 'Avis validé : code ' . $code . ' envoyé.' : 'Avis validé et code ' . $code . ' créé, mais le mail n’a pas pu être envoyé.');
                Audit::log('v3.review.approve', null, ['review' => $id, 'amount' => $amount, 'code' => $code]);
            } elseif ($action === 'reject') {
                $reason = trim(Http::str('reason'));
                if ($reason === '') {
                    throw new \RuntimeException('Indiquez un motif de refus.');
                }
                $publicToken = (string)$r['public_token'];
                if ($publicToken === '') {
                    $publicToken = bin2hex(random_bytes(24));
                    $db->run('UPDATE review_requests SET public_token=? WHERE id=?', [$publicToken, $id]);
                }
                $db->run("UPDATE review_requests SET status='rejected',rejection_reason=?,processed_by=?,processed_at=NOW() WHERE id=?", [$reason, Auth::id(), $id]);
                $link = rtrim((string)(App::config('public_base_url') ?: App::config('base_url', '')), '/') . '/avis.php?t=' . rawurlencode($publicToken);
                $vars = ['prenom' => $r['prenom'], 'nom' => $r['nom'], 'plateforme' => ucfirst((string)$r['platform']), 'motif' => $reason, 'lien_correction' => $link];
                $mail = MailTemplates::render($db, 'review_rejected', $vars, (int)($r['circuit_id'] ?? 0));
                $mailOk = Mailer::send($db, (string)$r['email'], $mail['subject'], $mail['body']);
                $db->run('UPDATE review_requests SET mail_status=?,mail_error=? WHERE id=?', [$mailOk ? 'sent' : 'error', $mailOk ? null : 'Envoi impossible.', $id]);
                flash('ok', $mailOk ? 'Avis refusé : le participant a reçu son lien de correction.' : 'Avis refusé, mais le mail n’a pas pu être envoyé.');
                Audit::log('v3.review.reject', null, ['review' => $id, 'reason' => $reason]);
            } elseif ($action === 'reopen') {
                $db->run("UPDATE review_requests SET status='pending',processed_by=NULL,processed_at=NULL WHERE id=?", [$id]);
                flash('ok', 'Dossier avis rouvert et replacé à vérifier.');
                Audit::log('v3.review.reopen', null, ['review' => $id]);
            } elseif ($action === 'resend') {
                if ($r['status'] === 'approved' && $r['promo_code']) {
                    $vars = ['prenom' => $r['prenom'], 'nom' => $r['nom'], 'montant' => $r['reward_amount'], 'code_promo' => $r['promo_code'], 'plateforme' => ucfirst((string)$r['platform'])];
                    $mail = MailTemplates::render($db, 'review_reward', $vars, (int)($r['circuit_id'] ?? 0));
                } elseif ($r['status'] === 'rejected') {
                    $link = rtrim((string)(App::config('public_base_url') ?: App::config('base_url', '')), '/') . '/avis.php?t=' . rawurlencode((string)$r['public_token']);
                    $vars = ['prenom' => $r['prenom'], 'nom' => $r['nom'], 'plateforme' => ucfirst((string)$r['platform']), 'motif' => $r['rejection_reason'], 'lien_correction' => $link];
                    $mail = MailTemplates::render($db, 'review_rejected', $vars, (int)($r['circuit_id'] ?? 0));
                } else {
                    throw new \RuntimeException('Aucun mail à renvoyer pour cet état.');
                }
                $mailOk = Mailer::send($db, (string)$r['email'], $mail['subject'], $mail['body']);
                $db->run('UPDATE review_requests SET mail_status=?,mail_error=? WHERE id=?', [$mailOk ? 'sent' : 'error', $mailOk ? null : 'Envoi impossible.', $id]);
                flash($mailOk ? 'ok' : 'warn', $mailOk ? 'Mail renvoyé.' : 'Échec du renvoi du mail.');
            } elseif ($action === 'mark_bw') {
                $db->run('UPDATE review_requests SET billetweb_created=1,billetweb_created_at=NOW() WHERE id=?', [$id]);
                flash('ok', 'Code marqué comme créé dans BilletWeb.');
                Audit::log('v3.review.mark_bw', null, ['review' => $id]);
            }
        } catch (\Throwable $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/avis');
    }

    public function file(int $id): void
    {
        $r = $this->db()->row('SELECT screenshot_stored,screenshot_name FROM review_requests WHERE id=?', [$id]);
        if (!$r) {
            Http::abort(404, 'Fichier introuvable.');
        }
        $path = Files::resolve('review', (string)$r['screenshot_stored']);
        if ($path === null) {
            Http::abort(404, 'Fichier absent du serveur.');
        }
        Audit::log('v3.review.file_view', null, ['review' => $id]);
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'");
        Files::send($path, (string)($r['screenshot_name'] ?: 'avis'));
    }
}
