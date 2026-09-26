<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\App;
use JC\Core\Db;

/**
 * Modèles d'e-mails : ceux de l'ancien Mail Center (table mail_templates) s'ils existent, sinon des textes par défaut.
 * Les valeurs insérées sont échappées, sauf les blocs HTML prévus (bloc_briefing...).
 */
final class MailTemplates
{
    private const HTML_KEYS = ['bloc_briefing', 'bloc_infos_evenement', 'purchase_block'];

    public const DEFAULTS = [
        'pilot_received' => [
            'Dossier reçu - {circuit} {date_evenement}',
            '<p>Bonjour <strong>{prenom}</strong>,</p><p>Votre dossier pour <strong>{evenement}</strong> a bien été reçu.</p><p>Nous allons maintenant vérifier vos justificatifs.</p><p><a href="{lien_suivi}">Suivre mon dossier</a></p><p><strong>À très vite sur circuit !</strong></p>',
        ],
        'passenger_confirmation' => [
            'Confirmation passager - {circuit} {date_evenement}',
            '<p>Bonjour <strong>{prenom}</strong>,</p><p>Votre décharge passager pour <strong>{evenement}</strong> est enregistrée.</p><p>{ticket_message}</p>{purchase_block}<p><a href="{lien_suivi}">Voir mon dossier</a></p>',
        ],
        'pilot_validated' => [
            'Votre dossier Journée Circuit est validé',
            '<p>Bonjour <strong>{prenom}</strong>,</p><p>Le dossier de <strong>{prenom} {nom}</strong> pour <strong>{evenement}</strong> est validé.</p><p>Toutes les vérifications ont été effectuées et vos documents sont conformes.</p>{bloc_briefing}<p><strong>À très vite sur circuit !</strong></p>',
        ],
        'pilot_validated_with_note' => [
            'Votre dossier Journée Circuit est validé',
            '<p>Bonjour {prenom},</p><p>Votre dossier pour <strong>{evenement}</strong> est validé.</p><p><strong>Message de l’organisation :</strong> {motif}</p><p>À très vite sur circuit !</p>',
        ],
        'document_validated_note' => [
            'Mise à jour de votre dossier Journée Circuit',
            '<p>Bonjour {prenom},</p><p>Un document de votre dossier pour <strong>{evenement}</strong> a été validé.</p><p><strong>Message de l’organisation :</strong> {motif}</p><p><a href="{lien_suivi}">Voir mon dossier</a></p>',
        ],
        'document_rejected' => [
            'Action requise sur votre dossier Journée Circuit',
            '<p>Bonjour <strong>{prenom}</strong>,</p><p>Un document de votre dossier pour <strong>{evenement}</strong> a été refusé.</p><p><strong>Motif :</strong> {motif}</p><p><a href="{lien_suivi}">Corriger / consulter mon dossier</a></p>',
        ],
        'participant_reminder' => [
            'Votre dossier Journée Circuit est à compléter',
            '<p>Bonjour {prenom},</p><p>Votre dossier pour <strong>{evenement}</strong> est encore incomplet.</p><p>Pensez à compléter votre décharge et les éléments manquants avant votre journée.</p><p><a href="{lien_suivi}">Compléter mon dossier</a></p>',
        ],
        'duplicate_file' => [
            'Votre dossier Journée Circuit existe déjà',
            '<p>Un dossier existe déjà pour cette journée.</p><p><a href="{lien_suivi}">Reprendre mon dossier</a></p>',
        ],
        'review_reward' => [
            'Merci pour votre avis - votre code promo',
            '<p>Bonjour {prenom},</p><p>Merci pour votre avis {plateforme}.</p><p>Votre code de réduction de <strong>{montant} €</strong> est : <strong>{code_promo}</strong></p><p>À très vite sur circuit !</p>',
        ],
        'review_rejected' => [
            'Votre demande de code promo est à corriger',
            '<p>Bonjour {prenom},</p><p>Votre demande ne peut pas encore être validée.</p><p><strong>Motif :</strong> {motif}</p><p><a href="{lien_correction}">Remplacer ma capture</a></p>',
        ],
        'replacement_registered' => [
            'Votre inscription Journée Circuit',
            '<p>Bonjour {prenom},</p><p>Vous êtes désormais inscrit(e) pour <strong>{evenement}</strong>, en remplacement d’un autre participant.</p><p>Les documents et décharges de l’ancien participant ne sont jamais transférés : merci de créer votre propre dossier.</p><p><a href="{lien_dossier}">Compléter mon dossier</a></p>',
        ],
    ];

