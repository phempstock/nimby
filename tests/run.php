<?php
declare(strict_types=1);

/**
 * NIMBY test suite. No framework needed:
 *
 *   php tests/run.php
 *
 * Uses an in-memory SQLite database and a fake council server.
 */

use Nimby\Council\ApplicationRecord;
use Nimby\Council\AppType;
use Nimby\Council\ArcGisClient;
use Nimby\Council\Status;
use Nimby\Export\GeoJsonExporter;
use Nimby\Storage\ApplicationStore;
use Nimby\Storage\Database;
use Nimby\SyncJob;
use Nimby\Tests\FakeHttpClient;

require dirname(__DIR__) . '/bootstrap.php';
require __DIR__ . '/FakeHttpClient.php';

$failures = 0;
$passes = 0;

function check(bool $condition, string $message): void
{
    global $failures, $passes;
    if ($condition) {
        $passes++;
    } else {
        $failures++;
        echo "  FAIL: {$message}\n";
    }
}

function test(string $name, callable $fn): void
{
    echo "- {$name}\n";
    try {
        $fn();
    } catch (Throwable $e) {
        check(false, get_class($e) . ': ' . $e->getMessage() . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}

function ms(string $date): int
{
    return (int) (strtotime($date . ' 00:00:00 UTC') * 1000);
}

/** A council feature as the ArcGIS server returns it. Values taken from The Park on 26 Oct 2026. */
function feature(int $oid, string $keyval, string $ref, string $address, string $proposal, ?string $received,
                 ?string $status, ?string $decision = null, ?string $decisionDate = null, float $x = -1.1601, float $y = 52.9514): array
{
    return [
        'attributes' => [
            'OBJECTID' => $oid, 'KEYVAL' => $keyval, 'APP_REF' => $ref, 'APP_ADDRESS' => $address,
            'PROPOSAL' => $proposal, 'DATE_APP_RECEIVED' => $received ? ms($received) : null,
            'DATE_APP_VALID' => $received ? ms($received) : null, 'APP_STATUS' => $status,
            'DECISION' => $decision, 'DECISION_DATE' => $decisionDate ? ms($decisionDate) : null,
        ],
        'geometry' => ['x' => $x, 'y' => $y],
    ];
}

function sampleFeatures(): array
{
    return [
        feature(203202, 'TN0707LYJC500', '26/01848/PWC', '1 Clumber Road East, Nottingham, Nottingham City, NG7 1BD',
            'T1 - Cherry: Reduce overhanging branches to 1 Clumber Road East and adjacent property to the fence/boundary line to appropriate growth points',
            '2026-10-16', 'Pending Consideration', null, null, -1.16014, 52.95141),
        feature(202584, 'TMY9T6LYJ4600', '26/01832/PWC', '3 Lenton Road, Nottingham, Nottingham City, NG7 1DP',
            'Tree is a cherry tree and works to be carried out is a 2 metre crown reduction', '2026-10-15', 'Pending Consideration',
            null, null, -1.15800, 52.94950),
        feature(203203, 'TN9ISDLY0ON00', '26/01860/PWC', 'Penrhyn House, Clumber Road East, Nottingham, NG7 1BD',
            "T10 Hawthorn. Remove.\rThe base is rotted and higher up the trunk sounds hollow.", '2026-10-15', 'Pending Consideration',
            null, null, -1.16100, 52.95200),
        feature(202577, 'TM696WLYGVS00', '26/01757/PWC', 'Sutherland House , 25 Cavendish Crescent South, Nottingham, Nottingham City, NG7 1ED',
            'T1 - Acer - Full crown reduction by up to 1m in order to create a balanced shape.', '2026-09-30', 'Pending Consideration',
            null, null, -1.15950, 52.95050),
        feature(202561, 'TMUQ3KLY0ON00', '26/01821/5DAY', 'Willoughby House, Clumber Road East, The Park, Nottingham, NG7 1BD',
            "Making safe of a fir tree.\rWorks required is for the removal of the tree.", '2026-09-28', 'Pending Decision',
            '5 Day Noticed Accepted', null, -1.16050, 52.95180),
    ];
}

/** Fresh database, temporary directories and a sync job wired to the fake server. */
function harness(): array
{
    $pdo = testConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (getenv('NIMBY_TEST_DSN')) {
        // Shared test database: start every test from empty tables.
        foreach (['application_events', 'applications', 'sync_runs'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
    Database::migrate($pdo, dirname(__DIR__) . '/migrations/' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '/001_applications.sql');
    $dir = sys_get_temp_dir() . '/nimby-test-' . bin2hex(random_bytes(4));
    mkdir($dir . '/public', 0755, true);
    $http = new FakeHttpClient();
    $council = new ArcGisClient($http,
        'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Planning/MapServer/13',
        'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Local_Plan_2020/MapServer/49');
    $store = new ApplicationStore($pdo);
    $exporter = new GeoJsonExporter($dir . '/public', 'https://publicaccess.nottinghamcity.gov.uk/online-applications/applicationDetails.do?activeTab=summary&keyVal=');
    $job = new SyncJob($council, $store, $exporter, $pdo, 'The Park', $dir . '/cache', 0.8);
    return compact('pdo', 'dir', 'http', 'store', 'job');
}

function readJson(string $path): array
{
    return json_decode((string) file_get_contents($path), true);
}

/**
 * In-memory SQLite by default. Set NIMBY_TEST_DSN (plus NIMBY_TEST_USER and
 * NIMBY_TEST_PASSWORD) to run the same tests against MySQL or MariaDB.
 */
function testConnection(): PDO
{
    return Database::connect([
        'dsn'      => getenv('NIMBY_TEST_DSN') ?: 'sqlite::memory:',
        'user'     => getenv('NIMBY_TEST_USER') ?: null,
        'password' => getenv('NIMBY_TEST_PASSWORD') ?: null,
    ]);
}

$probe = testConnection();
$probeDriver = $probe->getAttribute(PDO::ATTR_DRIVER_NAME);
echo 'Database: ', $probeDriver, ' ', $probe->query($probeDriver === 'sqlite' ? 'SELECT sqlite_version()' : 'SELECT VERSION()')->fetchColumn(), "\n\n";
$probe = null;

// ---------------------------------------------------------------------------

test('Record normalisation', function () {
    $r = ApplicationRecord::fromFeature(sampleFeatures()[2]);
    check($r['keyval'] === 'TN9ISDLY0ON00', 'keyval kept');
    check($r['app_type'] === 'PWC', 'type taken from reference suffix');
    check($r['date_received'] === '2026-10-15', 'date converted from milliseconds');
    check(!str_contains($r['proposal'], "\r"), 'carriage returns normalised');
    check($r['address'] === 'Penrhyn House, Clumber Road East, Nottingham, NG7 1BD', 'address trimmed');
    check(abs($r['lat'] - 52.952) < 1e-9 && abs($r['lng'] + 1.161) < 1e-9, 'coordinates mapped x=lng, y=lat');
    check(ApplicationRecord::fromFeature(['attributes' => ['APP_REF' => '26/1/X']]) === null, 'feature with no key is rejected');
});

test('Status categories, types and slugs', function () {
    check(Status::category('Pending Consideration', null) === Status::PENDING, 'pending');
    check(Status::category('Granted conditional permission', 'Grant conditional permission') === Status::GRANTED, 'granted');
    check(Status::category('Refused consent', null) === Status::REFUSED, 'refused');
    check(Status::category('Application withdrawn', null) === Status::WITHDRAWN, 'withdrawn');
    check(Status::category(null, 'Approve') === Status::GRANTED, 'falls back to decision when status is empty');
    check(Status::category(null, null, null) === Status::PENDING, 'no status, no decision = pending');
    check(Status::category('Something new', null) === Status::OTHER, 'unknown wording = other');
    check(!Status::isKnown('Something new') && Status::isKnown('TPO made'), 'isKnown');
    check(AppType::label('PWC') === 'Works to trees in a conservation area', 'type label');
    check(AppType::label('ZZZ') === 'ZZZ', 'unknown type shown as code');
    check(AppType::isTreeWork('5DAY') && !AppType::isTreeWork('PFUL3'), 'tree work flag');
    check(GeoJsonExporter::slug('26/01848/PWC') === '26-01848-PWC', 'slug for deep links');
});

test('First run back-fills and writes the map files', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir] = harness();
    $http->pages = [sampleFeatures()];
    $summary = $job->run(false, '2026-10-26 05:00:00');

    check($summary['status'] === 'ok', 'run succeeded');
    check($summary['inserted'] === 5, 'five applications inserted');
    check((int) $pdo->query('SELECT SUM(backfilled) FROM applications')->fetchColumn() === 5, 'all marked back-filled');
    check((int) $pdo->query('SELECT COUNT(*) FROM application_events')->fetchColumn() === 0, 'no events on first run');

    $geo = readJson($dir . '/public/applications.geojson');
    check(count($geo['features']) === 5, 'five pins in GeoJSON');
    $first = $geo['features'][0];
    check($first['properties']['ref'] === '26/01848/PWC', 'newest application first');
    check($first['geometry']['coordinates'] === [-1.16014, 52.95141], 'GeoJSON coordinates are [lng, lat]');
    check($first['properties']['url'] === 'https://publicaccess.nottinghamcity.gov.uk/online-applications/applicationDetails.do?activeTab=summary&keyVal=TN0707LYJC500', 'council link uses KEYVAL');
    check($first['properties']['category'] === 'pending' && $first['properties']['trees'] === true, 'category and tree flag');

    $meta = readJson($dir . '/public/meta.json');
    check($meta['applications'] === 5 && $meta['synced_at'] === '2026-10-26 05:00:00Z', 'meta.json written');
    check(is_file($dir . '/public/park-boundary.geojson'), 'boundary outline written for the map');
    check(is_file($dir . '/cache/boundary-the-park.json'), 'boundary cached');

    $post = array_values(array_filter($http->requests, fn($r) => $r[0] === 'POST'))[0];
    check($post[2]['geometryType'] === 'esriGeometryPolygon' && $post[2]['inSR'] === '27700', 'queried with the Park polygon in British National Grid');
    check($post[2]['outSR'] === '4326', 'asked for lat/long back');

    $run = $pdo->query('SELECT * FROM sync_runs')->fetch();
    check($run['status'] === 'ok' && (int) $run['fetched'] === 5, 'run logged');
});

test('Second run records changes, new and removed applications', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir] = harness();
    $http->pages = [sampleFeatures()];
    $job->run(false, '2026-10-26 05:00:00');
    $requestsAfterFirst = count($http->requests);

    $next = sampleFeatures();
    // 26/01757/PWC decided
    $next[3]['attributes']['APP_STATUS'] = 'No objection';
    $next[3]['attributes']['DECISION'] = 'No objection';
    $next[3]['attributes']['DECISION_DATE'] = ms('2026-10-26');
    // 26/01821/5DAY no longer in the feed
    unset($next[4]);
    // A brand-new application
    $next[] = feature(203300, 'TNEWKEY000000', '26/01900/PFUL3', '10 Park Terrace, Nottingham, NG1 5DN',
        'Erection of a single storey rear extension', '2026-10-26', 'Pending Consideration', null, null, -1.1575, 52.9530);
    $http->pages = [array_values($next)];

    $summary = $job->run(false, '2026-10-27 05:00:00');
    check($summary['inserted'] === 1 && $summary['updated'] === 1 && $summary['removed'] === 1, 'one new, one changed, one removed');

    $new = $pdo->query("SELECT * FROM applications WHERE keyval = 'TNEWKEY000000'")->fetch();
    check((int) $new['backfilled'] === 0 && $new['first_seen_at'] === '2026-10-27 05:00:00', 'new application not back-filled, first seen today');

    $events = $pdo->query("SELECT field, old_value, new_value FROM application_events WHERE keyval = 'TM696WLYGVS00' ORDER BY field")->fetchAll();
    check(array_column($events, 'field') === ['decision', 'decision_date', 'status'], 'status, decision and date changes logged');
    check($events[2]['old_value'] === 'Pending Consideration' && $events[2]['new_value'] === 'No objection', 'old and new status kept');

    $removed = $pdo->query("SELECT removed_at FROM applications WHERE keyval = 'TMUQ3KLY0ON00'")->fetchColumn();
    check($removed === '2026-10-27 05:00:00', 'missing application marked removed, not deleted');

    $geo = readJson($dir . '/public/applications.geojson');
    $refs = array_map(fn($f) => $f['properties']['ref'], $geo['features']);
    check(count($refs) === 5 && in_array('26/01900/PFUL3', $refs, true) && !in_array('26/01821/5DAY', $refs, true), 'map shows new, hides removed');

    $gets = array_filter(array_slice($http->requests, $requestsAfterFirst), fn($r) => str_contains($r[1], 'MapServer/49'));
    check(count($gets) === 0, 'boundary reused from cache on later runs');

    // Third run brings the removed one back.
    $http->pages = [sampleFeatures()];
    $summary = $job->run(false, '2026-10-28 05:00:00');
    check($summary['restored'] === 1, 'reappearing application restored');
});

test('Unchanged run makes no changes', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo] = harness();
    $http->pages = [sampleFeatures()];
    $job->run(false, '2026-10-26 05:00:00');
    $summary = $job->run(false, '2026-10-27 05:00:00');
    check($summary['inserted'] === 0 && $summary['updated'] === 0 && $summary['removed'] === 0, 'nothing reported');
    check((int) $pdo->query('SELECT COUNT(*) FROM application_events')->fetchColumn() === 0, 'no events');
    check($pdo->query("SELECT MIN(last_seen_at) FROM applications")->fetchColumn() === '2026-10-27 05:00:00', 'last seen updated');
});

