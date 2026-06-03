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


// Load Dolibarr environment
$res = 0;
// Try main.inc.php into web root known defined into CONTEXT_DOCUMENT_ROOT (not always defined)
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Try main.inc.php into web root detected using web root calculated from SCRIPT_FILENAME
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME'];
$tmp2 = realpath(__FILE__);
$i = strlen($tmp) - 1;
$j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--;
	$j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
// Try main.inc.php using relative path
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

require_once __DIR__ . '/core/class/BankImport.class.php';

// Security check - check for bankimport rights
if (!$user->rights->bankimport->import) {
    accessforbidden();
}

$langs->load("bankimport@bankimport");

// Get parameters
$action    = GETPOST('action', 'alpha');
$accountid = GETPOST('accountid', 'int');
$encoding  = GETPOST('encoding', 'alpha'); // UTF-8 or ISO-8859-1
$splitfees = GETPOST('splitfees', 'int');  // 0/1 from the per-import checkbox
$tokenFile = GETPOST('tokenfile', 'alpha'); // our temp-file handle (NOT the CSRF token)

// Token validation is handled by Dolibarr automatically (newToken()/checkToken).

$bankImport = new BankImport($db);

// Temp area for the two-step flow: the uploaded file is parked here between "preview" and
// "commit" so nothing is parsed-and-written in one shot. It lives under DOL_DATA_ROOT (not
// web-accessible) and is removed on commit/cancel.
$tempdir = DOL_DATA_ROOT.'/bankimport/temp';

$result  = array(); // processFile() outcome (commit)
$preview = null;     // buildPreview() outcome (preview)

llxHeader('', $langs->trans("BANKIMPORT_Title"));

print load_fiche_titre($langs->trans("BANKIMPORT_Title"));


/*
 * Actions
 */

// Cancel: drop the parked file and fall back to the upload form.
if ($action == 'cancel') {
    if ($tokenFile !== '') {
        $f = $tempdir.'/'.basename($tokenFile);
        if (is_file($f)) {
            dol_delete_file($f);
        }
    }
    setEventMessages($langs->trans("BANKIMPORT_Cancelled"), null, 'mesgs');
    $action = '';
}

// Step 1 — preview: validate, park the upload, build a dry-run plan (no DB writes).
if ($action == 'upload') {
    $errors = array();

    if (empty($accountid) || $accountid == 0) {
        $errors[] = $langs->trans("BANKIMPORT_Choose_account");
    } elseif (!$bankImport->accountExists($accountid)) {
        $errors[] = $langs->trans("BANKIMPORT_Invalid_account");
    }

    if (empty($_FILES['statement']['tmp_name'])) {
        $errors[] = $langs->trans("BANKIMPORT_Choose_file");
    }

    if (!empty($errors)) {
        foreach ($errors as $error) {
            setEventMessages($error, null, 'errors');
        }
    } else {
        $bankImport->setAccountId($accountid);
        $bankImport->setEncoding($encoding);

        if (!$bankImport->validateFile($_FILES['statement'])) {
            setEventMessages($bankImport->error, null, 'errors');
        } else {
            dol_mkdir($tempdir);

            // GC orphaned previews: a user who closes the tab after preview never reaches
            // commit/cancel, so the parked statement (sensitive data) would linger forever. Drop
            // anything older than an hour whenever a new upload starts — cheap and bounded.
            $cutoff = dol_now() - 3600;
            foreach (glob($tempdir.'/*') ?: array() as $stale) {
                if (is_file($stale) && filemtime($stale) < $cutoff) {
                    dol_delete_file($stale);
                }
            }

            $tokenFile = bin2hex(random_bytes(16));
            $dest = $tempdir.'/'.$tokenFile;
            $moved = dol_move_uploaded_file($_FILES['statement']['tmp_name'], $dest, 1, 0, $_FILES['statement']['error']);
            if (!is_numeric($moved) || $moved <= 0) {
                setEventMessages($langs->trans("BANKIMPORT_Upload_failed"), null, 'errors');
            } else {
                $bankImport->setSplitFees((bool) $splitfees);
                $preview = $bankImport->buildPreview($dest);
                if (!empty($preview['errors'])) {
                    foreach ($preview['errors'] as $error) {
                        setEventMessages($error, null, 'errors');
                    }
                }
            }
        }
    }
}

