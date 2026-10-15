<?php
$H = $argv[1];
define('NOSESSION', 1);
require $H.'/master.inc.php';
foreach (array('/product/stock/class/entrepot.class.php', '/product/class/product.class.php', '/product/stock/class/mouvementstock.class.php', '/societe/class/societe.class.php', '/bom/class/bom.class.php', '/mrp/class/mo.class.php', '/workstation/class/workstation.class.php', '/fourn/class/fournisseur.commande.class.php', '/commande/class/commande.class.php', '/fourn/class/fournisseur.product.class.php') as $f) { require_once DOL_DOCUMENT_ROOT.$f; }
$user->fetch(1); $user->loadRights();
$langs->setDefaultLang('fr_FR');
function chk($r, $o, $what) { if ($r <= 0) { echo "ERREUR $what: ".$o->error.' '.implode(',', (array) $o->errors)."\n"; exit(1); } return $r; }
$now = dol_now();
$day0 = dol_mktime(0, 0, 0, (int) date('m'), (int) date('d'), (int) date('Y'));

$wh = array();
foreach (array('CF1' => 'Chambre froide 1', 'CF2' => 'Chambre froide 2', 'MAG' => 'Magasin sec', 'QUAR' => 'Quarantaine') as $ref => $lab) {
	$e = new Entrepot($db); $e->ref = $ref; $e->label = $ref; $e->lieu = $lab; $e->statut = 1;
	$wh[$ref] = chk($e->create($user), $e, "entrepot $ref");
}
$ws = array();
foreach (array('CUIS' => 'Cuisson', 'COND' => 'Conditionnement', 'MEL' => 'Mélange') as $ref => $lab) {
	$w = new Workstation($db); $w->ref = $ref; $w->label = $lab; $w->type = 'BOTH'; $w->status = 1; $w->nb_operators_required = 1;
	$ws[$ref] = chk($w->create($user), $w, "poste $ref");
}
$p = array();
$defs = array(
	'MP-PORC' => array('Gorge de porc', 0, 1, 2, '3760123450011', 1, 0),
	'MP-SEL'  => array('Sel nitrité', 0, 0, 0, '3760123450028', 1, 0),
	'TERR'    => array('Terrine de campagne 180 g', 0, 1, 2, '3760123459991', 0, 1),
	'OP-CUIS' => array('Opération cuisson', 1, 0, 0, '', 0, 0),
);
foreach ($defs as $ref => $d) {
	$o = new Product($db); $o->ref = $ref; $o->label = $d[0]; $o->type = $d[1]; $o->status = 1; $o->status_buy = 1; $o->status_batch = $d[2];
	$o->sell_or_eat_by_mandatory = $d[3]; $o->barcode = $d[4]; $o->barcode_type = $d[4] ? 2 : 0; $o->price = 10; $o->price_base_type = 'HT';
	if ($ref == 'TERR') { $o->array_options = array('options_mrpbatch_dlc_days' => 90); }
	$p[$ref] = chk($o->create($user), $o, "produit $ref");
}
// Stocks initiaux
$mv = new MouvementStock($db);
chk($mv->reception($user, $p['MP-PORC'], $wh['CF1'], 8, 0, 'Init', $day0 - 2 * 86400, '', 'L-OLD', '', 0, 'INIT'), $mv, 'stock1');
$mv = new MouvementStock($db);
chk($mv->reception($user, $p['MP-PORC'], $wh['CF1'], 40, 0, 'Init', $day0 + 10 * 86400, '', 'L-NEW', '', 0, 'INIT'), $mv, 'stock2');
$mv = new MouvementStock($db);
chk($mv->reception($user, $p['MP-SEL'], $wh['MAG'], 100, 0, 'Init', '', '', '', '', 0, 'INIT'), $mv, 'stock3');
$mv = new MouvementStock($db);
chk($mv->reception($user, $p['TERR'], $wh['CF2'], 50, 0, 'Init', $day0 + 80 * 86400, '', 'T-001', '', 0, 'INIT'), $mv, 'stock4');