test('Paging through more than one page of results', function () {
    ['job' => $job, 'http' => $http] = harness();
    $all = sampleFeatures();
    $http->pages = [array_slice($all, 0, 3), array_slice($all, 3)];
    $summary = $job->run(false, '2026-10-26 05:00:00');
    check($summary['fetched'] === 5, 'both pages read');
    $offsets = array_map(fn($r) => $r[2]['resultOffset'], array_filter($http->requests, fn($r) => $r[0] === 'POST'));
    check(array_values($offsets) === ['0', '2000'], 'second page requested at offset 2000');
});

test('Duplicate points for one application are ignored', function () {
    ['job' => $job, 'http' => $http] = harness();
    $features = sampleFeatures();
    $dup = $features[0];
    $dup['attributes']['OBJECTID'] = 999999;
    $dup['geometry'] = ['x' => -1.17, 'y' => 52.96];
    $features[] = $dup;
    $http->pages = [$features];
    $summary = $job->run(false, '2026-10-26 05:00:00');
    check($summary['fetched'] === 5 && $summary['inserted'] === 5, 'duplicate not inserted twice');
    check((bool) array_filter($summary['log'], fn($l) => str_contains($l, 'duplicate')), 'duplicate reported in log');
});

test('Renamed council field stops the sync and keeps old data', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir] = harness();
    $http->pages = [sampleFeatures()];
    $job->run(false, '2026-10-26 05:00:00');
    $before = file_get_contents($dir . '/public/applications.geojson');

    $http->layerFields = array_values(array_filter($http->layerFields, fn($f) => $f['name'] !== 'APP_STATUS'));
    $threw = false;
    try {
        $job->run(false, '2026-10-27 05:00:00');
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'APP_STATUS');
    }
    check($threw, 'missing field named in the error');
    check(file_get_contents($dir . '/public/applications.geojson') === $before, 'map file untouched');
    check($pdo->query('SELECT status FROM sync_runs ORDER BY id DESC')->fetchColumn() === 'failed', 'failed run logged');
});

