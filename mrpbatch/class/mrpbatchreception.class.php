<?php
/* Module Mrpbatch — Réception des matières premières (avec ou sans commande fournisseur)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';
dol_include_once('/mrpbatch/class/mrpbatchconsume.class.php');
dol_include_once('/mrpbatch/class/mrpbatchevent.class.php');

/**
 * Réception de matières premières par scan : produit, n° de lot fournisseur, DLC / DLUO, contrôle à réception.
 *
 *  - avec commande fournisseur : ventilation native (CommandeFournisseur::dispatchProduct), le stock bouge
 *    selon l'option Dolibarr « Augmenter le stock à la ventilation » ;
 *  - sans commande : entrée en stock directe (MouvementStock::reception).
 */
class MrpbatchReception
{
	/** @var DoliDB */
	public $db;

	/** @var MrpbatchConsume */
	private $common;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->common = new MrpbatchConsume($db);
	}

	/**
	 * Droits de l'utilisateur sur la réception
	 *
	 * @param User $user Utilisateur
	 * @return array{read:bool,free:bool,order:bool}
	 */
	public static function rights(User $user)
	{
		$order = (isModEnabled('supplier_order') || isModEnabled('fournisseur')) && $user->hasRight('fournisseur', 'commande', 'receptionner');
		$free = isModEnabled('stock') && $user->hasRight('stock', 'mouvement', 'creer');
		return array(
			'read'  => $user->hasRight('stock', 'lire') || $order,
			'free'  => $free,
			'order' => $order,
		);
	}

	/**
	 * Table de ventilation des réceptions fournisseur (renommée en Dolibarr 20)
	 *
	 * @return array{table:string,order:string,line:string}
	 */
	private function dispatchTable()
	{
		static $t = null;
		if ($t === null) {
			$resql = @$this->db->query('SELECT fk_element FROM '.MAIN_DB_PREFIX.'receptiondet_batch WHERE 1 = 0');
			$t = $resql
				? array('table' => 'receptiondet_batch', 'order' => 'fk_element', 'line' => 'fk_elementdet')
				: array('table' => 'commande_fournisseur_dispatch', 'order' => 'fk_commande', 'line' => 'fk_commandefourndet');
		}
		return $t;
	}

	/**
	 * Commandes fournisseur en attente de réception (commandées ou partiellement reçues)
	 *
	 * @param string $search Recherche (réf., fournisseur)
	 * @return array
	 */
	public function listOrders($search = '')
	{
		$out = array();
		if (!isModEnabled('supplier_order') && !isModEnabled('fournisseur')) {
			return $out;
		}
		$d = $this->dispatchTable();
		$sql = 'SELECT c.rowid, c.ref, c.ref_supplier, c.fk_statut, c.date_livraison, c.date_commande, s.nom as socname,';
		$sql .= ' (SELECT COUNT(*) FROM '.MAIN_DB_PREFIX.'commande_fournisseurdet as cd WHERE cd.fk_commande = c.rowid AND cd.product_type = 0 AND cd.fk_product > 0) as nblines,';
		$sql .= ' (SELECT SUM(cd2.qty) FROM '.MAIN_DB_PREFIX.'commande_fournisseurdet as cd2 WHERE cd2.fk_commande = c.rowid AND cd2.product_type = 0 AND cd2.fk_product > 0) as qty_ordered,';
		$sql .= ' (SELECT SUM(d.qty) FROM '.MAIN_DB_PREFIX.$d['table'].' as d WHERE d.'.$d['order'].' = c.rowid) as qty_received';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'commande_fournisseur as c';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = c.fk_soc';
		$sql .= ' WHERE c.entity IN ('.getEntity('supplier_order').')';
		$sql .= ' AND c.fk_statut IN (3, 4)';
		if ($search !== '') {
			$e = $this->db->escape($search);
			$sql .= " AND (c.ref LIKE '%".$e."%' OR c.ref_supplier LIKE '%".$e."%' OR s.nom LIKE '%".$e."%')";
		}
		$sql .= ' ORDER BY c.date_livraison IS NULL, c.date_livraison ASC, c.ref ASC';
		$sql .= $this->db->plimit(300);
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror(), 500);
		}
		$today = dol_print_date(dol_now(), '%Y-%m-%d');
		while ($obj = $this->db->fetch_object($resql)) {
			$ord = (float) $obj->qty_ordered;
			$rec = (float) $obj->qty_received;
			$dl = $obj->date_livraison ? $this->db->jdate($obj->date_livraison) : 0;
			$out[] = array(
				'id'           => (int) $obj->rowid,
				'ref'          => $obj->ref,
				'ref_supplier' => (string) $obj->ref_supplier,
				'supplier'     => (string) $obj->socname,
				'status'       => (int) $obj->fk_statut,
				'delivery'     => $dl ? dol_print_date($dl, 'day') : '',
				'delivery_iso' => $dl ? dol_print_date($dl, '%Y-%m-%d') : '',
				'late'         => ($dl && dol_print_date($dl, '%Y-%m-%d') < $today) ? 1 : 0,
				'nblines'      => (int) $obj->nblines,
				'pct'          => $ord > 0 ? (int) min(100, round(100 * $rec / $ord)) : 0,
			);
		}
		$this->db->free($resql);
		return $out;
	}

	/**
	 * Commande fournisseur avec ses lignes produits (commandé / reçu / reste)
	 *
	 * @param int $id ID commande fournisseur
	 * @return array
	 * @throws Exception
	 */
	public function getOrder($id)
	{
		require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
		$order = new CommandeFournisseur($this->db);
		if ($order->fetch((int) $id) <= 0) {
			throw new Exception('Commande fournisseur introuvable', 404);
		}
		$order->fetch_thirdparty();
		$d = $this->dispatchTable();

		$received = array();
		$resql = $this->db->query('SELECT '.$d['line'].' as lid, SUM(qty) as qty FROM '.MAIN_DB_PREFIX.$d['table'].' WHERE '.$d['order'].' = '.((int) $order->id).' GROUP BY '.$d['line']);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$received[(int) $obj->lid] = (float) $obj->qty;
			}
		}

		$lines = array();
		foreach ($order->lines as $l) {
			if (empty($l->fk_product) || (int) $l->product_type != 0) {
				continue;
			}
			$p = new Product($this->db);
			$p->fetch($l->fk_product);
			$rec = isset($received[(int) $l->id]) ? $received[(int) $l->id] : 0;
			$lines[] = array(
				'objectid'      => (int) $l->id,
				'fk_product'    => (int) $l->fk_product,
				'product_ref'   => $p->ref,
				'product_label' => $p->label,
				'barcode'       => (string) $p->barcode,
				'ref_supplier'  => (string) $l->ref_supplier,
				'status_batch'  => (int) $p->status_batch,
				'qty_planned'   => (float) $l->qty,
				'qty_done'      => (float) $rec,
				'qty_remaining' => (float) $l->qty - $rec,
			);
		}

		return array(
			'id'           => (int) $order->id,
			'ref'          => $order->ref,
			'ref_supplier' => (string) $order->ref_supplier,
			'supplier'     => $order->thirdparty ? $order->thirdparty->name : '',
			'fk_soc'       => (int) $order->socid,
			'status'       => (int) ($order->status ?? $order->statut),
			'receivable'   => in_array((int) ($order->status ?? $order->statut), array(3, 4, 5)),
			'delivery'     => !empty($order->delivery_date) ? dol_print_date($order->delivery_date, 'day') : '',
			'stock_on_dispatch' => (bool) getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_DISPATCH_ORDER'),
			'url'          => DOL_URL_ROOT.'/fourn/commande/card.php?id='.((int) $order->id),
			'lines'        => $lines,
			'history'      => MrpbatchEvent::fetchLast($this->db, array('MRPBATCH_RECEPTION', 'MRPBATCH_RECEPTION_REFUSED'), 30, 'order_supplier', (int) $order->id),
		);
	}

	/**
	 * Proposition de saisie pour réceptionner un produit
	 *
	 * @param int    $fk_product ID produit
	 * @param array  $line       Ligne de commande (getOrder) ou null pour réception libre
	 * @param string $batch      Lot lu (étiquette fournisseur)
	 * @param array  $gs1        Données GS1 lues
	 * @return array
	 * @throws Exception
	 */
	public function getProposal($fk_product, $line = null, $batch = '', $gs1 = array())
	{
		$p = new Product($this->db);
		if ($p->fetch((int) $fk_product) <= 0) {
			throw new Exception('Produit introuvable', 404);
		}
		$mandatory = isset($p->sell_or_eat_by_mandatory) ? (int) $p->sell_or_eat_by_mandatory : 0;

		if ($line === null) {
			$line = array(
				'objectid'      => 0,
				'fk_product'    => (int) $p->id,
				'product_ref'   => $p->ref,
				'product_label' => $p->label,
				'barcode'       => (string) $p->barcode,
				'status_batch'  => (int) $p->status_batch,
				'qty_planned'   => 0,
				'qty_done'      => 0,
				'qty_remaining' => 0,
			);
		}
		$eatby = !empty($gs1['eatby']) ? $gs1['eatby'] : '';
		$sellby = !empty($gs1['sellby']) ? $gs1['sellby'] : '';
		$lotKnown = false;
		if ($batch !== '' && $p->status_batch) {
			$lot = new Productlot($this->db);
			if ($lot->fetch(0, $p->id, $batch) > 0) {
				$lotKnown = true;
				$eatby = !empty($lot->eatby) ? dol_print_date($lot->eatby, '%Y-%m-%d') : $eatby;
				$sellby = !empty($lot->sellby) ? dol_print_date($lot->sellby, '%Y-%m-%d') : $sellby;
			}
		}
		$qty = 0;
		if ((int) $p->status_batch == 2) {
			$qty = 1;
		} elseif (!empty($gs1['count'])) {
			$qty = (float) $gs1['count'];
		} elseif (!empty($gs1['weight'])) {
			$qty = (float) $gs1['weight'];
		} elseif ($line['qty_remaining'] > 0) {
			$qty = (float) $line['qty_remaining'];
		}

		$wh = getDolGlobalInt('MRPBATCH_RECEPTION_WAREHOUSE');
		$warehouses = $this->common->getWarehouses();
		if (!$wh && !empty($warehouses)) {
			$wh = (int) $warehouses[0]['id'];
		}

		return array(
			'mode'             => 'receive',
			'line'             => $line,
			'batch'            => (string) ($p->status_batch == 2 && !empty($gs1['serial']) ? $gs1['serial'] : $batch),
			'lot_known'        => $lotKnown,
			'qty'              => $qty,
			'eatby'            => $eatby,
			'sellby'           => $sellby,
			'eatby_mandatory'  => in_array($mandatory, array(2, 3)),
			'sellby_mandatory' => in_array($mandatory, array(1, 3)),
			'fk_warehouse'     => $wh,
			'warehouses'       => $warehouses,
			'quarantine'       => getDolGlobalInt('MRPBATCH_QUARANTINE_WAREHOUSE'),
			'min_days'         => getDolGlobalInt('MRPBATCH_RECEPTION_MIN_DAYS'),
			'today'            => dol_print_date(dol_now(), '%Y-%m-%d'),
			'gs1'              => $gs1 ? $gs1 : new stdClass(),
		);
	}

	/**
	 * Enregistre une réception
	 *
	 * @param array $v    fk_product, qty, fk_warehouse, batch, eatby, sellby (AAAA-MM-JJ), order_id, order_line_id, conform (0/1), note
	 * @param User  $user Utilisateur
	 * @return array Résultat
	 * @throws Exception
	 */
	public function receive($v, User $user)
	{
		global $langs;

		$rights = self::rights($user);
		$orderId = !empty($v['order_id']) ? (int) $v['order_id'] : 0;
		$lineId = !empty($v['order_line_id']) ? (int) $v['order_line_id'] : 0;
		if ($orderId && !$rights['order']) {
			throw new Exception('Droit « Réceptionner les commandes fournisseur » requis', 403);
		}
		if (!$orderId && !$rights['free']) {
			throw new Exception('Droit de création de mouvements de stock requis', 403);
		}

		$product = new Product($this->db);
		if (empty($v['fk_product']) || $product->fetch((int) $v['fk_product']) <= 0) {
			throw new Exception('Produit introuvable', 404);
		}
		$qty = isset($v['qty']) ? (float) price2num($v['qty']) : 0;
		if ($qty <= 0) {
			throw new Exception('La quantité doit être > 0', 400);
		}
		$batch = isset($v['batch']) ? trim((string) $v['batch']) : '';
		$conform = !isset($v['conform']) || (string) $v['conform'] !== '0';
		$note = isset($v['note']) ? trim((string) $v['note']) : '';
		$fk_warehouse = !empty($v['fk_warehouse']) ? (int) $v['fk_warehouse'] : 0;

		if ($product->status_batch && $batch === '') {
			throw new Exception('Le produit '.$product->ref.' est géré par lot : n° de lot obligatoire', 400);
		}
		if (!$product->status_batch) {
			$batch = '';
		}
		if ($product->status_batch == 2 && $qty != 1) {
			throw new Exception('Produit à n° de série : quantité = 1 par numéro', 400);
		}

		$eatby = $this->parseDate(isset($v['eatby']) ? $v['eatby'] : '', 'DLC');
		$sellby = $this->parseDate(isset($v['sellby']) ? $v['sellby'] : '', 'DLUO');
		if ($batch !== '') {
			$lot = new Productlot($this->db);
			if ($lot->fetch(0, $product->id, $batch) > 0) {
				$lotEat = !empty($lot->eatby) ? dol_print_date($lot->eatby, '%Y-%m-%d') : '';
				$lotSell = !empty($lot->sellby) ? dol_print_date($lot->sellby, '%Y-%m-%d') : '';
				if ($eatby !== '' && $lotEat !== '' && dol_print_date($eatby, '%Y-%m-%d') !== $lotEat) {
					throw new Exception('Le lot « '.$batch.' » existe déjà avec la DLC '.dol_print_date($lot->eatby, 'day'), 400);
				}
				if ($sellby !== '' && $lotSell !== '' && dol_print_date($sellby, '%Y-%m-%d') !== $lotSell) {
					throw new Exception('Le lot « '.$batch.' » existe déjà avec la DLUO '.dol_print_date($lot->sellby, 'day'), 400);
				}
				$eatby = ($eatby === '' && !empty($lot->eatby)) ? $lot->eatby : $eatby;
				$sellby = ($sellby === '' && !empty($lot->sellby)) ? $lot->sellby : $sellby;
			}
		}
		$mandatory = isset($product->sell_or_eat_by_mandatory) ? (int) $product->sell_or_eat_by_mandatory : 0;
		if ($batch !== '' && in_array($mandatory, array(2, 3)) && $eatby === '') {
			throw new Exception('DLC obligatoire pour '.$product->ref, 400);
		}
		if ($batch !== '' && in_array($mandatory, array(1, 3)) && $sellby === '') {
			throw new Exception('DLUO obligatoire pour '.$product->ref, 400);
		}

		// Non conforme : quarantaine si un entrepôt est configuré, sinon refus sans entrée en stock
		$quarantine = getDolGlobalInt('MRPBATCH_QUARANTINE_WAREHOUSE');
		$moveStock = true;
		if (!$conform) {
			if ($quarantine > 0) {
				$fk_warehouse = $quarantine;
			} else {
				$moveStock = false;
			}
		}
		if ($moveStock && $fk_warehouse <= 0) {
			throw new Exception('Entrepôt obligatoire', 400);
		}

		$order = null;
		$orderLine = null;
		if ($orderId) {
			require_once DOL_DOCUMENT_ROOT.'/fourn/class/fournisseur.commande.class.php';
			$order = new CommandeFournisseur($this->db);
			if ($order->fetch($orderId) <= 0) {
				throw new Exception('Commande fournisseur introuvable', 404);
			}
			if (!in_array((int) ($order->status ?? $order->statut), array(3, 4, 5))) {
				throw new Exception('La commande '.$order->ref.' n\'est pas au statut « Commandée » ou « Reçue partiellement »', 400);
			}
			foreach ($order->lines as $l) {
				if (($lineId && (int) $l->id == $lineId) || (!$lineId && (int) $l->fk_product == (int) $product->id)) {
					$orderLine = $l;
					break;
				}
			}
			if (!$orderLine) {
				throw new Exception('Le produit '.$product->ref.' ne figure pas sur la commande '.$order->ref, 400);
			}
		}

		$label = ($conform ? 'Réception MP' : 'Réception non conforme').($order ? ' '.$order->ref : '');
		$comment = $label.($note !== '' ? ' — '.$note : '');

		$this->db->begin();
		try {
			$idmove = 0;
			if ($moveStock) {
				if ($order) {
					$price = (float) $orderLine->subprice * (1 - (float) $orderLine->remise_percent / 100);
					$res = $order->dispatchProduct($user, $product->id, $qty, $fk_warehouse, $price, $comment, $eatby, $sellby, $batch, $orderLine->id, 0, 0);
					if ($res < 0) {
						$langs->load('errors');
						throw new Exception('Ventilation impossible : '.$langs->trans($order->error).' '.implode(', ', (array) $order->errors), 500);
					}
					if (method_exists($order, 'calcAndSetStatusDispatch')) {
						$order->calcAndSetStatusDispatch($user, 1, '');
					}
				} else {
					$mv = new MouvementStock($this->db);
					$mv->setOrigin('product', $product->id);
					$idmove = $mv->reception($user, $product->id, $fk_warehouse, $qty, 0, $comment, $eatby, $sellby, $batch, dol_now(), 0, dol_print_date(dol_now(), 'dayhourlog'));
					if ($idmove <= 0) {
						$langs->load('errors');
						throw new Exception('Erreur mouvement de stock : '.($mv->error ? $langs->trans($mv->error) : implode(', ', $mv->errors)), 500);
					}
				}
			}

			$ev = new MrpbatchEvent($this->db);
			$ev->code = $conform ? 'MRPBATCH_RECEPTION' : 'MRPBATCH_RECEPTION_REFUSED';
			$ev->status = $conform ? 1 : 0;
			$ev->element_type = $order ? 'order_supplier' : 'product';
			$ev->fk_element = $order ? (int) $order->id : (int) $product->id;
			$ev->element_ref = $order ? $order->ref : $product->ref;
			$ev->fk_soc = $order ? (int) $order->socid : 0;
			$ev->fk_product = (int) $product->id;
			$ev->product_ref = $product->ref;
			$ev->product_label = $product->label;
			$ev->batch = $batch;
			$ev->qty = $qty;
			$ev->eatby = $eatby ? dol_print_date($eatby, '%Y-%m-%d') : '';
			$ev->sellby = $sellby ? dol_print_date($sellby, '%Y-%m-%d') : '';
			$ev->fk_warehouse = $moveStock ? $fk_warehouse : 0;
			$ev->note = $note;
			$ev->data = array(
				'conform'        => $conform ? 1 : 0,
				'quarantine'     => (!$conform && $moveStock) ? 1 : 0,
				'stock_moved'    => $moveStock ? 1 : 0,
				'order_line_id'  => $orderLine ? (int) $orderLine->id : 0,
				'stock_movement_id' => (int) $idmove,
				'ref_supplier'   => $order ? (string) $order->ref_supplier : '',
			);
			$ev->record($user);

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		$msg = $product->ref.' × '.price($qty).($batch !== '' ? ' — lot '.$batch : '').($eatby ? ' — DLC '.dol_print_date($eatby, 'day') : '');
		if (!$conform) {
			$msg = ($moveStock ? 'Mis en quarantaine : ' : 'Refusé (non conforme) : ').$msg;
		} elseif ($order && !getDolGlobalString('STOCK_CALCULATE_ON_SUPPLIER_DISPATCH_ORDER')) {
			$msg .= ' (ventilé ; stock mis à jour à la validation de la réception)';
		}
		return array('message' => $msg, 'conform' => $conform, 'stock_moved' => $moveStock);
	}

	/**
	 * Date AAAA-MM-JJ → timestamp ('' si vide)
	 *
	 * @param string $value Date
	 * @param string $what  Libellé
	 * @return int|string
	 * @throws Exception
	 */
	private function parseDate($value, $what)
	{
		$value = trim((string) $value);
		if ($value === '') {
			return '';
		}
		if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
			throw new Exception($what.' invalide : « '.$value.' »', 400);
		}
		return dol_mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
	}
}
