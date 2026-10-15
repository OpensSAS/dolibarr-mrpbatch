<?php
/* Module Mrpbatch — Endpoint JSON des écrans atelier (web et tablette)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', 1);
}

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

dol_include_once('/mrpbatch/class/mrpbatchconsume.class.php');
dol_include_once('/mrpbatch/class/mrpbatchreception.class.php');
dol_include_once('/mrpbatch/class/mrpbatchorders.class.php');
dol_include_once('/mrpbatch/class/mrpbatchplanning.class.php');
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

/**
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

top_httphead('application/json');

/**
 * Réponse JSON et fin
 *
 * @param array $data Données
 * @return void
 */
function mrpbatch_json($data)
{
	global $db;
	echo json_encode($data);
	$db->close();
	exit;
}

/**
 * Erreur JSON
 *
 * @param string $msg Message
 * @return void
 */
/**
 * Code scanné. Le navigateur remplace le séparateur GS1 (GS, 0x1D) par « ␝ » (U+241D) :
 * les caractères de contrôle sont refusés par le filtre anti-injection de Dolibarr (403).
 *
 * @return string
 */
function mrpbatch_code()
{
	return trim(str_replace("\xE2\x90\x9D", "\x1D", GETPOST('code', 'alphanohtml')));
}

function mrpbatch_err($msg)
{
	mrpbatch_json(array('ok' => false, 'error' => $msg));
}

/**
 * Exige une requête POST
 *
 * @return void
 */
function mrpbatch_post()
{
	if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
		mrpbatch_err('POST requis');
	}
}

if (!isModEnabled('mrpbatch')) {
	mrpbatch_err('Module désactivé');
}

$langs->loadLangs(array('mrp', 'stocks', 'errors'));

$what    = GETPOST('what', 'aZ09');
$id      = (int) GETPOST('id', 'int');
$ws      = (int) GETPOST('ws', 'int');
$service = new MrpbatchConsume($db);

$canMrpRead = $user->hasRight('mrp', 'read');
$canWrite = $canMrpRead && $user->hasRight('mrp', 'write') && $user->hasRight('stock', 'mouvement', 'creer');
$rcRights = MrpbatchReception::rights($user);
$odRights = MrpbatchOrders::rights($user);

/**
 * Charge un OF avec ses lignes
 *
 * @param int $id ID OF
 * @return Mo
 */
function mrpbatch_loadmo($id)
{
	global $db;
	$mo = new Mo($db);
	if ($id <= 0 || $mo->fetch($id) <= 0) {
		mrpbatch_err('OF introuvable');
	}
	if (empty($mo->lines)) {
		$mo->fetchLines();
	}
	return $mo;
}

/**
 * Données d'un OF pour l'écran atelier
 *
 * @param Mo              $mo      OF
 * @param MrpbatchConsume $service Service
 * @return array
 */
