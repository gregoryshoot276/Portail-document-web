<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\App;
use JC\Core\CustomerAuth;
use JC\Core\Files;
use JC\Core\Http;
use JC\Core\Mode;
use JC\Core\ReadOnlyException;
use JC\Core\Throttle;
use JC\Core\View;
use JC\Domain\BilletwebMatch;
use JC\Domain\Dossiers;
use JC\Domain\I18n;
use JC\Domain\Notifier;
use JC\Domain\Submission;
use JC\Domain\Waivers;

/** Pages accessibles aux participants, sans connexion : dépôt du dossier, suivi, remplacement d'un document, décharges. */
final class PublicController extends BaseController
{
    private function render(string $tpl, array $vars): void
    {
        View::render($tpl, $vars + ['pageTitle' => 'Journée Circuit', 'lang' => I18n::lang()], 'layout_public');
    }

    private function formData(): array
    {
        $db = $this->db();
        $events = $db->all(
            "SELECT e.id,e.circuit_id,e.event_date,e.name,c.name circuit_name,c.slug,
                    (SELECT 1 FROM waiver_templates t WHERE t.circuit_id=e.circuit_id AND t.is_active=1 AND t.event_valid_from<=e.event_date AND t.event_valid_until>=e.event_date LIMIT 1) has_waiver
             FROM events e JOIN circuits c ON c.id=e.circuit_id WHERE e.is_active=1 AND e.event_date>=CURDATE() ORDER BY e.event_date,e.id"
        );
        $circuits = [];
        foreach ($events as $e) {
            $circuits[(int)$e['circuit_id']] = ['id' => (int)$e['circuit_id'], 'name' => $e['circuit_name'], 'slug' => $e['slug']];
        }
        return ['events' => $events, 'circuits' => array_values($circuits)];
    }

    /** Données transmises au JavaScript de la page : dates disponibles, textes traduits, présence de pdf.js. */
    private function boot(array $d): array
    {
        $keys = ['choose_circuit_first', 'choose_date', 'no_date', 'loading', 'fill_identity', 'search_bw', 'check_done', 'check_unavailable', 'scroll_required', 'pdf_error', 'signature_required', 'previous', 'next', 'step_of'];
        return [
            'events' => array_map(fn($e) => ['id' => (int)$e['id'], 'circuit' => (int)$e['circuit_id'], 'date' => $e['event_date'], 'slug' => $e['slug'], 'ok' => (bool)$e['has_waiver']], $d['events']),
            'lang'   => I18n::lang(),
            'pdfjs'  => is_file(App::path('public/assets/vendor/pdfjs/pdf.min.mjs')),
            'i18n'   => array_combine($keys, array_map(fn($k) => I18n::t($k), $keys)),
        ];
    }

    /** Permis/assurance validés et encore réutilisables pour un compte « mon espace » connecté. */
    private function accountDocs(int $accountId): array
    {
        $db = $this->db();
        return [
            'permis'    => $db->row("SELECT id,original_name FROM documents WHERE account_id=? AND document_type='permis' AND status='validated' AND reusable=1 ORDER BY id DESC LIMIT 1", [$accountId]),
            'assurance' => $db->row("SELECT id,original_name,valid_until FROM documents WHERE account_id=? AND document_type='assurance' AND status='validated' AND reusable=1 AND (valid_until IS NULL OR valid_until>=CURDATE()) ORDER BY valid_until DESC,id DESC LIMIT 1", [$accountId]),
        ];
    }

    public function form(): void
    {
        $d = $this->formData();
        $customer = CustomerAuth::user();
        $this->render('public/form', $d + [
            'old'       => $customer ? ['nom' => $customer['nom'] ?? '', 'prenom' => $customer['prenom'] ?? '', 'email' => $customer['email'] ?? '', 'telephone' => $customer['telephone'] ?? ''] : [],
            'errors'    => [],
            'key'       => bin2hex(random_bytes(16)),
            'readonly'  => Mode::readOnly(),
            'pdfjs'     => is_file(App::path('public/assets/vendor/pdfjs/pdf.min.mjs')),
            'boot'      => $this->boot($d),
            'customer'  => $customer,
            'accDocs'   => $customer ? $this->accountDocs((int)$customer['id']) : ['permis' => null, 'assurance' => null],
            'teamInvite'=> null,
        ]);
    }

