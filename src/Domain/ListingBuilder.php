<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/**
 * Construit les lignes du listing d'une journée, SANS aucune écriture.
 * Reprend les règles de l'ancien listing (V2.3.1) mais charge toutes les données en quelques requêtes
 * au lieu d'une quinzaine de requêtes par ligne.
 */
final class ListingBuilder
{
    private Db $db;
    private array $event;
    private int $eventId;

    // données préchargées
    private array $participants = [];       // id => row
    private array $overrides = [];          // entryId => [field => value]
    private array $overrideMeta = [];       // entryId => [field => updated_by]
    private array $payments = [];           // entryId => [advance=>float,onsite=>float,rows=>[]]
    private array $attById = [];            // attendee_id => row (billetweb_attendees, dernière ligne)
    private array $postById = [];           // attendee_id => row (billetweb_post_attendees)
    private array $ordersAtt = [];          // order_id => [rows payées et actives]
    private array $postInsuranceByEmail = [];// email => [rows ASSURANCE payées pour la date]
    private array $docStatuses = [];        // participantId => type => [statuts]
    private array $waivers = [];            // participantId => true
    private array $waiverOverrides = [];    // participantId => row
    private array $starter = [];
    private array $comm = [];
    private array $rfid = [];
    private array $rfidStats = [];
    private array $meta = [];
    private array $tariffs = [];
    private array $insTicketCache = [];

    public function __construct(Db $db, array $event)
    {
        $this->db = $db;
        $this->event = $event;
        $this->eventId = (int)$event['id'];
    }

    /** @return array<int,array> lignes prêtes à afficher */
    public function build(array $onlyIds = []): array
    {
        $params = [$this->eventId];
        $filter = '';
        if ($onlyIds) {
            $filter = ' AND e.id IN (' . Db::marks(count($onlyIds)) . ')';
            array_push($params, ...array_map('intval', $onlyIds));
        }
        $entries = $this->db->all(
            'SELECT e.* FROM listing_entries e
             LEFT JOIN listing_entry_hidden h ON h.listing_entry_id=e.id AND h.event_id=e.event_id
             WHERE e.event_id=? AND h.listing_entry_id IS NULL' . $filter . ' ORDER BY e.lastname,e.firstname,e.id',
            $params
        );
        $this->preload($entries);
        $rows = [];
        foreach ($entries as $entry) {
            $rows[] = $this->row($entry);
        }
        return $rows;
    }

    public function tariffs(): array
    {
        return $this->tariffs;
    }

    // ------------------------------------------------------------------------------------------------

