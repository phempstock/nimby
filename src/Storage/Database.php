<?php
declare(strict_types=1);

namespace Nimby\Storage;

use PDO;

final class Database
{
    public static function connect(array $dbConfig): PDO
    {
        $pdo = new PDO(
            $dbConfig['dsn'],
            $dbConfig['user'] ?? null,
            $dbConfig['password'] ?? null,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            // Store and compare timestamps in UTC regardless of server settings.
            $pdo->exec("SET time_zone = '+00:00'");
        }
        return $pdo;
    }

    /** Apply a migration file (statements separated by semicolons at line ends). */
    public static function migrate(PDO $pdo, string $sqlFile): void
    {
        $sql = (string) file_get_contents($sqlFile);
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        foreach (preg_split('/;\s*$/m', (string) $sql) as $statement) {
            if (trim($statement) !== '') {
                $pdo->exec($statement);
            }
        }
    }
}
