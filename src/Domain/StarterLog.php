<?php
declare(strict_types=1);

namespace JC\Domain;

use JC\Core\Db;

/** Main courante du starter : historique horodaté des arrêts/reprises de piste, journée par journée. */
final class StarterLog
{
    public const STATUSES = ['red' => 'Piste arrêtée', 'green' => 'Piste relancée', 'yellow' => 'Note'];

    private static function ensureTable(Db $db): void
    {
        $db->run('CREATE TABLE IF NOT EXISTS starter_log (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_id INT NOT NULL,
            status VARCHAR(10) NOT NULL,
            reason VARCHAR(255) NOT NULL DEFAULT \'\',
            created_by VARCHAR(120) NOT NULL DEFAULT \'\',
            created_at DATETIME NOT NULL,
            KEY idx_event (event_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    public static function log(Db $db, int $eventId, string $status, string $reason, string $by): array
    {
        if (!isset(self::STATUSES[$status])) {
            throw new \InvalidArgumentException('Statut de main courante inconnu.');
        }
        self::ensureTable($db);
        $db->run('INSERT INTO starter_log(event_id,status,reason,created_by,created_at) VALUES(?,?,?,?,NOW())', [$eventId, $status, trim($reason), $by]);
        $id = (int)$db->val('SELECT MAX(id) FROM starter_log WHERE event_id=?', [$eventId]);
        return self::row($db, $id);
    }

    private static function row(Db $db, int $id): array
    {
        $r = $db->row('SELECT id,status,reason,created_by,created_at FROM starter_log WHERE id=?', [$id]);
        return $r ?? [];
    }

    /** Plus récent en premier. Ne tente pas de créer la table (lecture possible même en mode lecture seule,
     *  avant toute première utilisation de la main courante). */
    public static function history(Db $db, int $eventId): array
    {
        try {
            return $db->all('SELECT id,status,reason,created_by,created_at FROM starter_log WHERE event_id=? ORDER BY created_at DESC, id DESC LIMIT 200', [$eventId]);
        } catch (\PDOException $e) {
            return [];
        }
    }
}
