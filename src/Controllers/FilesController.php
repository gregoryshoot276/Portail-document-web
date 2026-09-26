<?php
declare(strict_types=1);

namespace JC\Controllers;

use JC\Core\Audit;
use JC\Core\Files;
use JC\Core\Http;

/** Affichage des pièces (permis, assurance, signature, décharges PDF) : toujours derrière la connexion. */
final class FilesController extends BaseController
{
    public function document(int $id): void
    {
        $d = $this->db()->row('SELECT id,participant_id,document_type,stored_name,original_name FROM documents WHERE id=?', [$id]);
        if (!$d || empty($d['stored_name'])) {
            Http::abort(404, 'Document introuvable.');
        }
        $path = Files::resolve((string)$d['document_type'], (string)$d['stored_name']);
        if ($path === null) {
            Http::abort(404, 'Fichier absent du serveur.');
        }
        Audit::log('v3.file.view', (int)$d['participant_id'], ['document' => $id, 'type' => $d['document_type']]);
        // L'en-tête de sécurité global interdit tout cadrage (frame-ancestors 'none', anti-clickjacking) — trop
        // strict ici puisque l'aperçu admin affiche ce fichier dans un <iframe> de la même origine. On assouplit
        // uniquement pour cette réponse, en restant limité au même site (jamais un site externe).
        header('X-Frame-Options: SAMEORIGIN');
        header("Content-Security-Policy: frame-ancestors 'self'");
        Files::send($path, (string)($d['original_name'] ?: 'document'));
    }

    public function signature(int $participantId): void
    {
        $w = $this->db()->row('SELECT signature_file FROM waivers WHERE participant_id=?', [$participantId]);
        $path = $w ? Files::resolve('signature', (string)$w['signature_file']) : null;
        if ($path === null) {
            Http::abort(404, 'Signature introuvable.');
        }
        Files::send($path, 'signature-' . $participantId . '.png');
    }

    /** kind = proof (preuve Journée Circuit) ou official (décharge officielle du circuit). */
    public function waiver(int $participantId, string $kind): void
    {
        if ($kind !== 'official' && $kind !== 'proof') {
            Http::abort(404, 'Document introuvable.');
        }
        try {
            // En mode écriture le PDF est généré une fois et rangé ; en lecture seule il est produit à la volée, sans rien écrire.
            $r = \JC\Domain\Waivers::get($this->db(), $participantId, $kind);
        } catch (\RuntimeException $e) {
            Http::abort(404, $e->getMessage());
        }
        Audit::log('v3.file.view', $participantId, ['waiver' => $kind]);
        if ($r['path'] !== null) {
            Files::send($r['path'], $r['name']);
        }
        Files::sendBytes((string)$r['bytes'], $r['name']);
    }
}
