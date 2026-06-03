<?php

namespace BankImport;

require_once __DIR__ . '/FeeSplitter.php';
require_once __DIR__ . '/ImportKey.php';

/**
 * Pure planner: turns ONE parsed bank entry into the list of bank line(s) to write,
 * with zero Dolibarr coupling and without touching the database.
 *
 * This is the behaviour-preserving extraction of the line-building logic that used to
 * live inline in BankImport::processXmlEntry()/processRow(). Pulling it out lets the new
 * import preview render exactly what a commit would write — the same plan feeds both the
 * dry-run preview and the actual write loop, so "preview == commit" holds by construction
 * rather than by keeping two code paths in sync. It also makes the logic unit-testable
 * without a live Dolibarr, mirroring FeeSplitter and StatementSummary (see tests/bootstrap.php).
 *
 * What stays OUTSIDE this class (in the Dolibarr glue) and why:
 *   - Date parsing (dol_mktime) and CSV amount parsing (price2num) are Dolibarr functions.
 *     Beyond mere availability, the import_key hashes the booking-date timestamp, so the
 *     glue must keep producing that timestamp exactly as before to stay dedup-compatible
 *     with already-stored rows. The glue parses dates/amount and passes them in.
 *   - The fee line's human label: only the BASE text ("Bank fee") is passed in, composed by
 *     the caller from the lang file. This class stays i18n-agnostic, exactly like FeeSplitter.
 *   - Duplicate detection (isAlreadyImported) and the write (Account::addline) — both DB.
 *
 * Plan shape (returned by both planners):
 *   [
 *     'dateo' => int, 'datev' => int,
 *     'num_chq' => string,           // AcctSvcrRef (XML) / mandate reference (CSV)
 *     'owner_other' => string, 'bank_other' => string, 'note' => string,
 *     'label' => string,             // principal line label
 *     'is_split' => bool,            // true only when an embedded fee was broken out
 *     'lines' => array<int, array{amount: float, label: string, import_key: string, is_fee: bool}>,
 *   ]
 */
class EntryPlan
{
    /** Same length cap the previous inline code applied to stored labels/owners. */
    private const MAX_LABEL = 255;

