<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\App;
use JC\Core\Db;
use JC\Core\Files;
use JC\Core\Mode;

/**
 * Décharges : modèles par circuit, PDF de preuve Journée Circuit, PDF officiel du circuit (Magny-Cours, Bugatti)
 * rempli par superposition sur le PDF fourni par le circuit.
 */
final class Waivers
{
    /** Modèle actif pour un circuit à une date d'événement. */
    public static function templateFor(Db $db, int $circuitId, string $eventDate): ?array
    {
        return $db->row(
            'SELECT * FROM waiver_templates WHERE circuit_id=? AND is_active=1 AND event_valid_from<=? AND event_valid_until>=? ORDER BY event_valid_from DESC,id DESC LIMIT 1',
            [$circuitId, $eventDate, $eventDate]
        );
    }

    /** Un PDF officiel du circuit est-il exigé ? (Magny-Cours : tous ; Bugatti : pilotes et pilotes supplémentaires.) */
    public static function officialRequired(string $slug, string $type): bool
    {
        return $slug === 'magny-cours' || ($slug === 'bugatti' && in_array($type, ['pilot', 'supplemental_driver'], true));
    }

    /** Champs supplémentaires demandés par le circuit dans le formulaire. */
    public static function extraFields(string $slug): array
    {
        return match ($slug) {
            'magny-cours' => ['birth_date', 'address'],
            'bugatti' => ['birth_date', 'address', 'postal_code', 'city', 'permit_number', 'emergency_phone', 'image_rights'],
            default => [],
        };
    }

    public static function signingOpen(array $template): bool
    {
        $today = date('Y-m-d');
        if (!empty($template['signing_open_from']) && $template['signing_open_from'] > $today) {
            return false;
        }
        if (!empty($template['signing_open_until']) && $template['signing_open_until'] < $today) {
            return false;
        }
        return true;
    }

    // --- PDF officiel : catalogue, positions par défaut, valeurs -----------------------------------------

    public static function fieldCatalog(string $slug): array
    {
        $common = [
            'event_date' => 'text', 'nom' => 'text', 'prenom' => 'text', 'full_name' => 'text', 'birth_date' => 'text', 'address' => 'text',
            'postal_code' => 'text', 'city' => 'text', 'telephone' => 'text', 'email' => 'text', 'permit_number' => 'text', 'club' => 'text',
            'license_number' => 'text', 'aco_member_number' => 'text', 'emergency_phone' => 'text', 'image_rights_yes' => 'check',
            'image_rights_no' => 'check', 'signed_place' => 'text', 'signed_date' => 'text', 'approval_mention' => 'text', 'signature' => 'signature',
        ];
        $keep = match ($slug) {
            'magny-cours' => ['event_date', 'full_name', 'birth_date', 'address', 'telephone', 'email', 'signed_place', 'signed_date', 'approval_mention', 'signature'],
            'bugatti' => ['event_date', 'nom', 'prenom', 'birth_date', 'address', 'postal_code', 'city', 'club', 'license_number', 'aco_member_number', 'permit_number', 'emergency_phone', 'image_rights_yes', 'image_rights_no', 'signed_place', 'signed_date', 'approval_mention', 'signature'],
            default => [],
        };
        return array_intersect_key($common, array_flip($keep));
    }