// Step 2 — commit: re-parse the parked file and actually write, then remove it.
if ($action == 'commit') {
    $dest = ($tokenFile !== '') ? $tempdir.'/'.basename($tokenFile) : '';

    if ($dest === '' || !is_file($dest)) {
        setEventMessages($langs->trans("BANKIMPORT_Preview_Expired"), null, 'errors');
    } elseif (!$bankImport->accountExists($accountid)) {
        // Parity with the upload branch: the account id rode in a hidden field, so re-confirm it
        // exists before writing (authorized users only reach their own accounts — a friendly-error
        // guard, not a security boundary).
        setEventMessages($langs->trans("BANKIMPORT_Invalid_account"), null, 'errors');
        dol_delete_file($dest);
    } else {
        $bankImport->setAccountId($accountid);
        $bankImport->setEncoding($encoding);
        $bankImport->setSplitFees((bool) $splitfees);

        $result = $bankImport->processFile($dest);
        dol_delete_file($dest);

        if (!empty($result['success'])) {
            setEventMessages($langs->trans("BANKIMPORT_Success_imported", $result['success']), null, 'mesgs');
        }
        if (!empty($result['skipped'])) {
            setEventMessages($langs->trans("BANKIMPORT_Skipped_imported", $result['skipped']), null, 'warnings');
        }
        if (!empty($result['errors'])) {
            foreach ($result['errors'] as $error) {
                setEventMessages($error, null, 'errors');
            }
        }

        // Statement verification summary (XML/CAMT.053 only).
        if (!empty($result['verification'])) {
            $mismatchCount = 0;
            $skippedCount = 0;
            $errorCount = 0;
            foreach ($result['verification'] as $check) {
                if ($check['status'] === 'mismatch') {
                    $mismatchCount++;
                } elseif ($check['status'] === 'skipped') {
                    $skippedCount++;
                } elseif ($check['status'] === 'error') {
                    $errorCount++;
                }
            }
            if ($errorCount > 0) {
                setEventMessages($langs->trans("BANKIMPORT_Verification_Error", $errorCount), null, 'errors');
            }
            if ($mismatchCount > 0) {
                setEventMessages($langs->trans("BANKIMPORT_Verification_Failed", $mismatchCount), null, 'errors');
            } elseif ($skippedCount > 0 && $errorCount === 0) {
                setEventMessages($langs->trans("BANKIMPORT_Verification_PassedWithSkips", $skippedCount), null, 'warnings');
            } elseif ($errorCount === 0 && $skippedCount === 0) {
                setEventMessages($langs->trans("BANKIMPORT_Verification_AllPassed"), null, 'mesgs');
            }
        }

        // Cross-statement continuity summary (XML/CAMT.053 only). A non-empty list
        // means the bank's own balance chain has a break — most often a statement
        // file missing between two imported ones. Surfaced as a warning, not an
        // error: the rows that WERE imported are fine; the user just needs to
        // import the missing statement(s).
        if (!empty($result['continuity'])) {
            setEventMessages($langs->trans("BANKIMPORT_Continuity_Failed", count($result['continuity'])), null, 'warnings');
        }
    }
}


/*
 * View
 */

