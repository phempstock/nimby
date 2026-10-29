<?php
declare(strict_types=1);

namespace Nimby;

use Nimby\Council\ApplicationRecord;
use Nimby\Council\ArcGisClient;
use Nimby\Council\Status;
use Nimby\Export\GeoJsonExporter;
use Nimby\Storage\ApplicationStore;
use PDO;
use RuntimeException;

/**
 * The nightly job: fetch the applications in The Park (and within the
 * configured margin around it) from the council, store changes, and rewrite
 * the map's data files.
 *
 * On any failure the database transaction is rolled back and the existing
 * map files are left untouched, so the site keeps showing yesterday's data.
 */
final class SyncJob
{
    /** When the area is widened, newly included applications older than this are history, not news. */
    public const WIDENED_AREA_NEW_DAYS = 30;

    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private ArcGisClient $council,
        private ApplicationStore $store,
        private GeoJsonExporter $exporter,
        private PDO $pdo,
        private string $areaName,
        private string $cacheDir,
        private float $minRatioOfKnown = 0.8,
        private float $bufferMetres = 0
    ) {
    }

    /**
     * @param bool $refreshBoundary re-download the conservation area outline
     * @return array summary of the run
     */
    public function run(bool $refreshBoundary = false, ?string $nowUtc = null): array
    {
        $now = $nowUtc ?? gmdate('Y-m-d H:i:s');
        $runId = $this->startRun($now);

        try {
            $boundary = $this->boundary($refreshBoundary);
            $this->exporter->writeBoundary($boundary['geojson']);
            $watchArea = $this->bufferMetres > 0 ? $this->watchArea($boundary, $refreshBoundary) : null;
            if ($watchArea !== false) {
                $this->exporter->writeWatchArea($watchArea);   // null removes it (no margin)
            }

            // Has the area changed since the last successful run (e.g. a margin added)?
            $areaKey = $this->areaName . '|' . self::metres($this->bufferMetres);
            $firstRun = $this->store->isEmpty();
            $previousKey = $this->store->getSetting('area') ?? ($firstRun ? null : $this->areaName . '|0');
            $areaChanged = !$firstRun && $previousKey !== $areaKey;

            $missing = $this->council->missingPlanningFields();
            if ($missing) {
                throw new RuntimeException('Council planning layer is missing fields: ' . implode(', ', $missing));
            }

            $features = $this->council->fetchApplicationsWithin($boundary['esri'], $this->bufferMetres);
            $records = [];
            $skipped = 0;
            $duplicates = 0;
            foreach ($features as $feature) {
                $record = ApplicationRecord::fromFeature($feature);
                if ($record === null) {
                    $skipped++;
                    continue;
                }
                if (isset($records[$record['keyval']])) {
                    $duplicates++; // keep the first (lowest OBJECTID)
                    continue;
                }
                $records[$record['keyval']] = $record;
            }
            $fetched = count($records);
            $this->note("Fetched {$fetched} applications " . $this->describeArea());
            if ($skipped) {
                $this->note("Skipped {$skipped} features with no key or reference");
            }
            if ($duplicates) {
                $this->note("Ignored {$duplicates} duplicate points for the same application");
            }

            $known = $this->store->countActive();
            if ($fetched === 0) {
                throw new RuntimeException('Council returned no applications; keeping existing data');
            }
            if ($known > 0 && !$areaChanged && $fetched < $known * $this->minRatioOfKnown) {
                throw new RuntimeException(sprintf(
                    'Council returned %d applications but we hold %d; keeping existing data in case of a partial outage',
                    $fetched, $known
                ));
            }

            $unknown = $this->unknownWordings($records);
            if ($unknown) {
                $this->note('Unmapped status/decision wording (shown as "other"): ' . implode('; ', $unknown));
            }

            $backfillBefore = null;
            if ($firstRun) {
                $backfillBefore = '9999-12-31';
            } elseif ($areaChanged) {
                $backfillBefore = gmdate('Y-m-d', strtotime($now . ' UTC') - self::WIDENED_AREA_NEW_DAYS * 86400);
            }
            $stats = $this->store->sync(array_values($records), $now, $backfillBefore);
            if ($firstRun) {
                $this->note('First run: all applications marked as back-filled and will not be emailed');
            } elseif ($areaChanged) {
                $this->note(sprintf(
                    'Area changed from "%s" to "%s": %d newly included applications received before %s were added as history and will not be emailed',
                    str_replace('|', ' + ', $previousKey) . ' m', str_replace('|', ' + ', $areaKey) . ' m', $stats['backfilled'], $backfillBefore
                ));
            }
            $this->store->setSetting('area', $areaKey);
            $this->note(sprintf('Inserted %d, updated %d, removed %d, restored %d',
                $stats['inserted'], $stats['updated'], $stats['removed'], $stats['restored']));

            $meta = $this->exporter->write($this->store->allActive(), $now, $boundary['geojson'], $this->bufferMetres);
            $this->note("Wrote map data: {$meta['applications']} pins"
                . ($meta['outside'] ? ", {$meta['outside']} of them in the " . self::metres($this->bufferMetres) . ' m margin' : '')
                . ($meta['unplaced'] ? ", {$meta['unplaced']} without a location" : ''));

            $summary = ['status' => 'ok', 'fetched' => $fetched] + $stats + ['log' => $this->log];
            $this->finishRun($runId, 'ok', $summary);
            return $summary;
        } catch (\Throwable $e) {
            $this->note('FAILED: ' . $e->getMessage());
            $this->finishRun($runId, 'failed', ['log' => $this->log]);
            throw $e;
        }
    }

    public function log(): array
    {
        return $this->log;
    }

    /** Use the cached boundary unless asked to refresh or none is cached yet. */
    private function boundary(bool $refresh): array
    {
        $file = $this->cacheDir . '/boundary-' . preg_replace('/[^a-z0-9]+/', '-', strtolower($this->areaName)) . '.json';
        if (!$refresh && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && isset($cached['esri']['rings'], $cached['geojson'])) {
                return $cached;
            }
        }

        $boundary = $this->council->fetchBoundary($this->areaName);
        if (!is_dir($this->cacheDir) && !mkdir($this->cacheDir, 0755, true) && !is_dir($this->cacheDir)) {
            throw new RuntimeException("Cannot create cache directory {$this->cacheDir}");
        }
        file_put_contents($file, json_encode($boundary, JSON_UNESCAPED_SLASHES));
        $vertices = array_sum(array_map('count', $boundary['esri']['rings']));
        $this->note("Downloaded boundary for \"{$this->areaName}\" ({$vertices} points)");
        return $boundary;
    }

    /**
     * The widened outline for the map, cached like the boundary. Returns false if the
     * geometry service is unavailable: the sync carries on and the map keeps its last outline.
     */
    private function watchArea(array $boundary, bool $refresh): array|false
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($this->areaName));
        $file = $this->cacheDir . '/watch-area-' . $slug . '-' . self::metres($this->bufferMetres) . 'm.json';
        if (!$refresh && is_file($file)) {
            $cached = json_decode((string) file_get_contents($file), true);
            if (is_array($cached) && isset($cached['features'])) {
                return $cached;
            }
        }
        try {
            $outline = $this->council->bufferOutline($boundary['esri'], $this->bufferMetres);
        } catch (\Throwable $e) {
            $this->note('Could not draw the ' . self::metres($this->bufferMetres) . ' m outline (' . $e->getMessage() . '); the map keeps its previous outline');
            return false;
        }
        file_put_contents($file, json_encode($outline, JSON_UNESCAPED_SLASHES));
        $this->note('Drew the ' . self::metres($this->bufferMetres) . " m outline around \"{$this->areaName}\"");
        return $outline;
    }

    private function describeArea(): string
    {
        return $this->bufferMetres > 0
            ? "inside \"{$this->areaName}\" or within " . self::metres($this->bufferMetres) . ' m of it'
            : "inside \"{$this->areaName}\"";
    }

    /** 50.0 -> "50", 12.5 -> "12.5" */
    private static function metres(float $m): string
    {
        return rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.');
    }

    /** @return list<string> */
    private function unknownWordings(array $records): array
    {
        $unknown = [];
        foreach ($records as $r) {
            foreach (['status', 'decision'] as $f) {
                if (!Status::isKnown($r[$f])) {
                    $unknown[$r[$f]] = true;
                }
            }
        }
        return array_keys($unknown);
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    private function startRun(string $now): int
    {
        $stmt = $this->pdo->prepare("INSERT INTO sync_runs (started_at, status) VALUES (?, 'running')");
        $stmt->execute([$now]);
        return (int) $this->pdo->lastInsertId();
    }

    private function finishRun(int $id, string $status, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE sync_runs SET finished_at = ?, status = ?, fetched = ?, inserted = ?, updated = ?, removed = ?, message = ? WHERE id = ?'
        );
        $stmt->execute([
            gmdate('Y-m-d H:i:s'), $status,
            $summary['fetched'] ?? null, $summary['inserted'] ?? null,
            $summary['updated'] ?? null, $summary['removed'] ?? null,
            implode("\n", $summary['log'] ?? []), $id,
        ]);
    }
}
