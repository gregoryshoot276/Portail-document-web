<?php
declare(strict_types=1);

namespace JC\Core;

/**
 * Journal d'audit.
 * En mode écriture : table `audit_log` existante (visible dans l'ancien portail).
 * En lecture seule : fichier storage/logs/audit.log (aucune écriture en base).
 */
final class Audit
{
    public static function log(string $action, ?int $participantId = null, array $details = []): void
    {
        $admin = Auth::id();
        $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $db = App::db();
            if (!$db->isReadOnly()) {
                $db->run(
                    'INSERT INTO audit_log(admin_id,participant_id,action,details_json,ip_address,created_at) VALUES(?,?,?,?,?,NOW())',
                    [$admin, $participantId, $action, $json, Http::ip()]
                );
                return;
            }
        } catch (\Throwable $e) {
            Logger::error('audit', 'écriture base impossible : ' . $e->getMessage());
        }
        Logger::write('audit', 'info', sprintf('admin=%s participant=%s action=%s %s', (string)$admin, (string)$participantId, $action, (string)$json));
    }
}
