<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/** Demandes d'annulation / remplacement (reprise de l'outil « annulation.php » de l'ancien portail, même principe :
 * le participant identifie son inscription et déclare une annulation ou un remplaçant, un admin traite ensuite
 * la demande). Simplifié par rapport à l'ancien outil : pas de pavé de signature (jamais exploité côté admin dans
 * l'ancienne version — juste stocké sans usage), et aucune tentative de retoucher automatiquement les colonnes
 * Billetweb internes du participant : le rafraîchissement Billetweb et le nettoyage de la ligne (fusion/archivage)
 * restent des actions volontaires de l'admin dans le Listing, avec les outils déjà en place et déjà éprouvés. */
final class Changes
{
    public const TYPES = ['cancellation', 'replacement'];

    public static function ensureTable(Db $db): void
    {
        static $done = false;
        if ($done) {
            return;
        }
        $db->run("CREATE TABLE IF NOT EXISTS participant_change_requests (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            event_id BIGINT UNSIGNED NOT NULL,
            participant_id BIGINT UNSIGNED NULL,
            request_type ENUM('cancellation','replacement') NOT NULL,
            status VARCHAR(32) NOT NULL DEFAULT 'pending',
            nom VARCHAR(190) NOT NULL,
            prenom VARCHAR(190) NOT NULL,
            email VARCHAR(190) NOT NULL,
            replacement_nom VARCHAR(190) NULL,
            replacement_prenom VARCHAR(190) NULL,
            replacement_email VARCHAR(190) NULL,
            replacement_phone VARCHAR(80) NULL,
            replacement_vehicle VARCHAR(255) NULL,
            admin_note TEXT NULL,
            sync_note VARCHAR(255) NULL,
            processed_by INT UNSIGNED NULL,
            processed_at DATETIME NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY idx_change_event (event_id),
            KEY idx_change_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $done = true;
    }
}
