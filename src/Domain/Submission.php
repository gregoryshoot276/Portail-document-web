<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Audit;
use JC\Core\Db;
use JC\Core\Files;
use JC\Core\Logger;
use JC\Core\Mode;

/**
 * Dépôt d'un dossier par un participant : identité, rapprochement Billetweb, permis, assurance, décharge signée.
 * Règles reprises du formulaire public de l'ancien portail.
 */
final class Submission
{
    public const MAX_UPLOAD = 10 * 1024 * 1024;
    private const MIMES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/heic' => 'heic', 'image/heif' => 'heif'];

    /** @var callable */
    private $mover;

    public function __construct(private Db $db, ?callable $mover = null)
    {
        $this->mover = $mover ?? 'move_uploaded_file';
    }

    private static function post(array $in, string $k): string
    {
        return isset($in[$k]) && is_scalar($in[$k]) ? trim((string)$in[$k]) : '';
    }

    private static function uploaded(array $files, string $k): bool
    {
        return !empty($files[$k]) && ($files[$k]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;
    }

    /** Valide la signature (PNG en base64) et retourne les octets, ou null. */
    public static function decodeSignature(string $dataUrl): ?string
    {
        if (!str_starts_with($dataUrl, 'data:image/png;base64,')) {
            return null;
        }
        $bin = base64_decode(substr($dataUrl, 22), true);
        if ($bin === false || strlen($bin) < 100 || strlen($bin) > 1_500_000 || !str_starts_with($bin, "\x89PNG\r\n\x1a\n")) {
            return null;
        }
        $info = @getimagesizefromstring($bin);
        return ($info && $info[0] > 20 && $info[1] > 10 && $info[0] <= 3000 && $info[1] <= 2000) ? $bin : null;
    }

    /**
     * @param ?int $accountId compte « mon espace » connecté (réutilisation de documents), ou null en invité
     * @param ?int $teamEventDriverId pilote invité par un Team Manager (assurance flotte, type forcé à pilote)
     * @return array{ok:bool,errors:string[],token:?string}
     */
    public function run(array $in, array $files, string $ip, string $lang, string $submissionKey, ?int $accountId = null, ?int $teamEventDriverId = null): array
    {
        Mode::assertWritable();
        $db = $this->db;
        // Double clic / page rechargée : la même soumission déjà enregistrée renvoie simplement le même dossier.
        $done = $db->val('SELECT p.public_token FROM waiver_submission_guard g JOIN participants p ON p.id=g.participant_id WHERE g.submission_key=? LIMIT 1', [$submissionKey]);
        if ($done) {
            return ['ok' => true, 'errors' => [], 'token' => (string)$done];
        }
        $errors = [];
        $type = in_array($in['participant_type'] ?? 'pilot', ['pilot', 'supplemental_driver', 'passenger'], true) ? (string)$in['participant_type'] : 'pilot';
        if ($teamEventDriverId !== null) {
            // Un pilote invité par son Team roule toujours comme pilote, quel que soit ce que le formulaire a transmis.
            $type = 'pilot';
        }
        $eventId = (int)($in['event_id'] ?? 0);
        $event = $eventId > 0 ? Events::find($db, $eventId) : null;
        if (!$event || (int)($event['is_active'] ?? 0) !== 1) {
            $event = null;
            $errors[] = 'Veuillez sélectionner votre événement.';
        }
        $nom = self::post($in, 'nom');
        $prenom = self::post($in, 'prenom');
        $email = self::post($in, 'email');
        $tel = self::post($in, 'telephone');
        $place = self::post($in, 'signed_place');
        $buyerRef = self::post($in, 'buyer_ref');
        $vehicle = self::post($in, 'vehicle');
        $linked = self::post($in, 'linked_driver_name');
        $confirmed = self::post($in, 'confirmed_attendee_id');
        $ignored = self::post($in, 'ignored_candidate_id');
        if ($nom === '' || $prenom === '') {
            $errors[] = 'Nom et prénom obligatoires.';
        }
        if (mb_strlen($nom) > 120 || mb_strlen($prenom) > 120 || mb_strlen($email) > 190 || mb_strlen($tel) > 60 || mb_strlen($place) > 190) {
            $errors[] = 'Un des champs est trop long.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Adresse e-mail invalide.';
        }
        if ($place === '') {
            $errors[] = 'Le lieu de signature est obligatoire.';
        }
        if ($type === 'supplemental_driver' && $linked === '') {
            $errors[] = 'Indiquez le pilote principal auquel vous êtes rattaché.';
        }

        $slug = (string)($event['slug'] ?? '');
        $eventDate = (string)($event['event_date'] ?? '');
        $template = $event ? Waivers::templateFor($db, (int)$event['circuit_id'], $eventDate) : null;
        if ($event && !$template) {
            $errors[] = 'La décharge de cet événement n’est pas encore disponible.';
        } elseif ($template && !Waivers::signingOpen($template)) {
            $errors[] = 'La signature de la décharge n’est pas ouverte actuellement.';
        }
        $frClauses = I18n::frClauses($type);
        $shown = I18n::clauses($type, $lang);
        foreach ($frClauses as $k => $_) {
            if (empty($in['waiver_clause'][$k])) {
                $errors[] = 'Toutes les clauses doivent être acceptées.';
                break;
            }
        }
        $official = Waivers::officialRequired($slug, $type);
        if ($official && empty($in['official_scrolled'])) {
            $errors[] = 'Merci de parcourir la décharge officielle jusqu’à la fin avant de signer.';
        }
        $f = [];
        foreach (['birth_date', 'address', 'postal_code', 'city', 'permit_number', 'emergency_phone', 'club', 'license_number', 'aco_member_number', 'image_rights'] as $k) {
            $f[$k] = self::post($in, $k);
        }
        if ($f['birth_date'] !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['birth_date'])) {
            $errors[] = 'Date de naissance invalide.';
        }
        if ($official && $slug === 'magny-cours' && ($f['birth_date'] === '' || $f['address'] === '')) {
            $errors[] = 'Date de naissance et adresse sont requises par Magny-Cours.';
        }
        if ($official && $slug === 'bugatti' && in_array($type, ['pilot', 'supplemental_driver'], true)) {
            foreach (['birth_date', 'address', 'postal_code', 'city', 'permit_number', 'emergency_phone'] as $k) {
                if ($f[$k] === '') {
                    $errors[] = 'Merci de compléter les informations requises par Bugatti.';
                    break;
                }
            }
            if (!in_array($f['image_rights'], ['yes', 'no'], true)) {
                $errors[] = 'Choisissez votre préférence de droit à l’image.';
            }
        }
        $sigBin = self::decodeSignature(self::post($in, 'signature_data'));
        if ($sigBin === null) {
            $errors[] = 'La signature est obligatoire.';
        }

        $match = $event ? BilletwebMatch::match($db, $event, $type, $email, $nom, $prenom, $buyerRef, $confirmed)
            : ['status' => 'unknown', 'attendee' => null, 'insurance_found' => false, 'insurance_source' => 'none', 'score' => 0, 'message' => ''];
        $att = $match['attendee'];
        if (($match['status'] ?? '') === 'confirm_needed' && $ignored !== '' && $ignored === (string)($match['candidate_id'] ?? '')) {
            $match = ['status' => 'not_found', 'attendee' => null, 'insurance_found' => false, 'insurance_source' => 'none', 'score' => $match['score'] ?? 0, 'message' => 'Vous avez choisi de continuer avec une autre adresse e-mail.'];
            $att = null;
        } elseif (($match['status'] ?? '') === 'confirm_needed' && $confirmed === '') {
            $errors[] = 'Confirmez si l’adresse e-mail masquée retrouvée vous appartient, ou choisissez « Non, continuer quand même ».';
        }

        if ($event && !$errors) {
            // Un même e-mail peut être partagé par deux personnes différentes (téléphone prêté, foyer sans e-mail
            // individuel) : on ne bloque que si le nom ET le prénom correspondent aussi (vraie resoumission).
            $dupRow = $db->row("SELECT id,nom,prenom FROM participants WHERE event_id=? AND participant_type=? AND LOWER(TRIM(email))=LOWER(TRIM(?)) AND archived_at IS NULL ORDER BY id DESC LIMIT 1", [$eventId, $type, $email]);
            if ($dupRow && BilletwebMatch::nameKey((string)$dupRow['nom']) === BilletwebMatch::nameKey($nom) && BilletwebMatch::nameKey((string)$dupRow['prenom']) === BilletwebMatch::nameKey($prenom)) {
                Notifier::send($db, (int)$dupRow['id'], 'duplicate_file', 'duplicate_file', [], 'participant');
                $errors[] = 'Un dossier existe déjà pour ce nom et cet e-mail sur cet événement. Un lien de suivi vient de vous être renvoyé par e-mail.';
            }
        }

        // Documents « mon espace » proposés à la réutilisation (permis/assurance déjà validés, encore valides).
        $accPermit = ($accountId !== null && !empty($in['reuse_permit']))
            ? $db->row("SELECT * FROM documents WHERE account_id=? AND document_type='permis' AND status='validated' AND reusable=1 ORDER BY id DESC LIMIT 1", [$accountId])
            : null;
        $accInsurance = ($accountId !== null && !empty($in['reuse_insurance']))
            ? $db->row("SELECT * FROM documents WHERE account_id=? AND document_type='assurance' AND status='validated' AND reusable=1 AND (valid_until IS NULL OR valid_until>=CURDATE()) ORDER BY valid_until DESC,id DESC LIMIT 1", [$accountId])
            : null;
        $insuranceMode = self::post($in, 'insurance_mode') === 'organizer' ? 'organizer' : 'personal';
        // Un fichier réellement choisi mais rejeté par PHP (trop volumineux, connexion coupée en cours d'envoi —
        // fréquent en 4G au circuit) ne doit jamais ressembler à « vous avez oublié de le joindre » : le message
        // doit dire ce qui s'est vraiment passé, sinon la personne réessaie la même action en boucle sans succès.
        $uploadErrorLabels = [
            'permis' => 'du permis (recto)', 'permis_verso' => 'du permis (verso)', 'assurance' => 'de l’attestation d’assurance',
        ];
        foreach ($uploadErrorLabels as $k => $label) {
            $code = $files[$k]['error'] ?? UPLOAD_ERR_NO_FILE;
            if (in_array($code, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
                $errors[] = 'Le fichier ' . $label . ' est trop volumineux (10 Mo maximum) : merci d’en choisir un plus petit ou de reprendre une photo moins lourde.';
            } elseif (in_array($code, [UPLOAD_ERR_PARTIAL, UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION], true)) {
                $errors[] = 'L’envoi ' . $label . ' a été interrompu (connexion coupée). Merci de réessayer, si possible avec un bon réseau Wi-Fi ou 4G/5G.';
            }
        }
        if (in_array($type, ['pilot', 'supplemental_driver'], true)) {
            if (!self::uploaded($files, 'permis') && !$accPermit && ($files['permis']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'Le permis est obligatoire.';
            }
            if ($type === 'pilot' && $teamEventDriverId === null && empty($match['insurance_found']) && $insuranceMode === 'personal' && !self::uploaded($files, 'assurance') && !$accInsurance && ($files['assurance']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                $errors[] = 'L’attestation d’assurance est obligatoire.';
            }
        }
        foreach (['permis', 'permis_verso', 'assurance'] as $k) {
            if (self::uploaded($files, $k)) {
                $err = $this->checkUpload($files[$k]);
                if ($err) {
                    $errors[] = $err;
                }
            }
        }
        if ($errors) {
            return ['ok' => false, 'errors' => array_values(array_unique($errors)), 'token' => null];
        }

        // --- Enregistrement ---
        $written = [];
        $db->beginTransaction();
        try {
            $guard = $db->prepare('INSERT IGNORE INTO waiver_submission_guard(submission_key,event_id,participant_type,created_at) VALUES(?,?,?,NOW())');
            $guard->execute([$submissionKey, $eventId, $type]);
            if ($guard->rowCount() === 0) {
                $existing = (string)$db->val('SELECT p.public_token FROM waiver_submission_guard g LEFT JOIN participants p ON p.id=g.participant_id WHERE g.submission_key=? LIMIT 1', [$submissionKey]);
                $db->rollBack();
                if ($existing !== '') {
                    return ['ok' => true, 'errors' => [], 'token' => $existing];
                }
                return ['ok' => false, 'errors' => ['Cette décharge est déjà en cours d’enregistrement. Patientez quelques secondes puis utilisez votre lien de suivi.'], 'token' => null];
            }
            $token = bin2hex(random_bytes(24));
            $global = $type === 'passenger' ? 'validated' : 'pending';
            // Un dossier rattaché à un compte « mon espace » suit la rétention du compte, jamais la purge RGPD des invités.
            $retentionMode = $accountId !== null ? 'account' : 'event';
            $guestDays = max(1, (int)Settings::get($db, 'retention_guest_days', '30'));
            $deleteAfter = ($retentionMode === 'event' && $eventDate !== '') ? date('Y-m-d H:i:s', strtotime($eventDate . ' 23:59:59 +' . $guestDays . ' days')) : null;
            $db->run(
                'INSERT INTO participants(account_id,event_id,event_name,participant_type,nom,prenom,email,telephone,birth_date,address,postal_code,city,permit_number,emergency_phone,club,license_number,aco_member_number,image_rights,vehicle,ticket_status,ticket_message,billetweb_match_score,insurance_billetweb_source,billetweb_participant_id,billetweb_attendee_id,billetweb_order_ref,billetweb_order_id,insurance_mode,linked_driver_name,global_status,retention_mode,delete_after,public_token,created_at,updated_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())',
                [
                    $accountId, $eventId, (string)$event['name'], $type, $nom, $prenom, $email, $tel, $f['birth_date'] ?: null, $f['address'] ?: null, $f['postal_code'] ?: null, $f['city'] ?: null,
                    $f['permit_number'] ?: null, $f['emergency_phone'] ?: null, $f['club'] ?: null, $f['license_number'] ?: null, $f['aco_member_number'] ?: null, $f['image_rights'] ?: null, $vehicle ?: null,
                    $match['status'], $match['message'], $match['score'] ?? null, $match['insurance_source'] ?? 'none',
                    $att['attendee_id'] ?? null, $att['attendee_id'] ?? null, $att['order_ext_id'] ?? null, $att['order_id'] ?? null,
                    $insuranceMode, $linked ?: null, $global, $retentionMode, $deleteAfter, $token,
                ]
            );
            $pid = (int)$db->lastInsertId();

            if ($type === 'supplemental_driver' && $linked !== '') {
                $ln = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $linked) ?? $linked), 'UTF-8');
                $found = (bool)$db->val("SELECT id FROM participants WHERE event_id=? AND participant_type='pilot' AND archived_at IS NULL AND (LOWER(TRIM(CONCAT(prenom,' ',nom)))=? OR LOWER(TRIM(CONCAT(nom,' ',prenom)))=?) LIMIT 1", [$eventId, $ln, $ln])
                    || (bool)$db->val("SELECT id FROM billetweb_attendees WHERE event_id=? AND disabled=0 AND order_paid=1 AND (LOWER(TRIM(CONCAT(firstname,' ',name)))=? OR LOWER(TRIM(CONCAT(name,' ',firstname)))=?) LIMIT 1", [$eventId, $ln, $ln]);
                $db->run('UPDATE participants SET linked_driver_found=? WHERE id=?', [$found ? 1 : 0, $pid]);
            }

            // Un document neuf déposé par un compte connecté devient réutilisable pour ses prochaines inscriptions.
            $reusableFlag = $accountId !== null ? 1 : 0;
            $insDoc = "INSERT INTO documents(participant_id,account_id,document_type,original_name,stored_name,mime_type,file_size,status,source,valid_until,reusable,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())";
            if (in_array($type, ['pilot', 'supplemental_driver'], true)) {
                if ($accPermit) {
                    // Réutilisation : même fichier (stored_name partagé, jamais dupliqué ni supprimé tant qu'un dossier le référence).
                    $db->run($insDoc, [$pid, $accountId, 'permis', $accPermit['original_name'], $accPermit['stored_name'], $accPermit['mime_type'], $accPermit['file_size'], 'validated', 'account_reuse', null, 1]);
                } else {
                    foreach (['permis' => 'upload_recto', 'permis_verso' => 'upload_verso'] as $field => $src) {
                        if (!self::uploaded($files, $field)) {
                            continue;
                        }
                        [$stored, $mime, $size] = $this->store($files[$field], 'permis', $written);
                        $db->run($insDoc, [$pid, $accountId, 'permis', basename((string)$files[$field]['name']), $stored, $mime, $size, 'pending', $field === 'permis' && !self::uploaded($files, 'permis_verso') ? 'upload' : $src, null, $reusableFlag]);
                    }
                }
                if ($type === 'pilot') {
                    if ($teamEventDriverId !== null) {
                        // Assurance couverte par la flotte du Team (team_insurances) : rien à créer par pilote.
                    } elseif (!empty($match['insurance_found'])) {
                        $db->run("INSERT INTO documents(participant_id,account_id,document_type,status,source,reusable,created_at,updated_at) VALUES(?,?,'assurance','validated',?,0,NOW(),NOW())", [$pid, $accountId, 'billetweb_' . ($match['insurance_source'] ?? 'initial')]);
                    } elseif ($accInsurance) {
                        $db->run($insDoc, [$pid, $accountId, 'assurance', $accInsurance['original_name'], $accInsurance['stored_name'], $accInsurance['mime_type'], $accInsurance['file_size'], 'validated', 'account_reuse', $accInsurance['valid_until'], 1]);
                    } elseif ($insuranceMode === 'personal') {
                        $vu = self::post($in, 'insurance_valid_until');
                        if ($vu === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $vu)) {
                            $vu = substr($eventDate, 0, 4) . '-12-31';
                        }
                        [$stored, $mime, $size] = $this->store($files['assurance'], 'assurance', $written);
                        $db->run($insDoc, [$pid, $accountId, 'assurance', basename((string)$files['assurance']['name']), $stored, $mime, $size, 'pending', 'upload', $vu, $reusableFlag]);
                    } else {
                        $db->run("INSERT INTO documents(participant_id,account_id,document_type,status,source,created_at,updated_at) VALUES(?,?,'assurance','to_review','billetweb_not_found',NOW(),NOW())", [$pid, $accountId]);
                    }
                }
            }

            $sigName = bin2hex(random_bytes(24)) . '.png';
            $sigDir = Files::dir('signature');
            if (file_put_contents($sigDir . '/' . $sigName, $sigBin, LOCK_EX) === false) {
                throw new \RuntimeException('Signature non enregistrée.');
            }
            $written[] = $sigDir . '/' . $sigName;
            $clauseJson = json_encode([
                'accepted' => true, 'template' => $template['name'], 'version' => $template['version_label'], 'language' => $lang,
                'accepted_texts' => array_values($shown), 'accepted_texts_fr' => array_values($frClauses), 'participant_type' => $type,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $opened = $official && !empty($in['official_opened']) ? date('Y-m-d H:i:s') : null;
            $scrolled = $official && !empty($in['official_scrolled']) ? date('Y-m-d H:i:s') : null;
            $db->run(
                'INSERT INTO waivers(participant_id,template_id,clauses_json,signature_file,signed_name,signed_place,signed_at,official_opened_at,official_scrolled_at,ip_address,created_at) VALUES(?,?,?,?,?,?,NOW(),?,?,?,NOW())',
                [$pid, $template['id'], $clauseJson, $sigName, $prenom . ' ' . $nom, $place, $opened, $scrolled, $ip]
            );
            Audit::log('participant_submission', $pid, ['type' => $type, 'ticket_status' => $match['status'], 'event_id' => $eventId, 'via' => 'v3']);
            $db->run('UPDATE waiver_submission_guard SET participant_id=?,completed_at=NOW() WHERE submission_key=?', [$pid, $submissionKey]);
            if ($teamEventDriverId !== null) {
                $db->run("UPDATE team_event_drivers SET participant_id=?,invite_status='completed',completed_at=NOW() WHERE id=?", [$pid, $teamEventDriverId]);
            }
            $db->commit();
        } catch (\Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            foreach ($written as $w) {
                @unlink($w);
            }
            Logger::error('submission', $t->getMessage());
            if ($t instanceof \JC\Core\ReadOnlyException) {
                throw $t;
            }
            return ['ok' => false, 'errors' => ['Une erreur technique est survenue, votre dossier n’a pas été enregistré. Réessayez dans quelques instants.'], 'token' => null];
        }

        $this->notify($pid, $type, $event, $match);
        return ['ok' => true, 'errors' => [], 'token' => $token];
    }

    private function checkUpload(array $file): ?string
    {
        if ((int)$file['size'] > self::MAX_UPLOAD) {
            return 'Un fichier est trop volumineux (10 Mo maximum).';
        }
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        return isset(self::MIMES[$mime]) ? null : 'Format de fichier non autorisé (PDF, JPG, PNG, WEBP ou HEIC).';
    }

    /** @return array{0:string,1:string,2:int} nom rangé, type, taille */
    private function store(array $file, string $kind, array &$written): array
    {
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file((string)$file['tmp_name']);
        if (!isset(self::MIMES[$mime])) {
            throw new \RuntimeException('Format non autorisé.');
        }
        $stored = bin2hex(random_bytes(24)) . '.' . self::MIMES[$mime];
        $dir = Files::dir($kind);
        if (!($this->mover)((string)$file['tmp_name'], $dir . '/' . $stored)) {
            throw new \RuntimeException('Envoi du fichier impossible.');
        }
        $written[] = $dir . '/' . $stored;
        return [$stored, $mime, (int)$file['size']];
    }

    private function notify(int $pid, string $type, array $event, array $match): void
    {
        try {
            if ($type === 'passenger') {
                $purchase = trim((string)($event['passenger_purchase_url'] ?? '')) ?: Settings::get($this->db, 'billetweb_post_url', 'https://www.billetweb.fr/options-2026-post-inscription');
                $msg = match ($match['status']) {
                    'found' => 'Nous avons bien retrouvé votre billet passager.',
                    'unassigned' => 'Nous avons trouvé un billet passager sur la commande indiquée, mais sans correspondance nominative certaine. Présentez-vous avec l’acheteur ou le pilote principal.',
                    'not_found' => 'Nous n’avons pas retrouvé de billet à votre nom. Si le pilote principal a acheté plusieurs billets sans renseigner les noms, présentez-vous avec lui à l’accueil.',
                    default => 'La vérification Billetweb n’a pas encore pu être effectuée.',
                };
                $block = ($purchase !== '' && $match['status'] === 'not_found' && preg_match('#^https?://#i', $purchase))
                    ? '<p><a href="' . htmlspecialchars($purchase, ENT_QUOTES) . '">Acheter un billet passager</a></p>' : '';
                Notifier::send($this->db, $pid, 'passenger_confirmation', 'passenger_confirmation', ['ticket_message' => $msg, 'purchase_block' => $block], 'participant');
            } else {
                Notifier::received($this->db, $pid);
            }
        } catch (\Throwable $t) {
            Logger::error('submission', 'notification : ' . $t->getMessage());
        }
    }
}