    private function preload(array $entries): void
    {
        $db = $this->db;
        $eid = $this->eventId;
        $ids = array_map(fn($e) => (int)$e['id'], $entries);

        $this->tariffs = Pricing::forEvent($db, $eid);

        // participants du dossier
        $pids = array_values(array_unique(array_filter(array_map(fn($e) => (int)($e['participant_id'] ?? 0), $entries))));
        foreach (array_chunk($pids, 400) as $chunk) {
            foreach ($db->all('SELECT * FROM participants WHERE archived_at IS NULL AND id IN (' . Db::marks(count($chunk)) . ')', $chunk) as $p) {
                $this->participants[(int)$p['id']] = $p;
            }
        }

        foreach (array_chunk($ids, 400) as $chunk) {
            $in = Db::marks(count($chunk));
            foreach ($db->all("SELECT listing_entry_id,field_name,field_value,updated_by FROM listing_entry_overrides WHERE listing_entry_id IN ($in)", $chunk) as $r) {
                $this->overrides[(int)$r['listing_entry_id']][(string)$r['field_name']] = $r['field_value'];
                $this->overrideMeta[(int)$r['listing_entry_id']][(string)$r['field_name']] = $r['updated_by'];
            }
            foreach ($db->all("SELECT * FROM listing_entry_payments WHERE listing_entry_id IN ($in) ORDER BY id", $chunk) as $r) {
                $k = (int)$r['listing_entry_id'];
                $this->payments[$k] ??= ['advance' => 0.0, 'onsite' => 0.0, 'rows' => []];
                $this->payments[$k][(string)$r['payment_context']] += (float)$r['amount'];
                $this->payments[$k]['rows'][] = $r;
            }
        }

        foreach ($db->all('SELECT listing_entry_id,status,checked_by,checked_label,checked_at FROM listing_starter_checks WHERE event_id=?', [$eid]) as $r) {
            $this->starter[(int)$r['listing_entry_id']] = $r;
        }
        foreach ($db->all('SELECT listing_entry_id,status,delivered_at,opened_at,clicked_at FROM listing_communication_status WHERE event_id=?', [$eid]) as $r) {
            $this->comm[(int)$r['listing_entry_id']] = $r;
        }
        foreach ($db->all('SELECT listing_entry_id,tag_uid FROM listing_rfid_tags WHERE event_id=?', [$eid]) as $r) {
            $this->rfid[(int)$r['listing_entry_id']] = (string)$r['tag_uid'];
        }
        foreach ($db->all('SELECT listing_entry_id,COUNT(*) passages,MIN(scanned_at) first_scan,MAX(scanned_at) last_scan FROM listing_rfid_passages WHERE event_id=? GROUP BY listing_entry_id', [$eid]) as $r) {
            $this->rfidStats[(int)$r['listing_entry_id']] = $r;
        }
        foreach ($db->all('SELECT m.listing_entry_id,m.created_by,m.created_label,m.created_at FROM listing_entry_meta m JOIN listing_entries e ON e.id=m.listing_entry_id WHERE e.event_id=?', [$eid]) as $r) {
            $this->meta[(int)$r['listing_entry_id']] = $r;
        }

        // billets Billetweb : uniquement ceux référencés par la liste (par attendee_id)
        $aids = array_values(array_unique(array_filter(array_map(fn($e) => trim((string)($e['billetweb_attendee_id'] ?? '')), $entries), fn($v) => $v !== '')));
        foreach (array_chunk($aids, 400) as $chunk) {
            $in = Db::marks(count($chunk));
            foreach ($db->all("SELECT * FROM billetweb_attendees WHERE attendee_id IN ($in) ORDER BY id", $chunk) as $r) {
                $this->attById[(string)$r['attendee_id']] = Bw::prepare($r); // la plus récente écrase
            }
            foreach ($db->all("SELECT * FROM billetweb_post_attendees WHERE attendee_id IN ($in) ORDER BY id", $chunk) as $r) {
                $this->postById[(string)$r['attendee_id']] = Bw::prepare($r);
            }
        }

        // toutes les lignes payées et actives de la journée, groupées par commande (assurance déjà incluse, pilote lié...)
        foreach ($db->all('SELECT * FROM billetweb_attendees WHERE event_id=? AND disabled=0 AND order_paid=1 ORDER BY id', [$eid]) as $r) {
            $oid = trim((string)($r['order_id'] ?? ''));
            if ($oid !== '') {
                $this->ordersAtt[$oid][] = Bw::prepare($r);
            }
        }
        $date = (string)$this->event['event_date'];
        foreach ($db->all("SELECT * FROM billetweb_post_attendees WHERE disabled=0 AND order_paid=1 AND post_code='ASSURANCE' AND (session_date=? OR DATE(session_start)=?)", [$date, $date]) as $r) {
            $this->postInsuranceByEmail[strtolower(trim((string)$r['email']))][] = Bw::prepare($r);
        }

        // documents et décharges
        foreach (array_chunk($pids, 400) as $chunk) {
            $in = Db::marks(count($chunk));
            foreach ($db->all("SELECT participant_id,document_type,status FROM documents WHERE participant_id IN ($in) ORDER BY id DESC", $chunk) as $r) {
                $this->docStatuses[(int)$r['participant_id']][(string)$r['document_type']][] = (string)$r['status'];
            }
            foreach ($db->all("SELECT participant_id FROM waivers WHERE participant_id IN ($in)", $chunk) as $r) {
                $this->waivers[(int)$r['participant_id']] = true;
            }
            foreach ($db->all("SELECT * FROM listing_waiver_overrides WHERE participant_id IN ($in)", $chunk) as $r) {
                $this->waiverOverrides[(int)$r['participant_id']] = $r;
            }
        }
    }