    /**
     * Plan a single namespace-stripped CAMT.053 <Ntry>.
     *
     * @param \SimpleXMLElement $ntry         Namespace-stripped <Ntry> element.
     * @param int               $dateo        Booking-date timestamp (parsed by the glue via dol_mktime).
     * @param int               $datev        Value-date timestamp (parsed by the glue).
     * @param bool              $splitFees    Whether to break out an embedded same-currency fee.
     * @param string            $feeLabelBase i18n base text for a fee line (e.g. "Bank fee").
     * @return array See class docblock for the shape.
     */
    public static function planXmlEntry(\SimpleXMLElement $ntry, int $dateo, int $datev, bool $splitFees, string $feeLabelBase): array
    {
        $datev = self::resolveValueDate($dateo, $datev);

        $amount = (float) $ntry->Amt;
        $cdtDbt = (string) $ntry->CdtDbtInd;
        if ($cdtDbt === 'DBIT') {
            $amount = -$amount;
        }

        $transactionId = trim((string) $ntry->AcctSvcrRef);

        $label = '';
        $owner_other = '';
        $iban_other = '';
        $bank_other = '';

        if (isset($ntry->NtryDtls->TxDtls)) {
            // CRDT (incoming) -> the counterparty is the debtor; DBIT (outgoing) -> the creditor.
            if ($cdtDbt === 'CRDT') {
                $partyTag = 'Dbtr';
                $acctTag  = 'DbtrAcct';
                $agtTag   = 'DbtrAgt';
            } else {
                $partyTag = 'Cdtr';
                $acctTag  = 'CdtrAcct';
                $agtTag   = 'CdtrAgt';
            }

            foreach ($ntry->NtryDtls->TxDtls as $tx) {
                $piece = self::xmlText($tx, ['RmtInf', 'Ustrd']);
                if ($piece === '') {
                    $piece = self::xmlText($tx, ['AddtlTxInf']);
                }
                if ($piece !== '') {
                    $label = ($label === '') ? $piece : ($label . ' | ' . $piece);
                }

                $candName = self::xmlText($tx, ['RltdPties', $partyTag, 'Nm']);
                if ($candName === '') {
                    $candName = self::xmlText($tx, ['RltdPties', 'InitgPty', 'Pty', 'Nm']);
                }
                if ($candName !== '' && $owner_other === '') {
                    $owner_other = $candName;
                }

                $candIban = self::xmlText($tx, ['RltdPties', $acctTag, 'Id', 'IBAN']);
                if ($candIban !== '' && $iban_other === '') {
                    $iban_other = $candIban;
                }

                $candBic = self::xmlText($tx, ['RltdAgts', $agtTag, 'FinInstnId', 'BICFI']);
                if ($candBic === '') {
                    $candBic = self::xmlText($tx, ['RltdAgts', $agtTag, 'FinInstnId', 'BIC']);
                }
                if ($candBic !== '' && $bank_other === '') {
                    $bank_other = $candBic;
                }
            }
        }
        if ($label === '') {
            $label = trim((string) $ntry->AddtlNtryInf);
        }

        $label = self::limitString($label);
        $owner_other = self::limitString($owner_other);

        // Private note: transaction ref + counterparty IBAN, mirroring the previous inline build.
        $noteParts = array();
        if ($transactionId !== '') {
            $noteParts[] = 'AcctSvcrRef=' . $transactionId;
        }
        if ($iban_other !== '') {
            $noteParts[] = 'CounterpartyIBAN=' . $iban_other;
        }
        $note = implode(' ', $noteParts);

        // $ref (the 7th ImportKey::build slot) is unused on the XML path; keep '' to match
        // the original key derivation byte-for-byte.
        $ref = '';

        $split = $splitFees ? FeeSplitter::extract($ntry) : null;

        if ($split === null) {
            $lines = array(array(
                'amount'     => $amount,
                'label'      => $label,
                'import_key' => ImportKey::build($transactionId, $iban_other, $owner_other, $amount, $label, $ref, $dateo),
                'is_fee'     => false,
            ));
            $isSplit = false;
        } else {
            // The two lines MUST carry different import keys (ImportKey hashes only the ref when
            // present and ignores the amount), so the fee line's ref is salted with ':fee'.
            $feeRef = ($transactionId !== '') ? $transactionId . ':fee' : '';
            $feeLabel = self::buildFeeLabel($feeLabelBase, $label);
            $lines = array(
                array(
                    'amount'     => $split['main_amount'],
                    'label'      => $label,
                    'import_key' => ImportKey::build($transactionId, $iban_other, $owner_other, $split['main_amount'], $label, $ref, $dateo),
                    'is_fee'     => false,
                ),
                array(
                    'amount'     => $split['fee_amount'],
                    'label'      => $feeLabel,
                    'import_key' => ImportKey::build($feeRef, $iban_other, $owner_other, $split['fee_amount'], $feeLabel, $ref, $dateo),
                    'is_fee'     => true,
                ),
            );
            $isSplit = true;
        }

        return array(
            'dateo'       => $dateo,
            'datev'       => $datev,
            'num_chq'     => $transactionId,
            'owner_other' => $owner_other,
            'bank_other'  => $bank_other,
            'note'        => $note,
            'label'       => $label,
            'is_split'    => $isSplit,
            'lines'       => $lines,
        );
    }

