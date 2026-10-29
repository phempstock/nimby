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
use Nimby\Support\Geo;
use Nimby\Digest\DigestBuilder;
use Nimby\Digest\DigestSender;
use Nimby\Mail\LogMailer;
use Nimby\Storage\Settings;
use Nimby\Subscribers\SignupService;
use Nimby\Subscribers\SubscriberStore;
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
function harness(float $bufferMetres = 0): array
{
    $pdo = testConnection();
    $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    if (getenv('NIMBY_TEST_DSN')) {
        // Shared test database: start every test from empty tables.
        foreach (['application_events', 'applications', 'sync_runs', 'settings', 'subscribers', 'signup_attempts', 'digests', 'digest_sends'] as $table) {
            $pdo->exec("DROP TABLE IF EXISTS {$table}");
        }
    }
    $migrations = glob(dirname(__DIR__) . '/migrations/' . ($driver === 'sqlite' ? 'sqlite' : 'mysql') . '/*.sql');
    sort($migrations);
    foreach ($migrations as $file) {
        Database::migrate($pdo, $file);
    }
    $dir = sys_get_temp_dir() . '/nimby-test-' . bin2hex(random_bytes(4));
    mkdir($dir . '/public', 0755, true);
    $http = new FakeHttpClient();
    $council = new ArcGisClient($http,
        'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Planning/MapServer/13',
        'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Local_Plan_2020/MapServer/49',
        'https://maps164.nottinghamcity.gov.uk/server/rest/services/Utilities/Geometry/GeometryServer');
    $store = new ApplicationStore($pdo);
    $exporter = new GeoJsonExporter($dir . '/public', 'https://publicaccess.nottinghamcity.gov.uk/online-applications/applicationDetails.do?activeTab=summary&keyVal=');
    $job = new SyncJob($council, $store, $exporter, $pdo, 'The Park', $dir . '/cache', 0.8, $bufferMetres);
    $rebuild = fn(float $metres) => new SyncJob($council, $store, $exporter, $pdo, 'The Park', $dir . '/cache', 0.8, $metres);
    return compact('pdo', 'dir', 'http', 'store', 'job', 'rebuild');
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
    $messy = sampleFeatures()[0];
    $messy['attributes']['APP_ADDRESS'] = "Flat 1 \x16 2, 19 Lenton Road, Nottingham";
    $messy['attributes']['PROPOSAL'] = "Line one\r\nLine  two\x07";
    $m = ApplicationRecord::fromFeature($messy);
    check($m['address'] === 'Flat 1 2, 19 Lenton Road, Nottingham', 'control characters removed from addresses (' . json_encode($m['address']) . ')');
    check($m['proposal'] === "Line one\nLine two", 'line breaks kept, control characters and doubled spaces removed (' . json_encode($m['proposal']) . ')');
});

