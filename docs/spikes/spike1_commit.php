<?php
/**
 * SPIKE #1 — commit a payment onto an EXISTING llx_bank line without creating a duplicate.
 *
 * Throwaway exploratory test (spec §13), written test-first: the assertions in
 * assertPostState() are the spec of the desired end state, and we run two implementations
 * against the SAME assertions to prove the point:
 *
 *   --mode=naive : commit via addPaymentToBank()  -> expected RED
 *                  (creates a SECOND bank line => the duplicate the spec §2 warns about)
 *   --mode=ours  : commit via create() + Account::add_url_line() on the existing line,
 *                  NO addPaymentToBank()           -> expected GREEN
 *
 * Two flows, mirror of each other (spec §6 "two spikes, each x2"):
 *   --type=sales    : Facture          + Paiement      (links 'payment' + 'company')
 *   --type=supplier : FactureFournisseur + PaiementFourn (links 'payment_supplier' + 'company')
 *
 * Each run is self-contained: it creates a synthetic "imported CAMT" bank line (what
 * bankimport would have inserted), runs the commit under test, asserts the DB state
 * (reading the DB, not trusting object state — spec §7), then tears everything back down
 * to the pre-run baseline so runs are isolated and repeatable.
 *
 * Run:
 *   docker exec dolibarr-dev-app php .../spike1_commit.php --type=sales    --mode=naive
 *   docker exec dolibarr-dev-app php .../spike1_commit.php --type=sales    --mode=ours
 *   docker exec dolibarr-dev-app php .../spike1_commit.php --type=supplier --mode=naive
 *   docker exec dolibarr-dev-app php .../spike1_commit.php --type=supplier --mode=ours
 */

// ---------------------------------------------------------------------------
// CLI bootstrap
// ---------------------------------------------------------------------------
if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	echo "This spike must be run from the CLI.\n";
	exit(1);
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/fourn/class/paiementfourn.class.php';

/** @var DoliDB $db */
/** @var Conf $conf */

// NOTE — these query patterns are throwaway-spike convenience and must NOT be ported to the engine (§8):
//  - queries omit the `entity` filter (safe only on this single-entity dev DB),
//  - controlled inputs (table names / $type from flow_config) are interpolated without $db->escape(),
//  - the payment/company bank_url shapes are hand-mirrored from addPaymentToBank() rather than sourced
//    from native code — fine for a throwaway, a drift risk for production.

// ---------------------------------------------------------------------------
// Shared fixture configuration (no magic numbers inline — spec principle #3)
// ---------------------------------------------------------------------------
const TEST_ACCOUNT_ID   = 2;       // PostF-CHF (company currency = CHF, account currency = CHF)
const PAYMENT_MODE_ID   = 2;       // llx_c_paiement VIR (Credit Transfer)
const PAYMENT_MODE_CODE = 'VIR';
const IMPORTED_LABEL    = 'SPIKE1-IMPORTED-CAMT';   // marker for the synthetic imported bank line

/**
 * Per-flow configuration. The two flows are mirror images; everything that differs between
 * sales and supplier lives here so the engine code below stays single-sourced (DRY).
 */
function flow_config($type)
{
	$flows = array(
		'sales' => array(
			'invoice_id'     => 240,                  // open sales invoice TC1-2605-0158, 15.02 CHF
			'invoice_class'  => 'Facture',
			'payment_class'  => 'Paiement',
			'bank_mode'      => 'payment',            // addPaymentToBank() mode
			'payment_type'   => 'payment',            // bank_url type for the payment link
			'payment_url'    => DOL_URL_ROOT.'/compta/paiement/card.php?id=',
			'company_url'    => DOL_URL_ROOT.'/comm/card.php?socid=',
			'bank_sign'      => 1,                     // money received -> positive bank line
			'p_table'        => 'paiement',
			'pf_table'       => 'paiement_facture',
			'pf_fk_payment'  => 'fk_paiement',
		),
		'supplier' => array(
			'invoice_id'     => 5,                     // open supplier invoice SI2602-0001, 675.65 CHF
			'invoice_class'  => 'FactureFournisseur',
			'payment_class'  => 'PaiementFourn',
			'bank_mode'      => 'payment_supplier',
			'payment_type'   => 'payment_supplier',
			'payment_url'    => DOL_URL_ROOT.'/fourn/paiement/card.php?id=',
			'company_url'    => DOL_URL_ROOT.'/fourn/card.php?socid=',
			'bank_sign'      => -1,                    // money paid out -> negative bank line
			'p_table'        => 'paiementfourn',
			'pf_table'       => 'paiementfourn_facturefourn',
			'pf_fk_payment'  => 'fk_paiementfourn',
		),
	);
	if (!isset($flows[$type])) {
		throw new Exception("Unknown --type=$type (expected: sales | supplier)");
	}
	return $flows[$type];
}

