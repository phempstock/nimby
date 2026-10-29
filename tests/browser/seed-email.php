<?php
declare(strict_types=1);

/**
 * Seed the email walk-through site: 20 applications of history (never emailed)
 * and 5 new ones in The Park, plus the map files. Run from the site copy's root.
 */

use Nimby\Export\GeoJsonExporter;
use Nimby\Storage\ApplicationStore;
use Nimby\Storage\Database;

require getcwd() . '/bootstrap.php';

$config = nimby_config();
$pdo = Database::connect($config['db']);
$store = new ApplicationStore($pdo);

$record = static function (int $i, string $received) {
    return [
        'keyval' => sprintf('SEED%09d', $i), 'app_ref' => sprintf('%s/%05d/PFUL3', substr($received, 2, 2), $i),
        'app_type' => 'PFUL3', 'address' => (10 + $i) . ' Park Valley, Nottingham, Nottingham City, NG7 1BS',
        'proposal' => 'Single storey rear extension and replacement windows.', 'date_received' => $received,
        'date_valid' => $received, 'status' => 'Pending Consideration', 'decision' => null, 'decision_date' => null,
        'lat' => round(52.9500 + $i * 0.0001, 6), 'lng' => round(-1.1650 + $i * 0.0002, 6),
    ];
};

$now = time();
$history = [];
for ($i = 1; $i <= 20; $i++) {
    $history[] = $record($i, gmdate('Y-m-d', $now - (400 + $i) * 86400));
}
$store->sync($history, gmdate('Y-m-d H:i:s', $now - 30 * 86400), '9999-12-31');   // first run: history

$fresh = $history;
for ($i = 21; $i <= 25; $i++) {
    $fresh[] = $record($i, gmdate('Y-m-d', $now - ($i - 20) * 86400));
}
$store->sync($fresh, gmdate('Y-m-d H:i:s', $now - 86400), null);                  // yesterday: five new

$park = ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => ['NAME' => 'The Park'],
    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-1.1693, 52.9476], [-1.1559, 52.9476], [-1.1559, 52.9555], [-1.1693, 52.9555], [-1.1693, 52.9476]]]]]]];
$exporter = new GeoJsonExporter($config['paths']['public_data'], $config['council']['public_access_url']);
$exporter->writeBoundary($park);
$exporter->write($store->allActive(), gmdate('Y-m-d H:i:s'), $park, 0);
echo "Seeded 20 historical and 5 new applications\n";
