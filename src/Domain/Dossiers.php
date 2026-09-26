<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Audit;
use JC\Core\Db;

/** Dossiers participants : liste, détail, décisions sur les documents (permis / assurance). */
final class Dossiers
{
    public const STATUS_LABELS = [
        'pending'   => 'En attente',
        'to_review' => 'À vérifier',
        'validated' => 'Validé',
        'rejected'  => 'Refusé',
    ];

    public const TYPE_LABELS = [
        'pilot'               => 'Pilote',
        'supplemental_driver' => 'Pilote suppl.',
        'passenger'           => 'Passager',
    ];

    public static function list(Db $db, array $f): array
    {
        $where = [];
        $p = [];
        $q = trim((string)($f['q'] ?? ''));
        if ($q !== '') {
            $where[] = '(p.nom LIKE ? OR p.prenom LIKE ? OR p.email LIKE ? OR p.event_name LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($p, $like, $like, $like, $like);
        }
        if (($f['status'] ?? '') !== '' && isset(self::STATUS_LABELS[$f['status']])) {
            $where[] = 'p.global_status=?';
            $p[] = $f['status'];
        }
        if (!empty($f['circuit'])) {
            $where[] = 'e.circuit_id=?';
            $p[] = (int)$f['circuit'];
        }
        if (!empty($f['event'])) {
            $where[] = 'p.event_id=?';
            $p[] = (int)$f['event'];
        }
        if (!empty($f['reviewer'])) {
            $where[] = 'EXISTS(SELECT 1 FROM document_validations dv JOIN documents dd ON dd.id=dv.document_id WHERE dd.participant_id=p.id AND dv.admin_id=?)';
            $p[] = (int)$f['reviewer'];
        }
        $type = (string)($f['type'] ?? 'pilot');
        if ($type === 'passenger') {
            $where[] = "p.participant_type='passenger'";
        } elseif ($type === 'pilot') {
            $where[] = "p.participant_type IN ('pilot','supplemental_driver')";
        }
        if (in_array($f['ticket'] ?? '', ['found', 'unassigned', 'not_found', 'unknown'], true)) {
            $where[] = 'p.ticket_status=?';
            $p[] = $f['ticket'];
        }
        $where[] = !empty($f['archived']) ? 'p.archived_at IS NOT NULL' : 'p.archived_at IS NULL';
        $order = ($f['sort'] ?? 'oldest') === 'newest' ? 'DESC' : 'ASC';

        $sql = "SELECT p.id,p.event_id,p.participant_type,p.nom,p.prenom,p.email,p.telephone,p.vehicle,p.registration,p.global_status,
                       p.ticket_status,p.insurance_mode,p.created_at,p.event_name,e.event_date,e.circuit_id,c.name circuit_name,
                       (SELECT d.status FROM documents d WHERE d.participant_id=p.id AND d.document_type='permis' ORDER BY d.id DESC LIMIT 1) permis_status,
                       (SELECT d.status FROM documents d WHERE d.participant_id=p.id AND d.document_type='assurance' ORDER BY d.id DESC LIMIT 1) assurance_status,
                       (SELECT MAX(d.created_at) FROM documents d WHERE d.participant_id=p.id) latest_document_at,
                       (SELECT MAX(dv.created_at) FROM document_validations dv JOIN documents d2 ON d2.id=dv.document_id WHERE d2.participant_id=p.id AND dv.decision='rejected') latest_rejected_at,
                       EXISTS(SELECT 1 FROM waivers w WHERE w.participant_id=p.id) has_waiver
                FROM participants p
                LEFT JOIN events e ON e.id=p.event_id
                LEFT JOIN circuits c ON c.id=e.circuit_id
                WHERE " . implode(' AND ', $where) . " ORDER BY p.created_at $order, p.id $order LIMIT 1000";
        $rows = $db->all($sql, $p);
        foreach ($rows as &$r) {
            $r['updated_after_reject'] = !empty($r['latest_document_at']) && !empty($r['latest_rejected_at'])
                && strtotime((string)$r['latest_document_at']) > strtotime((string)$r['latest_rejected_at']);
        }
        unset($r);
        // Les dossiers à traiter d'abord, les validés ensuite.
        $todo = array_values(array_filter($rows, fn($r) => $r['global_status'] !== 'validated'));
        $done = array_values(array_filter($rows, fn($r) => $r['global_status'] === 'validated'));
        return ['todo' => $todo, 'done' => $done];
    }

