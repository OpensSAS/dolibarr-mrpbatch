<?php
// Active les modules nécessaires (CLI)
$H = $argv[1];
define('NOSESSION', 1);
define('NOREQUIREMENU', 1);
require $H.'/master.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
$user->fetch(1);
$user->loadRights();
$mods = array('modSociete', 'modProduct', 'modStock', 'modProductBatch', 'modBom', 'modMrp', 'modWorkstation', 'modCommande', 'modFournisseur', 'modExpedition', 'modWebhook', 'modApi', 'modBarcode', 'modMrpbatch');
foreach ($mods as $m) {
	$r = activateModule($m);
	echo $m.' : '.(empty($r['errors']) ? 'OK' : implode(' | ', $r['errors'])).(empty($r['nbmodules']) ? '' : ' ('.$r['nbmodules'].')')."\n";
}
dolibarr_set_const($db, 'STOCK_CALCULATE_ON_SUPPLIER_DISPATCH_ORDER', '1', 'chaine', 0, '', 1);
dolibarr_set_const($db, 'MAIN_LANG_DEFAULT', 'fr_FR', 'chaine', 0, '', 1);
// droits admin complets
$user->addrights(0, 'allmodules');
echo "done\n";