// Detailed verification table (after a commit).
if (!empty($result['verification'])) {
    $statusLabels = array(
        'ok'       => $langs->trans("BANKIMPORT_Verification_Status_ok"),
        'mismatch' => $langs->trans("BANKIMPORT_Verification_Status_mismatch"),
        'skipped'  => $langs->trans("BANKIMPORT_Verification_Status_skipped"),
        'error'    => $langs->trans("BANKIMPORT_Verification_Status_error"),
    );
    $statusColors = array('ok' => 'green', 'mismatch' => 'red', 'skipped' => '#999', 'error' => 'red');

    print load_fiche_titre($langs->trans("BANKIMPORT_Verification_Title"), '', '');
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans("BANKIMPORT_Verification_Check").'</td>';
    print '<td>'.$langs->trans("BANKIMPORT_Verification_Status").'</td>';
    print '<td>'.$langs->trans("BANKIMPORT_Verification_Detail").'</td>';
    print '</tr>';
    foreach ($result['verification'] as $check) {
        $status = $check['status'];
        $checkLabel = $check['check'];
        if (!empty($check['ref'])) {
            $checkLabel .= ' ('.dol_escape_htmltag($check['ref']).')';
        }
        $color = isset($statusColors[$status]) ? $statusColors[$status] : 'black';
        $statusText = isset($statusLabels[$status]) ? $statusLabels[$status] : $status;
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($checkLabel).'</td>';
        print '<td style="color:'.$color.';font-weight:bold;">'.dol_escape_htmltag($statusText).'</td>';
        print '<td>'.dol_escape_htmltag($check['detail']).'</td>';
        print '</tr>';
    }
    print '</table>';
    print '<br>';
}

// Cross-statement continuity gaps (after a commit). Listed separately from the
// per-statement verification table above because it answers a different question:
// not "did this statement import correctly?" but "is a statement file missing
// from the chain?".
if (!empty($result['continuity'])) {
    print load_fiche_titre($langs->trans("BANKIMPORT_Continuity_Title"), '', '');
    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td>'.$langs->trans("BANKIMPORT_Continuity_Currency").'</td>';
    print '<td>'.$langs->trans("BANKIMPORT_Continuity_Between").'</td>';
    print '<td class="right">'.$langs->trans("BANKIMPORT_Continuity_ExpectedOpening").'</td>';
    print '<td class="right">'.$langs->trans("BANKIMPORT_Continuity_ActualOpening").'</td>';
    print '</tr>';
    foreach ($result['continuity'] as $gap) {
        print '<tr class="oddeven">';
        print '<td>'.dol_escape_htmltag($gap['currency']).'</td>';
        print '<td>'.dol_escape_htmltag($gap['from_id'].' → '.$gap['to_id']).'</td>';
        print '<td class="right">'.price($gap['expected_opbd']).'</td>';
        print '<td class="right" style="color:red;font-weight:bold;">'.price($gap['actual_opbd']).'</td>';
        print '</tr>';
    }
    print '</table>';
    print '<br>';
}