    // ------------------------------------------------------------------------------------------------

    private function row(array $entry): array
    {
        $eid = (int)$entry['id'];
        $participant = !empty($entry['participant_id']) ? ($this->participants[(int)$entry['participant_id']] ?? null) : null;
        $ov = $this->overrides[$eid] ?? [];
        $type = (string)$entry['participant_type'];
        $src = $this->entrySource($entry, $participant);
        $att = $src['att'];

        $postCode = mb_strtoupper(trim((string)($att['post_code'] ?? '')));
        $optionCode = Formats::optionCode($att, (string)$src['duration']);
        $isOptionRow = in_array($optionCode, Formats::OPTION_CODES, true);

        $sourceName = $participant
            ? trim((string)$participant['nom'] . ' ' . (string)$participant['prenom'])
            : trim((string)$entry['lastname'] . ' ' . (string)$entry['firstname']);
        if ($isOptionRow) {
            $id = Bw::postIdentity($att, $optionCode);
            if ($id['first'] !== '' && $id['last'] !== '') {
                $sourceName = trim($id['last'] . ' ' . $id['first']);
            }
        }
        $name = (string)self::v($ov, 'display_name', $sourceName);
        // Le véhicule que le participant a lui-même corrigé à l'inscription prime sur celui déclaré chez Billetweb
        // (plus récent, et c'est celui qui doit correspondre à l'assurance fournie).
        $declaredVehicle = trim((string)($participant['vehicle'] ?? ''));
        $sourceVehicle = $declaredVehicle !== '' ? $declaredVehicle : $src['vehicle'];
        $vehicle = (string)self::v($ov, 'vehicle', in_array($type, ['passenger', 'supplemental_driver'], true) ? $src['linked_pilot'] : $sourceVehicle);
        // V = le participant a confirmé (ou n'a pas touché) le véhicule indiqué à l'inscription ; X = il l'a changé
        // lui-même (autorisé : ce n'est que l'assurance fournie qui doit correspondre au véhicule réellement utilisé).
        $vehicleStatus = '';
        if ($type === 'pilot' && $declaredVehicle !== '' && $src['vehicle'] !== '' && !array_key_exists('vehicle', $ov)) {
            $vehicleStatus = Text::normalize($declaredVehicle) === Text::normalize($src['vehicle']) ? 'V' : 'X';
        }

        $sourceDuration = (string)$src['duration'];
        if ($isOptionRow && !array_key_exists('duration', $ov)) {
            $sourceDuration = Formats::postFormat($optionCode, $sourceDuration);
        } elseif ($sourceDuration === '' && !array_key_exists('duration', $ov) && in_array($type, ['passenger', 'supplemental_driver'], true)) {
            // Décharge déposée sans billet Billetweb retrouvé : on propose le format déclaré par le participant
            // lui-même dans son dossier (passager ou pilote supplémentaire), modifiable à la main dans la colonne
            // Format — par exemple pour passer en variante « femme » (PSF), que rien ne permet de déduire seul.
            $sourceDuration = $type === 'passenger' ? 'Passager' : 'Pilote supplémentaire';
        }
        $duration = Formats::canonical((string)self::v($ov, 'duration', $sourceDuration));
        if ($duration === 'Pilote supplémentaire' && Formats::canonical((string)$src['duration']) === 'Pilote supplémentaire femme') {
            $duration = 'Pilote supplémentaire femme';
        }
        if (!$att && !array_key_exists('amount_due', $ov) && in_array($type, ['passenger', 'supplemental_driver'], true) && in_array($duration, ['Passager', 'Pilote supplémentaire', 'Pilote supplémentaire femme', 'Accompagnant'], true)) {
            // Aucun billet Billetweb retrouvé pour ce passager/pilote suppl./accompagnant : par défaut, ce ne peut
            // être qu'une inscription après coup (post-inscription) — même si le format a été corrigé à la main
            // (ex. passage en variante « femme », ou en « Accompagnant »). Mais on garde la main : dès qu'un prix
            // est saisi à la main sur la ligne (ex. après une fusion avec le vrai billet, ou une décision
            // volontaire), le badge repasse en variante inscription (PA/PS/PSF/AC) et ce prix prime — cf. la
            // fusion, le bon outil pour un remplacement de pilote supplémentaire/passager dont le billet existe
            // sous un autre nom.
            $optionCode = match ($duration) {
                'Passager' => 'PAP',
                'Pilote supplémentaire femme' => 'PSFP',
                'Accompagnant' => 'ACP',
                default => 'PSP',
            };
            $postCode = $optionCode;
            $isOptionRow = true;
        }

        $phone = (string)self::v($ov, 'phone', $participant ? (string)($participant['telephone'] ?? $src['phone']) : $src['phone']);
        $email = (string)self::v($ov, 'email', $participant ? (string)$participant['email'] : (string)$entry['email']);
        $discount = Text::float(self::v($ov, 'discount', $src['discount']));
        $credit = Text::float(self::v($ov, 'credit', '0'));

        $insuranceCode = (string)self::v($ov, 'insurance', $this->sourceInsurance($entry, $src, $participant));
        if (Formats::isOption($duration) || $isOptionRow) {
            $insuranceCode = '';
        }

        $fp = Pricing::total($this->tariffs, $duration, $insuranceCode, $postCode);
        $manualAmount = isset($this->overrideMeta[$eid]['amount_due']) && (int)$this->overrideMeta[$eid]['amount_due'] > 0;
        $amountDue = $manualAmount ? Text::float(self::v($ov, 'amount_due', '0')) : ($fp ?? 0.0);
        $priceKnown = $manualAmount || $fp !== null;

        $autoAdvance = $this->autoAdvance($entry, $src, $amountDue, $priceKnown);
        $pay = $this->payments[$eid] ?? ['advance' => 0.0, 'onsite' => 0.0, 'rows' => []];
        $advance = $autoAdvance + (float)$pay['advance'];
        $onsite = (float)$pay['onsite'];
        $remaining = $priceKnown ? ($amountDue - $advance - $onsite - $discount - $credit) : 0.0;

        $decharge = $this->decharge($participant, $ov);
        $progress = $this->progress($participant, $decharge);
        $dechargeDisplay = $this->dechargeDisplay($participant, $decharge);

        return [
            'entry'          => $entry,
            'id'             => $eid,
            'participant'    => $participant,
            'participant_id' => $participant ? (int)$participant['id'] : 0,
            'source'         => (string)$entry['source'],
            'type'           => $type,
            'ov'             => $ov,
            'name'           => $name,
            'vehicle'        => $vehicle,
            'vehicle_status' => $vehicleStatus,
            'reference'      => (string)($ov['reference'] ?? ''),
            'validator'      => (string)($ov['validator'] ?? ''),
            'notes'          => (string)($ov['notes'] ?? ''),
            'duration'       => $duration,
            'code'           => Formats::displayCode($duration, $optionCode),
            'phone'          => $phone,
            'email'          => $email,
            'first_time'     => (string)($ov['first_time'] ?? $src['first_time']),
            'insurance'      => $insuranceCode,
            'amount_due'     => $amountDue,
            'price_known'    => $priceKnown,
            'manual_amount'  => $manualAmount,
            'discount'       => $discount,
            'credit'         => $credit,
            'auto_advance'   => $autoAdvance,
            'bw_paid'        => (float)$src['advance'],
            'bw_method'      => Bw::paymentMethod($src['raw']),
            'advance'        => $advance,
            'onsite'         => $onsite,
            'remaining'      => $remaining,
            'payments'       => $pay['rows'],
            'decharge'       => $dechargeDisplay,
            'waiver_forced'  => $participant ? ($this->waiverOverrides[(int)$participant['id']] ?? null) : null,
            'progress'       => $progress,
            'ready'          => $progress['state'] === 'ready',
            'cancelled'      => $this->cancelled($entry),
            'no_remind'      => !empty($ov['no_remind']),
            'starter'        => (string)($this->starter[$eid]['status'] ?? 'pending'),
            'starter_info'   => $this->starter[$eid] ?? null,
            'comm'           => (string)($this->comm[$eid]['status'] ?? 'not_delivered'),
            'rfid'           => $this->rfid[$eid] ?? '',
            'rfid_stats'     => $this->rfidStats[$eid] ?? null,
            'meta'           => $this->meta[$eid] ?? null,
            'post_code'      => $postCode,
            'option_code'    => $optionCode,
            'is_option'      => $isOptionRow || Formats::isOption($duration),
            'linked_pilot'   => $src['linked_pilot'],
        ];
    }