// ---------------------------------------------------------------------------
// Small reporting / DB helpers
// ---------------------------------------------------------------------------
$ASSERTIONS = array();
function check($name, $ok, $detail = '')
{
	global $ASSERTIONS;
	$ASSERTIONS[] = array('ok' => (bool) $ok);
	printf("  [%s] %s%s\n", $ok ? 'PASS' : 'FAIL', $name, $detail !== '' ? "  ($detail)" : '');
}

function db_scalar($sql)
{
	global $db;
	$res = $db->query($sql);
	if (!$res) {
		throw new Exception('Query failed: '.$db->lasterror()." -- $sql");
	}
	$obj = $db->fetch_object($res);
	return $obj ? $obj->n : null;   // guard: no row (e.g. a bad fixture id) must not emit a notice
}

/** Snapshot the row counts we assert on, for the given flow's tables. */
function snapshot($cfg)
{
	$p = MAIN_DB_PREFIX;
	return array(
		'bank'     => (int) db_scalar("SELECT COUNT(*) n FROM {$p}bank"),
		'payment'  => (int) db_scalar("SELECT COUNT(*) n FROM {$p}{$cfg['p_table']}"),
		'pf'       => (int) db_scalar("SELECT COUNT(*) n FROM {$p}{$cfg['pf_table']}"),
	);
}

/** Count bank_url links of a given type on a given bank line. */
function url_count($fk_bank, $type)
{
	$p = MAIN_DB_PREFIX;
	return (int) db_scalar("SELECT COUNT(*) n FROM {$p}bank_url WHERE fk_bank = ".((int) $fk_bank)." AND type = '".$type."'");
}

// Registry of everything we create, so teardown removes exactly that and nothing else.
$CREATED_BANK_LINES = array();
$CREATED_PAYMENTS   = array();

// ---------------------------------------------------------------------------
// Fixture: the "imported CAMT" bank line that bankimport would have inserted.
// ---------------------------------------------------------------------------
function setup_imported_line(User $user, $amount, $cfg)
{
	global $db, $CREATED_BANK_LINES;

	$acc = new Account($db);
	if ($acc->fetch(TEST_ACCOUNT_ID) <= 0) {
		throw new Exception('Cannot fetch test bank account '.TEST_ACCOUNT_ID.': '.$acc->error);
	}

	$lineId = $acc->addline(
		dol_now(),
		PAYMENT_MODE_CODE,
		IMPORTED_LABEL,
		(float) ($cfg['bank_sign'] * $amount),   // sign: + received (sales), - paid out (supplier)
		'',
		0,
		$user
	);
	if ($lineId <= 0) {
		throw new Exception('addline (imported line) failed: '.$acc->error);
	}
	$CREATED_BANK_LINES[] = (int) $lineId;
	return (int) $lineId;
}

// ---------------------------------------------------------------------------
// Commit under test
// ---------------------------------------------------------------------------

/**
 * Build a payment for the test invoice (amount in company currency) and run create().
 * create() inserts the payment + payment<->invoice link only — it never touches llx_bank.
 */