test('Status categories, types and slugs', function () {
    check(Status::category('Pending Consideration', null) === Status::PENDING, 'pending');
    check(Status::category('Granted conditional permission', 'Grant conditional permission') === Status::GRANTED, 'granted');
    check(Status::category('Refused consent', null) === Status::REFUSED, 'refused');
    check(Status::category('Application withdrawn', null) === Status::WITHDRAWN, 'withdrawn');
    check(Status::category('Application lapsed', null) === Status::WITHDRAWN, 'lapsed counts as withdrawn (seen in the 50 m margin)');
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
    check($first['properties']['first_seen'] === null, 'back-filled history has no first-seen date on the map');

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
    $newPin = array_values(array_filter($geo['features'], fn($f) => $f['properties']['ref'] === '26/01900/PFUL3'))[0];
    check($newPin['properties']['first_seen'] === '2026-10-27', 'genuinely new application carries its first-seen date');

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

/** Two applications just outside the test conservation area (west edge is -1.165). */
function marginFeatures(): array
{
    return [
        feature(300001, 'MARGINOLD0001', '24/00500/PFUL3', '224 Derby Road, Nottingham, NG7 1NQ',
            '7 bedroom Sui Generis House in Multiple Occupation', '2024-03-12', 'Granted conditional permission',
            'Grant conditional permission', '2024-05-01', -1.1654, 52.9520),
        feature(300002, 'MARGINNEW0002', '26/01870/PFUL3', '16 Castle Boulevard, Nottingham, NG7 1FL',
            'Change of use from Class E to Class F1(a)', '2026-10-20', 'Pending Consideration',
            null, null, -1.1600, 52.9477),
    ];
}

test('Geometry helpers', function () {
    $square = ['type' => 'FeatureCollection', 'features' => [['type' => 'Feature', 'properties' => [], 'geometry' => [
        'type' => 'Polygon', 'coordinates' => [
            [[0, 0], [10, 0], [10, 10], [0, 10], [0, 0]],
            [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]],
        ]]]]];
    check(Geo::pointInGeoJson(2, 2, $square), 'point inside polygon');
    check(!Geo::pointInGeoJson(5, 5, $square), 'point in a hole is outside');
    check(!Geo::pointInGeoJson(12, 5, $square), 'point beyond the polygon is outside');
    // ArcGIS rings: outer clockwise, hole anticlockwise; two separate outer rings -> MultiPolygon
    $gj = Geo::esriRingsToGeoJson([
        [[0, 0], [0, 10], [10, 10], [10, 0], [0, 0]],
        [[4, 4], [6, 4], [6, 6], [4, 6], [4, 4]],
        [[20, 0], [20, 5], [25, 5], [25, 0], [20, 0]],
    ]);
    $g = $gj['features'][0]['geometry'];
    check($g['type'] === 'MultiPolygon' && count($g['coordinates']) === 2 && count($g['coordinates'][0]) === 2, 'ArcGIS rings become a MultiPolygon with its hole');
    check(Geo::pointInGeoJson(22, 2, $gj) && !Geo::pointInGeoJson(5, 5, $gj), 'converted outline behaves');
});

test('A 50 m margin: council query, outline and inside flags', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'dir' => $dir, 'store' => $store] = harness(50);
    $http->pages = [sampleFeatures()];
    $http->bufferedPages = [array_merge(sampleFeatures(), marginFeatures())];
    $summary = $job->run(false, '2026-10-29 05:00:00');

    $query = array_values(array_filter($http->requests, fn($r) => str_contains($r[1], 'MapServer/13/query')))[0][2];
    check(($query['distance'] ?? null) === '50' && ($query['units'] ?? null) === 'esriSRUnit_Meter', 'council asked for applications within 50 m');
    check($summary['inserted'] === 7, 'margin applications stored too (' . $summary['inserted'] . ')');
    check((bool) array_filter($summary['log'], fn($l) => str_contains($l, 'within 50 m of it')), 'log describes the area');

    $buffer = array_values(array_filter($http->requests, fn($r) => str_ends_with($r[1], '/buffer')))[0][2];
    check($buffer['distances'] === '50' && $buffer['unit'] === '9001' && $buffer['outSR'] === '4326', 'outline requested from the geometry service in metres');
    $watch = readJson($dir . '/public/watch-area.geojson');
    check($watch['features'][0]['geometry']['type'] === 'Polygon', 'watch-area outline written for the map');

    $geo = readJson($dir . '/public/applications.geojson');
    $inside = [];
    foreach ($geo['features'] as $f) {
        $inside[$f['properties']['ref']] = $f['properties']['inside'];
    }
    check($inside['26/01848/PWC'] === true, 'application in The Park flagged inside');
    check($inside['24/00500/PFUL3'] === false && $inside['26/01870/PFUL3'] === false, 'margin applications flagged outside');
    $meta = readJson($dir . '/public/meta.json');
    check($meta['outside'] === 2 && (float) $meta['buffer_metres'] === 50.0, 'meta.json records the margin');
    check($store->getSetting('area') === 'The Park|50', 'area remembered');

    // A second run reuses the cached outline.
    $before = count($http->requests);
    $job->run(false, '2026-10-30 05:00:00');
    check(count(array_filter(array_slice($http->requests, $before), fn($r) => str_ends_with($r[1], '/buffer'))) === 0, 'outline cached between runs');
});