    /**
     * Plan a single CSV row (camt.052 v8 export). CSV carries no embedded-fee structure, so the
     * result is always one non-fee line. The amount is parsed by the glue (price2num) and passed
     * in; dates likewise. num_chq carries the mandate reference, matching the previous addline()
     * argument order.
     *
     * @param array<int|string, string> $data         Raw CSV row.
     * @param array<string, int>        $fieldMapping  Column-name -> index map (BankImport::$fieldMapping).
     * @param float                     $amount        Signed amount (already through price2num).
     * @param int                       $dateo         Booking-date timestamp.
     * @param int                       $datev         Value-date timestamp.
     * @return array See class docblock for the shape.
     */
    public static function planCsvRow(array $data, array $fieldMapping, float $amount, int $dateo, int $datev): array
    {
        $datev = self::resolveValueDate($dateo, $datev);

        $label       = self::limitString($data[$fieldMapping['payment_purpose']]);
        $ref         = trim($data[$fieldMapping['mandate_reference']]);
        $bank_other  = $data[$fieldMapping['counterparty_bic']];
        $iban_other  = $data[$fieldMapping['counterparty_iban']];
        $owner_other = $data[$fieldMapping['counterparty_name']];

        // CSV rows have no transaction id, so the import key falls back to the amount-composite
        // (transaction_id = null), exactly as the previous processRow() did.
        $import_key = ImportKey::build(null, $iban_other, $owner_other, $amount, $label, $ref, $dateo);

        return array(
            'dateo'       => $dateo,
            'datev'       => $datev,
            'num_chq'     => $ref,
            'owner_other' => $owner_other,
            'bank_other'  => $bank_other,
            'note'        => self::buildNote($data, $fieldMapping),
            'label'       => $label,
            'is_split'    => false,
            'lines'       => array(array(
                'amount'     => $amount,
                'label'      => $label,
                'import_key' => $import_key,
                'is_fee'     => false,
            )),
        );
    }

    /**
     * A missing value date defaults to the booking date: CAMT.053 may omit <ValDt> and a Haspa
     * CSV may leave the Valutadatum column blank (it reaches the planner as 0 after the glue's
     * int cast). Shared by both planners so this rule and its rationale have a single home.
     *
     * @param int $dateo Booking-date timestamp (assumed already validated as > 0).
     * @param int $datev Value-date timestamp, or <= 0 when absent.
     * @return int $datev when present, else $dateo.
     */
    private static function resolveValueDate(int $dateo, int $datev): int
    {
        return $datev > 0 ? $datev : $dateo;
    }

    /**
     * Compose a fee line's label from the i18n base and the principal label, then length-clamp it.
     * Kept identical to the former BankImport::buildFeeLabel(), only the base text is now injected
     * instead of read from $langs here.
     */
    private static function buildFeeLabel(string $feeLabelBase, string $baseLabel): string
    {
        $text = $feeLabelBase;
        if ($baseLabel !== '') {
            $text .= ': ' . $baseLabel;
        }
        return self::limitString($text);
    }

    /**
     * Build the CSV private note (collector reference, creditor id, counterparty IBAN), matching
     * the former BankImport::buildNote() so stored notes are unchanged.
     *
     * @param array<int|string, string> $data
     * @param array<string, int>        $fieldMapping
     */
    private static function buildNote(array $data, array $fieldMapping): string
    {
        $note = '';
        $sep = '';

        if (!empty($data[$fieldMapping['collector_reference']])) {
            $note .= $sep . 'Sammlerreferenz=' . $data[$fieldMapping['collector_reference']];
            $sep = ' ';
        }
        if (!empty($data[$fieldMapping['creditor_id']])) {
            $note .= $sep . 'GlaeubigerId=' . $data[$fieldMapping['creditor_id']];
            $sep = ' ';
        }
        if (!empty($data[$fieldMapping['counterparty_iban']])) {
            $note .= $sep . 'CounterpartyIBAN=' . $data[$fieldMapping['counterparty_iban']];
            $sep = ' ';
        }

        return $note;
    }

    /**
     * Safely walk a SimpleXML path, returning the trimmed text or '' if any step is missing.
     * Moved verbatim from BankImport::xmlText() (it was already pure). Use only for OPTIONAL
     * CAMT.053 branches; mandatory ones are read directly so a missing one surfaces as an error.
     *
     * @param \SimpleXMLElement|null $node
     * @param string[]               $path
     */
    private static function xmlText($node, array $path): string
    {
        foreach ($path as $key) {
            if (!($node instanceof \SimpleXMLElement) || !isset($node->{$key})) {
                return '';
            }
            $node = $node->{$key};
        }
        return trim((string) $node);
    }

    /**
     * Length-limit a (possibly null) string. Moved verbatim from BankImport::limitString().
     */
    private static function limitString(?string $text, int $length = self::MAX_LABEL, bool $fixed = false): string
    {
        if ($text === null) {
            return $fixed ? str_repeat(' ', $length) : '';
        }
        $limited = substr($text, 0, $length);
        return $fixed ? str_pad($limited, $length) : $limited;
    }
}