function mrpbatch_mopayload(Mo $mo, MrpbatchConsume $service)
{
	global $db;

	$prod = new Product($db);
	if ($mo->fk_product > 0) {
		$prod->fetch($mo->fk_product);
	}
	$socname = '';
	if (!empty($mo->fk_soc)) {
		$soc = new Societe($db);
		if ($soc->fetch($mo->fk_soc) > 0) {
			$socname = $soc->name;
		}
	}

	$lines = $service->getConsumeLines($mo, false);
	$planned = 0;
	$consumed = 0;
	foreach ($lines as $l) {
		$planned += $l['qty_planned'];
		$consumed += min($l['qty_consumed'], $l['qty_planned']);
	}
	$plines = $service->getProduceLines($mo);
	$pplanned = 0;
	$pdone = 0;
	foreach ($plines as $l) {
		$pplanned += $l['qty_planned'];
		$pdone += $l['qty_produced'];
	}
	$map = $service->getMoWorkstationMap(array($mo->id));

	return array(
		'id'               => (int) $mo->id,
		'ref'              => $mo->ref,
		'label'            => (string) $mo->label,
		'status'           => (int) $mo->status,
		'consumable'       => in_array((int) $mo->status, array(Mo::STATUS_VALIDATED, Mo::STATUS_INPROGRESS)),
		'product_ref'      => (string) $prod->ref,
		'product_label'    => (string) $prod->label,
		'thirdparty'       => $socname,
		'qty'              => (float) $mo->qty,
		'date_start'       => $mo->date_start_planned ? dol_print_date($mo->date_start_planned, 'dayhour') : '',
		'date_end'         => $mo->date_end_planned ? dol_print_date($mo->date_end_planned, 'dayhour') : '',
		'workstations'     => isset($map[$mo->id]) ? $map[$mo->id] : array(),
		'pct'              => $planned > 0 ? (int) round(100 * $consumed / $planned) : 0,
		'produced'         => (float) $pdone,
		'produce_planned'  => (float) $pplanned,
		'produce_pct'      => $pplanned > 0 ? (int) min(100, round(100 * $pdone / $pplanned)) : 0,
		'url'              => DOL_URL_ROOT.'/mrp/mo_card.php?id='.((int) $mo->id),
		'lines'            => $lines,
		'produce_lines'    => $plines,
		'history'          => $service->getHistory($mo, 'consumed'),
		'history_produced' => $service->getHistory($mo, 'produced'),
	);
}

/**
 * Panneau de choix de lot pour une ligne de composant
 *
 * @param Mo              $mo      OF
 * @param array           $line    Ligne issue de getConsumeLines
 * @param string          $batch   Lot scanné éventuel
 * @param MrpbatchConsume $service Service
 * @return array
 */
function mrpbatch_consumepayload(Mo $mo, $line, $batch, MrpbatchConsume $service)
{
	$stock = $service->getAvailableStock($line['fk_product'], $line['status_batch']);
	$selected = -1;
	if (!empty($stock)) {
		$selected = 0;
		$done = false;
		if ($batch !== '') {
			foreach ($stock as $i => $s) {
				if ($s['batch'] === $batch) {
					$selected = $i;
					$done = true;
					break;
				}
			}
		}
		if (!$done) {
			// Premier lot non périmé (FEFO), de préférence dans l'entrepôt de l'OF
			$now = dol_now();
			$firstOk = -1;
			foreach ($stock as $i => $s) {
				if ($s['eatby'] && $s['eatby'] < $now) {
					continue;
				}
				if ($firstOk < 0) {
					$firstOk = $i;
				}
				if (!empty($mo->fk_warehouse) && $s['fk_warehouse'] == $mo->fk_warehouse) {
					$firstOk = $i;
					break;
				}
			}
			if ($firstOk >= 0) {
				$selected = $firstOk;
			}
		}
	}
	foreach ($stock as $i => $s) {
		$stock[$i]['eatby'] = $s['eatby'] ? dol_print_date($s['eatby'], 'day') : '';
		$stock[$i]['sellby'] = $s['sellby'] ? dol_print_date($s['sellby'], 'day') : '';
		$stock[$i]['expired'] = ($s['eatby'] && $s['eatby'] < dol_now()) ? 1 : 0;
	}

	$warning = '';
	if ($batch !== '' && empty($line['disable_stock_change'])) {
		$found = false;
		foreach ($stock as $s) {
			if ($s['batch'] === $batch) {
				$found = true;
			}
		}
		if (!$found) {
			$warning = 'Lot « '.$batch.' » scanné mais sans stock disponible';
		}
	}

	return array('mode' => 'consume', 'line' => $line, 'stock' => $stock, 'selected' => $selected, 'warning' => $warning);
}

/**
 * Proposition de production pour une ligne « à produire »
 *
 * @param Mo              $mo      OF
 * @param array           $line    Ligne issue de getProduceLines
 * @param string          $batch   Lot scanné éventuel (étiquette pré-imprimée)
 * @param MrpbatchConsume $service Service
 * @return array
 */
