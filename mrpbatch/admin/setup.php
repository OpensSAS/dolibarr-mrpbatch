<?php
/* Module Mrpbatch — Page de configuration
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
// Recherche à partir du chemin appelé (SCRIPT_FILENAME) : fonctionne quand le module est un lien symbolique
// (ex. htdocs/custom/mrpbatch -> /opt/dolibarr-mrpbatch/mrpbatch), car __DIR__ donne alors le chemin réel.
$mrpbatchDir = empty($_SERVER['SCRIPT_FILENAME']) ? '' : dirname($_SERVER['SCRIPT_FILENAME']);
for ($mrpbatchI = 0; !$res && $mrpbatchDir !== '' && $mrpbatchI < 6; $mrpbatchI++) {
	if (file_exists($mrpbatchDir."/main.inc.php")) {
		$res = @include $mrpbatchDir."/main.inc.php";
	}
	$mrpbatchDir = dirname($mrpbatchDir);
}
if (!$res && !empty($_SERVER["DOCUMENT_ROOT"]) && file_exists($_SERVER["DOCUMENT_ROOT"]."/main.inc.php")) {
	$res = @include $_SERVER["DOCUMENT_ROOT"]."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
dol_include_once('/mrpbatch/class/mrpbatchconsume.class.php');
dol_include_once('/mrpbatch/class/mrpbatchevent.class.php');
dol_include_once('/mrpbatch/class/mrpbatchorders.class.php');
dol_include_once('/mrpbatch/class/mrpbatchplanning.class.php');

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->loadLangs(array('admin', 'mrp', 'stocks', 'productbatch'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');

/*
 * Actions
 */
