<?php
declare(strict_types=1);

namespace JC\Core;

/** Accès sécurisé aux fichiers de l'ancien portail (permis, assurances, signatures, PDF). */
final class Files
{
    public const KINDS = [
        'permis'         => 'Permis',
        'assurance'      => 'Assurance',
        'signature'      => 'Signatures',
        'waiver'         => 'Waivers',
        'waiver_final'   => 'WaiversFinal',
        'template'       => 'Templates',
        'team_insurance' => 'TeamInsurance',
        // Minuscule à dessein : c'est le nom exact du dossier déjà utilisé par l'ancien portail pour les
        // captures d'avis (storage_root/reviews), et sur Linux « reviews » et « Reviews » sont deux dossiers
        // différents — se tromper de casse ici rend les anciennes captures introuvables.
        'review'         => 'reviews',
    ];

    private const MIME = [
        'application/pdf' => 'pdf',
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'image/heic'      => 'heic',
        'image/heif'      => 'heif',
    ];

    public static function root(): string
    {
        return rtrim((string)App::config('storage_root', ''), '/\\');
    }

    /** Chemin réel du fichier, ou null s'il n'existe pas / sort du dossier autorisé. */
    public static function resolve(string $kind, string $storedName): ?string
    {
        if (!isset(self::KINDS[$kind]) || $storedName === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $storedName)) {
            return null;
        }
        $base = realpath(self::root() . '/' . self::KINDS[$kind]);
        if ($base === false) {
            return null;
        }
        $full = realpath($base . DIRECTORY_SEPARATOR . $storedName);
        if ($full === false || !is_file($full)) {
            return null;
        }
        return str_starts_with($full, $base . DIRECTORY_SEPARATOR) ? $full : null;
    }

    /** Dossier d'écriture d'un type de fichier (créé si besoin). Refusé en lecture seule. */
    public static function dir(string $kind): string
    {
        Mode::assertWritable();
        if (!isset(self::KINDS[$kind])) {
            throw new \RuntimeException('Type de dossier inconnu.');
        }
        $dir = self::root() . '/' . self::KINDS[$kind];
        if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier « ' . self::KINDS[$kind] . ' » introuvable et impossible à créer.');
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException('Dossier « ' . self::KINDS[$kind] . ' » non inscriptible.');
        }
        return $dir;
    }

    /** Envoie un PDF produit en mémoire (sans le ranger sur le serveur). */
    public static function sendBytes(string $bytes, string $downloadName, bool $inline = true): never
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) ?: 'document.pdf';
        header('Content-Type: application/pdf');
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    }

    public static function send(string $path, string $downloadName, bool $inline = true): never
    {
        $mime = (string)(new \finfo(FILEINFO_MIME_TYPE))->file($path);
        if (!isset(self::MIME[$mime])) {
            Http::abort(415, 'Type de fichier non autorisé.');
        }
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $downloadName) ?: 'document';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"');
        header('X-Content-Type-Options: nosniff');
        if ($mime !== 'application/pdf') {
            // Le lecteur PDF des navigateurs ne supporte pas toutes les politiques CSP : on l'exclut.
            header("Content-Security-Policy: default-src 'none'; img-src data:; style-src 'unsafe-inline'");
        }
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /** Diagnostic : le dossier existe-t-il et est-il lisible ? */
    public static function status(): array
    {
        $out = [];
        foreach (self::KINDS as $kind => $dir) {
            $p = self::root() . '/' . $dir;
            $out[$kind] = ['dir' => $dir, 'exists' => is_dir($p), 'readable' => is_readable($p), 'writable' => is_writable($p)];
        }
        return $out;
    }
}