test('Widening the area on an existing site', function () {
    ['job' => $job, 'http' => $http, 'pdo' => $pdo, 'store' => $store, 'rebuild' => $rebuild] = harness(0);
    $http->pages = [sampleFeatures()];
    $job->run(false, '2026-10-27 05:00:00');                 // the site as it was: no margin, no settings row
    $pdo->exec("DELETE FROM settings");                        // as installed before this change

    $http->bufferedPages = [array_merge(sampleFeatures(), marginFeatures())];
    $summary = $rebuild(50)->run(false, '2026-10-29 05:00:00');
    check($summary['inserted'] === 2 && $summary['backfilled'] === 1, 'two margin applications added, one as history (' . json_encode([$summary['inserted'], $summary['backfilled']]) . ')');
    $flags = $pdo->query("SELECT keyval, backfilled FROM applications WHERE keyval LIKE 'MARGIN%' ORDER BY keyval")->fetchAll(PDO::FETCH_KEY_PAIR);
    check((int) $flags['MARGINOLD0001'] === 1, 'old margin application (2024) will not be emailed');
    check((int) $flags['MARGINNEW0002'] === 0, 'recent margin application (9 days old) counts as new');
    check((bool) array_filter($summary['log'], fn($l) => str_contains($l, 'Area changed from "The Park + 0 m" to "The Park + 50 m"')), 'area change logged');

    $summary = $rebuild(50)->run(false, '2026-10-30 05:00:00');
    check($summary['inserted'] === 0 && $summary['backfilled'] === 0, 'next run is an ordinary run');

    // Narrowing again is allowed even though fewer applications come back, and they are kept as removed.
    $http->pages = [array_slice(sampleFeatures(), 0, 3)];
    $summary = $rebuild(0)->run(false, '2026-10-31 05:00:00');
    check($summary['status'] === 'ok' && $summary['removed'] === 4, 'narrowing the area is not mistaken for an outage (' . $summary['removed'] . ' removed)');
});

test('Geometry service down: the sync carries on', function () {
    ['job' => $job, 'http' => $http, 'dir' => $dir] = harness(50);
    $http->pages = [sampleFeatures()];
    $http->bufferError = ['code' => 500, 'message' => 'Geometry service unavailable'];
    $summary = $job->run(false, '2026-10-29 05:00:00');
    check($summary['status'] === 'ok', 'sync succeeded without the outline');
    check(!is_file($dir . '/public/watch-area.geojson'), 'no outline written');
    check((bool) array_filter($summary['log'], fn($l) => str_contains($l, 'Could not draw the 50 m outline')), 'problem noted in the log');
});


/** Email services on a harness database. */
function emailKit(PDO $pdo, bool $open = true): array
{
    $settings = new Settings($pdo);
    if ($open) {
        $settings->set('signup_open', '1');
    }
    $store = new SubscriberStore($pdo);
    $mailer = new LogMailer();
    $signup = new SignupService($store, $settings, $mailer, 'https://nimby.parknews.co.uk');
    return compact('settings', 'store', 'mailer', 'signup');
}

function tokenFrom(string $text): string
{
    preg_match('/confirm\.php\?token=([a-f0-9]+)/', $text, $m);
    return $m[1] ?? '';
}

function addActive(SignupService $signup, LogMailer $mailer, string $email, string $now, string $ip): void
{
    $signup->subscribe($email, null, true, '', $ip, $now);
    $signup->confirm(tokenFrom(end($mailer->sent)->text), $now);
}

