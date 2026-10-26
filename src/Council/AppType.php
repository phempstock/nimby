<?php
declare(strict_types=1);

namespace Nimby\Council;

/**
 * Plain-English names for the suffix at the end of an application reference
 * (e.g. 26/01848/PWC). Unknown codes are shown as the code itself.
 */
final class AppType
{
    private const LABELS = [
        'PFUL3' => 'Full planning application',
        'POUT'  => 'Outline planning application',
        'PRES4' => 'Reserved matters',
        'PVAR3' => 'Variation of conditions',
        'PDS4'  => 'Discharge of conditions',
        'PNMA'  => 'Non-material amendment',
        'LLIS1' => 'Listed building consent',
        'PCLE'  => 'Lawful development certificate (existing)',
        'PCLO'  => 'Lawful development certificate (proposed)',
        'PWC'   => 'Works to trees in a conservation area',
        'PTPO'  => 'Works to protected trees (TPO)',
        '5DAY'  => 'Five-day notice: dead or dangerous tree',
    ];

    /** Tree applications are a large share of The Park's caseload; the map can filter them. */
    private const TREE_CODES = ['PWC', 'PTPO', '5DAY'];

    public static function label(?string $code): string
    {
        if ($code === null) {
            return 'Application';
        }
        return self::LABELS[strtoupper($code)] ?? $code;
    }

    public static function isTreeWork(?string $code): bool
    {
        return $code !== null && in_array(strtoupper($code), self::TREE_CODES, true);
    }
}
