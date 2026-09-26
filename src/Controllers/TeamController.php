<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Audit;
use JC\Core\Files;
use JC\Core\Http;
use JC\Core\Mode;
use JC\Core\ReadOnlyException;
use JC\Core\View;
use JC\Domain\Mailer;

/**
 * Teams : un Team Manager gère lui-même ses pilotes (invitations personnelles, décharge nominative obligatoire)
 * et l'assurance flotte de son Team pour une journée donnée. Tables partagées avec l'ancien portail (V1.6.0).
 */
final class TeamController extends BaseController
{
    // --- Back-office (staff) ---------------------------------------------------------------------------------------

    public function index(): void
    {
        $db = $this->db();
        $events = $db->all("SELECT e.id,e.event_date,c.name circuit FROM events e JOIN circuits c ON c.id=e.circuit_id WHERE e.is_active=1 AND e.event_date>=CURDATE() ORDER BY e.event_date");
        $rows = $db->all(
            "SELECT te.*,t.name team_name,t.manager_name,t.manager_email,e.event_date,c.name circuit,
                    (SELECT COUNT(*) FROM team_event_drivers d WHERE d.team_event_id=te.id) drivers,
                    (SELECT COUNT(*) FROM team_event_drivers d WHERE d.team_event_id=te.id AND d.participant_id IS NOT NULL) completed,
                    (SELECT COUNT(*) FROM team_insurances i WHERE i.team_event_id=te.id AND i.status='pending') pending_insurance
             FROM team_events te JOIN teams t ON t.id=te.team_id JOIN events e ON e.id=te.event_id JOIN circuits c ON c.id=e.circuit_id
             ORDER BY e.event_date DESC, t.name"
        );
        $this->view('teams/index', ['title' => 'Teams', 'events' => $events, 'rows' => $rows, 'page' => 'teams']);
    }

    public function create(): void
    {
        $name = Http::str('name');
        $manager = Http::str('manager_name');
        $email = mb_strtolower(Http::str('manager_email'));
        $phone = Http::str('manager_phone');
        $eventId = Http::int('event_id');
        if ($name === '' || $manager === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $eventId < 1) {
            flash('warn', 'Team, manager, e-mail et événement sont obligatoires.');
            Http::redirect('/teams');
        }
        try {
            Mode::assertWritable();
            $db = $this->db();
            $db->beginTransaction();
            $teamId = (int)($db->val('SELECT id FROM teams WHERE name=? LIMIT 1', [$name]) ?: 0);
            if ($teamId) {
                $db->run('UPDATE teams SET manager_name=?,manager_email=?,manager_phone=?,updated_at=NOW() WHERE id=?', [$manager, $email, $phone ?: null, $teamId]);
            } else {
                $db->run('INSERT INTO teams(name,manager_name,manager_email,manager_phone,is_active,created_at,updated_at) VALUES(?,?,?,?,1,NOW(),NOW())', [$name, $manager, $email, $phone ?: null]);
                $teamId = (int)$db->lastInsertId();
            }
            $token = bin2hex(random_bytes(24));
            $db->run('INSERT INTO team_events(team_id,event_id,manager_token,status,created_at,updated_at) VALUES(?,?,?,"open",NOW(),NOW())', [$teamId, $eventId, $token]);
            Audit::log('team_event_created', null, ['team_id' => $teamId, 'event_id' => $eventId]);
            $db->commit();
            flash('ok', 'Tableau de bord Team créé. Lien manager : /team-manager/' . $token);
        } catch (ReadOnlyException $e) {
            flash('warn', $e->getMessage());
        } catch (\Throwable $e) {
            if ($this->db()->inTransaction()) {
                $this->db()->rollBack();
            }
            flash('ko', 'Erreur : ' . $e->getMessage());
        }
        Http::redirect('/teams');
    }

    public function insurances(): void
    {
        $rows = $this->db()->all(
            "SELECT i.*,v.label vehicle_label,t.name team_name,e.event_date,c.name circuit
             FROM team_insurances i JOIN team_events te ON te.id=i.team_event_id JOIN teams t ON t.id=te.team_id
             JOIN events e ON e.id=te.event_id JOIN circuits c ON c.id=e.circuit_id LEFT JOIN team_vehicles v ON v.id=i.vehicle_id
             ORDER BY (i.status='pending') DESC, i.id DESC"
        );
        $this->view('teams/insurances', ['title' => 'Assurances Team', 'rows' => $rows, 'page' => 'teams']);
    }