test('Sign-up: closed, validation, honeypot', function () {
    ['pdo' => $pdo] = harness();
    ['signup' => $signup, 'mailer' => $mailer, 'settings' => $settings] = emailKit($pdo, false);
    check($signup->subscribe('a@example.com', null, true, '', '1.2.3.4', '2026-11-01 10:00:00')['code'] === 'closed', 'refused while sign-up is closed');
    $settings->set('signup_open', '1');
    check($signup->subscribe('not-an-email', null, true, '', '1.2.3.4', '2026-11-01 10:00:00')['code'] === 'invalid_email', 'bad address rejected');
    check($signup->subscribe('a@example.com', null, false, '', '1.2.3.4', '2026-11-01 10:00:00')['code'] === 'consent', 'consent required');
    $bot = $signup->subscribe('bot@example.com', null, true, 'http://spam', '1.2.3.4', '2026-11-01 10:00:00');
    check($bot['ok'] && $bot['message'] === SignupService::CHECK_INBOX, 'honeypot gets the normal reply');
    check(count($mailer->sent) === 0 && (int) $pdo->query('SELECT COUNT(*) FROM subscribers')->fetchColumn() === 0, 'but nothing is stored or sent');
});

test('Sign-up: double opt-in', function () {
    ['pdo' => $pdo] = harness();
    ['signup' => $signup, 'mailer' => $mailer, 'store' => $store] = emailKit($pdo);
    $r = $signup->subscribe('  Jane.Doe@Example.COM ', "Jane\x07 Doe", true, '', '1.2.3.4', '2026-11-01 10:00:00');
    check($r['ok'] && $r['message'] === SignupService::CHECK_INBOX, 'check-your-inbox reply');
    $row = $store->findByEmail('jane.doe@example.com');
    check($row && $row['status'] === 'pending' && $row['name'] === 'Jane Doe', 'stored as pending, lower-cased, name cleaned (' . json_encode($row['name'] ?? null) . ')');
    check(count($mailer->sent) === 1 && $mailer->sent[0]->to === 'jane.doe@example.com', 'confirmation email sent');
    $msg = $mailer->sent[0];
    check(str_contains($msg->html, 'Confirm my email address') && str_contains($msg->html, 'Hello Jane Doe'), 'confirmation email has a button and greeting');
    $token = tokenFrom($msg->text);
    check(strlen($token) === 48 && $row['confirm_hash'] === hash('sha256', $token), 'only a hash of the token is stored');

    $again = $signup->subscribe('jane.doe@example.com', null, true, '', '1.2.3.4', '2026-11-01 10:05:00');
    check($again['ok'] && count($mailer->sent) === 1, 'second sign-up within 10 minutes sends nothing more');

    check($signup->confirm($token, '2026-11-01 11:00:00')['code'] === 'confirmed', 'link confirms');
    check($store->findByEmail('jane.doe@example.com')['status'] === 'active', 'now active');
    check($signup->confirm($token, '2026-11-01 11:01:00')['code'] === 'invalid', 'link works once');
    check($signup->confirm('nonsense', '2026-11-01 11:01:00')['code'] === 'invalid', 'malformed token rejected');

    $signup->subscribe('JANE.DOE@example.com', null, true, '', '5.6.7.8', '2026-11-02 10:00:00');
    check(count($mailer->sent) === 1, 'already subscribed: same reply, no email, nothing revealed');
});