    public function submit(): void
    {
        $d = $this->formData();
        $old = Http::body();
        // PHP vide $_POST et $_FILES sans prévenir quand l'envoi dépasse post_max_size (photos trop lourdes) :
        // sans ce contrôle, la personne se retrouve avec « nom et prénom obligatoires » alors qu'elle a tout
        // rempli — un message qui ne correspond à rien de ce qu'elle a fait et qu'elle ne peut pas corriger.
        if (empty($old) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            $customer = CustomerAuth::user();
            http_response_code(422);
            $this->render('public/form', $d + [
                'old'        => [],
                'errors'     => ['Votre envoi était trop volumineux (photos trop lourdes). Merci de réessayer avec des photos plus légères, ou en les envoyant une par une.'],
                'key'        => bin2hex(random_bytes(16)),
                'readonly'   => Mode::readOnly(),
                'pdfjs'      => is_file(App::path('public/assets/vendor/pdfjs/pdf.min.mjs')),
                'boot'       => $this->boot($d),
                'customer'   => $customer,
                'accDocs'    => $customer ? $this->accountDocs((int)$customer['id']) : ['permis' => null, 'assurance' => null],
                'teamInvite' => null,
            ]);
            return;
        }
        $key = preg_replace('/[^a-f0-9]/i', '', (string)($old['submission_key'] ?? '')) ?? '';
        if (strlen($key) !== 32) {
            $key = bin2hex(random_bytes(16));
        }
        $errors = [];
        $result = null;
        $customer = CustomerAuth::user();
        $accountId = $customer ? (int)$customer['id'] : null;
        if (Mode::readOnly()) {
            $errors[] = I18n::t('readonly_notice');
        } elseif (!Throttle::allow('submit:' . Http::ip(), 12, 3600)) {
            $errors[] = 'Trop de dépôts depuis votre connexion. Réessayez plus tard.';
        } else {
            try {
                $result = (new Submission($this->db()))->run($old, $_FILES, Http::ip(), I18n::lang(), $key, $accountId);
                $errors = $result['errors'];
            } catch (ReadOnlyException $e) {
                $errors[] = I18n::t('readonly_notice');
            }
        }
        if ($result && $result['ok'] && $result['token']) {
            $type = (string)($old['participant_type'] ?? 'pilot');
            $p = $this->db()->row('SELECT p.participant_type,c.slug FROM participants p JOIN events e ON e.id=p.event_id JOIN circuits c ON c.id=e.circuit_id WHERE p.public_token=?', [$result['token']]);
            $this->render('public/success', ['token' => $result['token'], 'official' => $p ? Waivers::officialRequired((string)$p['slug'], (string)$p['participant_type']) : false, 'type' => $type]);
            return;
        }
        http_response_code(422);
        unset($old['signature_data'], $old['_csrf']);
        $this->render('public/form', $d + [
            'old'       => $old,
            'errors'    => $errors,
            'key'       => $key,
            'readonly'  => Mode::readOnly(),
            'pdfjs'     => is_file(App::path('public/assets/vendor/pdfjs/pdf.min.mjs')),
            'boot'      => $this->boot($d),
            'customer'  => $customer,
            'accDocs'   => $customer ? $this->accountDocs((int)$customer['id']) : ['permis' => null, 'assurance' => null],
            'teamInvite'=> null,
        ]);
    }

    // --- Invitation Team --------------------------------------------------------------------------------------------

    private function teamContext(string $token): ?array
    {
        $row = $this->db()->row(
            'SELECT d.*, te.id team_event_id, te.event_id, te.status team_event_status, t.name team_name
             FROM team_event_drivers d JOIN team_events te ON te.id=d.team_event_id JOIN teams t ON t.id=te.team_id
             WHERE d.invite_token=? AND t.is_active=1',
            [$token]
        );
        return $row ?: null;
    }