    /** Valeur d'un champ modifié à la main, sinon la valeur source (une valeur NULL en base compte comme « modifié »). */
    private static function v(array $ov, string $field, mixed $default): mixed
    {
        return array_key_exists($field, $ov) ? $ov[$field] : $default;
    }

    private function attendeeFor(array $entry): array
    {
        $aid = trim((string)($entry['billetweb_attendee_id'] ?? ''));
        if ($aid === '') {
            return [];
        }
        return (string)$entry['source'] === 'post' ? ($this->postById[$aid] ?? []) : ($this->attById[$aid] ?? []);
    }

    /** Équivalent de jc_listing_entry_source. */
    private function entrySource(array $entry, ?array $participant): array
    {
        $att = $this->attendeeFor($entry);
        $raw = $att ? Bw::raw($att) : [];
        $paid = $att ? Bw::price($att) : 0.0;
        $amountDue = $att ? Bw::originalPrice($att) : 0.0;
        $discount = $att ? max(0.0, $amountDue - $paid) : 0.0;
        if (!$att) {
            $paid = 0.0;
        }
        $type = (string)$entry['participant_type'];
        // Le pilote principal indiqué dans la décharge (déclaré par la personne elle-même) prime toujours ;
        // à défaut de dossier déposé, on retombe sur le rapprochement par commande Billetweb — comme pour les
        // passagers, dont c'était jusqu'ici le seul cas à en bénéficier (c'était la vraie cause des « trous »
        // pour les pilotes supplémentaires dont le billet existe mais qui n'ont pas encore fait leur décharge).
        $linked = '';
        if (in_array($type, ['passenger', 'supplemental_driver'], true)) {
            $linked = $this->linkedPilotFromOrder($att);
        }
        if ($type === 'supplemental_driver' && $participant) {
            $declared = trim((string)($participant['linked_driver_name'] ?? ''));
            if ($declared !== '') {
                $linked = $declared;
            }
        }
        return [
            'att'          => $att,
            'raw'          => $raw,
            'vehicle'      => Bw::vehicle($raw),
            'linked_pilot' => $linked,
            'duration'     => $att ? Bw::duration($att) : '',
            'amount_due'   => $amountDue,
            'discount'     => $discount,
            'advance'      => $paid,
            'phone'        => Bw::phone($raw),
            'first_time'   => Bw::firstTime($raw),
        ];
    }