    /** Positions normalisées (origine en haut à gauche) de chaque champ sur le PDF du circuit. */
    public static function defaultMapping(string $slug): array
    {
        if ($slug === 'magny-cours') {
            return [
                'event_date' => ['page' => 1, 'x' => 0.604, 'y' => 0.130, 'w' => 0.270, 'h' => 0.018, 'font' => 8, 'align' => 'L'],
                'full_name' => ['page' => 1, 'x' => 0.205, 'y' => 0.153, 'w' => 0.390, 'h' => 0.018, 'font' => 8, 'align' => 'L'],
                'birth_date' => ['page' => 1, 'x' => 0.650, 'y' => 0.153, 'w' => 0.220, 'h' => 0.018, 'font' => 8, 'align' => 'L'],
                'address' => ['page' => 1, 'x' => 0.205, 'y' => 0.176, 'w' => 0.665, 'h' => 0.018, 'font' => 8, 'align' => 'L'],
                'telephone' => ['page' => 1, 'x' => 0.184, 'y' => 0.199, 'w' => 0.685, 'h' => 0.018, 'font' => 8, 'align' => 'L'],
                'email' => ['page' => 1, 'x' => 0.160, 'y' => 0.222, 'w' => 0.710, 'h' => 0.018, 'font' => 8, 'align' => 'L'],
                'signed_place' => ['page' => 1, 'x' => 0.120, 'y' => 0.875, 'w' => 0.145, 'h' => 0.018, 'font' => 8, 'align' => 'L'],
                'signed_date' => ['page' => 1, 'x' => 0.275, 'y' => 0.875, 'w' => 0.145, 'h' => 0.018, 'font' => 8, 'align' => 'L'],
                'approval_mention' => ['page' => 1, 'x' => 0.535, 'y' => 0.862, 'w' => 0.220, 'h' => 0.018, 'font' => 7, 'align' => 'L'],
                'signature' => ['page' => 1, 'x' => 0.535, 'y' => 0.878, 'w' => 0.290, 'h' => 0.050],
            ];
        }
        if ($slug === 'bugatti') {
            return [
                'event_date' => ['page' => 1, 'x' => 0.485, 'y' => 0.180, 'w' => 0.245, 'h' => 0.020, 'font' => 9, 'align' => 'C'],
                'nom' => ['page' => 1, 'x' => 0.220, 'y' => 0.226, 'w' => 0.230, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'prenom' => ['page' => 1, 'x' => 0.545, 'y' => 0.226, 'w' => 0.260, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'birth_date' => ['page' => 1, 'x' => 0.205, 'y' => 0.258, 'w' => 0.300, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'address' => ['page' => 1, 'x' => 0.190, 'y' => 0.289, 'w' => 0.620, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'city' => ['page' => 1, 'x' => 0.160, 'y' => 0.321, 'w' => 0.300, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'postal_code' => ['page' => 1, 'x' => 0.545, 'y' => 0.321, 'w' => 0.260, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'club' => ['page' => 1, 'x' => 0.160, 'y' => 0.353, 'w' => 0.300, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'license_number' => ['page' => 1, 'x' => 0.545, 'y' => 0.353, 'w' => 0.260, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'aco_member_number' => ['page' => 1, 'x' => 0.215, 'y' => 0.384, 'w' => 0.245, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'permit_number' => ['page' => 1, 'x' => 0.585, 'y' => 0.384, 'w' => 0.220, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'emergency_phone' => ['page' => 1, 'x' => 0.330, 'y' => 0.416, 'w' => 0.475, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'image_rights_yes' => ['page' => 2, 'x' => 0.092, 'y' => 0.216, 'w' => 0.020, 'h' => 0.020, 'font' => 12, 'align' => 'C'],
                'image_rights_no' => ['page' => 2, 'x' => 0.092, 'y' => 0.326, 'w' => 0.020, 'h' => 0.020, 'font' => 12, 'align' => 'C'],
                'signed_place' => ['page' => 2, 'x' => 0.150, 'y' => 0.387, 'w' => 0.145, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'signed_date' => ['page' => 2, 'x' => 0.325, 'y' => 0.387, 'w' => 0.155, 'h' => 0.020, 'font' => 9, 'align' => 'L'],
                'approval_mention' => ['page' => 2, 'x' => 0.095, 'y' => 0.410, 'w' => 0.240, 'h' => 0.020, 'font' => 8, 'align' => 'L'],
                'signature' => ['page' => 2, 'x' => 0.095, 'y' => 0.430, 'w' => 0.300, 'h' => 0.075],
            ];
        }
        return [];
    }

    public static function mapping(array $template, string $slug): array
    {
        $m = json_decode((string)($template['mapping_json'] ?? ''), true);
        return is_array($m) && $m ? $m : self::defaultMapping($slug);
    }

    public static function values(array $p, array $w): array
    {
        $d = fn(?string $v) => !empty($v) ? date('d/m/Y', strtotime((string)$v)) : '';
        return [
            'event_date' => $d($p['event_date'] ?? null), 'nom' => (string)($p['nom'] ?? ''), 'prenom' => (string)($p['prenom'] ?? ''),
            'full_name' => trim((string)($p['prenom'] ?? '') . ' ' . (string)($p['nom'] ?? '')), 'birth_date' => $d($p['birth_date'] ?? null),
            'address' => (string)($p['address'] ?? ''), 'postal_code' => (string)($p['postal_code'] ?? ''), 'city' => (string)($p['city'] ?? ''),
            'telephone' => (string)($p['telephone'] ?? ''), 'email' => (string)($p['email'] ?? ''), 'permit_number' => (string)($p['permit_number'] ?? ''),
            'club' => (string)($p['club'] ?? ''), 'license_number' => (string)($p['license_number'] ?? ''), 'aco_member_number' => (string)($p['aco_member_number'] ?? ''),
            'emergency_phone' => (string)($p['emergency_phone'] ?? ''),
            'image_rights_yes' => (($p['image_rights'] ?? '') === 'yes') ? 'X' : '', 'image_rights_no' => (($p['image_rights'] ?? '') === 'no') ? 'X' : '',
            'signed_place' => (string)($w['signed_place'] ?? ''), 'signed_date' => $d($w['signed_at'] ?? null), 'approval_mention' => 'Lu et approuvé',
        ];
    }

    // --- Données d'une décharge ----------------------------------------------------------------------------

    public static function record(Db $db, int $participantId): ?array
    {
        return $db->row(
            'SELECT p.*,c.name circuit_name,c.slug,e.event_date,w.id waiver_id,w.signature_file,w.signed_place,w.signed_at,w.ip_address,w.pdf_file,w.pdf_sha256,w.official_pdf_file,w.official_pdf_sha256,w.clauses_json,
                    t.id template_id,t.name template_name,t.version_label,t.stored_name,t.mapping_json,t.circuit_id
             FROM participants p JOIN waivers w ON w.participant_id=p.id JOIN events e ON e.id=p.event_id JOIN circuits c ON c.id=e.circuit_id
             LEFT JOIN waiver_templates t ON t.id=w.template_id WHERE p.id=?',
            [$participantId]
        );
    }

    // --- PDF de preuve --------------------------------------------------------------------------------------

    private static function wrap(\FPDF $pdf, string $text, float $width): array
    {
        $lines = [];
        $cur = '';
        foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
            $test = $cur === '' ? $word : $cur . ' ' . $word;
            if ($pdf->GetStringWidth(Labels::pt($test)) > $width && $cur !== '') {
                $lines[] = $cur;
                $cur = $word;
            } else {
                $cur = $test;
            }
        }
        if ($cur !== '') {
            $lines[] = $cur;
        }
        return $lines;
    }

    /** PDF « preuve de décharge numérique » (texte accepté, signature, traçabilité). */
    public static function proofBytes(array $r, string $signaturePath): string
    {
        require_once App::path('vendor/setasign/fpdf/fpdf.php');
        $pdf = new \FPDF('P', 'pt', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->AddPage();
        $red = [194, 24, 25];
        $pdf->SetFillColor(...$red);
        $pdf->Rect(0, 0, 595.28, 29, 'F');
        $pdf->Rect(0, 830, 595.28, 12, 'F');
        $logo = App::path('public/assets/logo-jc.png');
        if (is_file($logo)) {
            $pdf->Image($logo, 42, 46, 95);
        } else {
            $pdf->SetFont('Helvetica', 'B', 21);
            $pdf->SetXY(42, 60);
            $pdf->Cell(300, 24, Labels::pt('JOURNÉE CIRCUIT'));
        }
        $put = function (string $txt, float $x, float $y, float $size = 10, bool $bold = false, array $rgb = [26, 26, 26]) use ($pdf): void {
            $pdf->SetFont('Helvetica', $bold ? 'B' : '', $size);
            $pdf->SetTextColor(...$rgb);
            $pdf->SetXY($x, $y);
            $pdf->Cell(510, $size + 3, Labels::pt($txt));
        };
        $put('PREUVE DE DÉCHARGE NUMÉRIQUE', 42, 118, 18, true, [20, 20, 20]);
        $put(trim((string)$r['circuit_name'] . ' - ' . fdate((string)$r['event_date'])), 42, 142, 11, false, [97, 97, 97]);
        $pdf->SetDrawColor(...$red);
        $pdf->Line(42, 166, 553, 166);

        $y = 186;
        $put('PARTICIPANT', 42, $y, 11, true, $red);
        $y += 24;
        foreach ([['Nom', trim((string)$r['prenom'] . ' ' . (string)$r['nom'])], ['E-mail', (string)$r['email']], ['Téléphone', (string)($r['telephone'] ?: '-')]] as [$k, $v]) {
            $put($k, 42, $y, 9, true, [90, 90, 90]);
            $put($v, 145, $y, 10);
            $y += 19;
        }
        $y += 6;
        $put('DÉCHARGE ACCEPTÉE', 42, $y, 11, true, $red);
        $y += 22;
        $put('Document : ' . trim((string)$r['template_name'] . ' ' . (string)$r['version_label']), 42, $y, 10, true);
        $y += 19;
        $d = json_decode((string)$r['clauses_json'], true);
        $accepted = (is_array($d) && !empty($d['accepted_texts'])) ? array_values($d['accepted_texts']) : ['J’ai pris connaissance de la décharge applicable à cet événement et je l’accepte.'];
        $pdf->SetFont('Helvetica', '', 9.4);
        foreach ($accepted as $txt) {
            foreach (self::wrap($pdf, '- ' . $txt, 480) as $line) {
                $put($line, 50, $y, 9.4);
                $y += 13.5;
            }
        }
        $y += 10;
        $put('SIGNATURE', 42, $y, 11, true, $red);
        $y += 20;
        $put('Mention : Lu et approuvé', 42, $y, 9.5);
        $put('Fait à : ' . ((string)$r['signed_place'] ?: '-'), 300, $y, 9.5);
        $y += 16;
        $put('Signé le : ' . fdate((string)$r['signed_at'], 'd/m/Y H:i:s'), 300, $y, 9.5);
        if (is_file($signaturePath)) {
            $info = @getimagesize($signaturePath);
            if ($info && $info[0] > 0) {
                $w = 185.0;
                $h = $w * $info[1] / $info[0];
                if ($h > 82) {
                    $h = 82.0;
                    $w = $h * $info[0] / $info[1];
                }
                $pdf->Image($signaturePath, 45, $y - 6, $w, $h, 'PNG');
                $y += $h;
            }
        }
        $y += 24;
        $pdf->SetDrawColor(220, 220, 220);
        $pdf->Line(42, $y, 553, $y);
        $y += 16;
        $put('TRAÇABILITÉ', 42, $y, 10, true, $red);
        $y += 18;
        foreach ([['Dossier', 'JC-' . $r['id']], ['Adresse IP', (string)($r['ip_address'] ?: '-')], ['Empreinte SHA-256', 'calculée et conservée côté serveur']] as [$k, $v]) {
            $put($k . ' : ' . $v, 42, $y, 8.5, false, [90, 90, 90]);
            $y += 14;
        }
        $put('Ce document constitue la preuve technique des informations et consentements enregistrés par le portail Journée Circuit.', 42, 800, 7.5, false, [115, 115, 115]);
        return $pdf->Output('S');
    }

    // --- PDF officiel du circuit ---------------------------------------------------------------------------------

    public static function officialBytes(array $r): string
    {
        require_once App::path('vendor/autoload.php');
        $tplDir = Files::root() . '/' . Files::KINDS['template'];
        $source = $tplDir . '/' . basename((string)$r['stored_name']);
        if (empty($r['stored_name']) || !is_file($source)) {
            throw new \RuntimeException('PDF officiel source absent (dossier Templates).');
        }
        $slug = (string)$r['slug'];
        $mapping = self::mapping(['mapping_json' => $r['mapping_json']], $slug);
        if (!$mapping) {
            throw new \RuntimeException('Positions des champs absentes pour ce circuit.');
        }
        $values = self::values($r, ['signed_place' => $r['signed_place'], 'signed_at' => $r['signed_at']]);
        $catalog = self::fieldCatalog($slug);
        $sig = Files::root() . '/' . Files::KINDS['signature'] . '/' . basename((string)$r['signature_file']);

        $pdf = new \setasign\Fpdi\Fpdi('P', 'mm');
        $pages = $pdf->setSourceFile($source);
        for ($page = 1; $page <= $pages; $page++) {
            $tpl = $pdf->importPage($page);
            $size = $pdf->getTemplateSize($tpl);
            $pdf->AddPage($size['width'] > $size['height'] ? 'L' : 'P', [$size['width'], $size['height']]);
            $pdf->useTemplate($tpl, 0, 0, $size['width'], $size['height']);
            foreach ($mapping as $key => $box) {
                if ((int)($box['page'] ?? 1) !== $page) {
                    continue;
                }
                $type = $catalog[$key] ?? ($key === 'signature' ? 'signature' : 'text');
                $x = (float)$box['x'] * $size['width'];
                $y = (float)$box['y'] * $size['height'];
                $w = max(1.0, (float)$box['w'] * $size['width']);
                $h = max(1.0, (float)$box['h'] * $size['height']);
                if ($type === 'signature') {
                    if (is_file($sig)) {
                        $pdf->Image($sig, $x, $y, $w, $h, 'PNG');
                    }
                    continue;
                }
                $value = (string)($values[$key] ?? '');
                if ($value === '') {
                    continue;
                }
                $pdf->SetFont('Helvetica', '', (float)($box['font'] ?? 9));
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetXY($x, $y);
                $pdf->Cell($w, $h, Labels::pt($value), 0, 0, (string)($box['align'] ?? 'L'));
            }
        }
        return $pdf->Output('S');
    }

    /**
     * Retourne le PDF demandé. En mode écriture il est généré une fois, rangé dans le dossier des documents et son empreinte
     * SHA-256 enregistrée ; en lecture seule il est produit à la volée sans rien écrire.
     * @return array{path:?string,bytes:?string,name:string}
     */
    public static function get(Db $db, int $participantId, string $kind): array
    {
        $r = self::record($db, $participantId);
        if (!$r) {
            throw new \RuntimeException('Décharge introuvable.');
        }
        $official = $kind === 'official';
        if ($official && !self::officialRequired((string)$r['slug'], (string)$r['participant_type'])) {
            throw new \RuntimeException('Aucune décharge officielle de circuit pour ce dossier.');
        }
        $col = $official ? 'official_pdf_file' : 'pdf_file';
        $dirKind = $official ? 'waiver_final' : 'waiver';
        $name = 'decharge-' . ($official ? 'circuit' : 'journee-circuit') . '-' . preg_replace('/[^a-z0-9-]+/i', '-', (string)$r['circuit_name']) . '-' . date('Y-m-d', strtotime((string)$r['event_date'])) . '.pdf';
        if (!empty($r[$col])) {
            $existing = Files::resolve($dirKind, (string)$r[$col]);
            if ($existing !== null) {
                return ['path' => $existing, 'bytes' => null, 'name' => $name];
            }
        }
        $sig = Files::root() . '/' . Files::KINDS['signature'] . '/' . basename((string)$r['signature_file']);
        $bytes = $official ? self::officialBytes($r) : self::proofBytes($r, $sig);
        if (Mode::readOnly()) {
            return ['path' => null, 'bytes' => $bytes, 'name' => $name];
        }
        $dir = Files::root() . '/' . Files::KINDS[$dirKind];
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier des PDF indisponible.');
        }
        $stored = ($official ? 'decharge-officielle-' : 'decharge-') . $participantId . '-' . bin2hex(random_bytes(8)) . '.pdf';
        if (file_put_contents($dir . '/' . $stored, $bytes, LOCK_EX) === false) {
            throw new \RuntimeException('Impossible d\'enregistrer le PDF.');
        }
        $hash = hash_file('sha256', $dir . '/' . $stored);
        if ($official) {
            $db->run('UPDATE waivers SET official_pdf_file=?,official_pdf_sha256=?,official_generated_at=NOW() WHERE id=?', [$stored, $hash, (int)$r['waiver_id']]);
        } else {
            $db->run('UPDATE waivers SET pdf_file=?,pdf_sha256=? WHERE id=?', [$stored, $hash, (int)$r['waiver_id']]);
        }
        return ['path' => realpath($dir . '/' . $stored) ?: $dir . '/' . $stored, 'bytes' => null, 'name' => $name];
    }
}
