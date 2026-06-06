<?php
/**
 * Integration check for the keystone wiring (spec §3, step 4) — the glue that PHPUnit cannot cover
 * because it is Dolibarr-coupled: BankImport::writePlan() capturing the principal line + writeLineRef()
 * doing the HMAC + INSERT into llx_bankimport_line_ref.
 *
 * It drives the REAL code path: EntryPlan::planXmlEntry() builds the plan from a crafted <Ntry>, then
 * the private writePlan() is invoked (via reflection) so addline() + writeLineRef() run for real. It
 * asserts the side-table row, then tears down every row it created. Throwaway (docs/spikes/), like the
 * SPIKE #1/#2 harness.
 *
 *   docker exec dolibarr-dev-app php /var/www/html/custom/bankimport/docs/spikes/keystone_wiring_check.php
 */

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	echo "CLI only.\n";
	exit(1);
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/custom/bankimport/core/class/BankImport.class.php';

use BankImport\EntryPlan;
use BankImport\IbanPseudonymizer;

/** @var DoliDB $db */

// The pepper writeLineRef() reads via `global` — set it in the script's (global) scope.
$dolibarr_main_bankimport_iban_pepper = 'pepper-123';

const TEST_ACCOUNT_ID = 2;          // PostF-CHF
const PEPPER          = 'pepper-123';
const TEST_IBAN       = 'CH9300762011623852957';

$user = new User($db);
$user->fetch(0, 'admin');
$user->loadRights();

$ASSERTIONS = array();
function check($name, $ok, $detail = '')
{
	global $ASSERTIONS;
	$ASSERTIONS[] = (bool) $ok;
	printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ($detail)" : '');
}

function scalar($sql)
{
	global $db;
	$res = $db->query($sql);
	if (!$res) {
		throw new Exception('Query failed: '.$db->lasterror()." -- $sql");
	}
	$obj = $db->fetch_object($res);
	return $obj ? $obj->n : null;
}

/** Resolve the bank line a plan line wrote, by its (unique) import_key. */
function bankIdForKey($importKey)
{
	$p = MAIN_DB_PREFIX;
	return scalar("SELECT rowid n FROM {$p}bank WHERE import_key = '".$GLOBALS['db']->escape($importKey)."'");
}

/** Invoke the private BankImport::writePlan() via reflection so the real glue runs. */
function runWritePlan(\BankImport $bi, array $plan)
{
	$ref = new ReflectionMethod($bi, 'writePlan');
	$ref->setAccessible(true);
	return $ref->invoke($bi, $plan, null);
}

$createdBankIds = array();
$exit = 0;