    private function linkedPilotFromOrder(array $att): string
    {
        $oid = trim((string)($att['order_id'] ?? ''));
        if ($oid === '') {
            return '';
        }
        foreach ($this->ordersAtt[$oid] ?? [] as $r) {
            if ((string)($r['attendee_id'] ?? '') === (string)($att['attendee_id'] ?? '')) {
                continue;
            }
            if (Bw::role($r) !== 'pilot') {
                continue;
            }
            return trim((string)$r['firstname'] . ' ' . (string)$r['name']);
        }
        return trim((string)($att['order_firstname'] ?? '') . ' ' . (string)($att['order_name'] ?? ''));
    }

    private function isInsuranceTicket(array $r): bool
    {
        $k = (string)($r['id'] ?? spl_object_id((object)$r));
        return $this->insTicketCache[$k] ??= Bw::isInsuranceTicket($r);
    }

    /** Assurance : O (à l'inscription), R (post-inscription), ou statut du document d'assurance (V/X/vide). */
    private function sourceInsurance(array $entry, array $src, ?array $participant): string
    {
        if ((string)$entry['participant_type'] !== 'pilot') {
            return '';
        }
        $att = $src['att'];
        if ($att) {
            $oid = trim((string)($att['order_id'] ?? ''));
            foreach ($this->ordersAtt[$oid] ?? [] as $r) {
                if ($this->isInsuranceTicket($r)) {
                    return 'O';
                }
            }
        }
        if (!empty($this->postInsuranceByEmail[strtolower(trim((string)($entry['email'] ?? '')))])) {
            return 'R';
        }
        return $participant ? $this->docStatus((int)$participant['id'], 'assurance') : '';
    }