    private static function replace(string $text, array $vars, bool $escape): string
    {
        foreach ($vars as $k => $v) {
            $val = (string)$v;
            if ($escape && !in_array($k, self::HTML_KEYS, true)) {
                $val = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $text = str_replace('{' . $k . '}', $val, $text);
        }
        return $text;
    }

    /** Bloc « briefing en ligne » configuré par circuit dans l'ancien Mail Center. */
    private static function briefing(Db $db, int $circuitId): array
    {
        if ($circuitId <= 0) {
            return ['lien_briefing' => '', 'bloc_briefing' => ''];
        }
        $r = $db->row('SELECT briefing_url,briefing_text,briefing_link_text FROM communication_circuit_config WHERE circuit_id=?', [$circuitId]);
        $url = trim((string)($r['briefing_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url)) {
            return ['lien_briefing' => '', 'bloc_briefing' => ''];
        }
        $text = trim((string)($r['briefing_text'] ?? '')) ?: 'Briefing en ligne : avez-vous pensé à le réaliser avant votre journée ?';
        $link = trim((string)($r['briefing_link_text'] ?? '')) ?: 'Accéder au briefing';
        $e = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        if (preg_match('/^Briefing en ligne\s*:\s*(.*)$/iu', $text, $m)) {
            $lead = '<strong>Briefing en ligne :</strong>' . (trim($m[1]) !== '' ? ' ' . $e(trim($m[1])) : '');
        } else {
            $lead = $e($text);
        }
        return ['lien_briefing' => $url, 'bloc_briefing' => '<p>' . $lead . ' <a href="' . $e($url) . '">' . $e($link) . '</a></p>'];
    }

    private static function wrap(Db $db, string $content): string
    {
        if (Settings::get($db, 'mail_branding_enabled', '1') !== '1') {
            return $content;
        }
        $base = rtrim((string)App::config('base_url', ''), '/');
        $logo = trim(Settings::get($db, 'mail_logo_url', $base . '/assets/logo-jc.png'));
        $accent = trim(Settings::get($db, 'mail_accent_color', '#c21819'));
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $accent)) {
            $accent = '#c21819';
        }
        $header = Settings::get($db, 'mail_header_html', '<div style="padding:24px 28px 18px;border-bottom:3px solid {accent};"><img src="{logo_url}" alt="Journée Circuit" style="display:block;max-width:220px;max-height:72px;width:auto;height:auto;border:0;"></div>');
        $footer = Settings::get($db, 'mail_footer_html', '<div style="padding:18px 28px;background:#161616;color:#fff;font-size:12px;line-height:1.5;"><strong>Journée Circuit</strong><br><span style="color:#d7d7d7;">À très vite sur circuit.</span></div>');
        $outer = Settings::get($db, 'mail_outer_html', '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head><body style="margin:0;padding:0;background:#f3f4f6;"><div style="max-width:680px;margin:0 auto;background:#ffffff;font-family:Arial,Helvetica,sans-serif;color:#111;font-size:15px;line-height:1.55;">{header}<div style="padding:24px 28px;">{content}</div>{footer}</div></body></html>');
        // Le contenu est inséré en dernier pour que d'éventuelles accolades saisies par un participant ne soient jamais interprétées.
        $page = self::replace($outer, ['header' => $header, 'footer' => $footer], false);
        return self::replace($page, ['logo_url' => $logo, 'accent' => $accent, 'content' => $content], false);
    }

    /** @return array{subject:string,body:string} */
    public static function render(Db $db, string $key, array $vars, int $circuitId = 0): array
    {
        $tpl = $db->row('SELECT subject,body_html FROM mail_templates WHERE template_key=? AND is_active=1', [$key]);
        [$defSubject, $defBody] = self::DEFAULTS[$key] ?? ['', ''];
        $subject = $tpl ? (string)$tpl['subject'] : $defSubject;
        $body = $tpl ? (string)$tpl['body_html'] : $defBody;
        $base = rtrim((string)App::config('base_url', ''), '/');
        $vars += self::briefing($db, $circuitId) + ['bloc_infos_evenement' => '', 'lien_infos_evenement' => $base, 'site_url' => $base, 'purchase_block' => '', 'ticket_message' => '', 'motif' => ''];
        return [
            'subject' => trim(str_replace(["\r", "\n"], ' ', self::replace($subject, $vars, false))),
            'body'    => self::wrap($db, self::replace($body, $vars, true)),
        ];
    }

    /** Variables communes d'un dossier : prénom, nom, événement, lien de suivi... */
    public static function participantVars(array $p, array $event, string $token): array
    {
        $circuit = (string)($event['circuit_name'] ?? 'Journée Circuit');
        $date = !empty($event['event_date']) ? date('d/m/Y', strtotime((string)$event['event_date'])) : '';
        $name = trim((string)($p['event_name'] ?? ''));
        if ($name === '') {
            $name = trim($circuit . ($date !== '' ? ' — ' . $date : ''));
        }
        $base = rtrim((string)(App::config('public_base_url') ?: App::config('base_url', '')), '/');
        return [
            'prenom'          => (string)($p['prenom'] ?? ''),
            'nom'             => (string)($p['nom'] ?? ''),
            'participant'     => trim((string)($p['prenom'] ?? '') . ' ' . (string)($p['nom'] ?? '')),
            'circuit'         => $circuit,
            'date_evenement'  => $date,
            'evenement'       => $name,
            'lien_suivi'      => $token !== '' ? $base . '/suivi/' . $token : $base . '/participer',
            'lien_dossier'    => $base . '/participer',
        ];
    }

    public static function log(Db $db, int $participantId, string $mailType, bool $ok, string $to, string $subject, string $source, ?int $adminId = null): void
    {
        $db->run(
            'INSERT INTO participant_mail_log(participant_id,admin_id,mail_type,status,recipient,subject,source,error_message,sent_at,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())',
            [$participantId, $adminId, $mailType, $ok ? 'sent' : 'error', $to, $subject, $source, $ok ? null : 'envoi impossible ou désactivé', $ok ? date('Y-m-d H:i:s') : null]
        );
    }
}