test('Partial outage does not wipe the map', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir] = harness();
    $http->pages = [sampleFeatures()];
    $job->run(false, '2026-10-26 05:00:00');
    $before = file_get_contents($dir . '/public/applications.geojson');

    $http->pages = [array_slice(sampleFeatures(), 0, 2)]; // only 2 of 5 come back
    $threw = false;
    try {
        $job->run(false, '2026-10-27 05:00:00');
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'partial outage');
    }
    check($threw, 'sync refused');
    check((int) $pdo->query('SELECT COUNT(*) FROM applications WHERE removed_at IS NULL')->fetchColumn() === 5, 'nothing marked removed');
    check(file_get_contents($dir . '/public/applications.geojson') === $before, 'map file untouched');

    $http->pages = [[]];
    $threw = false;
    try {
        $job->run(false, '2026-10-28 05:00:00');
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'no applications');
    }
    check($threw, 'empty response refused');
});

test('Council server error is reported', function () {
    ['job' => $job, 'http' => $http] = harness();
    $http->planningError = ['code' => 500, 'message' => 'Unable to complete operation.'];
    $threw = false;
    try {
        $job->run(false, '2026-10-26 05:00:00');
    } catch (RuntimeException $e) {
        $threw = str_contains($e->getMessage(), 'Unable to complete operation');
    }
    check($threw, 'server error message passed on');
});

echo "\n{$passes} checks passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
