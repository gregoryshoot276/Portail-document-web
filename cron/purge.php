<?php
declare(strict_types=1);

/*
 * Purge RGPD : supprime les dossiers dont la date de conservation est dépassée (par défaut 30 jours après la journée),
 * avec leurs documents, signatures et PDF. À lancer une fois par jour :  php /chemin/vers/documents/cron/purge.php
 * Ne fait rien en mode lecture seule.
 */
require dirname(__DIR__) . '/src/cli.php';

use JC\Core\App;
use JC\Core\Audit;
use JC\Core\Files;
use JC\Core\Mode;

if (Mode::readOnly()) {
    echo "Mode lecture seule : aucune purge.\n";
    exit(0);
}
$db = App::db();
$rows = $db->all("SELECT id FROM participants WHERE account_id IS NULL AND retention_mode='event' AND delete_after IS NOT NULL AND delete_after<NOW() ORDER BY id LIMIT 500");
$count = 0;
$remove = static function (string $kind, ?string $name): void {
    if ($name === null || $name === '') {
        return;
    }
    $p = Files::resolve($kind, $name);
    if ($p === null) {
        fwrite(STDERR, "Fichier introuvable (déjà supprimé ?) : $kind/$name\n");
    } elseif (!@unlink($p)) {
        fwrite(STDERR, "Fichier non supprimé : $kind/$name\n");
    } else {
        echo "Fichier supprimé : $kind/$name\n";
    }
};
foreach ($rows as $row) {
    $pid = (int)$row['id'];
    try {
        foreach ($db->all('SELECT document_type,stored_name FROM documents WHERE participant_id=?', [$pid]) as $d) {
            // Un fichier partagé avec un autre dossier (document réutilisé) n'est pas supprimé.
            $shared = (int)$db->val('SELECT COUNT(*) FROM documents WHERE stored_name=? AND participant_id<>?', [$d['stored_name'], $pid]);
            if (!$shared && in_array($d['document_type'], ['permis', 'assurance'], true)) {
                $remove($d['document_type'], (string)$d['stored_name']);
            }
        }
        $w = $db->row('SELECT signature_file,pdf_file,official_pdf_file FROM waivers WHERE participant_id=?', [$pid]);
        if ($w) {
            $remove('signature', $w['signature_file']);
            $remove('waiver', $w['pdf_file']);
            $remove('waiver_final', $w['official_pdf_file']);
        }
        $db->beginTransaction();
        Audit::log('retention_purge', $pid, ['via' => 'v3', 'mode' => 'guest']);
        $db->run('DELETE FROM participants WHERE id=? AND account_id IS NULL', [$pid]);
        $db->commit();
        $count++;
    } catch (Throwable $t) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        fwrite(STDERR, 'Dossier ' . $pid . ' : ' . $t->getMessage() . "\n");
    }
}
echo "Dossiers purgés : $count\n";