// Preview table + confirm/cancel (after a successful "preview" parse).
if ($preview !== null && empty($preview['errors'])) {
    if (empty($preview['rows'])) {
        // Parked file is unusable for a commit; drop it and show the form again.
        if ($tokenFile !== '') {
            $f = $tempdir.'/'.basename($tokenFile);
            if (is_file($f)) {
                dol_delete_file($f);
            }
        }
        setEventMessages($langs->trans("BANKIMPORT_Preview_NothingToImport"), null, 'warnings');
    } else {
        print load_fiche_titre($langs->trans("BANKIMPORT_Preview_Title"), '', '');
        $summary = $langs->trans("BANKIMPORT_Preview_Summary", $preview['new'], $preview['duplicate']);
        if ($preview['split'] > 0) {
            $summary .= ' '.$langs->trans("BANKIMPORT_Preview_SplitInfo", $preview['split']);
        }
        print '<div class="info">'.$summary.'</div><br>';

        print '<table class="noborder centpercent">';
        print '<tr class="liste_titre">';
        print '<td>'.$langs->trans("BANKIMPORT_Preview_Col_Date").'</td>';
        print '<td>'.$langs->trans("BANKIMPORT_Preview_Col_Counterparty").'</td>';
        print '<td>'.$langs->trans("BANKIMPORT_Preview_Col_Label").'</td>';
        print '<td class="right">'.$langs->trans("BANKIMPORT_Preview_Col_Amount").'</td>';
        print '<td>'.$langs->trans("BANKIMPORT_Preview_Col_Type").'</td>';
        print '<td>'.$langs->trans("BANKIMPORT_Preview_Col_Status").'</td>';
        print '</tr>';

        foreach ($preview['rows'] as $r) {
            $isDup = ($r['status'] === 'duplicate');

            // Grey out duplicates (whole row). Split entries (principal + fee) get a teal accent
            // bar on the first cell so the pair reads as one group without fighting the row
            // striping — a quieter cue than a full-row background tint.
            $rowStyle = $isDup ? 'color:#999;' : '';
            $firstCellStyle = $r['is_split'] ? 'border-left:4px solid #1f9d7a;padding-left:6px;' : '';

            if ($r['is_fee']) {
                $typeText = $langs->trans("BANKIMPORT_Preview_Type_Fee");
            } elseif ($r['is_split']) {
                $typeText = $langs->trans("BANKIMPORT_Preview_Type_Principal");
            } else {
                $typeText = $langs->trans("BANKIMPORT_Preview_Type_Normal");
            }

            $statusText = $isDup ? $langs->trans("BANKIMPORT_Preview_Status_Duplicate") : $langs->trans("BANKIMPORT_Preview_Status_New");
            $statusColor = $isDup ? '#999' : 'green';

            // Fee lines are indented so the principal/fee pairing reads at a glance.
            $labelHtml = ($r['is_fee'] ? '&nbsp;&nbsp;↳ ' : '').dol_escape_htmltag($r['label']);

            print '<tr class="oddeven" style="'.$rowStyle.'">';
            print '<td style="'.$firstCellStyle.'">'.dol_print_date($r['dateo'], 'day').'</td>';
            print '<td>'.dol_escape_htmltag($r['owner']).'</td>';
            print '<td>'.$labelHtml.'</td>';
            print '<td class="right">'.price($r['amount']).'</td>';
            print '<td>'.dol_escape_htmltag($typeText).'</td>';
            print '<td style="color:'.$statusColor.';">'.dol_escape_htmltag($statusText).'</td>';
            print '</tr>';
        }
        print '</table>';
        print '<br>';

        // Confirm: re-uses the parked file (tokenfile) — re-parsed and written server-side.
        print '<div class="center">';
        print '<form action="'.$_SERVER["PHP_SELF"].'" method="post" style="display:inline-block; margin-right:10px;">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="commit">';
        print '<input type="hidden" name="tokenfile" value="'.dol_escape_htmltag($tokenFile).'">';
        print '<input type="hidden" name="accountid" value="'.((int) $accountid).'">';
        print '<input type="hidden" name="encoding" value="'.dol_escape_htmltag($encoding).'">';
        print '<input type="hidden" name="splitfees" value="'.((int) $splitfees).'">';
        print '<input type="submit" class="button" value="'.$langs->trans("BANKIMPORT_Confirm_Import").'">';
        print '</form>';

        print '<form action="'.$_SERVER["PHP_SELF"].'" method="post" style="display:inline-block;">';
        print '<input type="hidden" name="token" value="'.newToken().'">';
        print '<input type="hidden" name="action" value="cancel">';
        print '<input type="hidden" name="tokenfile" value="'.dol_escape_htmltag($tokenFile).'">';
        print '<input type="submit" class="button button-cancel" value="'.$langs->trans("BANKIMPORT_Cancel").'">';
        print '</form>';
        print '</div>';
    }
}

