<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Audit;
use JC\Core\Http;
use JC\Core\Mode;
use JC\Core\ReadOnlyException;
use JC\Domain\Mailer;
use JC\Domain\MailTemplates;
use JC\Domain\Settings;

/** Mail Center : modèles d'e-mails participants, briefing en ligne par circuit, réglages d'envoi (PHP / SMTP / Brevo). */
final class MailCenterController extends BaseController
{
    private const LABELS = [
        'pilot_received'            => 'Dossier reçu (pilote)',
        'passenger_confirmation'    => 'Confirmation passager',
        'pilot_validated'           => 'Dossier validé',
        'pilot_validated_with_note' => 'Dossier validé (avec message)',
        'document_validated_note'  => 'Document validé (avec message)',
        'document_rejected'         => 'Document refusé',
        'participant_reminder'      => 'Relance dossier incomplet',
        'duplicate_file'            => 'Dossier déjà existant',
        'review_reward'             => 'Avis récompensé (code promo)',
        'review_rejected'           => 'Avis à corriger',
        'replacement_registered'    => 'Inscription (remplacement)',
    ];

    public function index(): void
    {
        $db = $this->db();
        $rows = [];
        foreach (self::LABELS as $key => $label) {
            $tpl = $db->row('SELECT subject,updated_at FROM mail_templates WHERE template_key=?', [$key]);
            $rows[] = [
                'key'        => $key,
                'label'      => $label,
                'customized' => (bool)$tpl,
                'subject'    => $tpl['subject'] ?? MailTemplates::DEFAULTS[$key][0],
                'updated_at' => $tpl['updated_at'] ?? null,
            ];
        }
        $circuits = $db->all('SELECT id,name FROM circuits WHERE is_active=1 ORDER BY name');
        $briefings = [];
        foreach ($db->all('SELECT * FROM communication_circuit_config') as $r) {
            $briefings[(int)$r['circuit_id']] = $r;
        }
        $this->view('mailcenter/index', [
            'title'       => 'Mail Center',
            'rows'        => $rows,
            'circuits'    => $circuits,
            'briefings'   => $briefings,
            'transport'   => Settings::get($db, 'mail_transport', 'php'),
            'mailEnabled' => Mailer::enabled($db),
            'page'        => 'mailcenter',
        ]);
    }

    public function edit(string $key): void
    {
        if (!isset(self::LABELS[$key])) {
            Http::abort(404, 'Modèle inconnu.');
        }
        $db = $this->db();
        $tpl = $db->row('SELECT * FROM mail_templates WHERE template_key=?', [$key]);
        [$defSubject, $defBody] = MailTemplates::DEFAULTS[$key];
        $vars = MailTemplates::participantVars(
            ['prenom' => 'Camille', 'nom' => 'Renard'],
            ['circuit_name' => 'Magny-Cours', 'event_date' => date('Y-m-d', strtotime('+15 days'))],
            str_repeat('0', 48)
        ) + [
            'motif' => 'Exemple de message de l’organisation.',
            'montant' => 10, 'code_promo' => 'JCAB12CD', 'plateforme' => 'Google',
            'lien_correction' => 'https://example.test/avis.php?t=exemple',
        ];
        $preview = MailTemplates::render($db, $key, $vars, 1);
        $this->view('mailcenter/edit', [
            'title'      => self::LABELS[$key],
            'key'        => $key,
            'label'      => self::LABELS[$key],
            'subject'    => $tpl['subject'] ?? $defSubject,
            'body'       => $tpl['body_html'] ?? $defBody,
            'customized' => (bool)$tpl,
            'preview'    => $preview,
            'page'       => 'mailcenter',
        ]);
    }

    public function save(string $key): void
    {
        if (!isset(self::LABELS[$key])) {
            Http::abort(404, 'Modèle inconnu.');
        }
        $subject = Http::str('subject');
        $body = Http::str('body');
        if ($subject === '' || $body === '') {
            flash('warn', 'Objet et corps du message obligatoires.');
            Http::redirect('/mailcenter/' . $key);
        }
        try {
            Mode::assertWritable();
            $this->db()->run(
                'INSERT INTO mail_templates(template_key,subject,body_html,is_active,updated_at) VALUES(?,?,?,1,NOW())
                 ON DUPLICATE KEY UPDATE subject=VALUES(subject),body_html=VALUES(body_html),is_active=1,updated_at=NOW()',
                [$key, $subject, $body]
            );
            Audit::log('mailcenter.save', null, ['template_key' => $key]);
            flash('ok', 'Modèle enregistré.');
        } catch (ReadOnlyException $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/mailcenter/' . $key);
    }

    public function reset(string $key): void
    {
        if (!isset(self::LABELS[$key])) {
            Http::abort(404, 'Modèle inconnu.');
        }
        try {
            Mode::assertWritable();
            $this->db()->run('DELETE FROM mail_templates WHERE template_key=?', [$key]);
            Audit::log('mailcenter.reset', null, ['template_key' => $key]);
            flash('ok', 'Modèle réinitialisé au texte par défaut.');
        } catch (ReadOnlyException $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/mailcenter/' . $key);
    }

    public function briefingSave(int $circuitId): void
    {
        $url = Http::str('briefing_url');
        if ($url !== '' && !preg_match('#^https?://#i', $url)) {
            flash('warn', 'Le lien de briefing doit commencer par http:// ou https://.');
            Http::redirect('/mailcenter');
        }
        try {
            Mode::assertWritable();
            $this->db()->run(
                'INSERT INTO communication_circuit_config(circuit_id,briefing_url,briefing_text,briefing_link_text,updated_at) VALUES(?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE briefing_url=VALUES(briefing_url),briefing_text=VALUES(briefing_text),briefing_link_text=VALUES(briefing_link_text),updated_at=NOW()',
                [$circuitId, $url, Http::str('briefing_text'), Http::str('briefing_link_text')]
            );
            Audit::log('mailcenter.briefing', null, ['circuit_id' => $circuitId]);
            flash('ok', 'Briefing enregistré.');
        } catch (ReadOnlyException $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/mailcenter');
    }

    public function transportSave(): void
    {
        $transport = in_array(Http::str('mail_transport'), ['php', 'smtp', 'brevo'], true) ? Http::str('mail_transport') : 'php';
        try {
            Mode::assertWritable();
            $db = $this->db();
            Settings::set($db, 'mail_transport', $transport);
            $key = Http::str('brevo_api_key');
            if ($key !== '') {
                Settings::set($db, 'brevo_api_key', $key);
            }
            Settings::set($db, 'brevo_sender_email', Http::str('brevo_sender_email'));
            Settings::set($db, 'brevo_sender_name', Http::str('brevo_sender_name'));
            Audit::log('mailcenter.transport', null, ['transport' => $transport]);
            flash('ok', 'Réglages d’envoi enregistrés.');
        } catch (ReadOnlyException $e) {
            flash('warn', $e->getMessage());
        }
        Http::redirect('/mailcenter');
    }
}
