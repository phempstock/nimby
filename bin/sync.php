<?php
declare(strict_types=1);

/**
 * Nightly sync. Run from cron on IONOS, for example:
 *
 *   0 5 * * * /usr/bin/php8.3 /path/to/nimby/bin/sync.php
 *
 * Options:
 *   --refresh-boundary   re-download The Park outline from the council
 *   --config=PATH        use a different config file
 *
 * Exit code 0 on success, 1 on failure (cron emails any output to MAILTO).
 */

use Nimby\Council\ArcGisClient;
use Nimby\Export\GeoJsonExporter;
use Nimby\Http\CurlHttpClient;
use Nimby\Storage\ApplicationStore;
use Nimby\Storage\Database;
use Nimby\Subscribers\SubscriberStore;
use Nimby\Support\Alerter;
use Nimby\SyncJob;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Command line only.\n");
}

require dirname(__DIR__) . '/bootstrap.php';

/** Email the admin only when there has been no successful sync for this long. */
const ALERT_AFTER_HOURS = 30;

$options = getopt('', ['refresh-boundary', 'config:']);
$alerter = null;

try {
    $config = nimby_config($options['config'] ?? null);
    $alerter = new Alerter($config['alerts']['admin_email'] ?? null, $config['alerts']['from_email'] ?? null);

    // Stop two runs overlapping (e.g. a manual run during the cron run).
    $cacheDir = $config['paths']['cache'];
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0755, true);
    }
    $lock = fopen($cacheDir . '/sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        fwrite(STDERR, "Another sync is already running.\n");
        exit(1);
    }

    try {
        $pdo = Database::connect($config['db']);
    } catch (PDOException $e) {
        throw new RuntimeException('Could not connect to the database (' . Database::describeError($e) . ')');
    }
    $council = new ArcGisClient(
        new CurlHttpClient((int) ($config['council']['timeout_seconds'] ?? 60)),
        $config['council']['planning_layer_url'],
        $config['council']['conservation_layer_url'],
        $config['council']['geometry_service_url']
            ?? 'https://maps164.nottinghamcity.gov.uk/server/rest/services/Utilities/Geometry/GeometryServer'
    );
    $job = new SyncJob(
        $council,
        new ApplicationStore($pdo),
        new GeoJsonExporter($config['paths']['public_data'], $config['council']['public_access_url']),
        $pdo,
        $config['council']['conservation_area_name'],
        $cacheDir,
        (float) ($config['sync']['min_ratio_of_known'] ?? 0.8),
        // Margin around the conservation area, agreed by the committee: 50 m.
        (float) ($config['council']['buffer_metres'] ?? 50)
    );

    // About 6,000 applications are held in memory during a run; allow headroom.
    ini_set('memory_limit', '512M');

    $summary = $job->run(isset($options['refresh-boundary']));
    echo implode("\n", $summary['log']), "\n";

    // Delete what the privacy notice promises to delete (stale sign-ups, old unsubscribes).
    try {
        $purged = (new SubscriberStore($pdo))->purge(gmdate('Y-m-d H:i:s'));
        if ($purged['pending'] || $purged['unsubscribed']) {
            printf("Deleted %d unconfirmed sign-ups and %d unsubscribed addresses, as the privacy notice says\n",
                $purged['pending'], $purged['unsubscribed']);
        }
    } catch (PDOException $e) {
        echo "Subscriber clean-up skipped: email tables not created yet\n";
    }
    printf("Peak memory %d MB\n", (int) round(memory_get_peak_usage(true) / 1048576));
    exit(0);
} catch (Throwable $e) {
    $lines = isset($job) ? $job->log() : ['FAILED: ' . $e->getMessage()];
    $report = implode("\n", $lines) . "\n\nThe map is still showing the previous data.\n";
    fwrite(STDERR, $report);

    // The council's service has short outages, and the sync runs twice a day, so one
    // failure is not news. Alert only when there has been no successful sync for 30 hours.
    $lastOk = null;
    try {
        $lastOk = isset($pdo) ? $pdo->query("SELECT MAX(started_at) FROM sync_runs WHERE status = 'ok'")->fetchColumn() : null;
    } catch (Throwable $ignored) {
    }
    $hours = $lastOk ? (time() - strtotime($lastOk . ' UTC')) / 3600 : INF;
    if ($hours >= ALERT_AFTER_HOURS) {
        $alerter?->send('Sync failing', $report . ($lastOk
            ? sprintf("\nNo successful sync for %d hours (last at %s UTC).\n", $hours, $lastOk) : ''));
    } else {
        fwrite(STDERR, sprintf("No alert sent: the last successful sync was %.0f hours ago.\n", $hours));
    }
    exit(1);
}
