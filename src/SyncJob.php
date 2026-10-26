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
 * The nightly job: fetch The Park's applications from the council,
 * store changes, and rewrite the map's data files.
 *
 * On any failure the database transaction is rolled back and the existing
 * map files are left untouched, so the site keeps showing yesterday's data.
 */
final class SyncJob
{
    /** @var list<string> */
    private array $log = [];

    public function __construct(
        private ArcGisClient $council,
        private ApplicationStore $store,
        private GeoJsonExporter $exporter,
        private PDO $pdo,
        private string $areaName,
        private string $cacheDir,
        private float $minRatioOfKnown = 0.8
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

            $missing = $this->council->missingPlanningFields();
            if ($missing) {
                throw new RuntimeException('Council planning layer is missing fields: ' . implode(', ', $missing));
            }

            $features = $this->council->fetchApplicationsWithin($boundary['esri']);
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
            $this->note("Fetched {$fetched} applications inside \"{$this->areaName}\"");
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
            if ($known > 0 && $fetched < $known * $this->minRatioOfKnown) {
                throw new RuntimeException(sprintf(
                    'Council returned %d applications but we hold %d; keeping existing data in case of a partial outage',
                    $fetched, $known
                ));
            }

            $unknown = $this->unknownWordings($records);
            if ($unknown) {
                $this->note('Unmapped status/decision wording (shown as "other"): ' . implode('; ', $unknown));
            }

            $backfill = $this->store->isEmpty();
            $stats = $this->store->sync(array_values($records), $now, $backfill);
            if ($backfill) {
                $this->note('First run: all applications marked as back-filled and will not be emailed');
            }
            $this->note(sprintf('Inserted %d, updated %d, removed %d, restored %d',
                $stats['inserted'], $stats['updated'], $stats['removed'], $stats['restored']));

            $meta = $this->exporter->write($this->store->allActive(), $now);
            $this->note("Wrote map data: {$meta['applications']} pins" . ($meta['unplaced'] ? ", {$meta['unplaced']} without a location" : ''));

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