// Réinstallation des menus et champs complémentaires (diagnostic)
if ($action == 'reloadmenus') {
	dol_include_once('/mrpbatch/core/modules/modMrpbatch.class.php');
	$mod = new modMrpbatch($db);
	$mod->delete_menus();
	$err = $mod->insert_menus();
	if ($err) {
		setEventMessages('Échec de la création des menus : '.($mod->error ? $mod->error : 'erreur inconnue (voir le journal dolibarr.log)'), null, 'errors');
	} else {
		setEventMessages('Menus réinstallés. Rechargez la page (F5) pour les voir.', null, 'mesgs');
	}
	if ($mod->createExtrafields()) {
		setEventMessages('Champs complémentaires : '.$mod->error, null, 'errors');
	}
	if (MrpbatchEvent::registerTriggerCodes($db)) {
		setEventMessages('Enregistrement des évènements webhook : voir le journal dolibarr.log', null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

// Nouvelle clé pour le flux ics (l'ancienne adresse cesse de fonctionner)
if ($action == 'regenics') {
	$planning = new MrpbatchPlanning($db);
	$planning->getIcsKey(true);
	setEventMessages('Nouvelle adresse d\'abonnement générée : mettez à jour les agendas abonnés.', null, 'warnings');
	header('Location: '.$_SERVER['PHP_SELF'].'#ics');
	exit;
}

if ($action == 'update') {
	$error = 0;
	require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
	foreach (array('MRPBATCH_LOT_MASK', 'MRPBATCH_SN_MASK') as $mk) {
		$mv = trim(GETPOST($mk, 'nohtml'));
		if ($mv !== '') {
			$test = get_next_value($db, $mv, 'product_lot', 'batch', '', null, dol_now());
			if (!is_string($test) || preg_match('/^Error/', $test)) {
				setEventMessages('Masque '.$mv.' invalide : '.$langs->trans((string) $test), null, 'errors');
				$error++;
				continue;
			}
		}
		dolibarr_set_const($db, $mk, $mv, 'chaine', 0, '', $conf->entity);
	}
	$values = array(
		'MRPBATCH_DEFAULT_EATBY_DAYS'  => (int) GETPOST('MRPBATCH_DEFAULT_EATBY_DAYS', 'int'),
		'MRPBATCH_DEFAULT_SELLBY_DAYS' => (int) GETPOST('MRPBATCH_DEFAULT_SELLBY_DAYS', 'int'),
		'MRPBATCH_PRODUCE_WAREHOUSE'   => max(0, (int) GETPOST('MRPBATCH_PRODUCE_WAREHOUSE', 'int')),
		'MRPBATCH_PRODUCE_PRICE'       => in_array(GETPOST('MRPBATCH_PRODUCE_PRICE', 'aZ09'), array('none', 'costprice', 'pmp')) ? GETPOST('MRPBATCH_PRODUCE_PRICE', 'aZ09') : 'none',
		'MRPBATCH_SUGGEST_BATCH'       => GETPOST('MRPBATCH_SUGGEST_BATCH', 'int') ? 1 : 0,
		'MRPBATCH_RECEPTION_WAREHOUSE' => max(0, (int) GETPOST('MRPBATCH_RECEPTION_WAREHOUSE', 'int')),
		'MRPBATCH_QUARANTINE_WAREHOUSE' => max(0, (int) GETPOST('MRPBATCH_QUARANTINE_WAREHOUSE', 'int')),
		'MRPBATCH_RECEPTION_MIN_DAYS'  => max(0, (int) GETPOST('MRPBATCH_RECEPTION_MIN_DAYS', 'int')),
		'MRPBATCH_DEFAULT_MO_HOURS'    => max(0.25, (float) str_replace(',', '.', GETPOST('MRPBATCH_DEFAULT_MO_HOURS', 'alphanohtml'))),
		'MRPBATCH_PLANNING_SHOW_DRAFT' => GETPOST('MRPBATCH_PLANNING_SHOW_DRAFT', 'int') ? 1 : 0,
		'MRPBATCH_ICS_DAYS_BACK'       => max(0, (int) GETPOST('MRPBATCH_ICS_DAYS_BACK', 'int')),
		'MRPBATCH_ICS_DAYS_FORWARD'    => max(1, (int) GETPOST('MRPBATCH_ICS_DAYS_FORWARD', 'int')),
		'MRPBATCH_DISABLE_TRIGGERS'    => GETPOST('MRPBATCH_DISABLE_TRIGGERS', 'int') ? 1 : 0,
	);
	$hours = trim(GETPOST('MRPBATCH_PLANNING_HOURS', 'alphanohtml'));
	if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $hours, $hm) && (int) $hm[1] < (int) $hm[2] && (int) $hm[2] <= 24) {
		$values['MRPBATCH_PLANNING_HOURS'] = ((int) $hm[1]).'-'.((int) $hm[2]);
	} else {
		setEventMessages('Plage horaire invalide (exemple : 6-20)', null, 'errors');
		$error++;
	}
	// Étapes de préparation des commandes : « code|Libellé » par ligne
	$stepsRaw = trim(GETPOST('MRPBATCH_ORDER_STEPS', 'restricthtml'));
	$stepsClean = array();
	foreach (preg_split('/\r\n|\r|\n/', $stepsRaw) as $sl) {
		if (strpos($sl, '|') === false) {
			continue;
		}
		list($sc, $slab) = array_map('trim', explode('|', $sl, 2));
		$sc = preg_replace('/[^a-z0-9_]/', '', strtolower($sc));
		if ($sc !== '' && $slab !== '') {
			$stepsClean[] = $sc.'|'.dol_string_nohtmltag($slab);
		}
	}
	if (count($stepsClean) < 2) {
		setEventMessages('Il faut au moins deux étapes de préparation (format code|Libellé)', null, 'errors');
		$error++;
	} else {
		$values['MRPBATCH_ORDER_STEPS'] = implode("\n", $stepsClean);
	}
	$values['MRPBATCH_ORDER_PACK_STEP'] = GETPOST('MRPBATCH_ORDER_PACK_STEP', 'aZ09');
	foreach ($values as $name => $val) {
		if (dolibarr_set_const($db, $name, $val, 'chaine', 0, '', $conf->entity) <= 0) {
			$error++;
		}
	}
	if ($error) {
		setEventMessages($langs->trans('Error'), null, 'errors');
	} else {
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

/*
 * Vue
 */
$form = new Form($db);
$formproduct = new FormProduct($db);

llxHeader('', 'Atelier — Configuration');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre('Atelier (mrpbatch) — Configuration', $linkback, 'title_setup');
print '<p><a class="butAction" href="'.dol_buildpath('/mrpbatch/index.php', 1).'">Écrans atelier</a> <a class="butAction" href="'.dol_buildpath('/mrpbatch/tablet.php', 1).'" target="mrpbatch_tablet">Version tablette</a></p>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="update">';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Production</td><td></td></tr>';

// Suggestion du n° de lot
print '<tr class="oddeven"><td>Suggérer automatiquement le n° de lot / série lors d\'une production<br>';
print '<span class="opacitymedium small">Utilise le modèle de numérotation des lots configuré dans Dolibarr (voir plus bas).</span></td><td>';
print $form->selectyesno('MRPBATCH_SUGGEST_BATCH', getDolGlobalInt('MRPBATCH_SUGGEST_BATCH', 1), 1);
print '</td></tr>';

$service = new MrpbatchConsume($db);
require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
foreach (array('MRPBATCH_LOT_MASK' => array('Masque des n° de lot', false), 'MRPBATCH_SN_MASK' => array('Masque des n° de série', true)) as $mk => $mdef) {
	$mval = $service->getMask($mdef[1]);
	$next = $mval !== '' ? get_next_value($db, $mval, 'product_lot', 'batch', '', null, dol_now()) : '';
	print '<tr class="oddeven"><td>'.$mdef[0].'<br>';
	print '<span class="opacitymedium small">Utilisé si le modèle de numérotation Dolibarr est « libre » ou non défini. Syntaxe identique aux factures : {yy} {mm} {dd} {0000} (compteur obligatoire)…</span></td><td>';
	print '<input type="text" class="minwidth200" name="'.$mk.'" value="'.dol_escape_htmltag($mval).'">';
	print ' <span class="opacitymedium">prochain : <strong>'.dol_escape_htmltag(is_string($next) ? $next : '').'</strong></span>';
	print '</td></tr>';
}

// DLC
print '<tr class="oddeven"><td>DLC proposée par défaut (jours après la date de fabrication)<br>';
print '<span class="opacitymedium small">0 = pas de proposition. Peut être surchargée par produit (champ « Durée DLC (jours) » de la fiche produit).</span></td><td>';
print '<input type="number" min="0" class="width75" name="MRPBATCH_DEFAULT_EATBY_DAYS" value="'.getDolGlobalInt('MRPBATCH_DEFAULT_EATBY_DAYS').'"> jours';
print '</td></tr>';

// DLUO / DDM
print '<tr class="oddeven"><td>DLUO / DDM proposée par défaut (jours après la date de fabrication)<br>';
print '<span class="opacitymedium small">0 = pas de proposition. Peut être surchargée par produit (champ « Durée DLUO (jours) »).</span></td><td>';
print '<input type="number" min="0" class="width75" name="MRPBATCH_DEFAULT_SELLBY_DAYS" value="'.getDolGlobalInt('MRPBATCH_DEFAULT_SELLBY_DAYS').'"> jours';
print '</td></tr>';

// Entrepôt de production
print '<tr class="oddeven"><td>Entrepôt de réception des produits fabriqués<br>';
print '<span class="opacitymedium small">Vide = entrepôt de l\'OF, sinon le premier entrepôt ouvert.</span></td><td>';
print $formproduct->selectWarehouses(getDolGlobalInt('MRPBATCH_PRODUCE_WAREHOUSE') ?: '', 'MRPBATCH_PRODUCE_WAREHOUSE', 'warehouseopen', 1, 0, 0, '', 0, 0, array(), 'minwidth200');
print '</td></tr>';

// Prix
print '<tr class="oddeven"><td>Prix d\'entrée en stock des produits fabriqués<br>';
print '<span class="opacitymedium small">« Aucun » laisse le PMP inchangé (comportement par défaut de Dolibarr sans prix saisi).</span></td><td>';
print $form->selectarray('MRPBATCH_PRODUCE_PRICE', array('none' => 'Aucun (PMP inchangé)', 'costprice' => 'Prix de revient du produit', 'pmp' => 'PMP actuel du produit'), getDolGlobalString('MRPBATCH_PRODUCE_PRICE', 'none'));
print '</td></tr>';

// ===== Réception des matières premières =====
print '<tr class="liste_titre"><td>Réception des matières premières</td><td></td></tr>';
print '<tr class="oddeven"><td>Entrepôt de réception proposé<br><span class="opacitymedium small">Vide = premier entrepôt ouvert.</span></td><td>';
print $formproduct->selectWarehouses(getDolGlobalInt('MRPBATCH_RECEPTION_WAREHOUSE') ?: '', 'MRPBATCH_RECEPTION_WAREHOUSE', 'warehouseopen', 1, 0, 0, '', 0, 0, array(), 'minwidth200');
print '</td></tr>';
print '<tr class="oddeven"><td>Entrepôt de quarantaine<br><span class="opacitymedium small">Marchandise déclarée non conforme. Vide = la réception non conforme est refusée (pas d\'entrée en stock, évènement tracé).</span></td><td>';
print $formproduct->selectWarehouses(getDolGlobalInt('MRPBATCH_QUARANTINE_WAREHOUSE') ?: '', 'MRPBATCH_QUARANTINE_WAREHOUSE', 'warehouseopen', 1, 0, 0, '', 0, 0, array(), 'minwidth200');
print '</td></tr>';
print '<tr class="oddeven"><td>DLC minimale à réception (jours)<br><span class="opacitymedium small">Alerte si la DLC saisie est plus proche (0 = alerte seulement si dépassée).</span></td><td>';
print '<input type="number" min="0" class="width75" name="MRPBATCH_RECEPTION_MIN_DAYS" value="'.getDolGlobalInt('MRPBATCH_RECEPTION_MIN_DAYS').'"> jours</td></tr>';
if (!getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_DISPATCH_ORDER')) {
	print '<tr class="oddeven"><td colspan="2"><div class="warning">L\'option du module Stocks « Augmenter les stocks réels sur ventilation manuelle dans les entrepôts, après réception de la commande fournisseur » est désactivée : '
		.'une réception rattachée à une commande fournisseur sera ventilée mais le stock ne bougera pas. Les réceptions sans commande ne sont pas concernées. '
		.'<a href="'.DOL_URL_ROOT.'/admin/stock.php">Configurer le module Stocks</a></div></td></tr>';
}

// ===== Planning et agenda =====
print '<tr class="liste_titre"><td>Planning de production</td><td></td></tr>';
print '<tr class="oddeven"><td>Plage horaire affichée<br><span class="opacitymedium small">Heures de début et de fin d\'une journée d\'atelier, ex. 6-20 ou 0-24.</span></td><td>';
print '<input type="text" class="width75" name="MRPBATCH_PLANNING_HOURS" value="'.dol_escape_htmltag(getDolGlobalString('MRPBATCH_PLANNING_HOURS', '6-20')).'"></td></tr>';
print '<tr class="oddeven"><td>Durée d\'un OF sans date de fin (heures)<br><span class="opacitymedium small">Pour dessiner la barre et pour l\'agenda (hachurée « fin estimée »).</span></td><td>';
print '<input type="text" class="width75" name="MRPBATCH_DEFAULT_MO_HOURS" value="'.dol_escape_htmltag(getDolGlobalString('MRPBATCH_DEFAULT_MO_HOURS', '2')).'"> h</td></tr>';
print '<tr class="oddeven"><td>Afficher aussi les OF brouillons</td><td>'.$form->selectyesno('MRPBATCH_PLANNING_SHOW_DRAFT', getDolGlobalInt('MRPBATCH_PLANNING_SHOW_DRAFT'), 1).'</td></tr>';
print '<tr class="oddeven"><td>Période exportée dans l\'agenda (.ics)</td><td>';
print '<input type="number" min="0" class="width50" name="MRPBATCH_ICS_DAYS_BACK" value="'.getDolGlobalInt('MRPBATCH_ICS_DAYS_BACK', 30).'"> jours passés · ';
print '<input type="number" min="1" class="width50" name="MRPBATCH_ICS_DAYS_FORWARD" value="'.getDolGlobalInt('MRPBATCH_ICS_DAYS_FORWARD', 180).'"> jours à venir</td></tr>';

// ===== Commandes clients =====
print '<tr class="liste_titre"><td>Préparation des commandes clients</td><td></td></tr>';
$stepsTxt = array();
foreach (MrpbatchOrders::getSteps() as $sc => $sl) {
	$stepsTxt[] = $sc.'|'.$sl;
}
print '<tr class="oddeven"><td>Étapes (une par ligne, <code>code|Libellé</code>)<br><span class="opacitymedium small">Colonnes du tableau de bord, dans l\'ordre. La 1re est l\'étape par défaut, la 2e est prise automatiquement au premier article préparé, la dernière émet l\'évènement « Commande client prête ».</span></td><td>';
print '<textarea name="MRPBATCH_ORDER_STEPS" rows="6" class="quatrevingtpercent">'.dol_escape_htmltag(implode("\n", $stepsTxt), 0, 1).'</textarea></td></tr>';
print '<tr class="oddeven"><td>Étape où l\'on saisit le nombre de colis et le poids</td><td>';
print $form->selectarray('MRPBATCH_ORDER_PACK_STEP', MrpbatchOrders::getSteps(), getDolGlobalString('MRPBATCH_ORDER_PACK_STEP', 'packed'), 1);
print '</td></tr>';

// ===== Évènements =====
print '<tr class="liste_titre"><td>Évènements (module Webhook, notifications)</td><td></td></tr>';
print '<tr class="oddeven"><td>Désactiver l\'émission des évènements MRPBATCH_*<br><span class="opacitymedium small">Le journal interne reste alimenté.</span></td><td>'.$form->selectyesno('MRPBATCH_DISABLE_TRIGGERS', getDolGlobalInt('MRPBATCH_DISABLE_TRIGGERS'), 1).'</td></tr>';

print '</table></div>';
print '<div class="center"><input type="submit" class="button button-save" value="'.$langs->trans('Save').'"></div>';
print '</form>';

// ===== Flux iCalendar =====
print '<br><a id="ics"></a>';
print load_fiche_titre('Agenda de production (.ics) — Google Agenda, Outlook, téléphone', '', 'fa-calendar');
$planning = new MrpbatchPlanning($db);
print '<p>Abonnez un agenda à l\'une de ces adresses (Google Agenda : <em>Autres agendas → « + » → À partir de l\'URL</em> ; Outlook : <em>Ajouter un calendrier → À partir d\'Internet</em>). '
	.'Chaque OF validé, en cours ou fabriqué devient un évènement (début / fin prévus, produit, quantité, poste, client, lien vers l\'OF). '
	.'Google Agenda rafraîchit les abonnements à son rythme (souvent plusieurs heures). L\'adresse doit être joignable depuis Internet.</p>';
print '<div class="warning">Ces adresses donnent accès au planning sans connexion : ne les diffusez qu\'en interne. Régénérez la clé en cas de fuite.</div>';
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Flux</td><td>Adresse d\'abonnement</td></tr>';
$icsRows = array(array('Tous les postes', $planning->getIcsUrl()), array('Tous les postes + livraisons des commandes clients', $planning->getIcsUrl(0, true)));
foreach ((new MrpbatchConsume($db))->getWorkstations() as $w) {
	$icsRows[] = array('Poste '.$w['ref'].($w['label'] ? ' — '.$w['label'] : ''), $planning->getIcsUrl($w['id']));
}
if (isModEnabled('workstation')) {
	$icsRows[] = array('OF sans poste de travail', $planning->getIcsUrl(-1));
}
foreach ($icsRows as $ir) {
	print '<tr class="oddeven"><td class="nowraponall">'.dol_escape_htmltag($ir[0]).'</td><td><input type="text" readonly class="quatrevingtpercent" value="'.dol_escape_htmltag($ir[1]).'" onclick="this.select()"> ';
	print '<a href="'.dol_escape_htmltag($ir[1]).'" target="_blank" title="Télécharger">'.img_picto('', 'download').'</a></td></tr>';
}
print '</table></div>';
print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" onsubmit="return confirm(\'Les agendas déjà abonnés ne seront plus mis à jour. Continuer ?\');">';
print '<input type="hidden" name="token" value="'.newToken().'"><input type="hidden" name="action" value="regenics">';
print '<input type="submit" class="button" value="Régénérer la clé du flux">';
print '</form>';

// ===== Webhook =====
print '<br>';
print load_fiche_titre('Évènements disponibles pour le module Webhook', '', 'fa-bolt');
print '<p>Dans <strong>Webhook → Cibles</strong>, ajoutez une cible (URL n8n, Make, Zapier, serveur…) et cochez les codes ci-dessous. '
	.'Chaque évènement est envoyé en POST JSON : <code>{"triggercode": "MRPBATCH_…", "object": {…}}</code>. '
	.'L\'objet contient : <code>code, label, datec, element_type</code> (mo, order_supplier, commande, product), <code>fk_element, element_ref, fk_soc, thirdparty_name, fk_product, product_ref, product_label, batch, qty, eatby, sellby, fk_warehouse, fk_workstation, status, note, data{…}, fk_user, user_login</code>. '
	.'Une cible de type « bloquante » en échec annule l\'opération atelier.</p>';
if (!isModEnabled('webhook')) {
	print '<div class="warning">Le module Webhook n\'est pas activé. <a href="'.DOL_URL_ROOT.'/admin/modules.php?search_keyword=webhook">Activer le module</a></div>';
} else {
	print '<p><a class="butAction" href="'.DOL_URL_ROOT.'/webhook/target_list.php">Gérer les cibles Webhook</a></p>';
}
$registered = array();
$resql = $db->query('SELECT code FROM '.MAIN_DB_PREFIX."c_action_trigger WHERE code LIKE 'MRPBATCH%'");
while ($resql && ($obj = $db->fetch_object($resql))) {
	$registered[$obj->code] = 1;
}
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Code</td><td>Évènement</td><td>Contenu de « data »</td><td class="center">Proposé au Webhook</td></tr>';
$dataHelp = array(
	'MRPBATCH_MO_START' => 'mo_status, mo_qty',
	'MRPBATCH_MO_CONSUME' => 'moline_id, fk_mrp_production, stock_movement_id, qty_planned',
	'MRPBATCH_MO_PRODUCE' => 'moline_id, fk_mrp_production, stock_movement_id, qty_planned',
	'MRPBATCH_MO_CLOSE' => 'mo_status, mo_qty',
	'MRPBATCH_MO_RESCHEDULE' => 'old{start,end}, new{start,end}',
	'MRPBATCH_RECEPTION' => 'conform, quarantine, stock_moved, order_line_id, stock_movement_id, ref_supplier',
	'MRPBATCH_RECEPTION_REFUSED' => 'idem + note (motif)',
	'MRPBATCH_ORDER_PICK' => 'order_line_id, qty_ordered, qty_prepared, reset',
	'MRPBATCH_ORDER_STEP' => 'step, step_label, previous_step, automatic, nb_packages, weight',
	'MRPBATCH_ORDER_READY' => 'idem MRPBATCH_ORDER_STEP',
);
foreach (MrpbatchEvent::getTriggerList() as $code => $def) {
	print '<tr class="oddeven"><td><code>'.$code.'</code></td><td><strong>'.dol_escape_htmltag($def[0]).'</strong><br><span class="opacitymedium small">'.dol_escape_htmltag($def[1]).'</span></td>';
	print '<td class="small">'.dol_escape_htmltag(isset($dataHelp[$code]) ? $dataHelp[$code] : '').'</td>';
	print '<td class="center">'.(isset($registered[$code]) ? img_picto('', 'tick') : img_picto('', 'error').' <span class="error">absent</span>').'</td></tr>';
}
print '</table></div>';

// Numérotation des lots (modèles natifs Dolibarr)
print '<br>';
print load_fiche_titre('Numérotation des lots et numéros de série', '', '');
print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Type</td><td>Modèle actif</td><td>Exemple</td></tr>';

$types = array(
	'PRODUCTBATCH_LOT_ADDON' => 'Lots',
	'PRODUCTBATCH_SN_ADDON'  => 'Numéros de série',
);
foreach ($types as $const => $label) {
	$addon = getDolGlobalString($const);
	$example = '';
	if ($addon && preg_match('/^[a-z0-9_]+$/i', $addon)) {
		$file = DOL_DOCUMENT_ROOT.'/core/modules/product_batch/'.$addon.'.php';
		if (is_readable($file)) {
			include_once $file;
		}
		if (class_exists($addon)) {
			$obj = new $addon();
			if (method_exists($obj, 'getExample')) {
				$example = $obj->getExample();
			}
		}
	}
	print '<tr class="oddeven"><td>'.$label.'</td>';
	print '<td>'.($addon ? dol_escape_htmltag($addon) : '<span class="opacitymedium">Aucun (saisie libre)</span>').'</td>';
	print '<td>'.dol_escape_htmltag($example).'</td></tr>';
}
print '<tr class="oddeven"><td>Masques par produit</td><td colspan="2">';
print 'Lots : '.(getDolGlobalString('PRODUCTBATCH_LOT_USE_PRODUCT_MASKS') ? 'activés' : 'désactivés');
print ' · Séries : '.(getDolGlobalString('PRODUCTBATCH_SN_USE_PRODUCT_MASKS') ? 'activés' : 'désactivés');
print '</td></tr>';
print '</table></div>';

print '<p>Les modèles (standard, avancé avec masque type <code>LOT{yy}{mm}{dd}-{000}</code>, masque par produit…) se configurent comme pour les factures ou les devis, dans la configuration du module Lots/Séries :</p>';
print '<a class="butAction" href="'.DOL_URL_ROOT.'/product/admin/product_lot.php">Configurer la numérotation des lots</a>';

// Diagnostic des menus
print '<br><br>';
print load_fiche_titre('Diagnostic', '', '');
dol_include_once('/mrpbatch/core/modules/modMrpbatch.class.php');
$modtmp = new modMrpbatch($db);
print '<p>Version des fichiers : <strong>'.dol_escape_htmltag($modtmp->version).'</strong> · Version activée : <strong>'.dol_escape_htmltag(getDolGlobalString('MAIN_MODULE_MRPBATCH') ? 'module actif' : 'module inactif').'</strong></p>';

$sql = 'SELECT rowid, type, mainmenu, leftmenu, fk_mainmenu, fk_leftmenu, titre, url, enabled, perms, position, usertype, entity';
$sql .= ' FROM '.MAIN_DB_PREFIX."menu WHERE module = 'mrpbatch' AND entity IN (0, ".((int) $conf->entity).')';
$sql .= ' ORDER BY position';
$resql = $db->query($sql);
print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td>Menu</td><td>Type</td><td>Rattachement</td><td>Condition « activé »</td><td>Condition « droits »</td><td class="center">Visible pour vous</td></tr>';
$nb = 0;
if ($resql) {
	while ($obj = $db->fetch_object($resql)) {
		$nb++;
		$en = verifCond($obj->enabled);
		$pe = verifCond($obj->perms);
		print '<tr class="oddeven"><td>'.dol_escape_htmltag($obj->titre).'</td><td>'.dol_escape_htmltag($obj->type).'</td>';
		print '<td>'.dol_escape_htmltag($obj->type == 'top' ? 'barre du haut ('.$obj->mainmenu.')' : $obj->fk_mainmenu.($obj->fk_leftmenu ? ' > '.$obj->fk_leftmenu : '')).'</td>';
		print '<td><code>'.dol_escape_htmltag($obj->enabled).'</code> '.($en ? img_picto('', 'tick') : img_picto('', 'error')).'</td>';
		print '<td><code>'.dol_escape_htmltag($obj->perms).'</code> '.($pe ? img_picto('', 'tick') : img_picto('', 'error')).'</td>';
		print '<td class="center">'.(($en && $pe) ? 'Oui' : '<span class="error">Non</span>').'</td></tr>';
	}
}
if (!$nb) {
	print '<tr class="oddeven"><td colspan="6"><span class="error">Aucune entrée de menu en base pour ce module : les menus n\'ont pas été créés à l\'activation.</span></td></tr>';
}
print '</table></div>';

$hasEf = 0;
$resql = $db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX."extrafields WHERE elementtype = 'product' AND name IN ('mrpbatch_dlc_days', 'mrpbatch_dluo_days')");
if ($resql && ($obj = $db->fetch_object($resql))) {
	$hasEf = (int) $obj->nb;
}
print '<p>Champs « Durée DLC / DLUO » sur la fiche produit : <strong>'.($hasEf == 2 ? 'présents' : '<span class="error">absents</span>').'</strong></p>';
$tables = array();
foreach (array('mrpbatch_event', 'mrpbatch_orderprep', 'mrpbatch_orderline') as $tb) {
	$tables[] = $tb.' '.(@$db->query('SELECT 1 FROM '.MAIN_DB_PREFIX.$tb.' WHERE 1 = 0') ? img_picto('', 'tick') : img_picto('', 'error').' <span class="error">absente (désactivez puis réactivez le module)</span>');
}
print '<p>Tables du module : '.implode(' · ', $tables).'</p>';
print '<p>API REST : '.(isModEnabled('api') ? '<code>'.dol_escape_htmltag(DOL_MAIN_URL_ROOT.'/api/index.php/mrpbatchapi/').'</code> (mos, mos/{id}/consumebatch, mos/{id}/producebatch, planning, workstations, receptions, orders, orders/{id}/step, orders/{id}/pick, events) — <a href="'.DOL_URL_ROOT.'/api/index.php/explorer" target="_blank">explorateur</a>' : 'module API REST non activé').'</p>';
$diagSvc = new MrpbatchConsume($db);
print '<p>Postes de travail : '.(isModEnabled('workstation') ? count($diagSvc->getWorkstations()).' poste(s) actif(s)'.($diagSvc->hasMoWorkstationField() ? ' · champ « Poste de travail (atelier) » présent sur les OF' : ' · <span class="error">champ « Poste de travail (atelier) » absent des OF</span> (bouton ci-dessous)') : 'module « Postes de travail » non activé : le sélecteur de poste est masqué').'</p>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="reloadmenus">';
print '<input type="submit" class="button" value="Réinstaller menus, champs complémentaires et évènements">';
print '</form>';

llxFooter();
$db->close();
