<?php
// ============================================================
//  HandwerkerPro — Database Model (PDO Singleton)
//  Sicherheit: ALLE Abfragen über Prepared Statements!
//              String-Concatenation für SQL ist VERBOTEN.
// ============================================================
declare(strict_types=1);

namespace App\Models;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

class Database
{
    private static ?PDO $instance = null;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * PDO-Verbindung holen (Singleton)
     */
    public function getConnection(): PDO
    {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['name'],
                $this->config['charset']
            );

            try {
                self::$instance = new PDO(
                    $dsn,
                    $this->config['user'],
                    $this->config['pass'],
                    $this->config['options']
                );
            } catch (PDOException $e) {
                // DB-Fehler niemals im Response enthüllen (Sicherheit!)
                throw new RuntimeException('Datenbankverbindung fehlgeschlagen.', 500);
            }
        }

        return self::$instance;
    }

    /**
     * Prepared Statement ausführen und alle Zeilen holen
     * Niemals rohe Werte direkt in SQL einbauen!
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchAll();
    }

    /**
     * Prepared Statement ausführen und eine Zeile holen
     */
    public function fetchOne(string $sql, array $params = []): array|false
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetch();
    }

    /**
     * Prepared Statement ausführen und einen Wert holen
     */
    public function fetchColumn(string $sql, array $params = []): mixed
    {
        $stmt = $this->execute($sql, $params);
        return $stmt->fetchColumn();
    }

    /**
     * INSERT/UPDATE/DELETE ausführen
     */
    public function execute(string $sql, array $params = []): PDOStatement
    {
        $pdo  = $this->getConnection();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /**
     * Letzte INSERT-ID
     */
    public function lastInsertId(): int
    {
        return (int) $this->getConnection()->lastInsertId();
    }

    /**
     * Transaktion starten
     */
    public function beginTransaction(): void
    {
        $this->getConnection()->beginTransaction();
    }

    public function commit(): void
    {
        $this->getConnection()->commit();
    }

    public function rollBack(): void
    {
        $this->getConnection()->rollBack();
    }

    /**
     * Stored Procedure aufrufen
     */
    public function callProcedure(string $name, array $params = []): void
    {
        $placeholders = implode(', ', array_fill(0, count($params), '?'));
        $this->execute("CALL {$name}({$placeholders})", $params);
    }
}