function make_payment(User $user, $cfg, $invoiceId, $amount)
{
	global $db, $CREATED_PAYMENTS;

	/** @var Paiement|PaiementFourn $paiement */
	$paiement = new $cfg['payment_class']($db);
	$paiement->datepaye     = dol_now();
	$paiement->paiementid   = PAYMENT_MODE_ID;
	$paiement->paiementcode = PAYMENT_MODE_CODE;
	$paiement->num_payment  = '';
	$paiement->amounts      = array($invoiceId => (float) $amount); // company currency ('dolibarr' way)

	if ($cfg['payment_class'] === 'PaiementFourn') {
		// PaiementFourn::create() reads fk_account for its currency validation, and the
		// validation branch only runs when multicurrency_code[$id] is set. Set both so we
		// actually exercise that branch (open item #5) — for CHF/CHF it must pass.
		$paiement->fk_account             = TEST_ACCOUNT_ID;
		$paiement->multicurrency_code     = array($invoiceId => 'CHF');
		$paiement->multicurrency_tx       = array($invoiceId => 1);
	}

	$pid = $paiement->create($user);
	if ($pid <= 0) {
		throw new Exception($cfg['payment_class'].'::create failed: '.$paiement->error.' | '.implode(' | ', (array) $paiement->errors));
	}
	$CREATED_PAYMENTS[] = (int) $pid;
	return $paiement;
}

/** RED path: the naive commit that the spec warns duplicates the bank line. */
function commit_naive(User $user, $cfg, $invoiceId, $amount)
{
	global $CREATED_BANK_LINES;

	$paiement = make_payment($user, $cfg, $invoiceId, $amount);

	// addPaymentToBank() internally calls Account::addline() => a brand-new llx_bank line.
	$newBankId = $paiement->addPaymentToBank($user, $cfg['bank_mode'], '(spike1 naive)', TEST_ACCOUNT_ID, '', '');
	if ($newBankId <= 0) {
		throw new Exception('addPaymentToBank failed: '.$paiement->error);
	}
	$CREATED_BANK_LINES[] = (int) $newBankId;   // track the duplicate so teardown removes it

	return $paiement;
}

/** GREEN path: create() + add_url_line() on the EXISTING imported line. No addPaymentToBank. */
function commit_ours(User $user, $cfg, $invoiceId, $importedLineId, $amount)
{
	global $db;

	$paiement = make_payment($user, $cfg, $invoiceId, $amount);

	$acc = new Account($db);
	$acc->fetch(TEST_ACCOUNT_ID);

	$invoice = new $cfg['invoice_class']($db);
	$invoice->fetch($invoiceId);
	$invoice->fetch_thirdparty();

	// Recreate the exact two links addPaymentToBank would have made — but onto the existing line.
	$r1 = $acc->add_url_line($importedLineId, $paiement->id, $cfg['payment_url'], '(paiement)', $cfg['payment_type']);
	if ($r1 <= 0) {
		throw new Exception('add_url_line('.$cfg['payment_type'].') failed: '.$acc->error);
	}
	$r2 = $acc->add_url_line($importedLineId, $invoice->thirdparty->id, $cfg['company_url'], (string) $invoice->thirdparty->name, 'company');
	if ($r2 <= 0) {
		throw new Exception('add_url_line(company) failed: '.$acc->error);
	}

	// NOTE: intentionally NOT calling update_fk_bank() — this validates the fk_bank=NULL/0
	// ("reversal-safe") variant from spec §6 / open item #1.

	return $paiement;
}

// ---------------------------------------------------------------------------
// The TEST: assert the post-commit DB state (read DB, do not trust objects).
// ---------------------------------------------------------------------------
function assertPostState($baseline, $importedLineId, $paiement, $cfg, $invoiceId, $amount)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$after = snapshot($cfg);

	// (1) The whole point: exactly ONE new bank line (the imported one). No duplicate.
	$deltaBank = $after['bank'] - $baseline['bank'];
	check('no duplicate bank line (delta llx_bank == 1)', $deltaBank === 1, "delta=$deltaBank");

	// (2) Exactly one payment + one payment<->invoice link were created.
	check('one new payment row', ($after['payment'] - $baseline['payment']) === 1);
	check('one new payment<->invoice link', ($after['pf'] - $baseline['pf']) === 1);

	// (3) The payment row holds the right amount.
	$amt = (float) price2num(db_scalar("SELECT amount n FROM {$p}{$cfg['p_table']} WHERE rowid=".((int) $paiement->id)), 'MT');
	check('payment amount == invoice ttc', abs($amt - $amount) < 0.005, "amount=$amt");

	// (4) Both bank_url links sit on the EXISTING imported line (not on a duplicate).
	$nPay = url_count($importedLineId, $cfg['payment_type']);
	$nCmp = url_count($importedLineId, 'company');
	check("'".$cfg['payment_type']."' link on imported line", $nPay === 1, "count=$nPay");
	check("'company' link on imported line", $nCmp === 1, "count=$nCmp");

	// (5) The invoice balance is settled.
	$invoice = new $cfg['invoice_class']($db);
	$invoice->fetch($invoiceId);
	$remain = (float) $invoice->getRemainToPay();
	check('getRemainToPay() == 0', abs($remain) < 0.005, "remain=$remain");
}