// Tiers
$s = new Societe($db); $s->name = 'Salaisons du Lot'; $s->fournisseur = 1; $s->code_fournisseur = -1; $s->client = 0; $sup = chk($s->create($user), $s, 'fournisseur');
$s = new Societe($db); $s->name = 'Épicerie Martin'; $s->client = 1; $s->code_client = -1; $cus = chk($s->create($user), $s, 'client');
$fp = new ProductFournisseur($db); $fp->fetch($p['MP-PORC']); $fp->id = $p['MP-PORC'];
$sc = new Societe($db); $sc->fetch($sup);
$fp->update_buyprice(1, 6.5, $user, 'HT', $sc, 0, 'SL-GORGE', 5.5);

// Nomenclature
$bom = new BOM($db); $bom->ref = 'BOM-TERR'; $bom->label = 'Terrine'; $bom->fk_product = $p['TERR']; $bom->qty = 1; $bom->bomtype = 0; $bom->status = 0;
$bomid = chk($bom->create($user), $bom, 'bom');
chk($bom->addLine($p['MP-PORC'], 0.2, 0, 0, 0, 1, '', 0, '', 0, 0), $bom, 'bomline1');
chk($bom->addLine($p['MP-SEL'], 0.01, 0, 0, 0, 1, '', 0, '', 0, 0), $bom, 'bomline2');
$r = $bom->addLine($p['OP-CUIS'], 0.03, 0, 0, 0, 1, '', 0, '', 0, 0, array(), $ws['CUIS']);
chk($r, $bom, 'bomline3');
$bom->fetch($bomid); $bom->fetchLines();
chk($bom->validate($user), $bom, 'bom validate');

function mkmo($db, $user, $bomid, $prod, $qty, $start, $end, $wsid = 0) {
	$mo = new Mo($db); $mo->fk_bom = $bomid; $mo->fk_product = $prod; $mo->qty = $qty; $mo->mrptype = 0; $mo->label = 'Test'; $mo->date_start_planned = $start; $mo->date_end_planned = $end;
	if ($wsid) { $mo->array_options = array('options_mrpbatch_workstation' => $wsid); }
	$id = chk($mo->create($user), $mo, 'mo');
	$mo->fetch($id);
	chk($mo->validate($user), $mo, 'mo validate');
	return $id;
}
$mo1 = mkmo($db, $user, $bomid, $p['TERR'], 100, $day0 + 8 * 3600, $day0 + 12 * 3600);
$mo2 = mkmo($db, $user, $bomid, $p['TERR'], 50, $day0 + 86400 + 9 * 3600, 0, $ws['MEL']);

// Commande fournisseur commandée
$cf = new CommandeFournisseur($db); $cf->socid = $sup; $cf->ref_supplier = 'BL-88412'; $cf->delivery_date = $day0;
$cfid = chk($cf->create($user), $cf, 'cmd fourn');
$cf->fetch($cfid);
chk($cf->addline('Gorge', 6.5, 30, 0, 0, 0, $p['MP-PORC'], 0, 'SL-GORGE'), $cf, 'cf line');
$cf->fetch($cfid);
chk($cf->valid($user), $cf, 'cf valid');
chk($cf->approve($user), $cf, 'cf approve');
chk($cf->commande($user, $now, 2), $cf, 'cf commande');

// Commande client validée
$co = new Commande($db); $co->socid = $cus; $co->date = $now; $co->delivery_date = $day0; $co->note_public = 'Livrer avant 10 h';
$coid = chk($co->create($user), $co, 'commande');
$co->fetch($coid);
chk($co->addline('Terrines', 5, 20, 5.5, 0, 0, $p['TERR']), $co, 'co line');
chk($co->addline('Sel', 2, 3, 5.5, 0, 0, $p['MP-SEL']), $co, 'co line2');
$co->fetch($coid);
chk($co->valid($user), $co, 'co valid');
echo json_encode(array('wh' => $wh, 'ws' => $ws, 'p' => $p, 'mo1' => $mo1, 'mo2' => $mo2, 'cf' => $cfid, 'co' => $coid, 'sup' => $sup, 'cus' => $cus)), "\n";
