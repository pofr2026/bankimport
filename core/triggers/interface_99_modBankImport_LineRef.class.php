<?php
/* Copyright (C) 2026 Melody
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *  \file       core/triggers/interface_99_modBankImport_LineRef.class.php
 *  \ingroup    bankimport
 *  \brief      Keeps the keystone side-table (llx_bankimport_line_ref) free of orphans.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Deletes a line's keystone matching-keys row when the underlying llx_bank line is deleted.
 *
 * llx_bankimport_line_ref is keyed on fk_bank with no FK-cascade (Dolibarr convention), so deleting an
 * llx_bank line would otherwise leave a stale row. Such a row is INERT for matching — the engine reads
 * line_ref only by joining FROM existing llx_bank lines, so an fk_bank pointing at a deleted line is
 * never selected — but it is dead storage AND it keeps a counterparty IBAN HMAC for a line that no
 * longer exists, which is a data-retention concern (spec §9). This trigger removes it immediately,
 * inside the bank line's own delete transaction (so if that delete rolls back, this cleanup rolls back
 * with it and the row is preserved).
 *
 * Scope note: our reversal flow (SPIKE #2) leaves the bank line in place (the payment keeps fk_bank
 * NULL), so it never orphans a row — native UI deletion of a bank line is the path this covers.
 */
class InterfaceLineRef extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "bankimport";
		$this->description = "Keeps the bankimport line_ref side-table free of orphans on bank-line deletion.";
		$this->version = self::VERSIONS['prod'];
		$this->picto = 'bank-import-logo@bankimport';
		$this->errors = [];
	}

	/**
	 * Function called when a Dolibarr business event is done.
	 *
	 * @param string		$action		Event action code
	 * @param CommonObject	$object     Object (an AccountLine for BANKACCOUNTLINE_DELETE)
	 * @param User		    $user       Object user
	 * @param Translate 	$langs      Object langs
	 * @param Conf		    $conf       Object conf
	 * @return int						Return integer <0 if KO, 0 if no trigger ran, >0 if OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action !== 'BANKACCOUNTLINE_DELETE') {
			return 0; // not our event
		}

		$fkBank = (int) $object->id;
		if ($fkBank <= 0) {
			return 0;
		}

		// BEST-EFFORT: must NEVER return < 0 here — a negative return rolls back AccountLine::delete()
		// and would BLOCK the native bank-line deletion. On our own failure we log and report 0 (no-op),
		// letting the line deletion proceed; a leftover row is harmless (inert) and reaped on next delete.
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."bankimport_line_ref WHERE fk_bank = ".$fkBank;
		if (!$this->db->query($sql)) {
			dol_syslog("InterfaceLineRef: failed to delete line_ref for fk_bank=".$fkBank.": ".$this->db->lasterror(), LOG_WARNING);
			return 0;
		}

		return 1;
	}
}
