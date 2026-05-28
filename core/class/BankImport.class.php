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
            foreach ($stmt->Ntry as $ntry) {
                $idx++;
                $importResult = $this->processXmlEntry($ntry, $idx);
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
        }

        return $result;
    }

    /**
     * Process a single CAMT.053 <Ntry> element.
     *
     * @param SimpleXMLElement $ntry Entry node
     * @param int $idx Entry index (1-based) for error messages
     * @return bool|string True on success, 'skipped' if duplicate, error message on failure
     */
    private function processXmlEntry($ntry, $idx)
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
                $piece = trim((string) $tx->RmtInf->Ustrd);
                if ($piece === '') $piece = trim((string) $tx->AddtlTxInf);
                if ($piece !== '') {
                    $label = ($label === '') ? $piece : ($label . ' | ' . $piece);
                }

                $candName = trim((string) $tx->RltdPties->{$partyTag}->Nm);
                if ($candName === '') {
                    $candName = trim((string) $tx->RltdPties->InitgPty->Pty->Nm);
                }
                if ($candName !== '' && $owner_other === '') $owner_other = $candName;

                $candIban = trim((string) $tx->RltdPties->{$acctTag}->Id->IBAN);
                if ($candIban !== '' && $iban_other === '') $iban_other = $candIban;

                $candBic = trim((string) $tx->RltdAgts->{$agtTag}->FinInstnId->BICFI);
                if ($candBic === '') {
                    $candBic = trim((string) $tx->RltdAgts->{$agtTag}->FinInstnId->BIC);
                }
                if ($candBic !== '' && $bank_other === '') $bank_other = $candBic;
            }
        }
        if ($label === '') $label = trim((string) $ntry->AddtlNtryInf);

        $label = $this->limitString($label);
        $owner_other = $this->limitString($owner_other);

        $ref = '';

        // import_key column is varchar(14); hash AcctSvcrRef to fit.
        $importKey = (!empty($transactionId))
            ? substr(sha1($transactionId), 0, 14)
            : $this->generateImportKey(null, $iban_other, $owner_other, $amount, $label, $ref);

        if ($this->isAlreadyImported($importKey)) {
            return 'skipped';
        }

        $note = '';
        if (!empty($transactionId)) {
            $note = 'AcctSvcrRef=' . $transactionId;
        }

        $this->db->begin();
        try {
            $account = new Account($this->db);
            $account->fetch($this->accountid);

            $bankline_id = $account->addline(
                $dateo,
                'VIR',
                $label,
                $amount,
                $ref,
                null, // categorie
                $user,
                $owner_other,
                $bank_other,
                $iban_other,
                $datev,
                null, // num_releve
                null, // amount_main_currency
                $note
            );

            if ($bankline_id > 0) {
                $this->updateImportKey($bankline_id, $importKey);
                $this->db->commit();
                return true;
            }
            $this->db->rollback();
            return $account->error ?: 'Unknown error while inserting bank line';
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

        // Generate import key
        $import_key = $this->generateImportKey($transaction_id, $iban_other, $owner_other, $amount, $label, $ref);

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
                $iban_other,
                $datev,
                null, // num_releve
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
     * Generate import key
     *
     * @param string|null $transaction_id Transaction ID
     * @param string $iban_other Counterparty IBAN
     * @param string $owner_other Counterparty name
     * @param float $amount Amount
     * @param string $label Label
     * @param string $ref Reference
     * @return string Import key
     */
    private function generateImportKey($transaction_id, $iban_other, $owner_other, $amount, $label, $ref)
    {
        if (!empty($transaction_id)) {
            return trim($transaction_id);
        }

        $key = implode('|', array(
            trim($iban_other),
            trim($owner_other),
            number_format($amount, 2, '.', ''),
            trim($label),
            trim($ref)
        ));
        return substr(sha1($key), 0, 14);
    }

    /**
     * Check if transaction is already imported
     *
     * @param string $import_key Import key
     * @return bool True if already imported
     */
    private function isAlreadyImported($import_key)
    {
        $sql = "SELECT rowid FROM " . MAIN_DB_PREFIX . "bank WHERE import_key = '" . $this->db->escape($import_key) . "'";
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

        return $note;
    }
} 
