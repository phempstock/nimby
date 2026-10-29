<?php
declare(strict_types=1);

/**
 * Short status report for the server, used by deploy/ionos.sh.
 *
 *   php bin/healthcheck.php
 *
 * Prints nothing sensitive (no host names, user names or paths), because its
 * output appears in the public GitHub Actions log.
 */

use Nimby\Storage\Database;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$lines = [];
$ok = true;

$extensions = array_map(
    static fn(string $e) => $e . (extension_loaded($e) ? ' yes' : ' MISSING'),
    ['curl', 'pdo_mysql', 'json', 'mbstring', 'openssl']
);
$lines[] = 'PHP extensions: ' . implode(', ', $extensions);

try {
    $config = nimby_config();
} catch (Throwable $e) {
    echo implode("\n", $lines), "\nconfig.php: missing\n";
    exit(1);
}

try {
    $pdo = Database::connect($config['db']);
    $lines[] = 'Database: connected (' . Database::version($pdo) . ')';
} catch (PDOException $e) {
    echo implode("\n", $lines), "\nDatabase: connection failed (" . Database::describeError($e) . ")\n";
    exit(1);
}

try {
    $active = (int) $pdo->query('SELECT COUNT(*) FROM applications WHERE removed_at IS NULL')->fetchColumn();
    $lines[] = "Applications stored: {$active}";
    $run = $pdo->query('SELECT started_at, status, fetched, inserted, updated, removed FROM sync_runs ORDER BY id DESC LIMIT 1')->fetch();
    $lines[] = $run
        ? sprintf('Last sync: %s UTC, %s (fetched %s, new %s, changed %s, removed %s)',
            $run['started_at'], $run['status'], $run['fetched'] ?? '-', $run['inserted'] ?? '-',
            $run['updated'] ?? '-', $run['removed'] ?? '-')
        : 'Last sync: never';
    if ($run && $run['status'] !== 'ok') {
        $ok = false;
    }
    $since = gmdate('Y-m-d H:i:s', time() - 4 * 86400);
    $recent = $pdo->prepare('SELECT started_at, status FROM sync_runs WHERE started_at >= ? ORDER BY id');
    $recent->execute([$since]);
    $runs = array_map(fn($r) => substr($r['started_at'], 5, 11) . ' ' . $r['status'], $recent->fetchAll());
    $lines[] = 'Sync runs in the last 4 days (UTC): ' . ($runs ? implode('; ', $runs) : 'none');
} catch (PDOException $e) {
    $lines[] = 'Tables: not created yet (run the setup task)';
    $ok = false;
}

try {
    $c = (new \Nimby\Subscribers\SubscriberStore($pdo))->counts();
    $open = (new \Nimby\Storage\Settings($pdo))->signupOpen();
    $lines[] = sprintf('Weekly email: sign-up %s; %d subscribers, %d awaiting confirmation',
        $open ? 'open' : 'closed', $c['active'], $c['pending']);
    $d = $pdo->query('SELECT created_at, status, applications FROM digests ORDER BY id DESC LIMIT 1')->fetch();
    $lines[] = $d ? sprintf('Last digest: %s UTC, %s, %d applications', $d['created_at'], $d['status'], $d['applications'])
                  : 'Last digest: none yet';
    $lines[] = 'Mail settings: ' . (empty($config['mail']['password']) ? 'not set (upload config.mail.php)' : 'set');
} catch (PDOException $e) {
    $lines[] = 'Weekly email: tables not created yet';
}

$meta = rtrim($config['paths']['public_data'], '/') . '/meta.json';
if (is_file($meta)) {
    $m = json_decode((string) file_get_contents($meta), true);
    $lines[] = sprintf('Map data: %d pins, refreshed %s', $m['applications'] ?? 0, $m['synced_at'] ?? '?');
} else {
    $lines[] = 'Map data: not written yet';
}

echo implode("\n", $lines), "\n";
exit($ok ? 0 : 1);
