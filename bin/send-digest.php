<?php
declare(strict_types=1);

/**
 * The Monday digest. Run from cron on IONOS, e.g. 08:03 server time (07:03 UK):
 *
 *   3 8 * * 1 /usr/bin/php8.5-cli /path/to/nimby/bin/send-digest.php
 *
 * Options:
 *   --test       send this week's digest only to the site's own mailbox, marked [TEST];
 *                nothing is recorded and no subscriber is emailed
 *   --dry-run    say what would be sent, send nothing
 *   --config=PATH
 *
 * Output never includes subscribers' addresses (it can appear in public logs).
 */

use Nimby\Digest\DigestBuilder;
use Nimby\Digest\DigestSender;
use Nimby\Mail\LogMailer;
use Nimby\Mail\MailerFactory;
use Nimby\Storage\Database;
use Nimby\Storage\Settings;
use Nimby\Subscribers\SignupService;
use Nimby\Subscribers\SubscriberStore;
use Nimby\Support\Alerter;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

$options = getopt('', ['test', 'dry-run', 'config:']);
$alerter = null;

try {
    $config = nimby_config($options['config'] ?? null);
    $alerter = new Alerter($config['alerts']['admin_email'] ?? null, $config['alerts']['from_email'] ?? null);

    $cacheDir = $config['paths']['cache'];
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $lock = fopen($cacheDir . '/digest.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another digest run is in progress.\n");
        exit(1);
    }

    try {
        $pdo = Database::connect($config['db']);
    } catch (PDOException $e) {
        throw new RuntimeException('Could not connect to the database (' . Database::describeError($e) . ')');
    }

    $dryRun = isset($options['dry-run']);
    $mailer = $dryRun ? new LogMailer() : MailerFactory::fromConfig($config);
    $store = new SubscriberStore($pdo);
    $signup = new SignupService($store, new Settings($pdo), $mailer, $config['site']['url']);
    $boundaryFile = $config['paths']['public_data'] . '/park-boundary.geojson';
    $coreArea = is_file($boundaryFile) ? json_decode((string) file_get_contents($boundaryFile), true) : null;
    $mail = $config['mail'] ?? [];

    $sender = new DigestSender(
        $pdo, $store, $signup, $mailer,
        new DigestBuilder($config['site']['url'], $config['council']['public_access_url'],
            (float) ($config['council']['buffer_metres'] ?? 50), $config['site']['organisation']),
        $coreArea,
        (int) ($mail['max_per_run'] ?? 200),
        $dryRun ? 0 : (int) ($mail['pause_ms'] ?? 500)
    );

    $now = gmdate('Y-m-d H:i:s');
    if (isset($options['test'])) {
        $to = $config['alerts']['admin_email'] ?? null;
        if (!$to) {
            throw new RuntimeException('No admin_email in config to send the test to');
        }
        $summary = $sender->test($now, $to);
    } elseif ($dryRun) {
        $pdo->beginTransaction();       // work it all out, then undo
        $summary = $sender->run($now);
        $pdo->rollBack();
        $summary['log'][] = 'Dry run: nothing was sent or recorded';
    } else {
        $summary = $sender->run($now);
    }

    echo implode("\n", $summary['log']), "\n";
    if (in_array($summary['status'], ['failed', 'partial'], true)) {
        $alerter->send('Weekly digest not finished', implode("\n", $summary['log'])
            . "\n\nThe next run will carry on where this one stopped, without emailing anyone twice.\n");
        exit(1);
    }
    exit(0);
} catch (Throwable $e) {
    $report = 'FAILED: ' . $e->getMessage() . "\n";
    fwrite(STDERR, $report);
    $alerter?->send('Weekly digest failed', $report);
    exit(1);
}