test('Sign-up: expiry, resend and rate limit', function () {
    ['pdo' => $pdo] = harness();
    ['signup' => $signup, 'mailer' => $mailer] = emailKit($pdo);
    $signup->subscribe('late@example.com', null, true, '', '1.1.1.1', '2026-11-01 10:00:00');
    $old = tokenFrom($mailer->sent[0]->text);
    check($signup->confirm($old, '2026-11-09 10:00:01')['code'] === 'expired', 'link expires after 7 days');
    $signup->subscribe('late@example.com', null, true, '', '1.1.1.1', '2026-11-01 10:11:00');
    check(count($mailer->sent) === 2, 'resent after 10 minutes');
    check($signup->confirm($old, '2026-11-01 10:12:00')['code'] === 'invalid', 'old link stops working once a new one is sent');
    check($signup->confirm(tokenFrom($mailer->sent[1]->text), '2026-11-01 10:12:00')['code'] === 'confirmed', 'new link works');

    for ($i = 0; $i < SignupService::MAX_PER_IP_PER_HOUR - 2; $i++) {
        $signup->subscribe("x{$i}@example.com", null, true, '', '1.1.1.1', '2026-11-01 10:20:00');
    }
    check($signup->subscribe('y@example.com', null, true, '', '1.1.1.1', '2026-11-01 10:21:00')['code'] === 'too_many', 'sixth sign-up in an hour from one address is refused');
    check($signup->subscribe('z@example.com', null, true, '', '2.2.2.2', '2026-11-01 10:21:00')['ok'], 'others are unaffected');
    check($signup->subscribe('y@example.com', null, true, '', '1.1.1.1', '2026-11-01 11:21:00')['ok'], 'allowed again an hour later');
    $hashes = $pdo->query('SELECT ip_hash FROM signup_attempts')->fetchAll(PDO::FETCH_COLUMN);
    check(!in_array('1.1.1.1', $hashes, true) && strlen($hashes[0]) === 64, 'IP addresses stored only as keyed hashes');
});

test('Unsubscribe and deletion', function () {
    ['pdo' => $pdo] = harness();
    ['signup' => $signup, 'mailer' => $mailer, 'store' => $store] = emailKit($pdo);
    addActive($signup, $mailer, 'leaver@example.com', '2026-11-01 10:00:00', '1.1.1.1');
    $id = (int) $store->findByEmail('leaver@example.com')['id'];
    $url = $signup->unsubscribeUrl($id);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
    check(str_starts_with($url, 'https://nimby.parknews.co.uk/unsubscribe.php?s=' . $id . '&t='), 'unsubscribe link');
    check(!$signup->unsubscribe($id, str_repeat('0', 32), '2026-11-02 10:00:00'), 'wrong token refused');
    check(!$signup->unsubscribe($id + 1, $q['t'], '2026-11-02 10:00:00'), 'token only works for its own subscriber');
    check($signup->unsubscribe($id, $q['t'], '2026-11-02 10:00:00') && $store->find($id)['status'] === 'unsubscribed', 'unsubscribed');
    check($signup->unsubscribe($id, $q['t'], '2026-11-02 10:05:00'), 'repeating it is harmless');

    addActive($signup, $mailer, 'stayer@example.com', '2026-10-01 10:00:00', '2.2.2.2');
    $signup->subscribe('never@example.com', null, true, '', '3.3.3.3', '2026-11-01 09:00:00');
    $p = $store->purge('2026-11-09 09:00:01');
    check($p['pending'] === 1 && $store->findByEmail('never@example.com') === null, 'unconfirmed sign-up deleted after 7 days');
    check($store->find($id) !== null, 'unsubscribed address kept for now');
    $p = $store->purge('2026-12-02 10:00:01');
    check($p['unsubscribed'] === 1 && $store->find($id) === null, 'unsubscribed address deleted after 30 days');
    check($store->findByEmail('stayer@example.com')['status'] === 'active', 'active subscribers untouched');
    check((int) $pdo->query('SELECT COUNT(*) FROM signup_attempts')->fetchColumn() === 0, 'sign-up attempts cleared after a day');
});

/** A site with history, then one new application in The Park and one in the margin. */
function digestSite(): array
{
    $h = harness(50);
    $h['http']->pages = [sampleFeatures()];
    $h['http']->bufferedPages = [sampleFeatures()];
    $h['job']->run(false, '2026-10-29 05:00:00');                 // history only
    $h['http']->bufferedPages = [array_merge(sampleFeatures(), [
        feature(400001, 'NEWINSIDE0001', '26/01950/PFUL3', '7 Lincoln Circus, Nottingham, NG7 1BS',
            'Replacement of <script>alert(1)</script> windows & "doors"', '2026-10-30', 'Pending Consideration', null, null, -1.1590, 52.9510),
        marginFeatures()[1],
    ])];
    $h['job']->run(false, '2026-10-31 05:00:00');                 // two new ones
    $h['coreArea'] = readJson($h['dir'] . '/public/park-boundary.geojson');
    return $h;
}