    /** Formulaire pré-rempli pour un pilote invité par son Team Manager (décharge strictement personnelle). */
    public function teamForm(string $token): void
    {
        $ctx = $this->teamContext($token);
        if (!$ctx || $ctx['team_event_status'] !== 'open') {
            Http::abort(404, 'Invitation Team invalide ou fermée.');
        }
        if (in_array($ctx['invite_status'], ['not_sent', 'sent'], true)) {
            $this->db()->run("UPDATE team_event_drivers SET invite_status='opened',opened_at=COALESCE(opened_at,NOW()) WHERE id=?", [(int)$ctx['id']]);
        }
        $d = $this->formData();
        $this->render('public/form', $d + [
            'old' => [
                'event_id' => (string)$ctx['event_id'], 'participant_type' => 'pilot',
                'nom' => (string)($ctx['expected_last_name'] ?? ''), 'prenom' => (string)($ctx['expected_first_name'] ?? ''), 'email' => (string)($ctx['expected_email'] ?? ''),
            ],
            'errors'    => [],
            'key'       => bin2hex(random_bytes(16)),
            'readonly'  => Mode::readOnly(),
            'pdfjs'     => is_file(App::path('public/assets/vendor/pdfjs/pdf.min.mjs')),
            'boot'      => $this->boot($d),
            'customer'  => null,
            'accDocs'   => ['permis' => null, 'assurance' => null],
            'teamInvite'=> ['token' => $token, 'team_name' => (string)$ctx['team_name']],
        ]);
    }

    public function teamSubmit(string $token): void
    {
        $ctx = $this->teamContext($token);
        if (!$ctx || $ctx['team_event_status'] !== 'open') {
            Http::abort(404, 'Invitation Team invalide ou fermée.');
        }
        $d = $this->formData();
        $old = Http::body();
        $old['event_id'] = (string)$ctx['event_id'];
        $old['participant_type'] = 'pilot';
        $key = preg_replace('/[^a-f0-9]/i', '', (string)($old['submission_key'] ?? '')) ?? '';
        if (strlen($key) !== 32) {
            $key = bin2hex(random_bytes(16));
        }
        $errors = [];
        $result = null;
        if (Mode::readOnly()) {
            $errors[] = I18n::t('readonly_notice');
        } elseif (!Throttle::allow('submit:' . Http::ip(), 12, 3600)) {
            $errors[] = 'Trop de dépôts depuis votre connexion. Réessayez plus tard.';
        } else {
            try {
                $result = (new Submission($this->db()))->run($old, $_FILES, Http::ip(), I18n::lang(), $key, null, (int)$ctx['id']);
                $errors = $result['errors'];
            } catch (ReadOnlyException $e) {
                $errors[] = I18n::t('readonly_notice');
            }
        }
        if ($result && $result['ok'] && $result['token']) {
            $slug = (string)$this->db()->val('SELECT c.slug FROM events e JOIN circuits c ON c.id=e.circuit_id WHERE e.id=?', [$ctx['event_id']]);
            $this->render('public/success', ['token' => $result['token'], 'official' => Waivers::officialRequired($slug, 'pilot'), 'type' => 'pilot']);
            return;
        }
        http_response_code(422);
        unset($old['signature_data'], $old['_csrf']);
        $this->render('public/form', $d + [
            'old' => $old, 'errors' => $errors, 'key' => $key, 'readonly' => Mode::readOnly(),
            'pdfjs' => is_file(App::path('public/assets/vendor/pdfjs/pdf.min.mjs')), 'boot' => $this->boot($d),
            'customer' => null, 'accDocs' => ['permis' => null, 'assurance' => null],
            'teamInvite' => ['token' => $token, 'team_name' => (string)$ctx['team_name']],
        ]);
    }