    /** Cases "Assurance" du Listing figées sur une ancienne valeur (V/X/...) alors que le document réel est
     * encore en attente de vérification — séquelles d'un bug corrigé (une décision ou un renvoi de document
     * n'effaçait pas la valeur forcée). Rétroactif : à lancer une fois pour nettoyer l'historique. */
    public static function staleInsuranceOverrides(Db $db): array
    {
        return $db->all(
            "SELECT o.id override_id, p.id participant_id, p.nom, p.prenom, e.event_date, c.name circuit_name, o.field_value override_value
             FROM listing_entry_overrides o
             JOIN listing_entries le ON le.id=o.listing_entry_id
             JOIN participants p ON p.id=le.participant_id
             JOIN events e ON e.id=le.event_id
             JOIN circuits c ON c.id=e.circuit_id
             WHERE o.field_name='insurance'
               AND (SELECT d.status FROM documents d WHERE d.participant_id=p.id AND d.document_type='assurance' ORDER BY d.id DESC LIMIT 1) IN ('pending','to_review')
             ORDER BY e.event_date DESC"
        );
    }

    /** @return int nombre de cases débloquées */
    public static function clearStaleInsuranceOverrides(Db $db): int
    {
        return $db->run(
            "DELETE o FROM listing_entry_overrides o
             JOIN listing_entries le ON le.id=o.listing_entry_id
             JOIN participants p ON p.id=le.participant_id
             WHERE o.field_name='insurance'
               AND (SELECT d.status FROM documents d WHERE d.participant_id=p.id AND d.document_type='assurance' ORDER BY d.id DESC LIMIT 1) IN ('pending','to_review')"
        );
    }

    public static function find(Db $db, int $id): ?array
    {
        return $db->row(
            'SELECT p.*,e.event_date,e.circuit_id,c.name circuit_name,c.slug circuit_slug
             FROM participants p LEFT JOIN events e ON e.id=p.event_id LEFT JOIN circuits c ON c.id=e.circuit_id WHERE p.id=?',
            [$id]
        );
    }

    /** Documents du dossier, chacun avec son historique de décisions. */
    public static function documents(Db $db, int $participantId): array
    {
        $docs = $db->all('SELECT * FROM documents WHERE participant_id=? ORDER BY document_type, id DESC', [$participantId]);
        if (!$docs) {
            return [];
        }
        $ids = array_map(fn($d) => (int)$d['id'], $docs);
        $hist = [];
        foreach ($db->all(
            'SELECT v.*,a.display_name admin_name FROM document_validations v LEFT JOIN admins a ON a.id=v.admin_id WHERE v.document_id IN (' . Db::marks(count($ids)) . ') ORDER BY v.id DESC',
            $ids
        ) as $v) {
            $hist[(int)$v['document_id']][] = $v;
        }
        foreach ($docs as &$d) {
            $d['history'] = $hist[(int)$d['id']] ?? [];
        }
        unset($d);
        return $docs;
    }

    public static function waiver(Db $db, int $participantId): ?array
    {
        return $db->row('SELECT w.*,t.name template_name,t.version_label FROM waivers w LEFT JOIN waiver_templates t ON t.id=w.template_id WHERE w.participant_id=?', [$participantId]);
    }

