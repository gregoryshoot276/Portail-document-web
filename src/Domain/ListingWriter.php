<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Audit;
use JC\Core\Db;

/**
 * Toutes les écritures du listing / starter / RFID / tarifs.
 * En mode lecture seule, la classe Db refuse chaque requête d'écriture (ReadOnlyException).
 */
final class ListingWriter
{
    public const FIELDS = ['validator', 'display_name', 'vehicle', 'reference', 'duration', 'amount_due', 'discount', 'credit', 'notes', 'phone', 'email', 'first_time', 'insurance'];
    public const VALIDATORS = ['', 'G', 'R', 'E', 'C', 'J'];
    public const STARTER_STATUSES = ['pending', 'ok', 'nok', 'recheck'];
    public const PAY_METHODS = ['ESP', 'CB', 'VIR', 'AVR', 'DIV'];
    public const FINANCE_FIELDS = ['piste', 'assurance', 'rh', 'service', 'deplacement', 'autres', 'objectif'];

    public function __construct(private Db $db, private int $adminId, private string $adminLabel)
    {
    }

    private function entry(int $entryId, ?int $eventId = null): array
    {
        $e = $this->db->row('SELECT * FROM listing_entries WHERE id=? LIMIT 1', [$entryId]);
        if (!$e || ($eventId !== null && (int)$e['event_id'] !== $eventId)) {
            throw new \RuntimeException('Ligne introuvable pour cette journée.');
        }
        return $e;
    }

    /** Enregistre un champ modifié à la main (surcharge de la valeur Billetweb). */
    public function saveField(int $entryId, string $field, string $value): void
    {
        if (!in_array($field, self::FIELDS, true)) {
            throw new \RuntimeException('Champ invalide.');
        }
        $entry = $this->entry($entryId);
        $eventId = (int)$entry['event_id'];
        if ($field === 'duration') {
            $value = Formats::canonical($value);
        }
        if ($field === 'validator' && !in_array($value, self::VALIDATORS, true)) {
            throw new \RuntimeException('Valeur de validation invalide.');
        }
        if ($field === 'insurance') {
            $value = strtoupper(trim($value));
            if (!in_array($value, ['', 'O', 'R', 'V', 'X'], true)) {
                throw new \RuntimeException('Code assurance invalide.');
            }
        }
        if (in_array($field, ['amount_due', 'discount', 'credit'], true)) {
            $value = $value === '' ? '' : (string)Text::float($value);
        }
        $old = $this->db->val('SELECT field_value FROM listing_entry_overrides WHERE listing_entry_id=? AND field_name=?', [$entryId, $field]);

        // Format ou assurance modifiés : le prix théorique est recalculé, on retire donc l'ancien prix automatique.
        if (in_array($field, ['duration', 'insurance'], true)) {
            $this->db->run("DELETE FROM listing_entry_overrides WHERE listing_entry_id=? AND field_name='amount_due' AND updated_by IS NULL", [$entryId]);
        }
        $this->db->run(
            'INSERT INTO listing_entry_overrides(listing_entry_id,field_name,field_value,updated_by,updated_at) VALUES(?,?,?,?,NOW())
             ON DUPLICATE KEY UPDATE field_value=VALUES(field_value),updated_by=VALUES(updated_by),updated_at=NOW()',
            [$entryId, $field, $value, $this->adminId]
        );
        // Véhicule modifié : le starter doit le recontrôler.
        if ($field === 'vehicle') {
            $st = (string)$this->db->val('SELECT status FROM listing_starter_checks WHERE listing_entry_id=? AND event_id=?', [$entryId, $eventId]);
            $this->starterSet($eventId, $entryId, in_array($st, ['nok', 'recheck'], true) ? 'recheck' : 'pending', false);
        }
        Audit::log('v3.listing.save', (int)($entry['participant_id'] ?? 0) ?: null, ['entry' => $entryId, 'field' => $field, 'old' => $old, 'new' => $value]);
    }