function digestSender(array $h, array $kit, int $maxPerRun = 200): DigestSender
{
    $builder = new DigestBuilder('https://nimby.parknews.co.uk',
        'https://publicaccess.nottinghamcity.gov.uk/online-applications/applicationDetails.do?activeTab=summary&keyVal=', 50,
        "the Nottingham Park Residents' Association (NPRA)");
    return new DigestSender($h['pdo'], $kit['store'], $kit['signup'], $kit['mailer'], $builder, $h['coreArea'], $maxPerRun, 0);
}

test('Monday digest', function () {
    $h = digestSite();
    $kit = emailKit($h['pdo']);
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $i => $email) {
        addActive($kit['signup'], $kit['mailer'], $email, '2026-10-20 10:00:00', "10.0.0.{$i}");
    }
    $kit['mailer']->sent = [];
    $sender = digestSender($h, $kit);

    $r = $sender->run('2026-11-02 07:03:00');
    check($r['status'] === 'sent' && $r['applications'] === 2 && $r['sent'] === 3, 'digest sent to all three (' . json_encode(array_slice($r, 0, 5)) . ')');
    $m = $kit['mailer']->sent[0];
    check($m->subject === 'NIMBY weekly: 2 new planning applications in and around The Park', 'subject (' . $m->subject . ')');
    check(str_contains($m->text, 'in the week to Monday 2 November'), 'week named');
    check(str_contains($m->html, 'In The Park') && str_contains($m->html, 'Just outside The Park, within 50 m'), 'grouped inside and just outside');
    check(strpos($m->html, '7 Lincoln Circus') < strpos($m->html, '16 Castle Boulevard'), 'The Park first, then the margin');
    check(str_contains($m->html, 'https://nimby.parknews.co.uk/?app=26-01950-PFUL3'), 'link to the pin on the map');
    check(str_contains($m->html, 'keyVal=NEWINSIDE0001'), 'link to the council case file');
    check(!str_contains($m->html, '<script>') && str_contains($m->html, '&lt;script&gt;'), 'council text escaped in the email');
    check(!str_contains($m->html, '1 Clumber Road East'), 'history is not included');
    check(str_starts_with($m->headers['List-Unsubscribe'], '<https://nimby.parknews.co.uk/unsubscribe.php?s=')
        && $m->headers['List-Unsubscribe-Post'] === 'List-Unsubscribe=One-Click', 'one-click unsubscribe headers');
    check(str_contains($m->text, 'Unsubscribe: https://nimby.parknews.co.uk/unsubscribe.php?s='), 'unsubscribe link in the text');
    $links = array_map(fn($x) => $x->headers['List-Unsubscribe'], $kit['mailer']->sent);
    check(count(array_unique($links)) === 3, 'each subscriber gets their own unsubscribe link');

    $kit['mailer']->sent = [];
    $r = $sender->run('2026-11-09 07:03:00');
    check($r['status'] === 'empty' && count($kit['mailer']->sent) === 0, 'quiet week: nothing sent');
    $periods = $h['pdo']->query('SELECT period_start, period_end FROM digests ORDER BY id')->fetchAll();
    check($periods[1]['period_start'] === $periods[0]['period_end'], 'each digest starts where the last ended, so nothing is missed');

    // Unsubscribed people get nothing.
    $id = (int) $kit['store']->findByEmail('b@example.com')['id'];
    parse_str((string) parse_url($kit['signup']->unsubscribeUrl($id), PHP_URL_QUERY), $q);
    $kit['signup']->unsubscribe($id, $q['t'], '2026-11-10 09:00:00');
    $h['http']->bufferedPages = [array_merge($h['http']->bufferedPages[0], [
        feature(400002, 'NEWINSIDE0002', '26/02001/PFUL3', '9 Lincoln Circus, Nottingham, NG7 1BS', 'New porch', '2026-11-12', 'Pending Consideration', null, null, -1.1592, 52.9512),
    ])];
    $h['job']->run(false, '2026-11-13 05:00:00');
    $r = $sender->run('2026-11-16 07:03:00');
    $to = array_map(fn($x) => $x->to, $kit['mailer']->sent);
    check($r['sent'] === 2 && !in_array('b@example.com', $to, true), 'unsubscribed subscriber skipped');
    check($kit['mailer']->sent[0]->subject === 'NIMBY weekly: 1 new planning application in and around The Park', 'singular subject');
});