// ---------------------------------------------------------------------------
// Teardown: remove exactly what we created, restore the invoice to open.
// ---------------------------------------------------------------------------
function teardown($cfg, $invoiceId)
{
	global $db, $user, $CREATED_BANK_LINES, $CREATED_PAYMENTS;
	$p = MAIN_DB_PREFIX;

	foreach ($CREATED_PAYMENTS as $pid) {
		$db->query("DELETE FROM {$p}{$cfg['pf_table']} WHERE {$cfg['pf_fk_payment']} = ".((int) $pid));
		$db->query("DELETE FROM {$p}{$cfg['p_table']} WHERE rowid = ".((int) $pid));
	}
	foreach ($CREATED_BANK_LINES as $bid) {
		$db->query("DELETE FROM {$p}bank_url WHERE fk_bank = ".((int) $bid));
		$db->query("DELETE FROM {$p}bank_class WHERE lineid = ".((int) $bid));
		$db->query("DELETE FROM {$p}bank WHERE rowid = ".((int) $bid));
	}
	// Re-open the invoice via the NATIVE setUnpaid() (not a raw UPDATE): it sets paye=0,
	// fk_statut=VALIDATED AND clears all closure metadata (close_code / close_note / date_closing /
	// fk_user_closing) that setPaid() wrote — keeping this live dev record clean and matching the
	// idiom the production engine (§6) will use.
	$invoice = new $cfg['invoice_class']($db);
	if ($invoice->fetch($invoiceId) > 0) {
		$invoice->setUnpaid($user);
	}
}

/**
 * Guard the assumption baked into teardown: the fixture starts at (paye=0, fk_statut=1), and teardown
 * restores it there. If the invoice is in any other state, refuse to run — otherwise teardown would
 * "restore" it to a state it was never in (silent data corruption on a live dev record).
 */
function assert_fixture_baseline_or_abort($cfg)
{
	$p = MAIN_DB_PREFIX;
	$invTable = ($cfg['invoice_class'] === 'FactureFournisseur') ? 'facture_fourn' : 'facture';
	$paye    = (int) db_scalar("SELECT paye n FROM {$p}{$invTable} WHERE rowid=".((int) $cfg['invoice_id']));
	$statut  = (int) db_scalar("SELECT fk_statut n FROM {$p}{$invTable} WHERE rowid=".((int) $cfg['invoice_id']));
	if ($paye !== 0 || $statut !== 1) {
		echo "ABORT: fixture invoice ".$cfg['invoice_id']." is not at the expected baseline "
			."(paye=0, fk_statut=1) — found paye=$paye, fk_statut=$statut. Refusing to run so teardown "
			."does not corrupt it.\n";
		exit(2);
	}
}

// ---------------------------------------------------------------------------
// SPIKE #2 — reversal. Bring an "ours"-committed payment to a clean reversed state.
// ---------------------------------------------------------------------------

/**
 * The reversed end state (the TEST for SPIKE #2):
 *   R1 the imported bank line still exists (native delete() must NOT remove it),
 *   R2 the payment + its invoice link are gone,
 *   R3 no payment/company bank_url links remain on the imported line (no orphans),
 *   R4 the invoice is reopened (paye=0) and fully owed again (remain == amount).
 */
