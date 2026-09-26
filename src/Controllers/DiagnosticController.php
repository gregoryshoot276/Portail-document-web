<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\App;
use JC\Core\Db;
use JC\Core\Files;
use JC\Core\Mode;

/** Page de diagnostic : vérifie que le serveur, la base et les dossiers sont prêts. */
final class DiagnosticController extends BaseController
{
    private const TABLES = [
        'admins', 'app_settings', 'audit_log', 'billetweb_attendees', 'billetweb_post_attendees', 'circuits', 'documents',
        'document_validations', 'events', 'listing_entries', 'listing_entry_overrides', 'listing_entry_payments', 'listing_entry_hidden',
        'listing_entry_meta', 'listing_starter_checks', 'listing_rfid_tags', 'listing_rfid_passages', 'listing_tariffs',
        'listing_waiver_overrides', 'listing_communication_status', 'listing_event_finance', 'listing_cell_locks',
        'participants', 'participant_mail_log', 'waivers', 'waiver_templates',
        // Fusion, mail center et anti-double-envoi : tables auto-créées par l'ancien portail à leur premier usage.
        'listing_entry_links', 'listing_merge_identities', 'listing_entry_merges', 'listing_participant_links',
        'mail_templates', 'communication_circuit_config', 'waiver_submission_guard',
        // Comptes participants et Teams (V1.x) : réutilisation de documents et invitations pilotes.
        'customer_accounts', 'teams', 'team_events', 'team_vehicles', 'team_event_vehicles', 'team_insurances', 'team_event_drivers',
    ];