    private function docStatus(int $pid, string $type): string
    {
        $a = $this->docStatuses[$pid][$type] ?? [];
        if (!$a) {
            return '';
        }
        if (in_array('rejected', $a, true)) {
            return 'X';
        }
        if (in_array('to_review', $a, true) || in_array('pending', $a, true)) {
            return '';
        }
        foreach ($a as $s) {
            if ($s !== 'validated') {
                return '';
            }
        }
        return 'V';
    }

    private function insurancePaidAmount(array $entry, array $src): float
    {
        if ((string)($entry['participant_type'] ?? '') !== 'pilot') {
            return 0.0;
        }
        $sum = 0.0;
        $att = $src['att'];
        if ($att) {
            $oid = trim((string)($att['order_id'] ?? ''));
            foreach ($this->ordersAtt[$oid] ?? [] as $r) {
                if ($this->isInsuranceTicket($r)) {
                    $sum += max(0.0, Bw::price($r));
                }
            }
        }
        if ($sum > 0) {
            return $sum;
        }
        foreach ($this->postInsuranceByEmail[strtolower(trim((string)($entry['email'] ?? '')))] ?? [] as $r) {
            $sum += max(0.0, Bw::price($r));
        }
        return $sum;
    }

    private function autoAdvance(array $entry, array $src, float $amountDue, bool $priceKnown): float
    {
        $auto = max(0.0, (float)$src['advance']) + max(0.0, $this->insurancePaidAmount($entry, $src));
        if ($priceKnown && $auto > $amountDue + 0.001) {
            $auto = max(0.0, $amountDue);
        }
        return max(0.0, $auto);
    }

    private function decharge(?array $participant, array $ov = []): string
    {
        if (!$participant) {
            // Sans dossier portail (ex. décharge reçue via l'ancien JotForm) : seule une validation manuelle
            // (mémorisée sur la ligne elle-même, faute de dossier participant à rattacher) peut la marquer.
            $forced = mb_strtoupper(trim((string)($ov['waiver_forced'] ?? '')), 'UTF-8');
            return in_array($forced, ['V', 'J', 'X'], true) ? $forced : '';
        }
        $pid = (int)$participant['id'];
        $forced = (string)($this->waiverOverrides[$pid]['status'] ?? '');
        if ($forced !== '') {
            return $forced;
        }
        return isset($this->waivers[$pid]) ? 'V' : '';
    }