    public static function timeline(Db $db, int $participantId): array
    {
        return $db->all(
            'SELECT l.*,a.display_name admin_name FROM audit_log l LEFT JOIN admins a ON a.id=l.admin_id WHERE l.participant_id=? ORDER BY l.id DESC LIMIT 60',
            [$participantId]
        );
    }

    public static function mails(Db $db, int $participantId): array
    {
        return $db->all('SELECT * FROM participant_mail_log WHERE participant_id=? ORDER BY id DESC LIMIT 30', [$participantId]);
    }

    /** Statut global du dossier d'après les statuts de ses documents (règle de l'ancien portail). */
    public static function globalStatus(array $statuses): string
    {
        if (in_array('rejected', $statuses, true)) {
            return 'rejected';
        }
        if (in_array('to_review', $statuses, true)) {
            return 'to_review';
        }
        if ($statuses && count(array_unique($statuses)) === 1 && $statuses[0] === 'validated') {
            return 'validated';
        }
        return 'pending';
    }

    /**
     * Décision sur un ou plusieurs documents. Refus : motif obligatoire ; décision groupée : validation uniquement.
     * @param int[] $documentIds
     */
    public static function decide(Db $db, int $adminId, int $participantId, array $documentIds, string $decision, string $reason): string
    {
        if ($db->isReadOnly()) {
            throw new \JC\Core\ReadOnlyException();
        }
        if (!in_array($decision, ['validated', 'rejected', 'to_review'], true)) {
            throw new \RuntimeException('Décision invalide.');
        }
        $documentIds = array_values(array_unique(array_filter(array_map('intval', $documentIds), fn($v) => $v > 0)));
        if (!$documentIds) {
            throw new \RuntimeException('Aucun document sélectionné.');
        }
        if (count($documentIds) > 1 && $decision !== 'validated') {
            throw new \RuntimeException('La décision groupée est réservée à la validation.');
        }
        $reason = trim($reason);
        if ($decision === 'rejected' && $reason === '') {
            throw new \RuntimeException('Un motif est obligatoire pour refuser.');
        }
        $db->beginTransaction();
        try {
            $db->val('SELECT global_status FROM participants WHERE id=? FOR UPDATE', [$participantId]);
            foreach ($documentIds as $docId) {
                $doc = $db->row('SELECT id,document_type FROM documents WHERE id=? AND participant_id=? FOR UPDATE', [$docId, $participantId]);
                if (!$doc) {
                    throw new \RuntimeException('Document introuvable : ' . $docId);
                }
                $db->run('UPDATE documents SET status=?,rejection_reason=?,updated_at=NOW() WHERE id=?', [$decision, $decision === 'rejected' ? $reason : null, $docId]);
                $db->run("INSERT INTO document_validations(document_id,admin_id,decision,reason,source,confidence,created_at) VALUES(?,?,?,?,'human',NULL,NOW())", [$docId, $adminId, $decision, $reason !== '' ? $reason : null]);
                if ((string)$doc['document_type'] === 'assurance') {
                    // La décision admin sur le vrai document prime : on efface une éventuelle validation manuelle
                    // laissée sur le Listing par un ancien fichier, sous peine d'y afficher un statut périmé
                    // (ex. « validée » alors que le document actuel vient d'être refusé).
                    $db->run("DELETE FROM listing_entry_overrides WHERE field_name='insurance' AND listing_entry_id IN (SELECT id FROM listing_entries WHERE participant_id=?)", [$participantId]);
                }
                Audit::log('document_decision', $participantId, ['document_id' => $docId, 'decision' => $decision, 'reason' => $reason, 'bulk' => count($documentIds) > 1, 'via' => 'v3']);
            }
            $global = self::globalStatus(array_map('strval', $db->col('SELECT status FROM documents WHERE participant_id=?', [$participantId])));
            $db->run('UPDATE participants SET global_status=?,updated_at=NOW() WHERE id=?', [$global, $participantId]);
            $db->commit();
            return $global;
        } catch (\Throwable $t) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $t;
        }
    }
}