    public function index(): void
    {
        $checks = [];
        $add = function (string $group, string $label, string $status, string $detail = '') use (&$checks) {
            $checks[] = ['group' => $group, 'label' => $label, 'status' => $status, 'detail' => $detail];
        };

        // Serveur
        $add('Serveur', 'Version de PHP', PHP_VERSION_ID >= 80100 ? 'ok' : 'ko', PHP_VERSION . ' (8.1 minimum)');
        foreach (['pdo_mysql', 'mbstring', 'fileinfo', 'curl', 'openssl', 'zlib', 'gd'] as $ext) {
            $add('Serveur', 'Extension ' . $ext, extension_loaded($ext) ? 'ok' : ($ext === 'gd' ? 'warn' : 'ko'), extension_loaded($ext) ? 'chargée' : 'manquante');
        }
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $add('Serveur', 'Connexion sécurisée (HTTPS)', $https ? 'ok' : 'warn', $https ? 'active' : 'non détectée : les cookies de session ne seront pas « secure »');
        foreach (['storage/state', 'storage/logs'] as $d) {
            $ok = is_dir(App::path($d)) && is_writable(App::path($d));
            $add('Serveur', 'Dossier ' . $d . ' inscriptible', $ok ? 'ok' : 'ko', $ok ? 'oui' : 'non : la limitation de connexions, les journaux et le choix du mode ne fonctionneront pas');
        }
        $add('Serveur', 'Fichier de configuration hors du dossier public', is_file(App::path('config/config.php')) ? 'ok' : 'ko');

        // Base
        $db = null;
        try {
            $db = $this->db();
            $add('Base de données', 'Connexion', 'ok', 'MySQL ' . $db->getAttribute(\PDO::ATTR_SERVER_VERSION));
            try {
                $ro = (int)$db->val('SELECT @@session.transaction_read_only'); // MySQL 8
            } catch (\Throwable $e) {
                $ro = (int)$db->val('SELECT @@session.tx_read_only');          // MariaDB / MySQL 5.7
            }
            $add('Base de données', 'Protection MySQL « lecture seule »', Mode::readOnly() ? ($ro === 1 ? 'ok' : 'ko') : 'warn',
                Mode::readOnly() ? ($ro === 1 ? 'active : MySQL refuserait toute écriture' : 'NON active alors que le mode lecture seule est demandé') : 'désactivée (mode écriture)');
            $add('Base de données', 'Garde-fou applicatif', Db::isWrite('UPDATE x SET a=1') && !Db::isWrite('SELECT 1') ? 'ok' : 'ko', 'les requêtes UPDATE/INSERT/DELETE sont reconnues et bloquées en lecture seule');
            $have = $db->col('SELECT table_name FROM information_schema.tables WHERE table_schema=DATABASE()');
            $have = array_map('strtolower', $have);
            $missing = array_values(array_filter(self::TABLES, fn($t) => !in_array($t, $have, true)));
            $add('Base de données', 'Tables attendues (' . count(self::TABLES) . ')', $missing ? 'ko' : 'ok', $missing ? 'manquantes : ' . implode(', ', $missing) : 'toutes présentes');
            foreach (['admins' => 'comptes', 'events' => 'journées', 'participants' => 'dossiers', 'documents' => 'documents', 'waivers' => 'décharges', 'listing_entries' => 'lignes de listing'] as $t => $l) {
                if (in_array($t, $have, true)) {
                    $add('Base de données', 'Contenu : ' . $l, 'info', (string)$db->val("SELECT COUNT(*) FROM `$t`"));
                }
            }
            // Décharges : modèles actifs et PDF officiels présents dans le dossier Templates
            if (in_array('waiver_templates', $have, true)) {
                $tpls = $db->all('SELECT t.id,t.stored_name,c.name circuit_name,c.slug FROM waiver_templates t JOIN circuits c ON c.id=t.circuit_id WHERE t.is_active=1 AND t.event_valid_until>=CURDATE()');
                $add('Décharges', 'Modèles de décharge actifs (à venir)', $tpls ? 'ok' : 'warn', (string)count($tpls) . ($tpls ? '' : ' : le formulaire public ne pourra pas enregistrer de dossier'));
                foreach ($tpls as $t) {
                    if (\JC\Domain\Waivers::officialRequired((string)$t['slug'], 'pilot')) {
                        $okPdf = $t['stored_name'] && Files::resolve('template', basename((string)$t['stored_name'])) !== null;
                        $add('Décharges', 'PDF officiel « ' . $t['circuit_name'] . ' »', $okPdf ? 'ok' : 'ko', $okPdf ? (string)$t['stored_name'] : 'fichier absent du dossier Templates : la décharge officielle ne pourra pas être générée');
                    }
                }
            }
            $pdfjs = is_file(App::path('public/assets/vendor/pdfjs/pdf.min.mjs'));
            $add('Décharges', 'Lecteur PDF de la décharge officielle (pdf.js)', $pdfjs ? 'ok' : 'warn', $pdfjs ? 'installé : le participant doit parcourir le document jusqu’à la fin' : 'non installé : le participant coche « J’ai lu la décharge » (déclaration sur l’honneur)');
            $mailOn = \JC\Domain\Mailer::enabled($db);
            $add('E-mails', 'Envoi d’e-mails aux participants', $mailOn ? 'ok' : 'warn', $mailOn ? 'activé (' . \JC\Domain\Settings::get($db, 'mail_transport', 'php') . ')' : 'désactivé (réglage mail_enabled de l’ancien portail) : aucun e-mail ne partira');
            $bwOk = (new \JC\Domain\BilletwebClient($db))->configured();
            $add('Billetweb', 'Identifiants API', $bwOk ? 'ok' : 'warn', $bwOk ? 'renseignés (valeurs non affichées)' : 'absents : la synchronisation est impossible');
            $last = \JC\Domain\Settings::get($db, 'v3_cron_last_sync');
            $add('Billetweb', 'Dernière synchronisation planifiée (cron v3)', $last !== '' ? 'ok' : 'info', $last !== '' ? $last : 'jamais lancée (voir README : tâche cron cron/sync.php)');
            foreach (['billetweb_user', 'billetweb_key', 'urtime_api_key', 'smtp_host'] as $k) {
                $set = trim((string)$db->val('SELECT setting_value FROM app_settings WHERE setting_key=?', [$k])) !== '';
                $add('Réglages existants', $k, $set ? 'ok' : 'info', $set ? 'renseigné (valeur non affichée)' : 'non renseigné');
            }
        } catch (\Throwable $e) {
            $add('Base de données', 'Connexion', 'ko', 'échec : ' . (App::config('debug') ? $e->getMessage() : 'vérifiez config/config.php'));
        }

        // Fichiers de l'ancien portail
        $add('Fichiers', 'Dossier racine des documents', is_dir(Files::root()) ? 'ok' : 'ko', Files::root() ?: 'non configuré (storage_root)');
        foreach (Files::status() as $k => $s) {
            $count = ($s['exists'] && $s['readable']) ? count(glob(Files::root() . '/' . $s['dir'] . '/*') ?: []) : 0;
            $add('Fichiers', $s['dir'], $s['exists'] && $s['readable'] ? 'ok' : ($k === 'team_insurance' || $k === 'waiver' ? 'warn' : 'ko'),
                $s['exists'] ? ($s['readable'] ? $count . ' fichier(s)' : 'illisible') : 'dossier absent');
        }

        $this->view('diagnostic', ['title' => 'Diagnostic', 'checks' => $checks]);
    }
}