    /** Vérification Billetweb en direct (nom, prénom, e-mail). Limitée en fréquence : elle ne doit pas permettre de fouiller les inscrits. */
    public function lookup(): void
    {
        if (!Throttle::allow('lookup:' . Http::ip(), 40, 600)) {
            Http::json(['status' => 'unknown', 'message' => 'Trop de vérifications. Patientez quelques minutes.'], 429);
        }
        $event = Http::int('event_id') > 0 ? \JC\Domain\Events::find($this->db(), Http::int('event_id')) : null;
        if (!$event || (int)$event['is_active'] !== 1) {
            Http::json(['status' => 'unknown', 'message' => ''], 404);
        }
        $type = in_array(Http::str('participant_type', 'pilot'), ['pilot', 'supplemental_driver', 'passenger'], true) ? Http::str('participant_type', 'pilot') : 'pilot';
        $nom = Http::str('nom');
        $prenom = Http::str('prenom');
        try {
            $m = BilletwebMatch::match($this->db(), $event, $type, Http::str('email'), $nom, $prenom, Http::str('buyer_ref'), Http::str('confirmed_attendee_id'));
        } catch (\Throwable $t) {
            \JC\Core\Logger::error('lookup', $t->getMessage());
            Http::json(['status' => 'unknown', 'message' => 'Vérification momentanément indisponible.']);
        }
        $a = $m['attendee'] ?? null;
        $warning = null;
        // Nom et prénom inversés : détecté uniquement à partir de ce que la personne vient de saisir.
        if ($nom !== '' && $prenom !== '' && !empty($event['billetweb_event_id'])) {
            $nn = BilletwebMatch::nameKey($nom);
            $np = BilletwebMatch::nameKey($prenom);
            foreach ($this->db()->all('SELECT firstname,name FROM billetweb_attendees WHERE event_id=? AND disabled=0 AND order_paid=1', [(int)$event['id']]) as $c) {
                if ($nn === BilletwebMatch::nameKey((string)$c['firstname']) && $np === BilletwebMatch::nameKey((string)$c['name'])) {
                    $warning = ['code' => 'name_firstname_inverted', 'message' => 'Nom et prénom potentiellement inversés par rapport à Billetweb.'];
                    break;
                }
            }
        }
        Http::json([
            'status' => $m['status'], 'message' => $m['message'], 'insurance_found' => (bool)($m['insurance_found'] ?? false),
            'insurance_source' => $m['insurance_source'] ?? 'none', 'attendee_id' => $a['attendee_id'] ?? null,
            'wrong_type_ticket' => $m['wrong_type_ticket'] ?? null, 'wrong_type_role' => $m['wrong_type_role'] ?? null,
            'identity_warning' => $warning, 'candidate_id' => $m['candidate_id'] ?? null, 'candidate_email_hint' => $m['candidate_email_hint'] ?? null,
            'vehicle' => ($type === 'pilot' && $a) ? \JC\Domain\Bw::vehicle(\JC\Domain\Bw::raw($a)) : '',
        ]);
    }

    /** Décharge officielle vierge du circuit, à lire avant de signer (document public, sans donnée personnelle). */
    public function officialPdf(int $eventId): void
    {
        $event = \JC\Domain\Events::find($this->db(), $eventId);
        if (!$event) {
            Http::abort(404);
        }
        $tpl = Waivers::templateFor($this->db(), (int)$event['circuit_id'], (string)$event['event_date']);
        $path = $tpl && !empty($tpl['stored_name']) ? Files::resolve('template', basename((string)$tpl['stored_name'])) : null;
        if ($path === null) {
            Http::abort(404, 'Document indisponible.');
        }
        Files::send($path, 'decharge-officielle-circuit.pdf');
    }

    /** Lecture plein écran de la décharge officielle, dans un nouvel onglet ouvert depuis le formulaire :
     * bien plus lisible qu'un petit cadre, tout en gardant le suivi « lu jusqu'au bout » (postMessage vers
     * l'onglet d'origine une fois la fin du document atteinte). */
    public function officialReader(int $eventId): void
    {
        $event = \JC\Domain\Events::find($this->db(), $eventId);
        if (!$event) {
            Http::abort(404);
        }
        View::render('public/official_reader', ['eventId' => $eventId, 'pageTitle' => 'Décharge officielle', 'boot' => ['event' => $eventId]], 'layout_reader');
    }

