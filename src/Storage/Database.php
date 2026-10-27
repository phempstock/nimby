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

    /**
     * A plain-English connection error with no host or user names in it,
     * e.g. "error 1045: wrong username or password".
     */
    public static function describeError(\Throwable $e): string
    {
        $code = preg_match('/\[(\d{4})\]/', $e->getMessage(), $m) ? $m[1] : (string) $e->getCode();
        $hint = [
            '1044' => 'the user has no access to this database',
            '1045' => 'wrong username or password',
            '1049' => 'database name not found',
            '2002' => 'database host not reachable',
            '2005' => 'database host name not recognised',
            '2006' => 'the database server went away',
        ][$code] ?? 'check the database settings in config.php';
        return "error {$code}: {$hint}";
    }

    /** Server version, e.g. "11.4.5-MariaDB-log" or "3.45.1" for SQLite. */
    public static function version(PDO $pdo): string
    {
        $sql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()';
        return (string) $pdo->query($sql)->fetchColumn();
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