test('Monday digest: interrupted runs finish without repeats', function () {
    $h = digestSite();
    $kit = emailKit($h['pdo']);
    foreach (['a@example.com', 'b@example.com', 'c@example.com'] as $i => $email) {
        addActive($kit['signup'], $kit['mailer'], $email, '2026-10-20 10:00:00', "10.0.0.{$i}");
    }
    $kit['mailer']->sent = [];

    $r = digestSender($h, $kit, 2)->run('2026-11-02 07:03:00');
    check($r['status'] === 'partial' && $r['sent'] === 2 && $r['remaining'] === 1, 'stops at the per-run limit');
    $r = digestSender($h, $kit, 2)->run('2026-11-02 08:03:00');
    check($r['status'] === 'sent' && $r['sent'] === 1, 'next run finishes the same digest');
    $to = array_map(fn($x) => $x->to, $kit['mailer']->sent);
    check(count($to) === 3 && count(array_unique($to)) === 3, 'nobody emailed twice');
    check((int) $h['pdo']->query('SELECT COUNT(*) FROM digests')->fetchColumn() === 1, 'still one digest');

    // Mail server failing: stops after three failures in a row, then recovers next run.
    $h2 = digestSite();
    $kit2 = emailKit($h2['pdo']);
    foreach (['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'] as $i => $email) {
        addActive($kit2['signup'], $kit2['mailer'], $email, '2026-10-20 10:00:00', "10.0.1.{$i}");
    }
    $kit2['mailer']->sent = [];
    $kit2['mailer']->failFor = ['a@example.com', 'b@example.com', 'c@example.com', 'd@example.com'];
    $r = digestSender($h2, $kit2)->run('2026-11-02 07:03:00');
    check($r['status'] === 'failed' && $r['failed'] === 3 && $r['sent'] === 0, 'gives up after three failures in a row');
    $kit2['mailer']->failFor = [];
    $r = digestSender($h2, $kit2)->run('2026-11-02 08:03:00');
    check($r['status'] === 'sent' && $r['sent'] === 4, 'retried successfully on the next run');
});

test('Test digest goes only to the site mailbox', function () {
    $h = digestSite();
    $kit = emailKit($h['pdo']);
    addActive($kit['signup'], $kit['mailer'], 'a@example.com', '2026-10-20 10:00:00', '10.0.0.1');
    $kit['mailer']->sent = [];
    $r = digestSender($h, $kit)->test('2026-11-02 07:03:00', 'nimby@parknews.co.uk');
    check(count($kit['mailer']->sent) === 1 && $kit['mailer']->sent[0]->to === 'nimby@parknews.co.uk', 'one email, to the site mailbox');
    check(str_starts_with($kit['mailer']->sent[0]->subject, '[TEST] ') && str_contains($kit['mailer']->sent[0]->html, 'This is a test'), 'clearly marked as a test');
    check((int) $h['pdo']->query('SELECT COUNT(*) FROM digests')->fetchColumn() === 0, 'nothing recorded, so the real Monday digest is unaffected');
    $r = digestSender($h, $kit)->test('2026-12-20 07:03:00', 'nimby@parknews.co.uk');
    check($r['applications'] === 5, 'with nothing new, the test shows the five most recent');
});


echo "\n{$passes} checks passed, {$failures} failed.\n";
exit($failures === 0 ? 0 : 1);