    // --- Suivi du dossier -----------------------------------------------------------------------------------------

    private function participantByToken(string|int $token): array
    {
        $token = (string)$token;
        $p = preg_match('/^[a-f0-9]{48}$/', $token)
            ? $this->db()->row('SELECT p.*,e.event_date,c.id circuit_id,c.name circuit_name,c.slug FROM participants p LEFT JOIN events e ON e.id=p.event_id LEFT JOIN circuits c ON c.id=e.circuit_id WHERE p.public_token=? AND p.archived_at IS NULL LIMIT 1', [$token])
            : null;
        if (!$p) {
            Http::abort(404, 'Dossier introuvable.');
        }
        return $p;
    }

    public function suivi(string|int $token): void
    {
        if (!Throttle::allow('suivi:' . Http::ip(), 120, 600)) {
            Http::abort(429, 'Trop de requêtes.');
        }
        $p = $this->participantByToken($token);
        $db = $this->db();
        // Une même adresse peut piloter plusieurs dossiers sur la journée (couple, pilote + passager) : on les rassemble.
        $people = [$p];
        if (trim((string)$p['email']) !== '') {
            $more = $db->all('SELECT p.*,e.event_date,c.id circuit_id,c.name circuit_name,c.slug FROM participants p LEFT JOIN events e ON e.id=p.event_id LEFT JOIN circuits c ON c.id=e.circuit_id WHERE p.event_id=? AND LOWER(TRIM(p.email))=LOWER(TRIM(?)) AND p.archived_at IS NULL ORDER BY CASE WHEN p.id=? THEN 0 ELSE 1 END,p.id', [(int)$p['event_id'], $p['email'], (int)$p['id']]);
            $people = $more ?: $people;
        }
        $ids = array_map(fn($x) => (int)$x['id'], $people);
        $docs = [];
        $waivers = [];
        if ($ids) {
            $in = \JC\Core\Db::marks(count($ids));
            foreach ($db->all("SELECT * FROM documents WHERE participant_id IN ($in) ORDER BY id", $ids) as $d) {
                $docs[(int)$d['participant_id']][] = $d;
            }
            foreach ($db->all("SELECT participant_id,signed_at FROM waivers WHERE participant_id IN ($in)", $ids) as $w) {
                $waivers[(int)$w['participant_id']] = $w;
            }
        }
        $this->render('public/suivi', ['p' => $p, 'people' => $people, 'docs' => $docs, 'waivers' => $waivers, 'token' => (string)$token, 'official' => Waivers::officialRequired((string)$p['slug'], (string)$p['participant_type'])]);
    }

    public function replaceForm(string|int $token, int $docId): void
    {
        [$p, $doc] = $this->replaceable($token, $docId);
        $this->render('public/replace', ['p' => $p, 'doc' => $doc, 'token' => (string)$token, 'errors' => [], 'readonly' => Mode::readOnly()]);
    }

    private function replaceable(string|int $token, int $docId): array
    {
        $p = $this->participantByToken($token);
        $doc = $this->db()->row('SELECT * FROM documents WHERE id=? AND participant_id=?', [$docId, (int)$p['id']]);
        if (!$doc || $doc['status'] !== 'rejected') {
            Http::abort(404, 'Document non remplaçable.');
        }
        return [$p, $doc];
    }