function assertReversedState($importedLineId, $paymentId, $cfg, $invoiceId, $amount)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$invTable = ($cfg['invoice_class'] === 'FactureFournisseur') ? 'facture_fourn' : 'facture';

	$lineExists = (int) db_scalar("SELECT COUNT(*) n FROM {$p}bank WHERE rowid=".((int) $importedLineId));
	check('R1 imported bank line still exists (line-safe)', $lineExists === 1, "count=$lineExists");

	$pGone  = (int) db_scalar("SELECT COUNT(*) n FROM {$p}{$cfg['p_table']} WHERE rowid=".((int) $paymentId));
	$pfGone = (int) db_scalar("SELECT COUNT(*) n FROM {$p}{$cfg['pf_table']} WHERE {$cfg['pf_fk_payment']}=".((int) $paymentId));
	check('R2 payment row deleted', $pGone === 0, "count=$pGone");
	check('R2 payment<->invoice link deleted', $pfGone === 0, "count=$pfGone");

	$nPay = url_count($importedLineId, $cfg['payment_type']);
	$nCmp = url_count($importedLineId, 'company');
	check('R3 no orphan '.$cfg['payment_type'].' link', $nPay === 0, "count=$nPay");
	check('R3 no orphan company link', $nCmp === 0, "count=$nCmp");

	$invoice = new $cfg['invoice_class']($db);
	$invoice->fetch($invoiceId);
	$remain = (float) $invoice->getRemainToPay();
	$paye = (int) db_scalar("SELECT paye n FROM {$p}{$invTable} WHERE rowid=".((int) $invoiceId));
	check('R4 invoice reopened (paye=0)', $paye === 0, "paye=$paye");
	check('R4 invoice fully owed again (remain == amount)', abs($remain - $amount) < 0.005, "remain=$remain");
}

/**
 * Reverse a freshly committed ("ours") payment.
 *
 *   --reverse-mode=naive : just call native delete() and nothing else -> expected RED.
 *       The invoice is closed (setPaid set fk_statut=CLOSED / paye=1), so native delete()
 *       REFUSES (sales guard: f.fk_statut>1 ; supplier guard: paye=1) and our bank_url links
 *       stay behind as orphans.
 *   --reverse-mode=ours  : setUnpaid() (reopen) -> fetch + delete() (line-safe at fk_bank
 *       NULL/0) -> manually remove our bank_url links (delete() never touches them).
 */
function run_reverse(User $user, $cfg, $reverseMode, $amount)
{
	global $db, $CREATED_PAYMENTS;
	$p = MAIN_DB_PREFIX;
	$invoiceId = $cfg['invoice_id'];

	// --- Commit (persisted, NOT torn down before the reversal) ---
	$importedLineId = setup_imported_line($user, $amount, $cfg);
	$paiement = commit_ours($user, $cfg, $invoiceId, $importedLineId, $amount);
	$paymentId = (int) $paiement->id;
	$invoice = new $cfg['invoice_class']($db);
	$invoice->fetch($invoiceId);
	if (abs((float) $invoice->getRemainToPay()) < 0.005) {   // same tolerance as the assertions
		$invoice->setPaid($user);   // setPaid() — set_paid() is @deprecated in core
	}
	echo "  committed: imported line=$importedLineId, payment=$paymentId, invoice closed.\n";

	// --- Reverse ---
	if ($reverseMode === 'naive') {
		$pay = new $cfg['payment_class']($db);
		$pay->fetch($paymentId);
		$rc = $pay->delete($user);
		echo "  naive delete() returned: $rc  (error: ".$pay->error.")\n";
	} else {
		// Correct order, settled empirically: reopen BEFORE delete, or delete() refuses.
		$inv = new $cfg['invoice_class']($db);
		$inv->fetch($invoiceId);
		$ru = $inv->setUnpaid($user);
		echo "  setUnpaid() returned: $ru\n";

		$pay = new $cfg['payment_class']($db);
		$pay->fetch($paymentId);  // loads bank_line from fk_bank (NULL/0 => line-safe)
		$rc = $pay->delete($user);
		echo "  delete() returned: $rc  (error: ".$pay->error.")\n";

		// delete() does NOT remove the bank_url links we added by hand -> remove them now.
		$db->query("DELETE FROM {$p}bank_url WHERE fk_bank=".((int) $importedLineId)
			." AND type IN ('".$cfg['payment_type']."','company')");
	}

	echo "  -- reversal assertions --\n";
	assertReversedState($importedLineId, $paymentId, $cfg, $invoiceId, $amount);
}