    public function insuranceDecision(int $id): void
    {
        $decision = Http::str('decision') === 'validated' ? 'validated' : 'rejected';
        try {
            Mode::assertWritable();
            $this->db()->run('UPDATE team_insurances SET status=?,notes=?,updated_at=NOW() WHERE id=?', [$decision, Http::str('notes') ?: null, $id]);
            Audit::log('team_insurance_decision', null, ['team_insurance_id' => $id, 'decision' => $decision]);
            flash('ok', 'Décision enregistrée.');
        } catch (ReadOnlyException $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/teams/insurances');
    }

    public function insuranceFile(int $id): void
    {
        $row = $this->db()->row('SELECT stored_name,original_name FROM team_insurances WHERE id=?', [$id]);
        $path = $row ? Files::resolve('team_insurance', (string)$row['stored_name']) : null;
        if (!$path) {
            Http::abort(404, 'Fichier introuvable.');
        }
        Files::send($path, (string)($row['original_name'] ?: 'assurance-team'));
    }

    // --- Tableau de bord du Team Manager (public, par lien secret) --------------------------------------------------

    private function managerContext(string $token): ?array
    {
        $row = $this->db()->row(
            'SELECT te.*,t.name team_name,t.manager_name,t.manager_email,t.manager_phone,
                    e.event_date,e.name event_name,e.circuit_id,c.name circuit_name,c.slug
             FROM team_events te JOIN teams t ON t.id=te.team_id JOIN events e ON e.id=te.event_id JOIN circuits c ON c.id=e.circuit_id
             WHERE te.manager_token=? AND t.is_active=1',
            [$token]
        );
        return $row ?: null;
    }

    private function renderManager(string $token, array $ctx, string $msg = '', string $err = ''): void
    {
        $db = $this->db();
        $teId = (int)$ctx['id'];
        $vehicles = $db->all('SELECT v.* FROM team_vehicles v JOIN team_event_vehicles x ON x.vehicle_id=v.id WHERE x.team_event_id=? ORDER BY v.label', [$teId]);
        $drivers = $db->all(
            "SELECT d.*,p.nom,p.prenom,p.email,p.global_status,
                    (SELECT MAX(CASE WHEN x.document_type='permis' THEN x.status END) FROM documents x WHERE x.participant_id=p.id) permit_status,
                    (SELECT COUNT(*) FROM waivers w WHERE w.participant_id=p.id) waiver_count
             FROM team_event_drivers d LEFT JOIN participants p ON p.id=d.participant_id WHERE d.team_event_id=? ORDER BY d.id",
            [$teId]
        );
        $insurances = $db->all('SELECT i.*,v.label vehicle_label FROM team_insurances i LEFT JOIN team_vehicles v ON v.id=i.vehicle_id WHERE i.team_event_id=? ORDER BY i.id DESC', [$teId]);
        View::render('team/manager', [
            'pageTitle' => 'Team ' . $ctx['team_name'], 'lang' => 'fr',
            'token' => $token, 'ctx' => $ctx, 'vehicles' => $vehicles, 'drivers' => $drivers, 'insurances' => $insurances, 'msg' => $msg, 'err' => $err,
        ], 'layout_public');
    }

    public function manager(string $token): void
    {
        $ctx = $this->managerContext($token);
        if (!$ctx) {
            Http::abort(404, 'Lien Team invalide.');
        }
        $this->renderManager($token, $ctx);
    }

    public function addDriver(string $token): void
    {
        $ctx = $this->managerContext($token);
        if (!$ctx) {
            Http::abort(404, 'Lien Team invalide.');
        }
        $nom = Http::str('nom');
        $prenom = Http::str('prenom');
        $email = mb_strtolower(Http::str('email'));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->renderManager($token, $ctx, '', 'Une adresse e-mail valide est obligatoire pour envoyer le lien au pilote.');
            return;
        }
        if ($ctx['status'] !== 'open') {
            $this->renderManager($token, $ctx, '', 'Participation Team fermée.');
            return;
        }
        try {
            Mode::assertWritable();
            $db = $this->db();
            $invite = bin2hex(random_bytes(24));
            $db->run(
                'INSERT INTO team_event_drivers(team_event_id,invite_token,expected_last_name,expected_first_name,expected_email,invite_status,created_at,updated_at) VALUES(?,?,?,?,?,"not_sent",NOW(),NOW())',
                [(int)$ctx['id'], $invite, $nom ?: null, $prenom ?: null, $email]
            );
            $driverId = (int)$db->lastInsertId();
            $base = rtrim((string)\JC\Core\App::config('public_base_url', \JC\Core\App::config('base_url', '')), '/');
            $link = $base . '/team/' . $invite;
            $hello = $prenom !== '' ? 'Bonjour ' . htmlspecialchars($prenom, ENT_QUOTES) . ',' : 'Bonjour,';
            $html = '<p>' . $hello . '</p><p>Votre Team Manager de <strong>' . htmlspecialchars((string)$ctx['team_name'], ENT_QUOTES) . '</strong> vous invite à compléter personnellement votre dossier pour <strong>'
                . htmlspecialchars((string)$ctx['circuit_name'], ENT_QUOTES) . ' — ' . e(date('d/m/Y', strtotime((string)$ctx['event_date']))) . '</strong>.</p>'
                . '<p><strong>Important :</strong> la décharge est nominative et doit être lue et signée par vous-même. Vous devez également transmettre vous-même votre permis de conduire.</p>'
                . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '">Compléter mon dossier et signer ma décharge</a></p>';
            $sent = false;
            try {
                $sent = Mailer::send($db, $email, 'Votre dossier Journée Circuit — ' . $ctx['circuit_name'] . ' ' . date('d/m/Y', strtotime((string)$ctx['event_date'])), $html);
            } catch (\Throwable $t) {
                $sent = false;
            }
            if ($sent) {
                $db->run('UPDATE team_event_drivers SET invite_status="sent",updated_at=NOW() WHERE id=?', [$driverId]);
            }
            Audit::log('team_driver_added', null, ['team_event_id' => (int)$ctx['id'], 'driver_id' => $driverId, 'mail_sent' => $sent]);
            $this->renderManager($token, $ctx, $sent ? 'Pilote ajouté et e-mail envoyé automatiquement.' : 'Pilote ajouté. L’e-mail automatique n’a pas pu être envoyé : transmettez le lien vous-même.');
        } catch (ReadOnlyException $e) {
            $this->renderManager($token, $ctx, '', $e->getMessage());
        }
    }

