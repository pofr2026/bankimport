<?php
/**
 * Integration check for the orphan-cleanup trigger (keystone W2): deleting an llx_bank line must remove
 * its llx_bankimport_line_ref row (via interface_99_modBankImport_LineRef::BANKACCOUNTLINE_DELETE), and
 * must NOT touch other lines' rows. Trigger code is Dolibarr-coupled, so this is integration-verified
 * (like the wiring spike), with full self-teardown.
 *
 *   docker exec dolibarr-dev-app php /var/www/html/custom/bankimport/docs/spikes/keystone_trigger_check.php
 */

if (substr(php_sapi_name(), 0, 3) !== 'cli') {
	echo "CLI only.\n";
	exit(1);
}
if (!defined('NOSESSION')) {
	define('NOSESSION', '1');
}

require_once '/var/www/html/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/user/class/user.class.php';
require_once DOL_DOCUMENT_ROOT.'/compta/bank/class/account.class.php';

/** @var DoliDB $db */

const TEST_ACCOUNT_ID = 2; // PostF-CHF

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

function makeLine(User $user, $label)
{
	global $db;
	$acc = new Account($db);
	$acc->fetch(TEST_ACCOUNT_ID);
	$id = $acc->addline(dol_now(), 'VIR', $label, 1.00, '', 0, $user);
	if ($id <= 0) {
		throw new Exception('addline failed: '.$acc->error);
	}
	return (int) $id;
}

function insertLineRef($fkBank)
{
	global $db;
	$p = MAIN_DB_PREFIX;
	$db->query("INSERT INTO {$p}bankimport_line_ref (fk_bank, structured_ref, date_import)"
		." VALUES (".((int) $fkBank).", 'TRIG-".$fkBank."', '".$db->idate(dol_now())."')");
}

$idA = 0;
$idB = 0;
$exit = 0;

try {
	$p = MAIN_DB_PREFIX;

	$idA = makeLine($user, 'SPIKE-TRIG-A');
	$idB = makeLine($user, 'SPIKE-TRIG-B');
	insertLineRef($idA);
	insertLineRef($idB);
	echo "  setup: line A=$idA, line B=$idB, both with a line_ref row.\n";

	check('precondition: line_ref(A) exists', (int) scalar("SELECT COUNT(*) n FROM {$p}bankimport_line_ref WHERE fk_bank=".$idA) === 1);
	check('precondition: line_ref(B) exists', (int) scalar("SELECT COUNT(*) n FROM {$p}bankimport_line_ref WHERE fk_bank=".$idB) === 1);

	// Delete line A through the native path -> should fire BANKACCOUNTLINE_DELETE.
	$accline = new AccountLine($db);
	$accline->fetch($idA);
	$rc = $accline->delete($user);
	echo "  AccountLine::delete(A) returned: $rc\n";
	check('AccountLine::delete(A) succeeded', $rc > 0, (string) $rc);

	check('bank line A is gone', (int) scalar("SELECT COUNT(*) n FROM {$p}bank WHERE rowid=".$idA) === 0);
	check('trigger removed line_ref(A)', (int) scalar("SELECT COUNT(*) n FROM {$p}bankimport_line_ref WHERE fk_bank=".$idA) === 0);
	check('line_ref(B) untouched', (int) scalar("SELECT COUNT(*) n FROM {$p}bankimport_line_ref WHERE fk_bank=".$idB) === 1);
	check('bank line B still present', (int) scalar("SELECT COUNT(*) n FROM {$p}bank WHERE rowid=".$idB) === 1);
} catch (Exception $e) {
	echo "  EXCEPTION: ".$e->getMessage()."\n";
	$exit = 1;
} finally {
	$p = MAIN_DB_PREFIX;
	foreach (array($idA, $idB) as $bid) {
		if ($bid > 0) {
			$db->query("DELETE FROM {$p}bankimport_line_ref WHERE fk_bank = ".((int) $bid));
			$db->query("DELETE FROM {$p}bank_url WHERE fk_bank = ".((int) $bid));
			$db->query("DELETE FROM {$p}bank WHERE rowid = ".((int) $bid));
		}
	}
	$orphans = (int) scalar("SELECT COUNT(*) n FROM {$p}bankimport_line_ref lr LEFT JOIN {$p}bank b ON b.rowid=lr.fk_bank WHERE b.rowid IS NULL");
	echo "  teardown done. orphan line_ref rows: $orphans\n";
}

$failed = count(array_filter($ASSERTIONS, fn($a) => !$a));
echo "=== keystone trigger: ".count($ASSERTIONS)." assertions, $failed failed ===\n";
exit($exit ?: ($failed > 0 ? 3 : 0));