function mrpbatch_producepayload(Mo $mo, $line, $batch, MrpbatchConsume $service)
{
	$p = $service->getProduceProposal($mo, $line);
	if ($batch !== '') {
		$p['batch'] = $batch;
	}
	$p['mode'] = 'produce';
	$p['today'] = dol_print_date(dol_now(), '%Y-%m-%d');
	return $p;
}

/**
 * Choisit la ligne à traiter parmi plusieurs (priorité au reste > 0)
 *
 * @param array $candidates Lignes
 * @return array|null
 */
function mrpbatch_pick($candidates)
{
	if (empty($candidates)) {
		return null;
	}
	foreach ($candidates as $c) {
		if ($c['qty_remaining'] > 0) {
			return $c;
		}
	}
	return $candidates[0];
}

/**
 * Exécute un appel de service et convertit l'exception en erreur JSON
 *
 * @param callable $fn Fonction
 * @return mixed
 */
function mrpbatch_try($fn)
{
	try {
		return $fn();
	} catch (Exception $e) {
		mrpbatch_err($e->getMessage());
	}
	return null;
}

switch ($what) {
	/* ============================== PRODUCTION ============================== */
	case 'list':
		if (!$canMrpRead) {
			mrpbatch_err('Accès refusé (droit de lecture GPAO)');
		}
		$mos = mrpbatch_try(function () use ($service, $ws) {
			return $service->listMos(array('ws' => $ws));
		});
		mrpbatch_json(array('ok' => true, 'mos' => $mos));
		break;

	case 'findmo':
		$code = mrpbatch_code();
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'mrp_mo';
		$sql .= " WHERE ref = '".$db->escape($code)."'";
		$sql .= ' AND entity IN ('.getEntity('mo').')';
		$resql = $db->query($sql);
		if ($canMrpRead && $resql && ($obj = $db->fetch_object($resql))) {
			mrpbatch_json(array('ok' => true, 'id' => (int) $obj->rowid));
		}
		mrpbatch_err('Aucun OF « '.$code.' »');
		break;

	case 'mo':
		if (!$canMrpRead) {
			mrpbatch_err('Accès refusé');
		}
		$mo = mrpbatch_loadmo($id);
		mrpbatch_json(array('ok' => true, 'mo' => mrpbatch_mopayload($mo, $service)));
		break;

	case 'scan':
		if (!$canMrpRead) {
			mrpbatch_err('Accès refusé');
		}
		$mo = mrpbatch_loadmo($id);
		$code = mrpbatch_code();
		$resolved = $service->resolveScannedCode($code, $mo);
		if (!$resolved) {
			mrpbatch_err('Code « '.$code.' » inconnu');
		}
		$candidates = array();
		foreach ($service->getConsumeLines($mo, false) as $l) {
			if ($l['fk_product'] == $resolved['fk_product']) {
				$candidates[] = $l;
			}
		}
		if ($line = mrpbatch_pick($candidates)) {
			mrpbatch_json(array('ok' => true) + mrpbatch_consumepayload($mo, $line, $resolved['batch'], $service));
		}
		$candidates = array();
		foreach ($service->getProduceLines($mo) as $l) {
			if ($l['fk_product'] == $resolved['fk_product']) {
				$candidates[] = $l;
			}
		}
		if ($line = mrpbatch_pick($candidates)) {
			mrpbatch_json(array('ok' => true) + mrpbatch_producepayload($mo, $line, $resolved['batch'], $service));
		}
		$p = new Product($db);
		$p->fetch($resolved['fk_product']);
		mrpbatch_err('Le produit '.$p->ref.' ne fait pas partie de cet OF');
		break;

	case 'line':
		if (!$canMrpRead) {
			mrpbatch_err('Accès refusé');
		}
		$mo = mrpbatch_loadmo($id);
		$lineid = (int) GETPOST('lineid', 'int');
		foreach ($service->getConsumeLines($mo, false) as $l) {
			if ($l['objectid'] == $lineid) {
				mrpbatch_json(array('ok' => true) + mrpbatch_consumepayload($mo, $l, '', $service));
			}
		}
		foreach ($service->getProduceLines($mo) as $l) {
			if ($l['objectid'] == $lineid) {
				mrpbatch_json(array('ok' => true) + mrpbatch_producepayload($mo, $l, '', $service));
			}
		}
		mrpbatch_err('Ligne introuvable');
		break;

	case 'consume':
	case 'produce':
		mrpbatch_post();
		if (!$canWrite) {
			mrpbatch_err('Droits insuffisants (GPAO écriture + mouvements de stock)');
		}
		$mo = mrpbatch_loadmo($id);

		$qty = trim(GETPOST('qty', 'alphanohtml'));
		$qtyNorm = str_replace(',', '.', $qty);
		if (!is_numeric($qtyNorm) || strlen($qtyNorm) >= 8) {
			mrpbatch_err('Quantité invalide : « '.$qty.' »');
		}
		$req = array('objectid' => (int) GETPOST('lineid', 'int'), 'qty' => $qtyNorm);
		$wh = (int) GETPOST('fk_warehouse', 'int');
		if ($wh > 0) {
			$req['fk_warehouse'] = $wh;
		}
		$batch = trim(GETPOST('batch', 'alphanohtml'));
		if ($batch !== '') {
			$req['batch'] = $batch;
		}
		if ($what == 'produce') {
			$req['eatby'] = GETPOST('eatby', 'alphanohtml');
			$req['sellby'] = GETPOST('sellby', 'alphanohtml');
		}
		$created = mrpbatch_try(function () use ($what, $service, $mo, $req, $user) {
			return ($what == 'produce') ? $service->produce($mo, array($req), $user) : $service->consume($mo, array($req), $user);
		});
		$mo = mrpbatch_loadmo($id);
		$c = $created[0];
		$msg = $c['product_ref'].' × '.price($c['qty']).($c['batch'] !== '' ? ' — lot '.$c['batch'] : '');
		if ($what == 'produce' && !empty($c['eatby'])) {
			$msg .= ' — DLC '.dol_print_date(dol_stringtotime($c['eatby'].' 00:00:00'), 'day');
		}
		mrpbatch_json(array('ok' => true, 'kind' => $what, 'message' => $msg, 'mo' => mrpbatch_mopayload($mo, $service)));
		break;

	case 'close':
		mrpbatch_post();
		$mo = mrpbatch_loadmo($id);
		mrpbatch_try(function () use ($service, $mo, $user) {
			$service->closeMo($mo, $user);
		});
		mrpbatch_json(array('ok' => true, 'message' => 'OF '.$mo->ref.' terminé'));
		break;

	/* =============================== PLANNING =============================== */
	case 'planning':
		if (!$canMrpRead) {
			mrpbatch_err('Accès refusé (droit de lecture GPAO)');
		}
		$from = (int) GETPOST('from', 'int');
		$to = (int) GETPOST('to', 'int');
		if ($from <= 0 || $to <= $from || $to - $from > 62 * 86400) {
			mrpbatch_err('Période invalide');
		}
		$planning = new MrpbatchPlanning($db);
		$data = mrpbatch_try(function () use ($planning, $from, $to, $ws) {
			return $planning->getPlanning($from, $to, $ws);
		});
		mrpbatch_json(array('ok' => true, 'planning' => $data));
		break;

	case 'reschedule':
		mrpbatch_post();
		$mo = mrpbatch_loadmo($id);
		$start = (int) GETPOST('start', 'int');
		$end = (int) GETPOST('end', 'int');
		mrpbatch_try(function () use ($service, $mo, $start, $end, $user) {
			$service->rescheduleMo($mo, $start, $end, $user);
		});
		mrpbatch_json(array('ok' => true, 'message' => 'OF '.$mo->ref.' replanifié'));
		break;

	/* =============================== RÉCEPTION ============================== */
	case 'rc_list':
		if (!$rcRights['read']) {
			mrpbatch_err('Accès refusé (droit de lecture des stocks)');
		}
		$rc = new MrpbatchReception($db);
		$orders = mrpbatch_try(function () use ($rc) {
			return $rc->listOrders(trim(GETPOST('search', 'alphanohtml')));
		});
		mrpbatch_json(array('ok' => true, 'orders' => $orders, 'history' => MrpbatchEvent::fetchLast($db, array('MRPBATCH_RECEPTION', 'MRPBATCH_RECEPTION_REFUSED'), 25)));
		break;

	case 'rc_order':
		if (!$rcRights['read']) {
			mrpbatch_err('Accès refusé');
		}
		$rc = new MrpbatchReception($db);
		$order = mrpbatch_try(function () use ($rc, $id) {
			return $rc->getOrder($id);
		});
		mrpbatch_json(array('ok' => true, 'order' => $order));
		break;

	case 'rc_scan':
	case 'rc_line':
		if (!$rcRights['read']) {
			mrpbatch_err('Accès refusé');
		}
		$rc = new MrpbatchReception($db);
		$code = mrpbatch_code();
		$order = null;
		if ($id > 0) {
			$order = mrpbatch_try(function () use ($rc, $id) {
				return $rc->getOrder($id);
			});
		}
		if ($what == 'rc_line') {
			$lineid = (int) GETPOST('lineid', 'int');
			foreach ($order ? $order['lines'] : array() as $l) {
				if ($l['objectid'] == $lineid) {
					mrpbatch_json(array('ok' => true) + mrpbatch_try(function () use ($rc, $l) {
						return $rc->getProposal($l['fk_product'], $l);
					}));
				}
			}
			$fk = (int) GETPOST('fk_product', 'int');
			if ($fk > 0) {
				mrpbatch_json(array('ok' => true) + mrpbatch_try(function () use ($rc, $fk) {
					return $rc->getProposal($fk, null);
				}));
			}
			mrpbatch_err('Ligne introuvable');
		}
		// Référence d'une commande fournisseur ?
		if (isModEnabled('supplier_order') || isModEnabled('fournisseur')) {
			$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX."commande_fournisseur WHERE (ref = '".$db->escape($code)."' OR ref_supplier = '".$db->escape($code)."') AND entity IN (".getEntity('supplier_order').')';
			$resql = $db->query($sql);
			if ($resql && ($obj = $db->fetch_object($resql))) {
				mrpbatch_json(array('ok' => true, 'goto' => 'order', 'id' => (int) $obj->rowid));
			}
		}
		$resolved = $service->resolveProductCode($code);
		if (!$resolved) {
			mrpbatch_err('Code « '.$code.' » inconnu (ni produit, ni GS1, ni commande fournisseur)');
		}
		$line = null;
		if ($order) {
			foreach ($order['lines'] as $l) {
				if ($l['fk_product'] == $resolved['fk_product'] && (!$line || ($line['qty_remaining'] <= 0 && $l['qty_remaining'] > 0))) {
					$line = $l;
				}
			}
			if (!$line) {
				$p = new Product($db);
				$p->fetch($resolved['fk_product']);
				mrpbatch_err('Le produit '.$p->ref.' ne figure pas sur la commande '.$order['ref']);
			}
		}
		mrpbatch_json(array('ok' => true) + mrpbatch_try(function () use ($rc, $resolved, $line) {
			return $rc->getProposal($resolved['fk_product'], $line, $resolved['batch'], $resolved['gs1']);
		}));
		break;

	case 'rc_search':
		if (!$rcRights['read']) {
			mrpbatch_err('Accès refusé');
		}
		$q = trim(GETPOST('q', 'alphanohtml'));
		if (dol_strlen($q) < 2) {
			mrpbatch_json(array('ok' => true, 'products' => array()));
		}
		$e = $db->escape($q);
		$sql = 'SELECT rowid, ref, label, barcode, tobatch FROM '.MAIN_DB_PREFIX.'product';
		$sql .= " WHERE entity IN (".getEntity('product').") AND fk_product_type = 0 AND tobuy = 1";
		$sql .= " AND (ref LIKE '%".$e."%' OR label LIKE '%".$e."%' OR barcode LIKE '%".$e."%')";
		$sql .= ' ORDER BY ref ASC'.$db->plimit(30);
		$out = array();
		$resql = $db->query($sql);
		if (!$resql) {
			mrpbatch_err($db->lasterror());
		}
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$out[] = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'label' => (string) $obj->label, 'barcode' => (string) $obj->barcode, 'status_batch' => (int) $obj->tobatch);
			}
		}
		mrpbatch_json(array('ok' => true, 'products' => $out));
		break;

	case 'rc_receive':
		mrpbatch_post();
		$rc = new MrpbatchReception($db);
		$v = array(
			'fk_product'    => (int) GETPOST('fk_product', 'int'),
			'qty'           => str_replace(',', '.', trim(GETPOST('qty', 'alphanohtml'))),
			'fk_warehouse'  => (int) GETPOST('fk_warehouse', 'int'),
			'batch'         => trim(GETPOST('batch', 'alphanohtml')),
			'eatby'         => GETPOST('eatby', 'alphanohtml'),
			'sellby'        => GETPOST('sellby', 'alphanohtml'),
			'order_id'      => $id,
			'order_line_id' => (int) GETPOST('lineid', 'int'),
			'conform'       => GETPOST('conform', 'int') === '0' ? '0' : '1',
			'note'          => GETPOST('note', 'alphanohtml'),
		);
		if (!is_numeric($v['qty']) || strlen($v['qty']) >= 8) {
			mrpbatch_err('Quantité invalide');
		}
		$r = mrpbatch_try(function () use ($rc, $v, $user) {
			return $rc->receive($v, $user);
		});
		$payload = array('ok' => true, 'message' => $r['message'], 'conform' => $r['conform']);
		if ($id > 0) {
			$payload['order'] = $rc->getOrder($id);
		} else {
			$payload['history'] = MrpbatchEvent::fetchLast($db, array('MRPBATCH_RECEPTION', 'MRPBATCH_RECEPTION_REFUSED'), 25);
		}
		mrpbatch_json($payload);
		break;

	/* ========================= COMMANDES CLIENTS ============================ */
	case 'od_list':
		if (!$odRights['read']) {
			mrpbatch_err('Accès refusé (droit de lecture des commandes clients)');
		}
		$od = new MrpbatchOrders($db);
		$days = GETPOST('days', 'alphanohtml');
		$list = mrpbatch_try(function () use ($od, $days) {
			return $od->listOrders(array('search' => trim(GETPOST('search', 'alphanohtml')), 'days' => $days === '' ? '' : (int) $days));
		});
		$steps = array();
		foreach (MrpbatchOrders::getSteps() as $k => $v) {
			$steps[] = array('code' => $k, 'label' => $v);
		}
		mrpbatch_json(array('ok' => true, 'orders' => $list, 'steps' => $steps, 'pack_step' => MrpbatchOrders::getPackStep()));
		break;

	case 'od_order':
		if (!$odRights['read']) {
			mrpbatch_err('Accès refusé');
		}
		$od = new MrpbatchOrders($db);
		$order = mrpbatch_try(function () use ($od, $id) {
			return $od->getOrder($id);
		});
		mrpbatch_json(array('ok' => true, 'order' => $order));
		break;

	case 'od_find':
		if (!$odRights['read']) {
			mrpbatch_err('Accès refusé');
		}
		$od = new MrpbatchOrders($db);
		$oid = $od->findOrder(mrpbatch_code());
		if ($oid) {
			mrpbatch_json(array('ok' => true, 'id' => $oid));
		}
		mrpbatch_err('Aucune commande client « '.mrpbatch_code().' »');
		break;

	case 'od_scan':
	case 'od_line':
		if (!$odRights['read']) {
			mrpbatch_err('Accès refusé');
		}
		$od = new MrpbatchOrders($db);
		$order = mrpbatch_try(function () use ($od, $id) {
			return $od->getOrder($id);
		});
		$line = null;
		$batch = '';
		if ($what == 'od_line') {
			$lineid = (int) GETPOST('lineid', 'int');
			foreach ($order['lines'] as $l) {
				if ($l['objectid'] == $lineid) {
					$line = $l;
				}
			}
		} else {
			$code = mrpbatch_code();
			$oid = $od->findOrder($code);
			if ($oid) {
				mrpbatch_json(array('ok' => true, 'goto' => 'order', 'id' => $oid));
			}
			$resolved = $service->resolveProductCode($code);
			if (!$resolved) {
				// N° de lot seul
				$sql = 'SELECT fk_product FROM '.MAIN_DB_PREFIX."product_lot WHERE batch = '".$db->escape($code)."' AND entity IN (".getEntity('product').')';
				$resql = $db->query($sql);
				while ($resql && ($obj = $db->fetch_object($resql))) {
					foreach ($order['lines'] as $l) {
						if ($l['fk_product'] == (int) $obj->fk_product) {
							$resolved = array('fk_product' => (int) $obj->fk_product, 'batch' => $code);
						}
					}
				}
			}
			if (!$resolved) {
				mrpbatch_err('Code « '.$code.' » inconnu');
			}
			$batch = (string) $resolved['batch'];
			foreach ($order['lines'] as $l) {
				if ($l['fk_product'] == $resolved['fk_product'] && (!$line || ($line['qty_remaining'] <= 0 && $l['qty_remaining'] > 0))) {
					$line = $l;
				}
			}
			if (!$line) {
				mrpbatch_err('Ce produit ne figure pas sur la commande '.$order['ref']);
			}
		}
		if (!$line) {
			mrpbatch_err('Ligne introuvable');
		}
		$lots = $line['status_batch'] ? $od->getLots($line['fk_product']) : array();
		mrpbatch_json(array('ok' => true, 'mode' => 'pick', 'line' => $line, 'lots' => $lots, 'batch' => $batch));
		break;

	case 'od_pick':
		mrpbatch_post();
		$od = new MrpbatchOrders($db);
		$qty = str_replace(',', '.', trim(GETPOST('qty', 'alphanohtml')));
		$reset = (bool) GETPOST('reset', 'int');
		if (!$reset && (!is_numeric($qty) || strlen($qty) >= 8)) {
			mrpbatch_err('Quantité invalide');
		}
		$r = mrpbatch_try(function () use ($od, $id, $qty, $reset, $user) {
			return $od->pick($id, (int) GETPOST('lineid', 'int'), $reset ? 0 : $qty, GETPOST('batch', 'alphanohtml'), $user, $reset);
		});
		mrpbatch_json(array('ok' => true, 'message' => $r['message'], 'order' => $od->getOrder($id)));
		break;

	case 'od_step':
		mrpbatch_post();
		$od = new MrpbatchOrders($db);
		$nb = GETPOST('nb_packages', 'alphanohtml');
		$wg = GETPOST('weight', 'alphanohtml');
		$note = GETPOSTISSET('note') ? GETPOST('note', 'alphanohtml') : null;
		$r = mrpbatch_try(function () use ($od, $id, $nb, $wg, $note, $user) {
			return $od->setStep($id, GETPOST('step', 'aZ09'), $nb === '' ? null : (int) $nb, $wg === '' ? null : (float) str_replace(',', '.', $wg), $note, $user);
		});
		mrpbatch_json(array('ok' => true, 'message' => $r['message'], 'order' => $od->getOrder($id)));
		break;

	default:
		mrpbatch_err('Action inconnue');
}
