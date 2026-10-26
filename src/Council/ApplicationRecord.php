<?php
declare(strict_types=1);

namespace Nimby\Council;

/**
 * Turns a raw ArcGIS feature into the tidy array NIMBY stores.
 */
final class ApplicationRecord
{
    /** Fields compared between runs; a change to any of them is logged as an event. */
    public const TRACKED_FIELDS = [
        'app_ref', 'app_type', 'address', 'proposal', 'date_received', 'date_valid',
        'status', 'decision', 'decision_date', 'lat', 'lng',
    ];

    /**
     * @return array<string, mixed>|null null when the feature has no usable key.
     */
    public static function fromFeature(array $feature): ?array
    {
        $a = $feature['attributes'] ?? [];
        $keyval = self::text($a['KEYVAL'] ?? null);
        $ref = self::text($a['APP_REF'] ?? null);
        if ($keyval === null || $ref === null) {
            return null;
        }

        $g = $feature['geometry'] ?? null;
        $lat = is_array($g) && is_numeric($g['y'] ?? null) ? round((float) $g['y'], 6) : null;
        $lng = is_array($g) && is_numeric($g['x'] ?? null) ? round((float) $g['x'], 6) : null;

        return [
            'keyval'        => $keyval,
            'app_ref'       => $ref,
            'app_type'      => self::typeFromRef($ref),
            'address'       => self::text($a['APP_ADDRESS'] ?? null),
            'proposal'      => self::text($a['PROPOSAL'] ?? null),
            'date_received' => self::date($a['DATE_APP_RECEIVED'] ?? null),
            'date_valid'    => self::date($a['DATE_APP_VALID'] ?? null),
            'status'        => self::text($a['APP_STATUS'] ?? null),
            'decision'      => self::text($a['DECISION'] ?? null),
            'decision_date' => self::date($a['DECISION_DATE'] ?? null),
            'lat'           => $lat,
            'lng'           => $lng,
        ];
    }

    /** "26/01848/PWC" -> "PWC". */
    public static function typeFromRef(string $ref): ?string
    {
        $parts = explode('/', $ref);
        $last = strtoupper(trim(end($parts)));
        return count($parts) >= 3 && $last !== '' ? $last : null;
    }

    /** ArcGIS dates are milliseconds since 1970 (UTC midnight for these fields). */
    private static function date(mixed $ms): ?string
    {
        if (!is_numeric($ms)) {
            return null;
        }
        return gmdate('Y-m-d', (int) floor(((float) $ms) / 1000));
    }

    /** Trim, normalise line endings and treat empty strings as null. */
    private static function text(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim(str_replace(["\r\n", "\r"], "\n", (string) $value));
        return $s === '' ? null : $s;
    }
}
