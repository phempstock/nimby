<?php
declare(strict_types=1);

namespace Nimby\Support;

/**
 * Small geometry helpers for WGS84 GeoJSON polygons at neighbourhood scale,
 * where treating longitude/latitude as flat x/y is accurate enough.
 */
final class Geo
{
    /** True if the point lies inside any polygon in the FeatureCollection (holes excluded). */
    public static function pointInGeoJson(float $lng, float $lat, array $featureCollection): bool
    {
        foreach ($featureCollection['features'] ?? [] as $feature) {
            $geometry = $feature['geometry'] ?? null;
            if (!is_array($geometry)) {
                continue;
            }
            $polygons = match ($geometry['type'] ?? '') {
                'Polygon' => [$geometry['coordinates']],
                'MultiPolygon' => $geometry['coordinates'],
                default => [],
            };
            foreach ($polygons as $rings) {
                if (!$rings || !self::pointInRing($lng, $lat, $rings[0])) {
                    continue;
                }
                $inHole = false;
                foreach (array_slice($rings, 1) as $hole) {
                    if (self::pointInRing($lng, $lat, $hole)) {
                        $inHole = true;
                        break;
                    }
                }
                if (!$inHole) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Ray-casting test; the ring is a list of [x, y] points. */
    public static function pointInRing(float $x, float $y, array $ring): bool
    {
        $inside = false;
        $n = count($ring);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$xi, $yi] = $ring[$i];
            [$xj, $yj] = $ring[$j];
            if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = !$inside;
            }
        }
        return $inside;
    }

    /**
     * Convert ArcGIS polygon rings (outer rings clockwise, holes anticlockwise)
     * into a GeoJSON FeatureCollection with one Polygon or MultiPolygon.
     */
    public static function esriRingsToGeoJson(array $rings, array $properties = []): array
    {
        $outers = [];
        $holes = [];
        foreach ($rings as $ring) {
            if (count($ring) < 4) {
                continue;
            }
            if (self::signedArea($ring) < 0) {
                $outers[] = [$ring];   // clockwise
            } else {
                $holes[] = $ring;
            }
        }
        foreach ($holes as $hole) {
            foreach ($outers as $k => $polygon) {
                if (self::pointInRing((float) $hole[0][0], (float) $hole[0][1], $polygon[0])) {
                    $outers[$k][] = $hole;
                    break;
                }
            }
        }
        $geometry = count($outers) === 1
            ? ['type' => 'Polygon', 'coordinates' => $outers[0]]
            : ['type' => 'MultiPolygon', 'coordinates' => $outers];

        return ['type' => 'FeatureCollection', 'features' => [
            ['type' => 'Feature', 'properties' => $properties ?: new \stdClass(), 'geometry' => $geometry],
        ]];
    }

    /** Shoelace formula: positive for anticlockwise rings, negative for clockwise. */
    public static function signedArea(array $ring): float
    {
        $sum = 0.0;
        $n = count($ring);
        for ($i = 0; $i < $n - 1; $i++) {
            $sum += $ring[$i][0] * $ring[$i + 1][1] - $ring[$i + 1][0] * $ring[$i][1];
        }
        return $sum / 2;
    }
}
