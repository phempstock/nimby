<?php
declare(strict_types=1);

namespace Nimby\Export;

use Nimby\Council\AppType;
use Nimby\Council\Status;
use RuntimeException;

/**
 * Writes the static files the map page loads, so visitors never touch
 * the database or the council's server.
 *
 *  - applications.geojson : one point per application
 *  - meta.json            : when the data was last refreshed, and counts
 */
final class GeoJsonExporter
{
    public function __construct(private string $publicDataDir, private string $publicAccessBaseUrl)
    {
    }

    /** @param array<int, array<string, mixed>> $rows from ApplicationStore::allActive() */
    public function write(array $rows, string $syncedAtUtc): array
    {
        $features = [];
        foreach ($rows as $r) {
            if ($r['lat'] === null || $r['lng'] === null) {
                continue; // cannot be placed on the map
            }
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [round((float) $r['lng'], 6), round((float) $r['lat'], 6)],
                ],
                'properties' => [
                    'ref'           => $r['app_ref'],
                    'slug'          => self::slug($r['app_ref']),
                    'type'          => $r['app_type'],
                    'type_label'    => AppType::label($r['app_type']),
                    'trees'         => AppType::isTreeWork($r['app_type']),
                    'address'       => $r['address'],
                    'proposal'      => $r['proposal'],
                    'received'      => $r['date_received'],
                    'valid'         => $r['date_valid'],
                    'status'        => $r['status'],
                    'decision'      => $r['decision'],
                    'decision_date' => $r['decision_date'],
                    'category'      => Status::category($r['status'], $r['decision'], $r['decision_date']),
                    // Null for history loaded on the first run, so the map never badges it as new.
                    'first_seen'    => (int) $r['backfilled'] === 1 ? null : substr((string) $r['first_seen_at'], 0, 10),
                    'url'           => $this->publicAccessBaseUrl . rawurlencode((string) $r['keyval']),
                ],
            ];
        }

        $collection = ['type' => 'FeatureCollection', 'features' => $features];
        $meta = [
            'synced_at'    => $syncedAtUtc . 'Z',
            'applications' => count($features),
            'unplaced'     => count($rows) - count($features),
        ];

        $this->writeAtomically('applications.geojson', $collection);
        $this->writeAtomically('meta.json', $meta);
        return $meta;
    }

    /** Write the boundary outline for the map. */
    public function writeBoundary(array $geojson): void
    {
        $this->writeAtomically('park-boundary.geojson', $geojson);
    }

    /** "26/01848/PWC" -> "26-01848-PWC", used in links such as /?app=26-01848-PWC */
    public static function slug(string $ref): string
    {
        return strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $ref), '-'));
    }

    private function writeAtomically(string $name, array $data): void
    {
        if (!is_dir($this->publicDataDir) && !mkdir($this->publicDataDir, 0755, true) && !is_dir($this->publicDataDir)) {
            throw new RuntimeException("Cannot create {$this->publicDataDir}");
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $target = $this->publicDataDir . '/' . $name;
        $tmp = $target . '.tmp';
        if (file_put_contents($tmp, $json) === false || !rename($tmp, $target)) {
            throw new RuntimeException("Could not write {$target}");
        }
    }
}
