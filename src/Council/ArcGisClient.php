<?php
declare(strict_types=1);

namespace Nimby\Council;

use Nimby\Http\HttpClient;
use RuntimeException;

/**
 * Reads Nottingham City Council's ArcGIS map server:
 *  - the conservation area layer, for The Park boundary
 *  - the planning application points layer, filtered to that boundary
 */
final class ArcGisClient
{
    /** Fields NIMBY relies on. If the council renames any, the sync stops and alerts. */
    public const REQUIRED_FIELDS = [
        'KEYVAL', 'APP_REF', 'APP_ADDRESS', 'PROPOSAL',
        'DATE_APP_RECEIVED', 'DATE_APP_VALID', 'APP_STATUS',
        'DECISION_DATE', 'DECISION',
    ];

    private const PAGE_SIZE = 2000;   // the layer's maxRecordCount
    private const MAX_PAGES = 50;     // safety stop: 100,000 records

    public function __construct(
        private HttpClient $http,
        private string $planningLayerUrl,
        private string $conservationLayerUrl
    ) {
    }

    /**
     * Check the planning layer still has the fields we use.
     * Returns the list of missing fields (empty when all is well).
     */
    public function missingPlanningFields(): array
    {
        $info = $this->http->getJson($this->planningLayerUrl, ['f' => 'json']);
        $this->throwIfError($info, 'planning layer info');
        $present = array_map(static fn(array $f) => $f['name'] ?? '', $info['fields'] ?? []);
        return array_values(array_diff(self::REQUIRED_FIELDS, $present));
    }

    /**
     * Fetch a conservation area boundary by name.
     *
     * @return array{esri: array, geojson: array} esri = polygon in the layer's
     *         native spatial reference (for spatial queries); geojson = the same
     *         polygon as a WGS84 GeoJSON FeatureCollection (for the map outline).
     */
    public function fetchBoundary(string $areaName): array
    {
        $where = "NAME = '" . str_replace("'", "''", $areaName) . "'";
        $url = rtrim($this->conservationLayerUrl, '/') . '/query';

        $esri = $this->http->getJson($url, [
            'where' => $where, 'outFields' => 'NAME,HECTARES', 'returnGeometry' => 'true', 'f' => 'json',
        ]);
        $this->throwIfError($esri, 'conservation area query');
        $features = $esri['features'] ?? [];
        if (count($features) !== 1) {
            throw new RuntimeException(sprintf(
                'Expected exactly one conservation area named "%s", found %d', $areaName, count($features)
            ));
        }
        $geometry = $features[0]['geometry'] ?? null;
        if (!is_array($geometry) || empty($geometry['rings'])) {
            throw new RuntimeException("Conservation area \"{$areaName}\" has no polygon geometry");
        }
        $geometry['spatialReference'] ??= $esri['spatialReference'] ?? ['wkid' => 27700];

        $geojson = $this->http->getJson($url, [
            'where' => $where, 'outFields' => 'NAME,HECTARES', 'returnGeometry' => 'true',
            'outSR' => '4326', 'f' => 'geojson',
        ]);
        $this->throwIfError($geojson, 'conservation area GeoJSON query');
        if (count($geojson['features'] ?? []) !== 1) {
            throw new RuntimeException("Conservation area GeoJSON query did not return one feature");
        }

        return ['esri' => $geometry, 'geojson' => $geojson];
    }

    /**
     * Fetch every planning application point inside the boundary polygon,
     * paging through the results. Returns raw ArcGIS features
     * (each with 'attributes' and 'geometry' {x: lng, y: lat}).
     */
    public function fetchApplicationsWithin(array $esriPolygon): array
    {
        $url = rtrim($this->planningLayerUrl, '/') . '/query';
        $inSr = $esriPolygon['spatialReference']['latestWkid'] ?? $esriPolygon['spatialReference']['wkid'] ?? 27700;
        $base = [
            'where'          => '1=1',
            'geometry'       => json_encode(['rings' => $esriPolygon['rings'], 'spatialReference' => ['wkid' => $inSr]]),
            'geometryType'   => 'esriGeometryPolygon',
            'inSR'           => (string) $inSr,
            'spatialRel'     => 'esriSpatialRelIntersects',
            'outFields'      => 'OBJECTID,' . implode(',', self::REQUIRED_FIELDS),
            'returnGeometry' => 'true',
            'outSR'          => '4326',
            'orderByFields'  => 'OBJECTID ASC',
            'resultRecordCount' => (string) self::PAGE_SIZE,
            'f'              => 'json',
        ];

        $all = [];
        for ($page = 0; $page < self::MAX_PAGES; $page++) {
            $response = $this->http->postJson($url, $base + ['resultOffset' => (string) ($page * self::PAGE_SIZE)]);
            $this->throwIfError($response, 'planning applications query');
            $features = $response['features'] ?? [];
            array_push($all, ...$features);
            if (empty($response['exceededTransferLimit']) || count($features) === 0) {
                return $all;
            }
        }
        throw new RuntimeException('Planning applications query exceeded ' . self::MAX_PAGES . ' pages; stopping for safety');
    }

    private function throwIfError(array $response, string $what): void
    {
        if (isset($response['error'])) {
            $message = $response['error']['message'] ?? 'unknown error';
            $code = $response['error']['code'] ?? '?';
            throw new RuntimeException("Council server error in {$what}: {$message} (code {$code})");
        }
    }
}