    /**
     * Valeur affichée dans la colonne D du Listing. Le 'V' automatique (décharge signée, sans forçage
     * admin) ne doit pas donner l'impression d'un dossier bouclé pour un pilote dont le permis n'a pas
     * encore été vérifié : on ne l'affiche que si le permis est aussi validé. Un forçage manuel (V/J/X)
     * reste une décision explicite de l'admin et n'est jamais reconditionné au permis.
     */
    private function dechargeDisplay(?array $participant, string $decharge): string
    {
        if ($decharge !== 'V' || !$participant) {
            return $decharge;
        }
        $pid = (int)$participant['id'];
        if ((string)($this->waiverOverrides[$pid]['status'] ?? '') !== '') {
            return $decharge;
        }
        if ((string)($participant['participant_type'] ?? 'pilot') === 'passenger') {
            return $decharge;
        }
        $statuses = $this->docStatuses[$pid]['permis'] ?? [];
        return in_array('validated', $statuses, true) ? $decharge : '';
    }

    private function progress(?array $participant, string $decharge): array
    {
        if (!$participant) {
            return ['state' => 'none', 'label' => 'Pas de dossier', 'detail' => 'Aucun dossier participant rattaché.'];
        }
        $pid = (int)$participant['id'];
        $hasWaiver = isset($this->waivers[$pid]);
        if ((string)($participant['participant_type'] ?? 'pilot') === 'passenger') {
            return $hasWaiver
                ? ['state' => 'ready', 'label' => 'Complet', 'detail' => 'Décharge passager reçue.']
                : ['state' => 'missing', 'label' => 'À compléter', 'detail' => 'Décharge passager non reçue.'];
        }
        $statuses = $this->docStatuses[$pid]['permis'] ?? [];
        if (in_array('rejected', $statuses, true)) {
            return ['state' => 'correction', 'label' => 'À corriger', 'detail' => 'Un permis a été refusé / doit être corrigé.'];
        }
        if (in_array('pending', $statuses, true) || in_array('to_review', $statuses, true)) {
            return ['state' => 'review', 'label' => 'À vérifier', 'detail' => 'Document reçu : vérification administrative en attente.'];
        }
        $permitValidated = in_array('validated', $statuses, true);
        // La décharge seule (V/J) ne suffit pas : un pilote doit AUSSI avoir son permis validé pour que le
        // dossier soit « Complet ». Avant, la décharge signée suffisait à elle seule à afficher la ligne en vert,
        // y compris avec un permis jamais vérifié — un pilote « ouvrant son dossier » (donc signant sa décharge,
        // la dernière étape du formulaire) apparaissait donc complet avant toute vérification administrative.
        $dechargeOk = in_array($decharge, ['V', 'J'], true);
        if ($dechargeOk && $permitValidated) {
            return ['state' => 'ready', 'label' => 'Complet', 'detail' => 'Dossier complet.'];
        }
        if ($dechargeOk && !$permitValidated) {
            return ['state' => 'partial', 'label' => 'Permis manquant', 'detail' => 'Décharge reçue, permis non validé ou non reçu.'];
        }
        if (!$dechargeOk && $permitValidated) {
            return ['state' => 'partial', 'label' => 'Décharge manquante', 'detail' => 'Permis validé, décharge non reçue.'];
        }
        if ($statuses || $dechargeOk) {
            return ['state' => 'partial', 'label' => 'À compléter', 'detail' => 'Dossier commencé mais incomplet.'];
        }
        return ['state' => 'missing', 'label' => 'À compléter', 'detail' => 'Aucun élément complet reçu.'];
    }

    /** Annulé côté Billetweb : désactivé ou non payé. La première table où le billet existe fait foi. */
    private function cancelled(array $entry): bool
    {
        $aid = trim((string)($entry['billetweb_attendee_id'] ?? ''));
        if ($aid === '') {
            return false;
        }
        foreach ([$this->attById, $this->postById] as $map) {
            if (isset($map[$aid])) {
                $r = $map[$aid];
                return (int)($r['disabled'] ?? 0) === 1 || (int)($r['order_paid'] ?? 0) !== 1;
            }
        }
        return false;
    }
}
