<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\App;

/**
 * Étiquettes A4 : 24 par page (3 x 8), 70 x 37 mm, uniquement Journée / Matin / Après-midi.
 * Mise en page identique à l'ancien portail (NOM et prénom gros et centrés, véhicule gris italique,
 * format en rouge en haut à droite).
 */
final class Labels
{
    public static function pt(string $s): string
    {
        $x = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return is_string($x) ? $x : $s;
    }

    public static function formatLabel(string $f): string
    {
        return match (Text::normalize($f)) {
            'j', 'journee' => 'JOURNÉE',
            'm', 'matin' => 'MATIN',
            'a', 'apres-midi', 'apres midi' => 'APRÈS-MIDI',
            default => '',
        };
    }

    /**
     * Le listing porte un nom unique : on le découpe pour retrouver NOM en gras / Prénom dessous.
     * Les premiers mots entièrement en majuscules sont considérés comme le nom.
     * @return array{0:string,1:string}
     */
    public static function splitName(string $full): array
    {
        $full = trim(preg_replace('/\s+/u', ' ', $full) ?? $full);
        if ($full === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/u', $full) ?: [$full];
        if (count($parts) === 1) {
            return [$parts[0], ''];
        }
        $last = [];
        $first = [];
        foreach ($parts as $i => $part) {
            $letters = preg_replace('/[^\p{L}]/u', '', $part) ?? $part;
            $isUpper = $letters !== '' && mb_strtoupper($letters, 'UTF-8') === $letters;
            if (!$first && $isUpper) {
                $last[] = $part;
            } else {
                $first = array_slice($parts, $i);
                break;
            }
        }
        if (!$first && count($last) > 1) {
            $first = [array_pop($last)];
        }
        if (!$last) {
            $last = [array_shift($parts)];
            $first = $parts;
        }
        return [trim(implode(' ', $last)), trim(implode(' ', $first))];
    }

    /** Lignes éligibles : ni annulées, avec un nom, au format Journée / Matin / Après-midi. */
    public static function eligible(array $rows): array
    {
        return array_values(array_filter($rows, fn($r) => !$r['cancelled'] && trim((string)$r['name']) !== '' && self::formatLabel((string)$r['duration']) !== ''));
    }

    private static function fit(\FPDF $pdf, string $text, float $maxW, float $start, float $min, string $style = ''): void
    {
        $size = $start;
        while ($size > $min) {
            $pdf->SetFont('Arial', $style, $size);
            if ($pdf->GetStringWidth(self::pt($text)) <= $maxW) {
                break;
            }
            $size -= 0.5;
        }
        $pdf->SetFont('Arial', $style, $size);
    }

    /** Envoie le PDF au navigateur. @param array<int,array> $rows */
    public static function render(array $rows): never
    {
        require_once App::path('vendor/setasign/fpdf/fpdf.php');
        $rows = self::eligible($rows);

        $pdf = new \FPDF('P', 'mm', 'A4');
        $pdf->SetAutoPageBreak(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetTitle(self::pt('Étiquettes Journée Circuit'));

        $labelW = 70.0;
        $labelH = 37.0;
        $cols = 3;
        $perPage = $cols * 8;

        foreach ($rows as $i => $r) {
            if ($i % $perPage === 0) {
                $pdf->AddPage();
            }
            $slot = $i % $perPage;
            $row = intdiv($slot, $cols);
            $col = $slot % $cols;
            $x = $col * $labelW;
            $y = 0.5 + $row * $labelH;

            [$lastName, $firstName] = self::splitName(trim((string)$r['name']));
            $vehicle = trim((string)$r['vehicle']);
            $format = self::formatLabel((string)$r['duration']);

            // Zone sûre : retrait supplémentaire sur les bords externes de l'A4.
            $safeLeft = $col === 0 ? 5.5 : 3.5;
            $safeRight = $col === 2 ? 6.0 : 3.5;
            $cx = $x + $safeLeft;
            $cw = $labelW - $safeLeft - $safeRight;

            $pdf->SetTextColor(220, 0, 0);
            $pdf->SetFont('Arial', 'B', 5.8);
            $pdf->SetXY($cx, $y + 2.1);
            $pdf->Cell($cw - 1.5, 3.0, self::pt($format), 0, 0, 'R');

            $pdf->SetTextColor(0, 0, 0);
            self::fit($pdf, $lastName, $cw, 21.5, 13.0, 'B');
            $pdf->SetXY($cx, $y + 6.0);
            $pdf->Cell($cw, 8.0, self::pt($lastName), 0, 0, 'C');

            if ($firstName !== '') {
                self::fit($pdf, $firstName, $cw, 17.0, 10.5, '');
                $pdf->SetXY($cx, $y + 15.2);
                $pdf->Cell($cw, 7.0, self::pt($firstName), 0, 0, 'C');
            }
            if ($vehicle !== '') {
                $pdf->SetTextColor(170, 170, 170);
                self::fit($pdf, $vehicle, $cw, 9.8, 7.0, 'I');
                $pdf->SetXY($cx, $y + 25.7);
                $pdf->Cell($cw, 5.0, self::pt($vehicle), 0, 0, 'C');
            }
            $pdf->SetTextColor(0, 0, 0);
        }
        if (!$rows) {
            $pdf->AddPage();
            $pdf->SetFont('Arial', '', 12);
            $pdf->SetXY(15, 20);
            $pdf->Cell(180, 8, self::pt('Aucune étiquette Journée / Matin / Après-midi à générer.'), 0, 1, 'C');
        }
        header('Cache-Control: private, no-store');
        $pdf->Output('I', 'etiquettes-' . date('Ymd') . '.pdf');
        exit;
    }
}
