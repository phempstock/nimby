<?php
declare(strict_types=1);

namespace Nimby\Tests;

use Nimby\Http\HttpClient;

/**
 * Stands in for the council's server. Responses are chosen by URL and
 * parameters, and every request is recorded for assertions.
 */
final class FakeHttpClient implements HttpClient
{
    public array $requests = [];
    public array $layerFields;
    /** @var list<array> pages of features returned for the planning query */
    public array $pages = [];
    /** @var list<array>|null pages returned when the query asks for a margin (distance); null = same as $pages */
    public ?array $bufferedPages = null;
    public ?array $planningError = null;
    public ?array $bufferError = null;

    public function __construct()
    {
        $this->layerFields = array_map(
            static fn($n) => ['name' => $n],
            ['OBJECTID', 'SHAPE', 'KEYVAL', 'APP_REF', 'APP_ADDRESS', 'PROPOSAL',
             'DATE_APP_RECEIVED', 'DATE_APP_VALID', 'APP_STATUS', 'DECISION_DATE', 'DECISION']
        );
    }

    public function getJson(string $url, array $params = []): array
    {
        $this->requests[] = ['GET', $url, $params];
        if (str_ends_with($url, '/MapServer/13')) {
            return ['name' => 'Planning Application Points', 'fields' => $this->layerFields];
        }
        if (str_contains($url, '/MapServer/49/query')) {
            $ring = [[456000.0, 339000.0], [456800.0, 339000.0], [456800.0, 339800.0], [456000.0, 339800.0], [456000.0, 339000.0]];
            if (($params['f'] ?? '') === 'geojson') {
                return ['type' => 'FeatureCollection', 'features' => [[
                    'type' => 'Feature', 'properties' => ['NAME' => 'The Park'],
                    'geometry' => ['type' => 'Polygon', 'coordinates' => [[[-1.165, 52.948], [-1.153, 52.948], [-1.153, 52.955], [-1.165, 52.955], [-1.165, 52.948]]]],
                ]]];
            }
            return ['spatialReference' => ['wkid' => 27700, 'latestWkid' => 27700],
                    'features' => [['attributes' => ['NAME' => 'The Park'], 'geometry' => ['rings' => [$ring]]]]];
        }
        throw new \RuntimeException("Unexpected GET {$url}");
    }

    public function postJson(string $url, array $params): array
    {
        $this->requests[] = ['POST', $url, $params];
        if (str_contains($url, '/MapServer/13/query')) {
            if ($this->planningError) {
                return ['error' => $this->planningError];
            }
            $pages = isset($params['distance']) && $this->bufferedPages !== null ? $this->bufferedPages : $this->pages;
            $page = intdiv((int) ($params['resultOffset'] ?? 0), 2000);
            $features = $pages[$page] ?? [];
            $response = ['features' => $features];
            if ($page < count($pages) - 1) {
                $response['exceededTransferLimit'] = true;
            }
            return $response;
        }
        if (str_ends_with($url, '/GeometryServer/buffer')) {
            if ($this->bufferError) {
                return ['error' => $this->bufferError];
            }
            // The test conservation area widened by roughly 50 m, clockwise as ArcGIS returns it.
            return ['geometries' => [['rings' => [[[-1.1657, 52.9475], [-1.1657, 52.9555], [-1.1523, 52.9555], [-1.1523, 52.9475], [-1.1657, 52.9475]]]]]];
        }
        throw new \RuntimeException("Unexpected POST {$url}");
    }
}