// Upload form — shown unless a preview is currently on screen waiting for confirmation.
$showUploadForm = ($preview === null || !empty($preview['errors']) || empty($preview['rows']));
if ($showUploadForm) {
    print '<form action="'.$_SERVER["PHP_SELF"].'" method="post" enctype="multipart/form-data">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="upload">';

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<td colspan="2">'.$langs->trans("BANKIMPORT_Import_Form").'</td>';
    print '</tr>';

    // Bank account selection
    print '<tr class="oddeven">';
    print '<td class="fieldrequired">'.$langs->trans("BANKIMPORT_Bank_account").'</td>';
    print '<td>';
    $form = new Form($db);
    print $form->select_comptes($accountid, 'accountid', 0, '', 1, 0, 'all');
    print '<span class="fieldrequired" style="color: red;">*</span>';
    print '</td>';
    print '</tr>';

    // File upload
    print '<tr class="oddeven">';
    print '<td class="fieldrequired">'.$langs->trans("BANKIMPORT_File_label").'</td>';
    print '<td>';
    print '<input type="file" name="statement" accept=".csv,.xml,text/csv,text/plain,text/xml,application/xml" required>';
    print '</td>';
    print '</tr>';

    // Encoding selection
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("BANKIMPORT_Encoding").'</td>';
    print '<td>';
    print '<select name="encoding">';
    $encodings = array('ISO-8859-1' => 'ISO-8859-1', 'UTF-8' => 'UTF-8');
    foreach ($encodings as $key => $label) {
        $selected = ($encoding == $key) ? 'selected' : '';
        print '<option value="'.$key.'" '.$selected.'>'.$label.'</option>';
    }
    print '</select>';
    print '</td>';
    print '</tr>';

    // Per-import fee-split toggle (defaults to the global BANKIMPORT_SPLIT_FEES setting).
    $splitDefault = ($action == 'upload') ? $splitfees : getDolGlobalInt('BANKIMPORT_SPLIT_FEES', 0);
    print '<tr class="oddeven">';
    print '<td>'.$langs->trans("BANKIMPORT_SplitFees_ThisImport").'</td>';
    print '<td><input type="checkbox" name="splitfees" value="1"'.($splitDefault ? ' checked' : '').'></td>';
    print '</tr>';

    // Submit
    print '<tr class="oddeven">';
    print '<td colspan="2" class="center">';
    print '<input type="submit" class="button" id="submitButton" value="'.$langs->trans("BANKIMPORT_Importieren_label").'" disabled>';
    print '</td>';
    print '</tr>';

    print '</table>';
    print '</form>';

    // JavaScript validation
    print '<script type="text/javascript">
document.addEventListener("DOMContentLoaded", function() {
    var form = document.querySelector("form");
    var accountSelect = document.querySelector("select[name=\'accountid\']");
    var fileInput = document.querySelector("input[name=\'statement\']");
    var submitButton = document.getElementById("submitButton");

    function updateSubmitButton() {
        var hasAccount = accountSelect.value && accountSelect.value != "0";
        var hasFile = fileInput.files && fileInput.files.length > 0;
        submitButton.disabled = !hasAccount || !hasFile;
    }

    accountSelect.addEventListener("change", updateSubmitButton);
    fileInput.addEventListener("change", updateSubmitButton);
    updateSubmitButton();

    form.addEventListener("submit", function(e) {
        var isValid = true;
        var errorMessages = [];

        if (!accountSelect.value || accountSelect.value == "0") {
            errorMessages.push("' . dol_escape_js($langs->trans("BANKIMPORT_Choose_account")) . '");
            accountSelect.focus();
            isValid = false;
        }

        if (!fileInput.files || fileInput.files.length === 0) {
            errorMessages.push("' . dol_escape_js($langs->trans("BANKIMPORT_Choose_file")) . '");
            if (isValid) fileInput.focus();
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
            alert(errorMessages.join("\\n"));
        }
    });
});
</script>';

    // Help
    print '<br>';
    print '<div class="info">';
    print '<strong>'.$langs->trans("BANKIMPORT_Help_Title").'</strong><br>';
    print $langs->trans("BANKIMPORT_Help_Description").'<br><br>';
    print '<strong>'.$langs->trans("BANKIMPORT_Help_Format").'</strong><br>';
    print $langs->trans("BANKIMPORT_Help_Format_Details");
    print '</div>';
}

llxFooter();
$db->close();
