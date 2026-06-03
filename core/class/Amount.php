<?php

namespace BankImport;

/**
 * Single source of truth for monetary equality across the whole verification /
 * reconciliation feature. Bank amounts are floats parsed out of XML, so direct
 * == comparison is unsafe (e.g. 0.1 + 0.2); every comparison must allow a small
 * tolerance. Centralising it here means the tolerance can be tightened or loosened
 * in exactly one edit, and no helper is free to invent its own inline abs() check.
 *
 * Zero Dolibarr coupling — pure arithmetic — so it is reusable by every pure
 * helper (StatementSummary, StatementContinuity, …) and unit-testable on its own.
 */
final class Amount
{
    /**
     * The largest absolute difference between two amounts that still counts as
     * equal: half a cent. Anything below half a cent cannot be a real bookkeeping
     * discrepancy (currencies settle to the cent), so it must be float drift.
     */
    public const TOLERANCE = 0.005;

    /**
     * Whether two amounts are equal within TOLERANCE. The one comparison routine
     * the rest of the codebase calls instead of writing abs($a - $b) < … inline.
     */
    public static function match(float $a, float $b): bool
    {
        return abs($a - $b) < self::TOLERANCE;
    }
}