    public function replaceSubmit(string|int $token, int $docId): void
    {
        [$p, $doc] = $this->replaceable($token, $docId);
        $errors = [];
        if (Mode::readOnly()) {
            $errors[] = I18n::t('readonly_notice');
        } else {
            $f = $_FILES['document'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $errors[] = 'Fichier requis.';
            } elseif ((int)$f['size'] > Submission::MAX_UPLOAD) {
                $errors[] = 'Fichier trop volumineux (10 Mo maximum).';
            } else {
                $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$f['tmp_name']);
                $ext = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'][$mime] ?? null;
                if (!$ext) {
                    $errors[] = 'Format non autorisé (PDF, JPG, PNG, WEBP ou HEIC).';
                } else {
                    try {
                        $dir = Files::dir($doc['document_type'] === 'permis' ? 'permis' : 'assurance');
                        $stored = bin2hex(random_bytes(24)) . '.' . $ext;
                        if (!move_uploaded_file((string)$f['tmp_name'], $dir . '/' . $stored)) {
                            throw new \RuntimeException('Envoi impossible.');
                        }
                        $db = $this->db();
                        $db->beginTransaction();
                        try {
                            // Comme l'ancien portail : le même document est remplacé et repasse « en attente » (l'historique des décisions est conservé).
                            $db->run("UPDATE documents SET original_name=?,stored_name=?,mime_type=?,file_size=?,status='pending',source='replacement',rejection_reason=NULL,updated_at=NOW() WHERE id=?", [basename((string)$f['name']), $stored, $mime, (int)$f['size'], $docId]);
                            $db->run("UPDATE participants SET global_status='pending',updated_at=NOW() WHERE id=?", [(int)$p['id']]);
                            if ($doc['document_type'] === 'assurance') {
                                // Nouveau fichier envoyé par le participant : une éventuelle validation manuelle
                                // laissée sur le Listing par l'ancien fichier ne doit plus s'afficher (le Listing
                                // reprend alors le statut réel du nouveau document, « en attente »).
                                $db->run("DELETE FROM listing_entry_overrides WHERE field_name='insurance' AND listing_entry_id IN (SELECT id FROM listing_entries WHERE participant_id=?)", [(int)$p['id']]);
                            }
                            \JC\Core\Audit::log('document_replaced', (int)$p['id'], ['document_id' => $docId, 'via' => 'v3']);
                            $db->commit();
                            // Un fichier partagé avec un compte « mon espace » (document réutilisé) n'est jamais supprimé (cf. cron/purge.php).
                            $shared = !empty($doc['stored_name']) && (int)$db->val('SELECT COUNT(*) FROM documents WHERE stored_name=? AND id<>?', [$doc['stored_name'], $docId]) > 0;
                            if (!$shared && !empty($doc['stored_name']) && preg_match('/^[A-Za-z0-9._-]+$/', (string)$doc['stored_name'])) {
                                $old = $dir . '/' . basename((string)$doc['stored_name']);
                                if (is_file($old)) {
                                    @unlink($old);
                                }
                            }
                        } catch (\Throwable $t) {
                            if ($db->inTransaction()) {
                                $db->rollBack();
                            }
                            @unlink($dir . '/' . $stored);
                            throw $t;
                        }
                        Http::redirect('/suivi/' . $token);
                    } catch (ReadOnlyException $e) {
                        $errors[] = I18n::t('readonly_notice');
                    } catch (\RuntimeException $e) {
                        $errors[] = $e->getMessage();
                    }
                }
            }
        }
        http_response_code(422);
        $this->render('public/replace', ['p' => $p, 'doc' => $doc, 'token' => (string)$token, 'errors' => $errors, 'readonly' => Mode::readOnly()]);
    }

    /** Téléchargement de sa décharge par le participant (lien secret). kind = jc | official. */
    public function download(string|int $token, string $kind): void
    {
        if (!Throttle::allow('dl:' . Http::ip(), 60, 600)) {
            Http::abort(429, 'Trop de requêtes.');
        }
        $p = $this->participantByToken($token);
        try {
            $r = Waivers::get($this->db(), (int)$p['id'], $kind === 'official' ? 'official' : 'proof');
        } catch (\RuntimeException $e) {
            Http::abort(404, $e->getMessage());
        }
        \JC\Core\Audit::log('v3.waiver_download', (int)$p['id'], ['kind' => $kind]);
        if ($r['path'] !== null) {
            Files::send($r['path'], $r['name'], false);
        }
        Files::sendBytes((string)$r['bytes'], $r['name'], false);
    }
}
