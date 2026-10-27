<?php
declare(strict_types=1);

/**
 * Create or update the database tables.
 *
 *   php bin/migrate.php
 *
 * Safe to run more than once: every statement uses CREATE ... IF NOT EXISTS.
 */

use Nimby\Storage\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['config:']);

try {
    $config = nimby_config($options['config'] ?? null);
} catch (RuntimeException $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

try {
    $pdo = Database::connect($config['db']);
} catch (PDOException $e) {
    fwrite(STDERR, 'Could not connect to the database (' . Database::describeError($e) . ").\n");
    exit(1);
}

$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
$dir = dirname(__DIR__) . '/migrations/' . ($driver === 'sqlite' ? 'sqlite' : 'mysql');

$files = glob($dir . '/*.sql') ?: [];
sort($files);
foreach ($files as $file) {
    Database::migrate($pdo, $file);
    echo 'Applied ', basename($file), "\n";
}
echo "Database is up to date ({$driver} " . Database::version($pdo) . ").\n";
