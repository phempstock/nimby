<?php
declare(strict_types=1);

namespace Nimby\Council;

/**
 * Groups the council's many status and decision wordings into five
 * categories used for pin colours and filters.
 *
 * Built from the values actually present for The Park on 26 Oct 2026.
 * A value not listed here falls into "other"; the sync reports any such
 * values so this list can be extended.
 */
final class Status
{
    public const PENDING   = 'pending';
    public const GRANTED   = 'granted';
    public const REFUSED   = 'refused';
    public const WITHDRAWN = 'withdrawn';
    public const OTHER     = 'other';

    public const LABELS = [
        self::PENDING   => 'Pending',
        self::GRANTED   => 'Granted / no objection',
        self::REFUSED   => 'Refused / objection',
        self::WITHDRAWN => 'Withdrawn / returned',
        self::OTHER     => 'Other outcome',
    ];

    /** Lower-cased council wording => category. Covers APP_STATUS and DECISION values. */
    private const MAP = [
        // Pending
        'pending consideration'                   => self::PENDING,
        'pending decision'                        => self::PENDING,

        // Granted, approved or otherwise allowed to proceed
        'granted conditional permission'          => self::GRANTED,
        'grant conditional permission'            => self::GRANTED,
        'granted conditional consent'             => self::GRANTED,
        'grant conditional consent'               => self::GRANTED,
        'granted permission'                      => self::GRANTED,
        'grant permission'                        => self::GRANTED,
        'granted consent'                         => self::GRANTED,
        'grant consent'                           => self::GRANTED,
        'granted full certificate'                => self::GRANTED,
        'grant full certificate'                  => self::GRANTED,
        'approved'                                => self::GRANTED,
        'approve'                                 => self::GRANTED,
        'approved conditionally'                  => self::GRANTED,
        'approve conditionally'                   => self::GRANTED,
        'no objection'                            => self::GRANTED,
        'no objection subject to conditions'      => self::GRANTED,
        'prior approval given'                    => self::GRANTED,
        'prior approval not required'             => self::GRANTED,
        'permitted development'                   => self::GRANTED,
        '5 day notice accepted'                   => self::GRANTED,
        '5 day noticed accepted'                  => self::GRANTED,
        'tpo works exempt'                        => self::GRANTED,

        // Refused or objected to
        'refused permission'                      => self::REFUSED,
        'refuse permission'                       => self::REFUSED,
        'refused consent'                         => self::REFUSED,
        'refuse consent'                          => self::REFUSED,
        'refused certificate'                     => self::REFUSED,
        'refuse certificate'                      => self::REFUSED,
        'objection'                               => self::REFUSED,
        'rejected'                                => self::REFUSED,
        'reject'                                  => self::REFUSED,
        '5 day notice not accepted'               => self::REFUSED,
        '5 day noticed not accepted'              => self::REFUSED,

        // Withdrawn, returned or closed without a decision
        'application withdrawn'                   => self::WITHDRAWN,
        'finally disposed of'                     => self::WITHDRAWN,
        'application returned'                    => self::WITHDRAWN,
        'returned incomplete or invalid'          => self::WITHDRAWN,
        'insufficient fee'                        => self::WITHDRAWN,
        'application lapsed'                      => self::WITHDRAWN,

        // Recognised, but not a simple yes/no
        'part refused and part granted'           => self::OTHER,
        'part refuse & part grant'                => self::OTHER,
        'tpo made'                                => self::OTHER,
        'tpo made (pwc notifications only)'       => self::OTHER,
        'prior approval required'                 => self::OTHER,
        'application required'                    => self::OTHER,
        'no application required (nd or pd etc)'  => self::OTHER,
        'not development'                         => self::OTHER,
        'notification under article 3 of gpdo'    => self::OTHER,
        'nomination accepted'                     => self::OTHER,
        'accept nomination (ascmvl)'              => self::OTHER,
    ];

    /**
     * Category for an application: from its status, falling back to its
     * decision, then to pending if neither is set and it has no decision date.
     */
    public static function category(?string $status, ?string $decision, ?string $decisionDate = null): string
    {
        foreach ([$status, $decision] as $value) {
            if ($value !== null && isset(self::MAP[strtolower(trim($value))])) {
                return self::MAP[strtolower(trim($value))];
            }
        }
        if ($status === null && $decision === null && $decisionDate === null) {
            return self::PENDING;
        }
        return self::OTHER;
    }

    /** True if the wording is one we have explicitly mapped. */
    public static function isKnown(?string $value): bool
    {
        return $value === null || isset(self::MAP[strtolower(trim($value))]);
    }
}