try {
	$bi = new \BankImport($db);
	$bi->setAccountId(TEST_ACCOUNT_ID);

	$dateo = dol_now();

	// ---------------------------------------------------------------------------------------------
	// Case A — real QR-bill entry through planXmlEntry(): one principal line, full line_ref.
	// ---------------------------------------------------------------------------------------------
	$uidA = 'spikeKW-A-'.uniqid();
	$xml = '<Ntry>'
		. '<Amt Ccy="CHF">15.02</Amt><CdtDbtInd>CRDT</CdtDbtInd>'
		. '<AcctSvcrRef>'.$uidA.'</AcctSvcrRef>'
		. '<NtryDtls><TxDtls>'
		.   '<RltdPties><Dbtr><Nm>Client</Nm></Dbtr><DbtrAcct><Id><IBAN>'.TEST_IBAN.'</IBAN></Id></DbtrAcct></RltdPties>'
		.   '<RmtInf><Strd>'
		.     '<CdtrRefInf><Tp><CdOrPrtry><Prtry>QRR</Prtry></CdOrPrtry></Tp><Ref>210000000003139471430009017</Ref></CdtrRefInf>'
		.     '<AddtlRmtInf>//S1/10/TC1-2605-0158/11/260528</AddtlRmtInf>'
		.   '</Strd></RmtInf>'
		. '</TxDtls></NtryDtls>'
		. '</Ntry>';
	$ntry = simplexml_load_string($xml);

	$planA = EntryPlan::planXmlEntry($ntry, $dateo, $dateo, false, 'Bank fee');
	$resA = runWritePlan($bi, $planA);
	check('Case A: writePlan succeeded', $resA === true, var_export($resA, true));

	$bankA = (int) bankIdForKey($planA['lines'][0]['import_key']);
	$createdBankIds[] = $bankA;

	$p = MAIN_DB_PREFIX;
	$row = $db->fetch_object($db->query(
		"SELECT structured_ref, structured_ref_type, invoice_ref_token, counterparty_iban_hmac"
		." FROM {$p}bankimport_line_ref WHERE fk_bank = ".$bankA
	));
	check('Case A: line_ref row exists', (bool) $row);
	if ($row) {
		check('Case A: structured_ref', $row->structured_ref === '210000000003139471430009017', $row->structured_ref);
		check('Case A: structured_ref_type', $row->structured_ref_type === 'QRR', (string) $row->structured_ref_type);
		check('Case A: invoice_ref_token', $row->invoice_ref_token === 'TC1-2605-0158', (string) $row->invoice_ref_token);
		check('Case A: iban_hmac == HMAC(iban, pepper)',
			$row->counterparty_iban_hmac === IbanPseudonymizer::hash(TEST_IBAN, PEPPER),
			(string) $row->counterparty_iban_hmac);
	}

	// ---------------------------------------------------------------------------------------------
	// Case B — split plan (principal + fee): line_ref must attach to the PRINCIPAL line only.
	// ---------------------------------------------------------------------------------------------
	// import_key is varchar(14) — keep the harness keys short so they are not truncated on lookup.
	$uidP = 'kwbp'.substr(uniqid(), -6);
	$uidF = 'kwbf'.substr(uniqid(), -6);
	$planB = array(
		'dateo' => $dateo, 'datev' => $dateo, 'num_chq' => 'kw-b', 'owner_other' => 'X',
		'bank_other' => '', 'note' => '', 'label' => 'PrincipalB', 'is_split' => true,
		'lines' => array(
			array('amount' => 100.0, 'label' => 'PrincipalB', 'import_key' => $uidP, 'is_fee' => false),
			array('amount' => -5.0,  'label' => 'FeeB',       'import_key' => $uidF, 'is_fee' => true),
		),
		'line_ref' => array(
			'structured_ref' => 'RF18539007547034', 'structured_ref_type' => 'SCOR',
			'invoice_ref_token' => null, 'counterparty_iban' => TEST_IBAN,
		),
	);
	$resB = runWritePlan($bi, $planB);
	check('Case B: writePlan succeeded', $resB === true, var_export($resB, true));

	$bankBp = (int) bankIdForKey($uidP);
	$bankBf = (int) bankIdForKey($uidF);
	$createdBankIds[] = $bankBp;
	$createdBankIds[] = $bankBf;

	$refOnPrincipal = (int) scalar("SELECT COUNT(*) n FROM {$p}bankimport_line_ref WHERE fk_bank = ".$bankBp);
	$refOnFee       = (int) scalar("SELECT COUNT(*) n FROM {$p}bankimport_line_ref WHERE fk_bank = ".$bankBf);
	check('Case B: line_ref on PRINCIPAL line', $refOnPrincipal === 1, "count=$refOnPrincipal");
	check('Case B: NO line_ref on FEE line', $refOnFee === 0, "count=$refOnFee");
} catch (Exception $e) {
	echo "  EXCEPTION: ".$e->getMessage()."\n";
	$exit = 1;
} finally {
	// Teardown: remove every bank line we created and its side-table row.
	$p = MAIN_DB_PREFIX;
	foreach (array_filter($createdBankIds) as $bid) {
		$db->query("DELETE FROM {$p}bankimport_line_ref WHERE fk_bank = ".((int) $bid));
		$db->query("DELETE FROM {$p}bank_url WHERE fk_bank = ".((int) $bid));
		$db->query("DELETE FROM {$p}bank_class WHERE lineid = ".((int) $bid));
		$db->query("DELETE FROM {$p}bank WHERE rowid = ".((int) $bid));
	}
	$leftover = (int) scalar("SELECT COUNT(*) n FROM {$p}bankimport_line_ref lr LEFT JOIN {$p}bank b ON b.rowid=lr.fk_bank WHERE b.rowid IS NULL");
	echo "  teardown done. orphan line_ref rows: $leftover\n";
}

$failed = count(array_filter($ASSERTIONS, fn($a) => !$a));
echo "=== keystone wiring: ".count($ASSERTIONS)." assertions, $failed failed ===\n";
exit($exit ?: ($failed > 0 ? 3 : 0));
