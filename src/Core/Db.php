<?php
declare(strict_types=1);

namespace JC\Core;

use PDO;
use PDOStatement;

/** Écriture refusée parce que le portail est en mode lecture seule. */
final class ReadOnlyException extends \RuntimeException
{
    public function __construct(string $message = 'Mode lecture seule : cette action n\'a pas été enregistrée.')
    {
        parent::__construct($message);
    }
}

/**
 * Connexion PDO à double protection :
 *  1. en mode lecture seule, toute requête d'écriture est refusée par le code ;
 *  2. MySQL lui-même est placé en « transaction en lecture seule » pour la session.
 */
final class Db extends PDO
{
    private bool $ro = true;

    // Nommée open() et non connect() : PHP 8.4 a ajouté PDO::connect() en natif, et une méthode statique de
    // signature différente sous le même nom est alors refusée par PHP (erreur de compatibilité de déclaration).
    public static function open(array $c, bool $readOnly): self
    {
        // 'dsn' n'est utilisé que par les tests automatisés (base SQLite locale).
        $dsn = $c['dsn'] ?? sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $c['host'],
            (int)($c['port'] ?? 3306),
            $c['name'],
            $c['charset'] ?? 'utf8mb4'
        );
        $db = new self($dsn, (string)($c['user'] ?? ''), (string)($c['pass'] ?? ''), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 8,
        ]);
        $db->ro = $readOnly;
        $db->lockSessionReadOnly();
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $db->sqliteCompat();
        }
        return $db;
    }

    /** Fonctions MySQL simulées pour les tests automatisés sur SQLite (jamais utilisé en production). */
    private function sqliteCompat(): void
    {
        $this->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
        $this->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'), 0);
        $this->sqliteCreateFunction('UNIX_TIMESTAMP', fn() => time(), 0);
        $this->sqliteCreateFunction('FROM_UNIXTIME', fn($t) => date('Y-m-d H:i:s', (int)$t), 1);
    }

    public function isReadOnly(): bool
    {
        return $this->ro;
    }

    /** Vrai si la requête modifie des données ou la structure. */
    public static function isWrite(string $sql): bool
    {
        $s = ltrim($sql, " \t\r\n(");
        // retire d'éventuels commentaires en tête
        while (str_starts_with($s, '/*')) {
            $end = strpos($s, '*/');
            if ($end === false) {
                break;
            }
            $s = ltrim(substr($s, $end + 2), " \t\r\n(");
        }
        while (str_starts_with($s, '--')) {
            $nl = strpos($s, "\n");
            $s = $nl === false ? '' : ltrim(substr($s, $nl + 1), " \t\r\n(");
        }
        return (bool)preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP|TRUNCATE|RENAME|GRANT|REVOKE|LOAD|CALL|LOCK|OPTIMIZE|REPAIR)\b/i', $s);
    }

    private function guard(string $sql): void
    {
        if ($this->ro && self::isWrite($sql)) {
            throw new ReadOnlyException();
        }
    }

    #[\ReturnTypeWillChange]
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->guard($query);
        return parent::prepare($query, $options);
    }

    #[\ReturnTypeWillChange]
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $this->guard($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }

    #[\ReturnTypeWillChange]
    public function exec(string $statement): int|false
    {
        $this->guard($statement);
        return parent::exec($statement);
    }

    /** Applique la protection côté MySQL (appelé juste après la connexion). */
    public function lockSessionReadOnly(): void
    {
        if ($this->ro && $this->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            parent::exec('SET SESSION TRANSACTION READ ONLY');
        }
    }

    // --- Raccourcis de lecture / écriture -------------------------------------------------

    public function all(string $sql, array $params = []): array
    {
        $st = $this->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public function row(string $sql, array $params = []): ?array
    {
        $st = $this->prepare($sql);
        $st->execute($params);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public function val(string $sql, array $params = []): mixed
    {
        $st = $this->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public function col(string $sql, array $params = []): array
    {
        $st = $this->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_COLUMN);
    }

    public function run(string $sql, array $params = []): int
    {
        $st = $this->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** "?,?,?" pour une clause IN (...). */
    public static function marks(int $n): string
    {
        return implode(',', array_fill(0, max(1, $n), '?'));
    }
}