// ---------------------------------------------------------------------------
// Driver
// ---------------------------------------------------------------------------
$mode = 'ours';
$type = 'sales';
$phase = 'commit';
$reverseMode = 'ours';
foreach ($argv as $a) {
	if (strpos($a, '--mode=') === 0) {
		$mode = substr($a, 7);
	}
	if (strpos($a, '--type=') === 0) {
		$type = substr($a, 7);
	}
	if (strpos($a, '--phase=') === 0) {
		$phase = substr($a, 8);
	}
	if (strpos($a, '--reverse-mode=') === 0) {
		$reverseMode = substr($a, 15);
	}
}
if (!in_array($mode, array('naive', 'ours'), true)) {
	echo "Unknown --mode=$mode (expected: naive | ours)\n";
	exit(2);
}
if (!in_array($phase, array('commit', 'reverse'), true)) {
	echo "Unknown --phase=$phase (expected: commit | reverse)\n";
	exit(2);
}
if (!in_array($reverseMode, array('naive', 'ours'), true)) {
	echo "Unknown --reverse-mode=$reverseMode (expected: naive | ours)\n";
	exit(2);
}

$cfg = flow_config($type);

$user = new User($db);
$user->fetch(0, 'admin');
$user->loadRights();

$p = MAIN_DB_PREFIX;
$invTable = ($cfg['invoice_class'] === 'FactureFournisseur') ? 'facture_fourn' : 'facture';
$amount = (float) price2num(db_scalar("SELECT total_ttc n FROM {$p}{$invTable} WHERE rowid=".((int) $cfg['invoice_id'])), 'MT');

$label = ($phase === 'reverse') ? "reverse-mode=$reverseMode" : "mode=$mode";
echo "=== SPIKE  phase=$phase  type=$type  $label  invoice=".$cfg['invoice_id']."  amount=$amount CHF ===\n";

assert_fixture_baseline_or_abort($cfg);   // refuse to run if the fixture is not at its known baseline

$baseline = array();
$exitCode = 0;
try {
	$baseline = snapshot($cfg);

	if ($phase === 'reverse') {
		run_reverse($user, $cfg, $reverseMode, $amount);
	} else {
		$importedLineId = setup_imported_line($user, $amount, $cfg);
		echo "  setup: imported bank line id = $importedLineId\n";

		if ($mode === 'naive') {
			$paiement = commit_naive($user, $cfg, $cfg['invoice_id'], $amount);
		} else {
			$paiement = commit_ours($user, $cfg, $cfg['invoice_id'], $importedLineId, $amount);
		}
		echo "  commit: payment id = ".$paiement->id."\n";

		// Mirror the UI: settle the invoice once fully paid.
		$invoice = new $cfg['invoice_class']($db);
		$invoice->fetch($cfg['invoice_id']);
		if (abs((float) $invoice->getRemainToPay()) < 0.005) {   // same tolerance as the assertions
			$invoice->setPaid($user);   // setPaid() — set_paid() is @deprecated in core
		}

		echo "  -- assertions --\n";
		assertPostState($baseline, $importedLineId, $paiement, $cfg, $cfg['invoice_id'], $amount);
	}
} catch (Exception $e) {
	echo "  EXCEPTION: ".$e->getMessage()."\n";
	$exitCode = 1;
} finally {
	teardown($cfg, $cfg['invoice_id']);
	$restored = snapshot($cfg);
	echo "  teardown done. baseline restored: "
		.((isset($baseline['bank']) && $restored['bank'] === $baseline['bank']) ? 'yes' : 'NO — CHECK MANUALLY')."\n";
}

$failed = 0;
foreach ($ASSERTIONS as $a) {
	if (!$a['ok']) {
		$failed++;
	}
}
echo "=== result: ".count($ASSERTIONS)." assertions, $failed failed ===\n";
exit($exitCode ?: ($failed > 0 ? 3 : 0));
