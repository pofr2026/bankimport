<?php

namespace BankImport;

// Shared half-cent tolerance lives in Amount. Required explicitly so this helper
// is self-contained under Dolibarr's runtime, which does not register the module's
// composer autoloader (BankImport.class.php loads each helper with a flat require).
require_once __DIR__ . '/Amount.php';

/**
 * Pure helper for the cross-statement continuity check (the Reconciliation /
 * Re-verify goal cut out of v0.0.13).
 *
 * StatementSummary verifies a single statement against its own declared totals,
 * but it is blind to a whole statement FILE that the user never imported between
 * two files they did import. The bank itself signals such a gap: the ledger
 * invariant is that each statement's closing booking balance equals the next
 * statement's opening booking balance — CLBD_N == OPBD_(N+1). A break in that
 * chain means a statement is missing (or balances were tampered with).
 *
 * Running totals in llx_bank cannot reveal this: the missing rows simply are not
 * there and the stored total stays internally consistent over whatever WAS
 * imported. Only the bank-declared OPBD/CLBD, compared across statements, exposes
 * the hole — which is why those balances are persisted per statement and fed here.
 *
 * Zero Dolibarr coupling — operates only on plain arrays — so it is unit-testable
 * per tests/bootstrap.php, mirroring StatementSummary / FeeSplitter / EntryPlan.
 * Numeric equality routes through the shared Amount::match() so the half-cent
 * tolerance has a single source of truth.
 */
class StatementContinuity
{
    /**
     * Check a set of statements for breaks in the CLBD_N == OPBD_(N+1) chain.
     *
     * Each currency forms its OWN independent chain: a multi-currency Revolut
     * account emits one <Stmt> per currency, and a CHF closing balance has no
     * relationship to a EUR opening balance. We therefore group by currency,
     * order each group by its electronic sequence number, then compare every
     * adjacent pair.
     *
     * Statements are NOT assumed to arrive in order — they may be imported (and
     * thus stored) in any sequence — so each currency chain is sorted by 'seq'
     * before the adjacency walk.
     *
     * Each gap record is presentation-free (no prose, no locale): the UI layer
     * owns wording and translation. 'from_id'/'to_id' carry the statement
     * identifiers (num_releve) the rest of Dolibarr shows; 'from_seq'/'to_seq'
     * carry the chain-order keys.
     *
     * @param list<array{seq: string, currency: string, opbd: float, clbd: float, id: string}> $statements
     * @return list<array{currency: string, from_seq: string, to_seq: string,
     *                     from_id: string, to_id: string,
     *                     expected_opbd: float, actual_opbd: float}>
     *         One record per detected gap; empty when every chain is continuous.
     */
    public static function check(array $statements): array
    {
        $byCurrency = [];
        foreach ($statements as $statement) {
            $byCurrency[$statement['currency']][] = $statement;
        }

        $gaps = [];
        foreach ($byCurrency as $currency => $chain) {
            usort(
                $chain,
                static fn(array $a, array $b): int => self::seqOrder($a['seq']) <=> self::seqOrder($b['seq'])
            );

            for ($i = 1, $n = count($chain); $i < $n; $i++) {
                $prev = $chain[$i - 1];
                $next = $chain[$i];
                $expectedOpbd = (float) $prev['clbd'];
                $actualOpbd   = (float) $next['opbd'];

                if (Amount::match($expectedOpbd, $actualOpbd)) {
                    continue;
                }

                $gaps[] = [
                    'currency'      => $currency,
                    'from_seq'      => (string) $prev['seq'],
                    'to_seq'        => (string) $next['seq'],
                    'from_id'       => (string) $prev['id'],
                    'to_id'         => (string) $next['id'],
                    'expected_opbd' => $expectedOpbd,
                    'actual_opbd'   => $actualOpbd,
                ];
            }
        }

        return $gaps;
    }

    /**
     * Ordering key for an electronic sequence number. Revolut emits an integer
     * <ElctrncSeqNb>, so numeric ordering is correct (and avoids "10" sorting
     * before "2" as strings would). A non-numeric seq casts to 0, which keeps
     * sorting stable rather than throwing.
     */
    private static function seqOrder(string $seq): int
    {
        return (int) $seq;
    }
}
