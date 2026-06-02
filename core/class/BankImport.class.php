<?php
/* Copyright (C) 2024 Tilo Thiele <tilo.thiele@hamburg.de>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

// Pure helpers shipped with the module — required manually because Dolibarr's
// runtime does not register our composer PSR-4 autoloader.
require_once __DIR__ . '/ImportKey.php';
require_once __DIR__ . '/StatementSummary.php';
require_once __DIR__ . '/FeeSplitter.php';

use BankImport\ImportKey;
use BankImport\StatementSummary;
use BankImport\FeeSplitter;

/**
 * BankImport class
 */
class BankImport extends CommonObject
{
    /**
     * @var DoliDB Database handler.
     */
    public $db;

    /**
     * @var string Error code (or message)
     */
    public $error = '';

    /**
     * @var string[] Error codes (or messages)
     */
    public $errors = array();

    /**
     * @var int Bank account ID
     */
    public $accountid;

    /**
     * @var string File encoding
     */
    public $encoding;

    /**
     * @var array CSV field mapping
     */
    public $fieldMapping = array(
        'account' => 0,
        'booking_date' => 1,
        'value_date' => 2,
        'booking_text' => 3,
        'payment_purpose' => 4,
        'creditor_id' => 5,
        'mandate_reference' => 6,
        'collector_reference' => 8,
        'counterparty_name' => 11,
        'counterparty_iban' => 12,
        'counterparty_bic' => 13,
        'amount' => 14,
        'currency' => 15,
        'info' => 16
    );

    /**
     * Constructor
     *
     * @param DoliDB $db Database handler
     */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Set account ID
     *
     * @param int $accountid Bank account ID
     * @return void
     */
    public function setAccountId($accountid)
    {
        $this->accountid = (int) $accountid;
    }

    /**
     * Set encoding
     *
     * @param string $encoding File encoding
     * @return void
     */
    public function setEncoding($encoding)
    {
        $this->encoding = $encoding;
    }

    /**
     * Validate uploaded file
     *
     * @param array $file $_FILES array element
     * @return bool True if valid, false otherwise
     */
    public function validateFile($file)
    {
        if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
            $this->error = 'No file uploaded';
            return false;
        }

        if (!is_uploaded_file($file['tmp_name'])) {
            $this->error = 'Invalid file upload';
            return false;
        }

        if ($file['size'] > 10 * 1024 * 1024) { // 10MB limit
            $this->error = 'File too large (max 10MB)';
            return false;
        }

        $allowedTypes = array('text/csv', 'text/plain', 'application/csv', 'text/xml', 'application/xml');
        if (!in_array($file['type'], $allowedTypes) && !preg_match('/\.(csv|xml)$/i', $file['name'])) {
            $this->error = 'Invalid file type (CSV or XML required)';
            return false;
        }

