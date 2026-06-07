<?php
/* BankImport minimal Module for Dolibarr */

include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

require_once __DIR__ . '/BankImportHelper.php';

class modBankImport extends DolibarrModules
{
    /**
     * Constructor
     */
    function __construct($db)
    {
        global $langs, $conf;
        $this->db = $db;

        $this->version = BankImportHelper::getEnv('VERSION', '0.0.18');

        // Unique ID (custom modules > 100000)
        $this->numero = 104001;

        $this->rights_class = 'bankimport';

        // Where the module shows up in Setup
        $this->family = "financial";
        $this->name = "BankImport";
        $this->description = "Import von Kontoauszügen";
        $this->const_name = 'MAIN_MODULE_BANKIMPORT';
        $this->license = 'MIT';
        $this->special = 0;
        $this->picto = 'bank-import-logo@bankimport';
        $this->editor_name = 'Tilo Thiele';
        $this->editor_url = 'mailto:tilo.thiele@hamburg.de';

        // Default module options. 'triggers' => 1 tells Dolibarr to scan core/triggers/ of this module
        // (writes MAIN_MODULE_BANKIMPORT_TRIGGERS on activation), enabling the line_ref orphan-cleanup
        // trigger (interface_99_modBankImport_LineRef) on bank-line deletion.
        $this->module_parts = array('triggers' => 1);
        $this->dirs = array();
        // Link the module's setup page so Dolibarr shows the configure (gear) icon
        // on the Modules list. Without this the page (admin/setup.php) — which holds
        // the "Split fees" toggle — is unreachable from the UI.
        $this->config_page_url = array('setup.php@bankimport');
        $this->depends = array();
        $this->requiredby = array();
        $this->phpmin = array(7, 4);
        $this->langfiles = array("bankimport@bankimport");

        // --- Permissions definition ---
        $r = 0;
        $this->rights[$r][0] = $this->numero + $r;
        $this->rights[$r][1] = 'Bankauszüge importieren';
        $this->rights[$r][3] = 1;
        $this->rights[$r][4] = 'import';
        $r++;

        // --- Menu definition ---
        $r = 0;
        $this->menu[$r++] = array(
            'fk_menu'   => 'fk_mainmenu=bank',
            'type'      => 'left',
            'titre'     => 'Kontoauszüge importieren',
            'mainmenu'  => 'bank',
            'leftmenu'  => 'bankimport',
            'url'       => '/custom/bankimport/import.php',
            'langs'     => 'bankimport@bankimport',
            'position'  => 100,
            'enabled'   => '1',
            'perms'     => '1',
            'target'    => '',
            'user'      => 0
        );
    }

    /**
     * Module activation: create this module's own tables.
     *
     * _load_tables() runs every .sql file under the module's sql/ directory
     * (rewriting the llx_ prefix to the instance prefix), so activating — or
     * re-activating — the module is what provisions llx_bankimport_statement.
     * The loader tolerates an already-existing table, so re-activation is safe.
     *
     * @param string $options Options when enabling module ('', 'noboxes', …)
     * @return int 1 on success, <= 0 on failure
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/bankimport/sql/');
        if ($result < 0) {
            return -1;
        }

        $sql = array();
        return $this->_init($sql, $options);
    }

    /**
     * Module deactivation. The statement-balance table is intentionally LEFT IN
     * PLACE so the continuity history survives a deactivate/reactivate cycle
     * (e.g. during an upgrade); dropping it would silently lose the chain.
     *
     * @param string $options Options when disabling module
     * @return int 1 on success, <= 0 on failure
     */
    public function remove($options = '')
    {
        $sql = array();
        return $this->_remove($sql, $options);
    }
}
