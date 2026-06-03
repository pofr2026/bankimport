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
require_once __DIR__ . '/StatementContinuity.php';
require_once __DIR__ . '/FeeSplitter.php';
require_once __DIR__ . '/EntryPlan.php';

use BankImport\ImportKey;
use BankImport\StatementSummary;
use BankImport\StatementContinuity;
use BankImport\FeeSplitter;
use BankImport\EntryPlan;

/**
 * BankImport class
 */
class BankImport extends CommonObject
{
    /**
     * Maximum accepted size of an uploaded statement file, in bytes (10 MB). Single source
     * of truth for the upload guard and for what setup.php advertises, so the limit can never
     * drift between enforcement and documentation.
     */
    public const MAX_FILE_SIZE = 10 * 1024 * 1024;

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
     * @var bool|null Per-import override for fee splitting. null means "not set" → fall back
     *                to the BANKIMPORT_SPLIT_FEES global. The preview screen sets this so a
     *                user can toggle splitting for one import without changing the global.
     */
    private $splitFees = null;

    /**
     * @var array CSV field mapping
     */
    public $fieldMapping = array(
        'booking_date' => 1,
        'value_date' => 2,
        'payment_purpose' => 4,
        'creditor_id' => 5,
        'mandate_reference' => 6,
        'collector_reference' => 8,
        'counterparty_name' => 11,
        'counterparty_iban' => 12,
        'counterparty_bic' => 13,
        'amount' => 14
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
     * Override fee splitting for this import (preview checkbox). Pass null to revert to the
     * BANKIMPORT_SPLIT_FEES global default.
     *
     * @param bool|null $on
     * @return void
     */
    public function setSplitFees($on)
    {
        $this->splitFees = ($on === null) ? null : (bool) $on;
    }

    /**
     * Resolve whether fee splitting is active: the per-import override if set, else the global.
     *
     * @return bool
     */
    private function splitFeesEnabled()
    {
        if ($this->splitFees !== null) {
            return $this->splitFees;
        }
        return (bool) getDolGlobalInt('BANKIMPORT_SPLIT_FEES', 0);
    }

    /**
     * Human-readable form of the upload limit (e.g. "10 MB"). Centralises the byte->MB conversion
     * and the unit label so every user-facing mention — the upload error, the setup page and the
     * import help — derives from MAX_FILE_SIZE and stays spelled identically.
     *
     * @return string
     */
    public static function maxFileSizeLabel()
    {
        return (self::MAX_FILE_SIZE / 1024 / 1024) . ' MB';
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

        if ($file['size'] > self::MAX_FILE_SIZE) {
            $this->error = 'File too large (max ' . self::maxFileSizeLabel() . ')';
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
     * Whether a bank account with this id exists. Used to validate the chosen account on BOTH the
     * preview-upload step and the commit step (the id rides in a hidden field between them), so the
     * existence query lives here once instead of being inlined on both call sites. A lightweight
     * indexed check — we only need yes/no, not a full Account::fetch().
     *
     * @param int $accountid
     * @return bool
     */
    public function accountExists($accountid)
    {
        $id = (int) $accountid;
        if ($id <= 0) {
            return false;
        }
        $resql = $this->db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."bank_account WHERE rowid = ".$id);
        return ($resql && $this->db->num_rows($resql) > 0);
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
     * Dry-run: parse a statement file and return the line(s) that WOULD be written, each marked
     * 'new' or 'duplicate' (per target account), WITHOUT touching the database. The preview UI
     * renders this so a wrong account or wrong file is caught before anything is committed. It
     * uses the same loaders and the same EntryPlan as the real import, so preview == commit.
     *
     * Fee splitting follows $this->splitFeesEnabled() — set it via setSplitFees() beforehand
     * (the same single source the real import uses).
     *
     * @param string $filename
     * @return array{rows: array, new: int, duplicate: int, split: int, errors: array}
     */
    public function buildPreview($filename)
    {
        $preview = array('rows' => array(), 'new' => 0, 'duplicate' => 0, 'split' => 0, 'errors' => array());

        if (empty($this->accountid) || $this->accountid <= 0) {
            $preview['errors'][] = 'No valid bank account selected';
            return $preview;
        }

        // import_keys already planned in THIS file, so two identical rows in one statement (a CSV
        // without a transaction id can collide on the composite key) are flagged the same way the
        // commit would treat them: the first is new, the rest duplicates. Keeps preview == commit
        // (writePlan would skip the later ones once the first is written).
        $seen = array();

        if ($this->detectFormat($filename) === 'xml') {
            $error = '';
            $xml = $this->loadXml($filename, $error);
            if ($xml === null) {
                $preview['errors'][] = $error;
                return $preview;
            }

            $idx = 0;
            foreach ($xml->BkToCstmrStmt->Stmt as $stmt) {
                foreach ($stmt->Ntry as $ntry) {
                    $idx++;
                    $planned = $this->planXmlNtry($ntry);
                    if (isset($planned['error'])) {
                        $preview['errors'][] = "Entry $idx: " . $planned['error'];
                        continue;
                    }
                    $this->appendPreviewRows($preview, $planned['plan'], $seen);
                }
            }
            if ($idx === 0) {
                $preview['errors'][] = 'No transactions (Ntry) found in XML';
            }
        } else {
            $handle = fopen($filename, 'r');
            if (!$handle) {
                $preview['errors'][] = 'Could not open file';
                return $preview;
            }
            $row = 0;
            while (($data = fgetcsv($handle, 0, ";")) !== FALSE) {
                $row++;
                if ($row == 1) continue; // header
                $data = $this->convertEncoding($data);
                if (!$this->validateRow($data, $row)) {
                    $preview['errors'][] = "Row $row: " . $this->error;
                    continue;
                }
                $this->appendPreviewRows($preview, $this->planCsvRowData($data), $seen);
            }
            fclose($handle);
        }

        return $preview;
    }

    /**
     * Append a plan's line(s) to the preview accumulator, tagging each with its duplicate status.
     * The whole entry's status is decided by the principal line's key — the key writePlan dedups
     * on — checked against BOTH the database (already imported) and the keys seen earlier in this
     * same file (intra-file duplicate), so the preview's "duplicate" flag matches what a commit
     * would skip.
     *
     * @param array $preview Accumulator (by reference).
     * @param array $plan    A plan from EntryPlan.
     * @param array $seen    Principal keys already planned in this file (by reference).
     * @return void
     */
    private function appendPreviewRows(&$preview, $plan, &$seen)
    {
        $principalKey = $plan['lines'][0]['import_key'];
        $duplicate = isset($seen[$principalKey]) || $this->isAlreadyImported($principalKey);
        $seen[$principalKey] = true;

        if ($plan['is_split']) {
            $preview['split']++;
        }
        foreach ($plan['lines'] as $line) {
            $preview['rows'][] = array(
                'dateo'    => $plan['dateo'],
                'owner'    => $plan['owner_other'],
                'label'    => $line['label'],
                'amount'   => $line['amount'],
                'status'   => $duplicate ? 'duplicate' : 'new',
                'is_fee'   => $line['is_fee'],
                'is_split' => $plan['is_split'],
            );
            if ($duplicate) {
                $preview['duplicate']++;
            } else {
                $preview['new']++;
            }
        }
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
        $error = '';
        $xml = $this->loadXml($filename, $error);
        if ($xml === null) {
            $this->error = $error;
            $result['errors'][] = $error;
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

        // Parse the verification-relevant blocks once and feed both consumers:
        // per-statement verification and cross-statement continuity both work off
        // the same StatementSummary::parse() output, so there is no reason to walk
        // the document twice.
        $parsedStatements = StatementSummary::parse($xml);

        // Post-import verification: compare the bank's own summary blocks
        // (<Bal>/<TxsSummry>) against what actually landed in llx_bank.
        $result['verification'] = $this->verifyImport($parsedStatements);

        // Cross-statement continuity: persist this file's declared opening/closing
        // balances, then re-check the whole stored chain for a break that would
        // betray a statement file the user never imported (CLBD_N != OPBD_(N+1)).
        $result['continuity'] = $this->checkContinuity($parsedStatements);

        return $result;
    }

    /**
     * Load + validate a CAMT.053 file into a namespace-stripped SimpleXMLElement, or return null
     * with $error set. Shared by the real import and the dry-run preview so both parse identically.
     *
     * @param string $filename
     * @param string $error Out-param: human-readable failure reason when null is returned.
     * @return \SimpleXMLElement|null
     */
    private function loadXml($filename, &$error)
    {
        $content = @file_get_contents($filename);
        if ($content === false) {
            $error = 'Could not read XML file';
            return null;
        }

        // Strip the default namespace declaration so SimpleXML element access works without prefixes.
        $content = preg_replace('/\sxmlns="[^"]+"/', '', $content, 1);

        $prevUseErrors = libxml_use_internal_errors(true);
        // LIBXML_NONET forbids any network access during parsing. libxml >= 2.9 already
        // disables external-entity expansion by default, so this is defense-in-depth for an
        // uploaded, attacker-influenced file rather than a fix for an active hole.
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            $errors = libxml_get_errors();
            $error = 'Invalid XML';
            if (!empty($errors)) {
                $error .= ': ' . trim($errors[0]->message);
            }
            libxml_clear_errors();
            libxml_use_internal_errors($prevUseErrors);
            return null;
        }
        libxml_use_internal_errors($prevUseErrors);

        if (!isset($xml->BkToCstmrStmt)) {
            $error = 'Not a CAMT bank statement (missing BkToCstmrStmt)';
            return null;
        }

        return $xml;
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
     * @param array<int, array<string, mixed>> $parsedStatements StatementSummary::parse() output (one element per <Stmt>)
     * @return array<int, array<string, mixed>> Flat list of check-result records across all stmts
     */
    private function verifyImport($parsedStatements)
    {
        $checks = array();
        foreach ($parsedStatements as $expectedStmt) {
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
     * Persist the imported file's per-statement declared balances, then re-run
     * the cross-statement continuity check over the WHOLE stored chain.
     *
     * Continuity is checked against the persisted history, not merely the
     * statements inside the file being imported: the defect we are hunting is a
     * gap between a file imported now and one imported weeks earlier, so the
     * stored chain is the only place that gap is visible. The parsed statements
     * carry per-Stmt OPBD/CLBD/currency/seq; we upsert each, then read every
     * statement stored for this account back and hand the chain to the pure
     * StatementContinuity::check().
     *
     * @param array<int, array<string, mixed>> $parsedStatements StatementSummary::parse() output (one element per <Stmt>)
     * @return array<int, array<string, mixed>> Continuity gap records (empty when the chain is intact)
     */
    private function checkContinuity($parsedStatements)
    {
        foreach ($parsedStatements as $stmt) {
            $this->persistStatementBalances($stmt);
        }
        return StatementContinuity::check($this->readStoredStatements());
    }

    /**
     * Upsert one statement's declared opening/closing balances into
     * llx_bankimport_statement, keyed by (fk_account, currency, electronic_seq_nb).
     *
     * Idempotent via delete-then-insert on the unique key, so re-importing the
     * same statement file refreshes the row instead of duplicating it. The
     * delete-then-insert pair (rather than INSERT ... ON DUPLICATE KEY UPDATE) is
     * used deliberately so the SQL stays portable across the DB drivers Dolibarr
     * supports.
     *
     * Statements that cannot anchor a continuity chain are skipped silently:
     * continuity needs a sequence number to order the chain, a currency to scope
     * it, and both balances to compare. A statement missing any of these
     * contributes nothing to the check and must not pollute the table with an
     * unorderable row.
     *
     * @param array<string, mixed> $stmt One element of StatementSummary::parse()
     * @return void
     */
    private function persistStatementBalances($stmt)
    {
        $seq = (string) ($stmt['electronic_seq_nb'] ?? '');
        $currency = (string) ($stmt['currency'] ?? '');
        if ($seq === '' || $currency === '' || $stmt['opbd'] === null || $stmt['clbd'] === null) {
            return;
        }

        $table = MAIN_DB_PREFIX . 'bankimport_statement';
        $accountId = (int) $this->accountid;

        // Wrap the delete-then-insert in a transaction so the refresh is atomic:
        // without it, an INSERT that fails after a successful DELETE would lose the
        // statement's previously stored balances and tear a hole in the very chain
        // this method exists to protect. On any failure we roll back and leave the
        // prior row untouched.
        $this->db->begin();

        $del = "DELETE FROM " . $table
            . " WHERE fk_account = " . $accountId
            . " AND currency = '" . $this->db->escape($currency) . "'"
            . " AND electronic_seq_nb = '" . $this->db->escape($seq) . "'";
        if (!$this->db->query($del)) {
            dol_syslog(__CLASS__ . '::persistStatementBalances delete ' . $this->db->lasterror(), LOG_ERR);
            $this->db->rollback();
            return;
        }

        // number_format with an explicit '.' decimal separator and 8 fractional
        // digits matches the double(24,8) column exactly and is locale-independent
        // on every PHP version (the module's declared phpmin is 7.4, where a
        // (string) cast of a float would honour LC_NUMERIC and could emit a comma).
        $sql = "INSERT INTO " . $table
            . " (fk_account, electronic_seq_nb, num_releve, currency, opbd, clbd, date_import) VALUES ("
            . $accountId . ", "
            . "'" . $this->db->escape($seq) . "', "
            . "'" . $this->db->escape((string) ($stmt['id'] ?? '')) . "', "
            . "'" . $this->db->escape($currency) . "', "
            . number_format((float) $stmt['opbd'], 8, '.', '') . ", "
            . number_format((float) $stmt['clbd'], 8, '.', '') . ", "
            . "'" . $this->db->idate(dol_now()) . "')";
        if (!$this->db->query($sql)) {
            dol_syslog(__CLASS__ . '::persistStatementBalances insert ' . $this->db->lasterror(), LOG_ERR);
            $this->db->rollback();
            return;
        }

        $this->db->commit();
    }

    /**
     * Read every persisted statement for the configured account, projected into
     * the shape StatementContinuity::check() consumes. The pure checker does the
     * per-currency grouping and sequence ordering itself, so this stays a plain
     * unordered SELECT.
     *
     * @return list<array{seq: string, currency: string, opbd: float, clbd: float, id: string}>
     */
    private function readStoredStatements()
    {
        $rows = array();
        $sql = "SELECT electronic_seq_nb, num_releve, currency, opbd, clbd FROM "
            . MAIN_DB_PREFIX . "bankimport_statement"
            . " WHERE fk_account = " . ((int) $this->accountid);
        $resql = $this->db->query($sql);
        if (!$resql) {
            dol_syslog(__CLASS__ . '::readStoredStatements ' . $this->db->lasterror(), LOG_ERR);
            return $rows;
        }
        while ($obj = $this->db->fetch_object($resql)) {
            $rows[] = array(
                'seq'      => (string) $obj->electronic_seq_nb,
                'currency' => (string) $obj->currency,
                'opbd'     => (float) $obj->opbd,
                'clbd'     => (float) $obj->clbd,
                'id'       => (string) $obj->num_releve,
            );
        }
        return $rows;
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
        $planned = $this->planXmlNtry($ntry);
        if (isset($planned['error'])) {
            return $planned['error'];
        }
        return $this->writePlan($planned['plan'], $numReleve);
    }

    /**
     * Date-parse one <Ntry> and turn it into a plan (no DB write). Returns ['plan' => array] on
     * success or ['error' => string]. Shared by the real import and the dry-run preview, so the
     * lines shown in the preview are exactly the lines the commit will write. Fee splitting is
     * read from $this->splitFeesEnabled() (the per-import override or the global) so both callers
     * use one single source of truth — set it via setSplitFees() before previewing/importing.
     *
     * @param \SimpleXMLElement $ntry
     * @return array{plan?: array, error?: string}
     */
    private function planXmlNtry($ntry)
    {
        global $langs;

        // Dates are parsed here, not in EntryPlan: dol_mktime() is Dolibarr-coupled and the
        // import_key hashes the booking timestamp, so this must keep producing it exactly as
        // before to stay dedup-compatible with already-stored rows.
        $bookDtTm = (string) $ntry->BookgDt->DtTm;
        if ($bookDtTm === '') $bookDtTm = (string) $ntry->BookgDt->Dt;
        $valDtTm = (string) $ntry->ValDt->DtTm;
        if ($valDtTm === '') $valDtTm = (string) $ntry->ValDt->Dt;

        $dateo = $this->parseIsoDate($bookDtTm);
        if (!$dateo) {
            return array('error' => 'Missing or invalid booking date');
        }
        // The value-date fallback (datev defaults to dateo when absent) now lives in EntryPlan,
        // shared with the CSV path; here we just parse and pass the result through. parseIsoDate()
        // already returns an int (0 when absent), like $dateo above, so neither needs a cast.
        $datev = $this->parseIsoDate($valDtTm);

        $plan = EntryPlan::planXmlEntry($ntry, $dateo, $datev, $this->splitFeesEnabled(), $langs->trans('BANKIMPORT_FeeLineLabel'));
        return array('plan' => $plan);
    }

    /**
     * Write a planned entry (one line, or principal + fee) to llx_bank atomically, after a
     * per-account duplicate check on the principal line's key. Shared by the XML and CSV
     * import paths (and therefore by the post-preview commit, which re-parses and re-plans);
     * the dry-run preview builds the same plan via EntryPlan but never calls this.
     *
     * @param array $plan A plan from EntryPlan::planXmlEntry()/planCsvRow().
     * @param string|null $numReleve Statement id for num_releve scoping (XML); null for CSV.
     * @return true|string True on success, 'skipped' if already imported, else an error message.
     */
    private function writePlan(array $plan, $numReleve = null)
    {
        global $user;

        // Dedup on the principal (first) line. Both lines are written atomically below, so the
        // principal key's presence means the whole entry was already imported. This keeps
        // re-imports idempotent even if the split setting is toggled: a split principal is keyed
        // by the bare ref hash (identical to the unsplit single line), and ref-less entries are
        // never split, so their amount-composite key is stable across both modes.
        if ($this->isAlreadyImported($plan['lines'][0]['import_key'])) {
            return 'skipped';
        }

        $this->db->begin();
        try {
            $account = new Account($this->db);
            $account->fetch($this->accountid);

            foreach ($plan['lines'] as $line) {
                $bankline_id = $account->addline(
                    $plan['dateo'],
                    'VIR',
                    $line['label'],
                    $line['amount'],
                    $plan['num_chq'],
                    null, // categorie
                    $user,
                    $plan['owner_other'],
                    $plan['bank_other'],
                    '',   // accountancycode (counterparty IBAN lives in the note; see EntryPlan)
                    $plan['datev'],
                    $numReleve,
                    null, // amount_main_currency
                    $plan['note']
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
        return $this->writePlan($this->planCsvRowData($data), null);
    }

    /**
     * Parse the Dolibarr-coupled parts of a CSV row (dates via dol_mktime, amount via price2num)
     * and build its plan. Shared by the real import and the dry-run preview.
     *
     * @param array $data Validated CSV row.
     * @return array Plan from EntryPlan::planCsvRow().
     */
    private function planCsvRowData($data)
    {
        // parseDate() returns '' for an empty/unparseable date column; cast to int so the typed
        // planner receives 0 (its value-date fallback then substitutes the booking date) rather
        // than hitting a TypeError on the int parameter.
        $dateo = (int) $this->parseDate($data[$this->fieldMapping['booking_date']]);
        $datev = (int) $this->parseDate($data[$this->fieldMapping['value_date']]);
        $amount = price2num($data[$this->fieldMapping['amount']]);

        return EntryPlan::planCsvRow($data, $this->fieldMapping, (float) $amount, $dateo, $datev);
    }

    /**
     * Parse a Haspa CSV date in the fixed DD.MM.YY format into a timestamp.
     *
     * The character positions are hardcoded (offsets 0/3/6) because the Haspa camt.052
     * export always emits a 2-digit year with '.' separators. A file with a 4-digit year
     * would be misread (e.g. "2024" -> substr gives "20", then the "20" prefix yields
     * 2020), so this helper is intentionally specific to the documented Haspa layout and
     * must not be reused for other date formats.
     *
     * @param string $dateString Date string in DD.MM.YY
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
}