    /** Personne à ne jamais relancer par e-mail (déjà contactée par téléphone, etc.) : exclut du bouton
     * individuel et de « Relancer incomplets », réversible. */
    public function setNoRemind(int $entryId, bool $value): void
    {
        $e = $this->entry($entryId);
        if ($value) {
            $this->db->run(
                'INSERT INTO listing_entry_overrides(listing_entry_id,field_name,field_value,updated_by,updated_at) VALUES(?,\'no_remind\',\'1\',?,NOW())
                 ON DUPLICATE KEY UPDATE field_value=VALUES(field_value),updated_by=VALUES(updated_by),updated_at=NOW()',
                [$entryId, $this->adminId]
            );
        } else {
            $this->db->run("DELETE FROM listing_entry_overrides WHERE listing_entry_id=? AND field_name='no_remind'", [$entryId]);
        }
        Audit::log('v3.listing.no_remind', (int)($e['participant_id'] ?? 0) ?: null, ['entry' => $entryId, 'value' => $value]);
    }

    public function waiverOverride(int $entryId, string $status): void
    {
        $status = in_array($status, ['', 'V', 'J', 'X'], true) ? $status : '';
        $e = $this->entry($entryId);
        $pid = (int)($e['participant_id'] ?? 0);
        if ($pid <= 0) {
            // Pas de dossier portail (ex. décharge reçue via l'ancien JotForm, ou signée sur papier) : rien à
            // rattacher dans listing_waiver_overrides (clé = participant), donc mémorisé sur la ligne elle-même.
            if ($status === '') {
                $this->db->run("DELETE FROM listing_entry_overrides WHERE listing_entry_id=? AND field_name='waiver_forced'", [$entryId]);
            } else {
                $this->db->run(
                    'INSERT INTO listing_entry_overrides(listing_entry_id,field_name,field_value,updated_by,updated_at) VALUES(?,\'waiver_forced\',?,?,NOW())
                     ON DUPLICATE KEY UPDATE field_value=VALUES(field_value),updated_by=VALUES(updated_by),updated_at=NOW()',
                    [$entryId, $status, $this->adminId]
                );
            }
            Audit::log('v3.listing.waiver_override', null, ['entry' => $entryId, 'status' => $status]);
            return;
        }
        if ($status === '') {
            $this->db->run('DELETE FROM listing_waiver_overrides WHERE participant_id=?', [$pid]);
        } else {
            $this->db->run(
                'INSERT INTO listing_waiver_overrides(participant_id,event_id,status,forced_by,forced_label,forced_at) VALUES(?,?,?,?,?,NOW())
                 ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),status=VALUES(status),forced_by=VALUES(forced_by),forced_label=VALUES(forced_label),forced_at=NOW()',
                [$pid, (int)$e['event_id'], $status, $this->adminId, $this->adminLabel]
            );
        }
        Audit::log('v3.listing.waiver_override', $pid, ['entry' => $entryId, 'status' => $status]);
    }

    // --- Paiements -----------------------------------------------------------------------------------

    public function addPayment(int $entryId, string $context, string $method, float $amount, string $note = ''): void
    {
        $context = in_array($context, ['advance', 'onsite'], true) ? $context : 'onsite';
        $method = mb_strtoupper(trim($method), 'UTF-8');
        if ($entryId <= 0 || $amount <= 0 || !in_array($method, self::PAY_METHODS, true)) {
            throw new \RuntimeException('Paiement invalide.');
        }
        $e = $this->entry($entryId);
        $this->db->run(
            'INSERT INTO listing_entry_payments(listing_entry_id,payment_context,payment_method,amount,note,created_by,created_at) VALUES(?,?,?,?,?,?,NOW())',
            [$entryId, $context, $method, round($amount, 2), mb_substr($note, 0, 255), $this->adminId]
        );
        Audit::log('v3.listing.payment_add', (int)($e['participant_id'] ?? 0) ?: null, compact('entryId', 'context', 'method', 'amount'));
    }

    /** Saisie directe d'un montant (remplace tous les paiements du même contexte). */
    public function replacePayment(int $entryId, string $context, string $method, float $amount): void
    {
        $method = mb_strtoupper(trim($method), 'UTF-8');
        if ($entryId <= 0 || !in_array($context, ['advance', 'onsite'], true) || !in_array($method, self::PAY_METHODS, true)) {
            throw new \RuntimeException('Paiement invalide.');
        }
        $e = $this->entry($entryId);
        $this->db->beginTransaction();
        try {
            $this->db->run('DELETE FROM listing_entry_payments WHERE listing_entry_id=? AND payment_context=?', [$entryId, $context]);
            if ($amount > 0.001) {
                $this->db->run(
                    'INSERT INTO listing_entry_payments(listing_entry_id,payment_context,payment_method,amount,note,created_by,created_at) VALUES(?,?,?,?,?,?,NOW())',
                    [$entryId, $context, $method, round($amount, 2), 'Saisie directe listing', $this->adminId]
                );
            }
            $this->db->commit();
        } catch (\Throwable $t) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $t;
        }
        Audit::log('v3.listing.payment_replace', (int)($e['participant_id'] ?? 0) ?: null, compact('entryId', 'context', 'method', 'amount'));
    }

    public function deletePayment(int $paymentId): void
    {
        $this->db->run('DELETE FROM listing_entry_payments WHERE id=?', [$paymentId]);
        Audit::log('v3.listing.payment_delete', null, ['payment' => $paymentId]);
    }

    // --- Lignes manuelles, masquage ------------------------------------------------------------------

    public function createRow(int $eventId, array $in): int
    {
        $name = trim((string)($in['display_name'] ?? ''));
        if ($eventId <= 0 || $name === '') {
            throw new \RuntimeException('Nom du participant obligatoire.');
        }
        $duration = Formats::canonical(trim((string)($in['duration'] ?? '')));
        $type = match ($duration) {
            'Passager' => 'passenger',
            'Pilote supplémentaire', 'Pilote supplémentaire femme' => 'supplemental_driver',
            default => 'pilot',
        };
        $tariffs = Pricing::forEvent($this->db, $eventId);
        $auto = Pricing::total($tariffs, $duration, (string)($in['insurance'] ?? ''), '');
        if (trim((string)($in['amount_due'] ?? '')) === '' && $auto !== null) {
            $in['amount_due'] = (string)$auto;
        }
        $onsite = Text::float($in['onsite_amount'] ?? 0);
        $method = mb_strtoupper(trim((string)($in['onsite_method'] ?? '')), 'UTF-8');
        if ($onsite > 0 && !in_array($method, self::PAY_METHODS, true)) {
            throw new \RuntimeException('Choisissez le mode du règlement sur place.');
        }

        $this->db->beginTransaction();
        try {
            $this->db->run("INSERT INTO listing_entries(event_id,source,participant_type,firstname,lastname,email,created_at,updated_at) VALUES(?,'manual',?,'',?,'',NOW(),NOW())", [$eventId, $type, $name]);
            $id = (int)$this->db->lastInsertId();
            $in['display_name'] = $name;
            $in['duration'] = $duration;
            foreach (self::FIELDS as $f) {
                $v = (string)($in[$f] ?? '');
                if ($v === '') {
                    continue;
                }
                $this->db->run('INSERT INTO listing_entry_overrides(listing_entry_id,field_name,field_value,updated_by,updated_at) VALUES(?,?,?,?,NOW())', [$id, $f, $v, $this->adminId]);
            }
            $this->db->run('INSERT INTO listing_entry_meta(listing_entry_id,created_by,created_label,created_at) VALUES(?,?,NULL,NOW()) ON DUPLICATE KEY UPDATE created_by=VALUES(created_by),created_label=NULL', [$id, $this->adminId]);
            if ($onsite > 0) {
                $this->db->run(
                    'INSERT INTO listing_entry_payments(listing_entry_id,payment_context,payment_method,amount,note,created_by,created_at) VALUES(?,\'onsite\',?,?,?,?,NOW())',
                    [$id, $method, round($onsite, 2), 'Ajout inscription sur place', $this->adminId]
                );
            }
            $this->db->commit();
        } catch (\Throwable $t) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $t;
        }
        Audit::log('v3.listing.create_row', null, ['entry' => $id, 'event' => $eventId, 'name' => $name]);
        return $id;
    }

    /** Ligne manuelle : supprimée. Ligne issue de Billetweb / du portail : masquée seulement. */
    public function hideOrDelete(int $eventId, int $entryId): void
    {
        $e = $this->entry($entryId, $eventId);
        if ((string)$e['source'] === 'manual') {
            $this->db->beginTransaction();
            try {
                foreach (['listing_entry_overrides', 'listing_entry_payments', 'listing_cell_locks', 'listing_rfid_tags', 'listing_starter_checks', 'listing_entry_meta', 'listing_communication_status'] as $t) {
                    $this->db->run("DELETE FROM $t WHERE listing_entry_id=?", [$entryId]);
                }
                $this->db->run('DELETE FROM listing_rfid_passages WHERE listing_entry_id=? AND event_id=?', [$entryId, $eventId]);
                $this->db->run("DELETE FROM listing_entries WHERE id=? AND event_id=? AND source='manual'", [$entryId, $eventId]);
                $this->db->commit();
            } catch (\Throwable $t) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $t;
            }
            Audit::log('v3.listing.delete_row', null, ['entry' => $entryId]);
            return;
        }
        $this->db->run(
            'INSERT INTO listing_entry_hidden(listing_entry_id,event_id,hidden_by,hidden_at) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE hidden_by=VALUES(hidden_by),hidden_at=NOW()',
            [$entryId, $eventId, $this->adminId]
        );
        Audit::log('v3.listing.hide_row', (int)($e['participant_id'] ?? 0) ?: null, ['entry' => $entryId]);
    }

    // --- Starter ------------------------------------------------------------------------------------

    public function starterSet(int $eventId, int $entryId, string $status, bool $attribute = true): void
    {
        if (!in_array($status, self::STARTER_STATUSES, true)) {
            $status = 'pending';
        }
        $this->db->run(
            "INSERT INTO listing_starter_checks(listing_entry_id,event_id,status,checked_by,checked_label,checked_at) VALUES(?,?,?,?,?,IF(? IN ('pending','recheck'),NULL,NOW()))
             ON DUPLICATE KEY UPDATE event_id=VALUES(event_id),status=VALUES(status),checked_by=VALUES(checked_by),checked_label=VALUES(checked_label),checked_at=IF(VALUES(status) IN ('pending','recheck'),NULL,NOW())",
            [$entryId, $eventId, $status, $attribute ? $this->adminId : null, $attribute ? $this->adminLabel : '', $status]
        );
        if ($attribute) {
            Audit::log('v3.starter.check', null, ['entry' => $entryId, 'status' => $status]);
        }
    }

    // --- RFID ---------------------------------------------------------------------------------------

    public static function rfidNormalize(string $uid): string
    {
        return strtoupper(preg_replace('/\s+/', '', trim($uid)) ?? trim($uid));
    }

    public function rfidSet(int $eventId, int $entryId, string $uid): void
    {
        $uid = self::rfidNormalize($uid);
        if ($eventId <= 0 || $entryId <= 0) {
            throw new \RuntimeException('Ligne RFID invalide.');
        }
        if ($uid === '') {
            $this->db->run('DELETE FROM listing_rfid_tags WHERE event_id=? AND listing_entry_id=?', [$eventId, $entryId]);
            return;
        }
        $this->entry($entryId, $eventId);
        $other = (int)$this->db->val('SELECT listing_entry_id FROM listing_rfid_tags WHERE event_id=? AND tag_uid=? LIMIT 1', [$eventId, $uid]);
        if ($other && $other !== $entryId) {
            throw new \RuntimeException('Cette puce RFID est déjà attribuée à une autre personne.');
        }
        $this->db->run(
            'INSERT INTO listing_rfid_tags(event_id,listing_entry_id,tag_uid,created_by,created_at,updated_at) VALUES(?,?,?,?,NOW(),NOW())
             ON DUPLICATE KEY UPDATE tag_uid=VALUES(tag_uid),created_by=VALUES(created_by),updated_at=NOW()',
            [$eventId, $entryId, $uid, $this->adminId]
        );
    }

    public function rfidLogTag(int $eventId, string $uid, string $source = 'scanner'): array
    {
        $uid = self::rfidNormalize($uid);
        if ($uid === '') {
            throw new \RuntimeException('Puce RFID vide.');
        }
        $entryId = (int)$this->db->val('SELECT listing_entry_id FROM listing_rfid_tags WHERE event_id=? AND tag_uid=? LIMIT 1', [$eventId, $uid]);
        if (!$entryId) {
            return ['ok' => false, 'unknown' => true, 'tag_uid' => $uid];
        }
        $this->db->run('INSERT INTO listing_rfid_passages(event_id,listing_entry_id,tag_uid,scanned_at,source,created_by) VALUES(?,?,?,NOW(),?,?)', [$eventId, $entryId, $uid, $source, $this->adminId]);
        return ['ok' => true, 'unknown' => false, 'entry_id' => $entryId, 'tag_uid' => $uid];
    }

    /** Bip enregistré à partir de la REF notée à la main. */
    public function rfidLogReference(int $eventId, string $reference, string $source = 'reference'): array
    {
        $ref = mb_strtoupper(trim($reference), 'UTF-8');
        if ($ref === '') {
            throw new \RuntimeException('REF vide.');
        }
        $ids = [];
        foreach ($this->db->all("SELECT e.id,o.field_value FROM listing_entries e JOIN listing_entry_overrides o ON o.listing_entry_id=e.id AND o.field_name='reference' WHERE e.event_id=?", [$eventId]) as $r) {
            if (mb_strtoupper(trim((string)$r['field_value']), 'UTF-8') === $ref) {
                $ids[] = (int)$r['id'];
            }
        }
        if (!$ids) {
            return ['ok' => false, 'unknown_ref' => true, 'reference' => $ref];
        }
        if (count($ids) > 1) {
            return ['ok' => false, 'duplicate_ref' => true, 'reference' => $ref, 'count' => count($ids)];
        }
        $entryId = $ids[0];
        $uid = (string)$this->db->val('SELECT tag_uid FROM listing_rfid_tags WHERE event_id=? AND listing_entry_id=? LIMIT 1', [$eventId, $entryId]);
        if ($uid === '') {
            return ['ok' => false, 'no_tag' => true, 'reference' => $ref, 'entry_id' => $entryId];
        }
        $this->db->run('INSERT INTO listing_rfid_passages(event_id,listing_entry_id,tag_uid,scanned_at,source,created_by) VALUES(?,?,?,NOW(),?,?)', [$eventId, $entryId, $uid, $source, $this->adminId]);
        return ['ok' => true, 'entry_id' => $entryId, 'tag_uid' => $uid, 'reference' => $ref];
    }

    // --- Tarifs et bilan ----------------------------------------------------------------------------

    public function tariffSet(int $eventId, string $code, ?float $amount): void
    {
        $code = Pricing::normalizeCode($code);
        if ($eventId <= 0 || !isset(Pricing::CODES[$code])) {
            throw new \RuntimeException('Code tarif inconnu.');
        }
        if ($amount === null) {
            $this->db->run('DELETE FROM listing_tariffs WHERE event_id=? AND code=?', [$eventId, $code]);
        } else {
            $this->db->run(
                'INSERT INTO listing_tariffs(event_id,code,amount,updated_by,updated_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE amount=VALUES(amount),updated_by=VALUES(updated_by),updated_at=NOW()',
                [$eventId, $code, $amount, $this->adminId]
            );
        }
        Audit::log('v3.tariff.set', null, ['event' => $eventId, 'code' => $code, 'amount' => $amount]);
    }

    public function financeSet(int $eventId, string $field, float $value): void
    {
        if ($eventId <= 0 || !in_array($field, self::FINANCE_FIELDS, true)) {
            throw new \RuntimeException('Champ de bilan invalide.');
        }
        // $field provient de la liste blanche ci-dessus.
        $this->db->run(
            "INSERT INTO listing_event_finance(event_id,$field,updated_by,updated_at) VALUES(?,?,?,NOW())
             ON DUPLICATE KEY UPDATE $field=VALUES($field),updated_by=VALUES(updated_by),updated_at=NOW()",
            [$eventId, round($value, 2), $this->adminId]
        );
        Audit::log('v3.finance.set', null, ['event' => $eventId, 'field' => $field, 'value' => $value]);
    }
}