        return true;
    }

    /**
     * Process a bank statement file (CSV camt.052 v8 or XML camt.053).
     * The format is detected automatically.
     *
     * @param string $filename File path
     * @return array Array with success count, skipped count and errors
     */
    public function processFile($filename)
    {
        $result = array(
            'success' => 0,
            'errors' => array(),
            'skipped' => 0
        );

        // Validate account ID is set
        if (empty($this->accountid) || $this->accountid <= 0) {
            $this->error = 'No valid bank account selected';
            $result['errors'][] = 'No valid bank account selected';
            return $result;
        }

        if ($this->detectFormat($filename) === 'xml') {
            return $this->processFileXml($filename, $result);
        }
        return $this->processFileCsv($filename, $result);
    }

    /**
     * Detect file format by sniffing the first bytes.
     *
     * @param string $filename File path
     * @return string 'xml' or 'csv'
     */
    private function detectFormat($filename)
    {
        $fp = @fopen($filename, 'r');
        if (!$fp) return 'csv';
        $head = fread($fp, 512);
        fclose($fp);
        $head = ltrim($head, "\xEF\xBB\xBF \t\n\r");
        if (strncmp($head, '<?xml', 5) === 0 || strncmp($head, '<Document', 9) === 0) {
            return 'xml';
        }
        return 'csv';
    }

    /**
     * Process a CSV file (camt.052 v8 export, e.g. Haspa).
     *
     * @param string $filename File path
     * @param array $result Accumulator
     * @return array Result array
     */
    private function processFileCsv($filename, $result)
    {
        $handle = fopen($filename, 'r');
        if (!$handle) {
            $this->error = 'Could not open file';
            $result['errors'][] = $this->error;
            return $result;
        }

        $row = 0;
        while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
            $row++;
            if ($row == 1) continue; // Skip header

            // Convert encoding if needed
            $data = $this->convertEncoding($data);

            // Validate data
            if (!$this->validateRow($data, $row)) {
                $result['errors'][] = "Row $row: " . $this->error;
                continue;
            }

            // Process row
            $importResult = $this->processRow($data, $row);
            if ($importResult === true) {
                $result['success']++;
            } elseif ($importResult === 'skipped') {
                $result['skipped']++;
            } else {
                $result['errors'][] = "Row $row: " . $importResult;
            }
        }

        fclose($handle);
        return $result;
    }

    /**
     * Process an XML file in CAMT.053 format (e.g. Revolut Business statement).
     *
     * @param string $filename File path
     * @param array $result Accumulator
     * @return array Result array
     */
    private function processFileXml($filename, $result)
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            $this->error = 'Could not read XML file';
            $result['errors'][] = $this->error;
            return $result;
        }

        // Strip the default namespace declaration so SimpleXML element access works without prefixes.
        $content = preg_replace('/\sxmlns="[^"]+"/', '', $content, 1);

        $prevUseErrors = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content);
        if ($xml === false) {
            $errors = libxml_get_errors();
            $msg = 'Invalid XML';
            if (!empty($errors)) {
                $msg .= ': ' . trim($errors[0]->message);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);
            $result['errors'][] = $msg;
            return $result;
        }
        libxml_use_internal_errors($prevUseErrors);

        if (!isset($xml->BkToCstmrStmt)) {
            $result['errors'][] = 'Not a CAMT bank statement (missing BkToCstmrStmt)';
            return $result;
        }

        $idx = 0;
        foreach ($xml->BkToCstmrStmt->Stmt as $stmt) {
            // <Stmt><Id> scopes every line of this statement via num_releve, so
            // post-import verification can aggregate exactly the rows we wrote.
            $numReleve = (string) $stmt->Id;
            foreach ($stmt->Ntry as $ntry) {
                $idx++;
                $importResult = $this->processXmlEntry($ntry, $idx, $numReleve);
                if ($importResult === true) {
                    $result['success']++;
                } elseif ($importResult === 'skipped') {
                    $result['skipped']++;
                } else {
                    $result['errors'][] = "Entry $idx: " . $importResult;
                }
            }
        }

        if ($idx === 0) {
            $result['errors'][] = 'No transactions (Ntry) found in XML';
            return $result;
        }

        // Post-import verification: compare the bank's own summary blocks
        // (<Bal>/<TxsSummry>) against what actually landed in llx_bank.
        $result['verification'] = $this->verifyImport($xml);

        return $result;
    }

    /**
     * Run statement verification for every <Stmt> in the parsed document and
     * return a flat list of check-result records (see StatementSummary::verify).
     *
     * This is the thin Dolibarr-coupled glue around the pure StatementSummary
     * pipeline: parse() the expectation from XML, read the actuals back out of
     * llx_bank scoped by num_releve, run the verificationPrecondition() gate
     * (which short-circuits DB errors / unscopable / zero-row statements into a
     * single 'error'/'skipped' record), then aggregate() + verify() each Stmt
     * whose preconditions are sound.
     *
     * @param SimpleXMLElement $xml Root <Document> element (default namespace already stripped)
     * @return array<int, array<string, mixed>> Flat list of check-result records across all stmts
     */
    private function verifyImport($xml)
    {
        $checks = array();
        foreach (StatementSummary::parse($xml) as $expectedStmt) {
            $numReleve = (string) $expectedStmt['id'];
            $scopeEmpty = ($numReleve === '');

            // Read the actuals back — but only when the statement is scopable.
            // An empty num_releve (statement without <Id>) would run
            // WHERE num_releve = '' and scan/aggregate unrelated legacy rows,
            // only for the gate below to discard the result as 'skipped'. Skip
            // the query and feed the gate an empty read instead.
            $read = $scopeEmpty
                ? array('query_failed' => false, 'rows' => array())
                : $this->readImportedRows($numReleve);

            // Gate before verify(): a DB error, an unscopable statement (empty
            // <Id>), or zero rows under a real scope all collapse into a
            // misleading per-entry "missing" storm if fed straight into
            // verify(). The pure precondition turns each into one honest
            // 'error'/'skipped' disposition instead (reviewer findings #1/#2/#4).
            $disposition = StatementSummary::verificationPrecondition(
                $expectedStmt,
                $read['query_failed'],
                $scopeEmpty,
                count($read['rows'])
            );
            if ($disposition !== null) {
                $checks[] = $disposition;
                continue;
            }

            $actual = StatementSummary::aggregate($read['rows']);
            foreach (StatementSummary::verify($expectedStmt, $actual) as $record) {
                $checks[] = $record;
            }
        }
        return $checks;
    }

    /**
     * Read back the llx_bank rows we stored for one statement, projected into
     * the minimal shape StatementSummary::aggregate() consumes, alongside the
     * query outcome so the caller can distinguish a genuine empty result from a
     * failed query. The previous version swallowed query failures (if ($resql)
     * with no else), so a DB error silently became zero rows and verify()
     * misreported it as "every expected entry is missing" (reviewer finding #1).
     *
     * On query failure the concrete DBMS error is logged via dol_syslog() for
     * the operator; it is deliberately NOT returned for display, so the
     * user-facing 'error' disposition stays generic and does not leak SQL or
     * schema structure to anyone holding the bankimport->import right.
     *
     * Scoping is by num_releve = <Stmt><Id> on the configured account. The
     * per-entry handle is num_chq, where the import writes the CAMT.053
     * AcctSvcrRef (AGG-2); rows without it (none for Revolut) project to a
     * null ref and land in aggregate()'s unaddressable bucket.
     *
     * @param string $numReleve The <Stmt><Id> used as num_releve at import time
     * @return array{query_failed: bool, rows: list<array{ref: ?string, amount: float}>}
     */
    private function readImportedRows($numReleve)
    {
        $rows = array();
        $sql = "SELECT amount, num_chq FROM " . MAIN_DB_PREFIX . "bank"
            . " WHERE fk_account = " . ((int) $this->accountid)
            . " AND num_releve = '" . $this->db->escape($numReleve) . "'";
        $resql = $this->db->query($sql);
        if (!$resql) {
            // Surface the failure as query_failed (verify() would otherwise read
            // zero rows as "every expected entry is missing"); log the concrete
            // DBMS error for the operator rather than showing it to the user.
            dol_syslog(__CLASS__ . '::readImportedRows ' . $this->db->lasterror(), LOG_ERR);
            return array('query_failed' => true, 'rows' => $rows);
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $ref = ($obj->num_chq !== null && $obj->num_chq !== '') ? $obj->num_chq : null;
            $rows[] = array('ref' => $ref, 'amount' => (float) $obj->amount);
        }
        return array('query_failed' => false, 'rows' => $rows);
    }

    /**
     * Process a single CAMT.053 <Ntry> element.
     *
     * @param SimpleXMLElement $ntry Entry node
     * @param int $idx Entry index (1-based) for error messages
     * @param string $numReleve Statement id (<Stmt><Id>) stored as num_releve for verification scoping
     * @return bool|string True on success, 'skipped' if duplicate, error message on failure
     */
    private function processXmlEntry($ntry, $idx, $numReleve = '')
    {
        global $user;

        $amount = (float) $ntry->Amt;
        $cdtDbt = (string) $ntry->CdtDbtInd;
        if ($cdtDbt === 'DBIT') {
            $amount = -$amount;
        }

        $bookDtTm = (string) $ntry->BookgDt->DtTm;
        if ($bookDtTm === '') $bookDtTm = (string) $ntry->BookgDt->Dt;
        $valDtTm = (string) $ntry->ValDt->DtTm;
        if ($valDtTm === '') $valDtTm = (string) $ntry->ValDt->Dt;

        $dateo = $this->parseIsoDate($bookDtTm);
        if (!$dateo) {
            return 'Missing or invalid booking date';
        }
        $datev = $this->parseIsoDate($valDtTm);
        if (!$datev) $datev = $dateo;

        $transactionId = trim((string) $ntry->AcctSvcrRef);

        $label = '';
        $owner_other = '';
        $iban_other = '';
        $bank_other = '';

        if (isset($ntry->NtryDtls->TxDtls)) {
            // CRDT (incoming) -> debtor is the counterparty; DBIT (outgoing) -> creditor.
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
                $piece = $this->xmlText($tx, ['RmtInf', 'Ustrd']);
                if ($piece === '') $piece = $this->xmlText($tx, ['AddtlTxInf']);
                if ($piece !== '') {
                    $label = ($label === '') ? $piece : ($label . ' | ' . $piece);
                }

                $candName = $this->xmlText($tx, ['RltdPties', $partyTag, 'Nm']);
                if ($candName === '') {
                    $candName = $this->xmlText($tx, ['RltdPties', 'InitgPty', 'Pty', 'Nm']);
                }
                if ($candName !== '' && $owner_other === '') $owner_other = $candName;

                $candIban = $this->xmlText($tx, ['RltdPties', $acctTag, 'Id', 'IBAN']);
                if ($candIban !== '' && $iban_other === '') $iban_other = $candIban;

                $candBic = $this->xmlText($tx, ['RltdAgts', $agtTag, 'FinInstnId', 'BICFI']);
                if ($candBic === '') {
                    $candBic = $this->xmlText($tx, ['RltdAgts', $agtTag, 'FinInstnId', 'BIC']);
                }
                if ($candBic !== '' && $bank_other === '') $bank_other = $candBic;
            }
        }
        if ($label === '') $label = trim((string) $ntry->AddtlNtryInf);

        $label = $this->limitString($label);
        $owner_other = $this->limitString($owner_other);

        $ref = '';

        // Build the private note from the transaction ref plus the counterparty IBAN.
        // The IBAN used to be passed into addline()'s accountancycode slot (a pre-existing
        // bug — position 10 is $accountancycode, not an IBAN field); it lives in the note
        // instead so it is neither lost nor polluting the chart of accounts. When an entry
        // is split into principal + fee, both lines share this same note.
        $noteParts = array();
        if (!empty($transactionId)) {
            $noteParts[] = 'AcctSvcrRef=' . $transactionId;
        }
        if ($iban_other !== '') {
            $noteParts[] = 'CounterpartyIBAN=' . $iban_other;
        }
        $note = implode(' ', $noteParts);

        // num_chq carries the AcctSvcrRef so post-import verification can scope per entry
        // (AGG-2). The column is varchar(50); Revolut's AcctSvcrRef is 32 hex chars, well
        // within range. Both lines of a fee split keep the SAME num_chq (the original ref)
        // so StatementSummary::aggregate() folds them back into one logical entry equal to
        // the original Amt; the two lines are told apart instead by their import keys.
        $num_chq = $transactionId;

        // v0.0.14: when enabled, an entry whose CAMT.053 <Chrgs> records an embedded fee in
        // the entry's own currency is posted as two bank lines (principal + fee) that sum
        // to the original Amt. FeeSplitter is pure and returns null whenever there is
        // nothing to split (no Chrgs, zero, or a cross-currency fee that belongs to the
        // other FX leg), so the ordinary single-line path stays the default.
        $split = getDolGlobalInt('BANKIMPORT_SPLIT_FEES', 0) ? FeeSplitter::extract($ntry) : null;

        if ($split === null) {
            $lines = array(array(
                'amount'     => $amount,
                'label'      => $label,
                'import_key' => ImportKey::build($transactionId, $iban_other, $owner_other, $amount, $label, $ref, $dateo),
            ));
        } else {
            // The two lines MUST carry different import keys, or the dedup check would drop
            // the second as a duplicate of the first: ImportKey::build() hashes only the
            // AcctSvcrRef when one is present and ignores the amount, so we salt the fee
            // line's ref with ':fee'. With no ref the composite key already differs (the
            // fee line has a different amount and label).
            $feeRef = ($transactionId !== '') ? $transactionId . ':fee' : '';
            $feeLabel = $this->buildFeeLabel($label);
            $lines = array(
                array(
                    'amount'     => $split['main_amount'],
                    'label'      => $label,
                    'import_key' => ImportKey::build($transactionId, $iban_other, $owner_other, $split['main_amount'], $label, $ref, $dateo),
                ),
                array(
                    'amount'     => $split['fee_amount'],
                    'label'      => $feeLabel,
                    'import_key' => ImportKey::build($feeRef, $iban_other, $owner_other, $split['fee_amount'], $feeLabel, $ref, $dateo),
                ),
            );
        }

        // Dedup on the principal (first) line. Both lines are written atomically below, so
        // the principal key's presence means the whole entry was already imported. This also
        // keeps re-imports idempotent if the split setting is later toggled: FeeSplitter only
        // splits entries that carry an AcctSvcrRef, so a split principal is always keyed by the
        // bare ref hash (identical to the unsplit single line), while entries without a ref are
        // never split and stay on their stable amount-composite key in both modes.
        if ($this->isAlreadyImported($lines[0]['import_key'])) {
            return 'skipped';
        }

        $this->db->begin();
        try {
            $account = new Account($this->db);
            $account->fetch($this->accountid);

            foreach ($lines as $line) {
                $bankline_id = $account->addline(
                    $dateo,
                    'VIR',
                    $line['label'],
                    $line['amount'],
                    $num_chq,
                    null, // categorie
                    $user,
                    $owner_other,
                    $bank_other,
                    '',   // accountancycode (NOT the counterparty IBAN; see note above)
                    $datev,
                    $numReleve,
                    null, // amount_main_currency
                    $note
                );

                if ($bankline_id <= 0) {
                    $this->db->rollback();
                    return $account->error ?: 'Unknown error while inserting bank line';
                }
                $this->updateImportKey($bankline_id, $line['import_key']);
            }

            $this->db->commit();
            return true;
        } catch (Exception $e) {
            $this->db->rollback();
            return $e->getMessage();
        }
    }

    /**
     * Compose the stored label for a broken-out fee line. The base text comes from the
     * bankimport lang domain (loaded by import.php for this request); the originating
     * payment's label is appended for traceability so a reader can tell which transaction
     * the fee belongs to. The result is length-clamped like any other stored label.
     *
     * @param string $baseLabel The principal line's label (may be '').
     * @return string Length-limited fee line label.
     */
    private function buildFeeLabel($baseLabel)
    {
        global $langs;
        $text = $langs->trans('BANKIMPORT_FeeLineLabel');
        if ($baseLabel !== '') {
            $text .= ': ' . $baseLabel;
        }
        return $this->limitString($text);
    }

    /**
     * Parse an ISO-8601 datetime (e.g. "2026-04-28T16:12:03.829Z") into a
     * Dolibarr server-time timestamp at midnight of that day.
     *
     * @param string $dateString ISO-8601 datetime
     * @return int Timestamp (0 if invalid)
     */
    private function parseIsoDate($dateString)
    {
        if (empty($dateString)) return 0;
        $ts = strtotime($dateString);
        if ($ts === false || $ts <= 0) return 0;
        return dol_mktime(0, 0, 0, (int) date('m', $ts), (int) date('d', $ts), (int) date('Y', $ts));
    }

    /**
     * Safely walk a SimpleXML path and return the trimmed string value, or '' if any step is missing.
     *
     * Use this only for OPTIONAL CAMT.053 branches (RltdPties/RltdAgts and below). Mandatory
     * branches (Amt, CdtDbtInd, BookgDt, ValDt) are accessed directly because they are
     * guaranteed by the schema and a missing one is a real error worth surfacing as a warning.
     *
     * Why isset() instead of property_exists(): SimpleXMLElement overrides __isset() (SPL
     * magic) so isset($node->Foo) correctly returns false when no <Foo> child exists and
     * true when one does — including for empty containers like <Foo/>. property_exists()
     * does not honor the magic and would always return false for dynamic children.
     *
     * Edge case (intentional behavior): if an empty container exists at an intermediate
     * step (e.g. <RltdPties/>), isset() at the NEXT step returns false and we return ''.
     * If the final step lands on an empty element, trim((string) $node) yields ''. Either
     * way the caller gets '' for "not present" without distinguishing the two cases.
     *
     * Known limitation: CAMT.053 allows several elements to repeat (notably <Ustrd> inside
     * <RmtInf> — one per line of description). This helper returns ONLY THE FIRST sibling
     * at each step. For Revolut statements, Ustrd never repeats in practice, but other
     * banks (e.g. Polish ING/mBank) may split descriptions across multiple Ustrd elements
     * and that text would be silently truncated. Follow-up: when a bank with multi-line
     * Ustrd is added, change the relevant call sites to iterate, not rely on xmlText().
     *
     * @param SimpleXMLElement|null $node Starting node
     * @param string[] $path Sequence of child element names to traverse
     * @return string Trimmed text content, or '' if the path doesn't resolve
     */
    private function xmlText($node, array $path)
    {
        foreach ($path as $key) {
            if (!($node instanceof SimpleXMLElement) || !isset($node->{$key})) return '';
            $node = $node->{$key};
        }
        return trim((string) $node);
    }

    /**
     * Convert encoding of data array
     *
     * @param array $data Data array
     * @return array Converted data array
     */
    private function convertEncoding($data)
    {
        if ($this->encoding && strtoupper($this->encoding) !== 'UTF-8') {
            foreach ($data as &$field) {
                $field = iconv($this->encoding, "UTF-8//TRANSLIT", $field);
            }
        }
        return $data;
    }

    /**
     * Validate CSV row data
     *
     * @param array $data Row data
     * @param int $row Row number
     * @return bool True if valid, false otherwise
     */
    private function validateRow($data, $row)
    {
        if (count($data) < 15) {
            $this->error = 'Insufficient columns in CSV';
            return false;
        }

        // Validate required fields
        if (empty($data[$this->fieldMapping['booking_date']])) {
            $this->error = 'Missing booking date';
            return false;
        }

        if (empty($data[$this->fieldMapping['amount']])) {
            $this->error = 'Missing amount';
            return false;
        }

        return true;
    }

    /**
     * Process single CSV row
     *
     * @param array $data Row data
     * @param int $row Row number
     * @return bool|string True on success, 'skipped' if already imported, error message on failure
     */
    private function processRow($data, $row)
    {
        global $user;

        // Extract data
        $dateo = $this->parseDate($data[$this->fieldMapping['booking_date']]);
        $datev = $this->parseDate($data[$this->fieldMapping['value_date']]);
        $label = $this->limitString($data[$this->fieldMapping['payment_purpose']]);
        $amount = price2num($data[$this->fieldMapping['amount']]);
        $oper = 'VIR';
        $ref = trim($data[$this->fieldMapping['mandate_reference']]);
        $categorie = null;
        $transaction_id = null;
        $bank_other = $data[$this->fieldMapping['counterparty_bic']];
        $iban_other = $data[$this->fieldMapping['counterparty_iban']];
        $owner_other = $data[$this->fieldMapping['counterparty_name']];

        // Generate import key — booking date is included so recurring identical rows on different days don't collide.
        $import_key = ImportKey::build($transaction_id, $iban_other, $owner_other, $amount, $label, $ref, $dateo);

        // Check if already imported
        if ($this->isAlreadyImported($import_key)) {
            return 'skipped';
        }

        // Prepare notes
        $note = $this->buildNote($data);

        // Begin transaction
        $this->db->begin();

        try {
            $account = new Account($this->db);
            $account->fetch($this->accountid);

            $bankline_id = $account->addline(
                $dateo,
                $oper,
                $label,
                $amount,
                $ref,
                $categorie,
                $user,
                $owner_other,
                $bank_other,
                '',   // accountancycode (NOT the counterparty IBAN; IBAN is kept in note via buildNote)
                $datev,
                null, // num_releve (CSV has no statement id; verification is XML-only)
                null, // amount_main_currency
                $note
            );

            if ($bankline_id > 0) {
                // Update import key
                $this->updateImportKey($bankline_id, $import_key);
                $this->db->commit();
                return true;
            } else {
                $this->db->rollback();
                return $account->error;
            }
        } catch (Exception $e) {
            $this->db->rollback();
            return $e->getMessage();
        }
    }

    /**
     * Parse date from DD.MM.YY format
     *
     * @param string $dateString Date string
     * @return int Timestamp
     */
    private function parseDate($dateString)
    {
        $dd = substr($dateString, 0, 2);
        $mm = substr($dateString, 3, 2);
        $yyyy = substr($dateString, 6, 2);
        if (!empty($yyyy)) {
            $yyyy = '20' . $yyyy;
        }
        return dol_mktime(0, 0, 0, $mm, $dd, $yyyy);
    }

    /**
     * Limit string length
     *
     * @param string|null $text Text to limit
     * @param int $length Maximum length
     * @param bool $fixed Fixed length
     * @return string Limited string
     */
    private function limitString($text, $length = 255, $fixed = false)
    {
        if ($text === null) {
            return $fixed ? str_repeat(' ', $length) : '';
        }
        $limited = substr($text, 0, $length);
        return $fixed ? str_pad($limited, $length) : $limited;
    }

    /**
     * Check if a transaction is already imported INTO THE TARGET ACCOUNT.
     *
     * The account scope is essential: a Revolut FX swap between the user's own
     * accounts (e.g. "Main EUR -> Main CHF") emits the SAME AcctSvcrRef on both
     * legs — the debit in the EUR account and the credit in the CHF account.
     * Both legs are legitimate, distinct bank lines that must each import into
     * their respective account. Without the fk_account filter the second leg
     * would collide with the first on import_key and be silently dropped,
     * losing one side of every cross-account FX swap.
     *
     * @param string $import_key Import key
     * @return bool True if already imported into this account
     */
    private function isAlreadyImported($import_key)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank"
            . " WHERE import_key = '" . $this->db->escape($import_key) . "'"
            . " AND fk_account = " . ((int) $this->accountid);
        $resql = $this->db->query($sql);
        if ($resql) {
            return $this->db->num_rows($resql) > 0;
        }
        return false;
    }

    /**
     * Update import key for bank line
     *
     * @param int $bankline_id Bank line ID
     * @param string $import_key Import key
     * @return bool Success
     */
    private function updateImportKey($bankline_id, $import_key)
    {
        $sql = "UPDATE " . MAIN_DB_PREFIX . "bank SET import_key = '" . $this->db->escape($import_key) . "' WHERE rowid = " . ((int) $bankline_id);
        return $this->db->query($sql);
    }

    /**
     * Build note from CSV data
     *
     * @param array $data CSV data
     * @return string Note
     */
    private function buildNote($data)
    {
        $note = '';
        $sep = '';

        if (!empty($data[$this->fieldMapping['collector_reference']])) {
            $note .= $sep . 'Sammlerreferenz=' . $data[$this->fieldMapping['collector_reference']];
            $sep = ' ';
        }

        if (!empty($data[$this->fieldMapping['creditor_id']])) {
            $note .= $sep . 'GlaeubigerId=' . $data[$this->fieldMapping['creditor_id']];
            $sep = ' ';
        }

        // Counterparty IBAN goes into the note (not addline()'s accountancycode
        // slot — see processRow), mirroring the XML path's CounterpartyIBAN= tag.
        if (!empty($data[$this->fieldMapping['counterparty_iban']])) {
            $note .= $sep . 'CounterpartyIBAN=' . $data[$this->fieldMapping['counterparty_iban']];
            $sep = ' ';
        }

        return $note;
    }
} 
