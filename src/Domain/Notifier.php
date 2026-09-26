<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;
use JC\Core\Logger;

/** E-mails envoyés aux participants (reçu, validé, refusé, relance...), avec journal dans participant_mail_log. */
final class Notifier
{
    /** Envoie un modèle à un participant. Retourne false si l'envoi échoue ou est désactivé (sans jamais lever d'erreur métier). */
    public static function send(Db $db, int $participantId, string $templateKey, string $mailType, array $extra = [], string $source = 'v3', ?int $adminId = null): bool
    {
        $p = $db->row('SELECT * FROM participants WHERE id=? AND archived_at IS NULL', [$participantId]);
        if (!$p) {
            return false;
        }
        $event = Events::find($db, (int)$p['event_id']) ?? [];
        $vars = MailTemplates::participantVars($p, $event, (string)$p['public_token']) + $extra;
        $mail = MailTemplates::render($db, $templateKey, $vars, (int)($event['circuit_id'] ?? 0));
        $to = (string)$p['email'];
        try {
            $ok = Mailer::send($db, $to, $mail['subject'], $mail['body']);
        } catch (\JC\Core\ReadOnlyException $e) {
            throw $e;
        } catch (\Throwable $t) {
            Logger::error('mail', $t->getMessage());
            $ok = false;
        }
        MailTemplates::log($db, $participantId, $mailType, $ok, $to, $mail['subject'], $source, $adminId);
        return $ok;
    }

    public static function received(Db $db, int $pid): bool
    {
        return self::send($db, $pid, 'pilot_received', 'pilot_received', [], 'participant');
    }

    public static function validatedFinal(Db $db, int $pid): bool
    {
        return self::send($db, $pid, 'pilot_validated', 'validated_confirmation', [], 'billetweb');
    }

    public static function reminder(Db $db, int $pid, ?int $adminId): bool
    {
        return self::send($db, $pid, 'participant_reminder', 'reminder', [], 'listing', $adminId);
    }

    /**
     * Relance d'une inscription du listing. Avec dossier : lien de suivi. Sans dossier : lien vers le formulaire de la journée.
     * @throws \RuntimeException adresse absente ou invalide, ou envoi impossible
     */
    public static function reminderForEntry(Db $db, array $row, array $event, ?int $adminId): string
    {
        $to = trim((string)$row['email']);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Adresse e-mail absente ou invalide pour cette inscription.');
        }
        if ((int)$row['participant_id'] > 0) {
            if (!self::reminder($db, (int)$row['participant_id'], $adminId)) {
                throw new \RuntimeException('Le mail n’a pas pu être envoyé (envoi désactivé ou refusé).');
            }
            return $to;
        }
        $parts = preg_split('/\s+/', trim((string)$row['name'])) ?: [];
        $nom = (string)array_shift($parts);
        $prenom = trim(implode(' ', $parts));
        $base = rtrim((string)(\JC\Core\App::config('public_base_url') ?: \JC\Core\App::config('base_url', '')), '/');
        $vars = MailTemplates::participantVars(['prenom' => $prenom ?: $nom, 'nom' => $prenom ? $nom : '', 'event_name' => ''], $event, '');
        $vars['lien_suivi'] = $base . '/participer';
        $mail = MailTemplates::render($db, 'participant_reminder', $vars, (int)($event['circuit_id'] ?? 0));
        if (!Mailer::send($db, $to, $mail['subject'], $mail['body'])) {
            throw new \RuntimeException('Le mail n’a pas pu être envoyé (envoi désactivé ou refusé).');
        }
        return $to;
    }

    /** Suite d'une décision d'un administrateur sur un document. */
    public static function afterDecision(Db $db, int $pid, string $decision, string $reason, bool $notify, string $global, string $previousGlobal, ?int $adminId): void
    {
        $extra = ['motif' => $reason];
        if ($decision === 'rejected') {
            self::send($db, $pid, 'document_rejected', 'document_rejected', $extra, 'admin', $adminId);
        } elseif ($notify) {
            $final = $global === 'validated' && $previousGlobal !== 'validated';
            self::send($db, $pid, $final ? 'pilot_validated_with_note' : 'document_validated_note', $final ? 'validated_confirmation' : 'document_validated_note', $extra, 'admin', $adminId);
        } elseif ($global === 'validated' && $previousGlobal !== 'validated') {
            self::send($db, $pid, 'pilot_validated', 'validated_confirmation', $extra, 'admin', $adminId);
        }
    }
}