    public function addVehicle(string $token): void
    {
        $ctx = $this->managerContext($token);
        if (!$ctx) {
            Http::abort(404, 'Lien Team invalide.');
        }
        $vehicle = Http::str('vehicle');
        $reg = Http::str('registration');
        if ($vehicle === '' && $reg === '') {
            $this->renderManager($token, $ctx, '', 'Renseignez au moins le véhicule ou son immatriculation.');
            return;
        }
        try {
            Mode::assertWritable();
            $db = $this->db();
            $label = $vehicle !== '' ? $vehicle : $reg;
            $db->run('INSERT INTO team_vehicles(team_id,label,registration,vehicle,is_active,created_at,updated_at) VALUES(?,?,?,?,1,NOW(),NOW())', [(int)$ctx['team_id'], $label, $reg ?: null, $vehicle ?: null]);
            $vid = (int)$db->lastInsertId();
            $db->run('INSERT INTO team_event_vehicles(team_event_id,vehicle_id) VALUES(?,?)', [(int)$ctx['id'], $vid]);
            Audit::log('team_vehicle_added', null, ['team_event_id' => (int)$ctx['id'], 'vehicle_id' => $vid]);
            $this->renderManager($token, $ctx, 'Véhicule ajouté.');
        } catch (ReadOnlyException $e) {
            $this->renderManager($token, $ctx, '', $e->getMessage());
        }
    }

    public function uploadInsurance(string $token): void
    {
        $ctx = $this->managerContext($token);
        if (!$ctx) {
            Http::abort(404, 'Lien Team invalide.');
        }
        $f = $_FILES['insurance'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->renderManager($token, $ctx, '', 'Choisissez un fichier d’assurance.');
            return;
        }
        if ((int)$f['size'] > 10 * 1024 * 1024) {
            $this->renderManager($token, $ctx, '', 'Fichier trop volumineux (10 Mo maximum).');
            return;
        }
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
        $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'][$mime] ?? null;
        if (!$ext) {
            $this->renderManager($token, $ctx, '', 'Format non autorisé (PDF, JPG, PNG, WEBP ou HEIC).');
            return;
        }
        $vehicleId = Http::int('vehicle_id');
        $validUntil = Http::str('valid_until');
        if ($validUntil !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $validUntil)) {
            $validUntil = '';
        }
        try {
            Mode::assertWritable();
            $dir = Files::dir('team_insurance');
            $stored = bin2hex(random_bytes(24)) . '.' . $ext;
            if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $stored)) {
                throw new \RuntimeException('Envoi impossible.');
            }
            $db = $this->db();
            $db->run(
                'INSERT INTO team_insurances(team_event_id,vehicle_id,insurance_type,original_name,stored_name,mime_type,file_size,valid_until,status,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,"pending",NOW(),NOW())',
                [(int)$ctx['id'], $vehicleId ?: null, $vehicleId ? 'vehicle' : 'fleet', basename((string)$f['name']), $stored, $mime, (int)$f['size'], $validUntil ?: null]
            );
            Audit::log('team_insurance_uploaded', null, ['team_event_id' => (int)$ctx['id']]);
            $this->renderManager($token, $ctx, 'Assurance envoyée pour contrôle.');
        } catch (ReadOnlyException $e) {
            $this->renderManager($token, $ctx, '', $e->getMessage());
        }
    }
}
