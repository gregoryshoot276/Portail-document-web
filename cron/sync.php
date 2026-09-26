<?php
declare(strict_types=1);

/*
 * Synchronisation planifiée Billetweb -> listing (à lancer toutes les 10 minutes par une tâche cron IONOS) :
 *   php /chemin/vers/documents/cron/sync.php
 * Ne fait rien en mode lecture seule. Jamais accessible depuis le web (dossier hors « public »).
 */
require dirname(__DIR__) . '/src/cli.php';

use JC\Core\App;
use JC\Core\Mode;
use JC\Domain\ListingSync;
use JC\Domain\Settings;

if (Mode::readOnly()) {
    echo "Mode lecture seule : aucune synchronisation.\n";
    exit(0);
}
$lock = fopen(sys_get_temp_dir() . '/jc_v3_sync.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    echo "Synchronisation déjà en cours.\n";
    exit(0);
}
$db = App::db();
$events = $db->all("SELECT id,event_date,name FROM events WHERE is_active=1 AND billetweb_event_id IS NOT NULL AND billetweb_event_id<>'' AND event_date BETWEEN DATE_SUB(CURDATE(),INTERVAL 15 DAY) AND DATE_ADD(CURDATE(),INTERVAL 2 MONTH) ORDER BY event_date,id");
$report = [];
foreach ($events as $e) {
    try {
        $r = (new ListingSync($db))->run((int)$e['id'], null, true);
        $report[] = ['event' => (int)$e['id'], 'date' => $e['event_date'], 'entries' => $r['entries'], 'errors' => $r['errors']];
    } catch (Throwable $t) {
        $report[] = ['event' => (int)$e['id'], 'error' => $t->getMessage()];
    }
}
Settings::set($db, 'v3_cron_last_sync', date('Y-m-d H:i:s'));
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
flock($lock, LOCK_UN);
