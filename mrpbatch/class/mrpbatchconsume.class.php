<?php
/* Module Mrpbatch — logique partagée (API + écran de scan)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/mrp/class/mo.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/mouvementstock.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';
dol_include_once('/mrpbatch/class/mrpbatchevent.class.php');

/**
 * Consommation et production de lots / séries / produits sur un OF
 *
 * Les erreurs sont levées en Exception avec code 400 (donnée invalide) ou 500 (erreur système).
 */
class MrpbatchConsume
{
	/** @var DoliDB */
	public $db;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Lignes « à consommer » d'un OF avec prévu / consommé / reste
	 *
	 * @param Mo   $mo        OF chargé (avec lignes)
	 * @param bool $withLots  Inclure les lots disponibles
	 * @return array
	 */
	public function getConsumeLines(Mo $mo, $withLots = false)
	{
		$consumed = array();
		foreach ($mo->lines as $line) {
			if ($line->role == 'consumed' && $line->fk_mrp_production > 0) {
				$consumed[$line->fk_mrp_production] = (isset($consumed[$line->fk_mrp_production]) ? $consumed[$line->fk_mrp_production] : 0) + (float) $line->qty;
			}
		}

		$result = array();
		foreach ($mo->lines as $line) {
			if ($line->role != 'toconsume') {
				continue;
			}
			$product = new Product($this->db);
			$product->fetch($line->fk_product);
			$done = isset($consumed[$line->id]) ? $consumed[$line->id] : 0;

			$row = array(
				'objectid'      => (int) $line->id,
				'fk_product'    => (int) $line->fk_product,
				'product_ref'   => $product->ref,
				'product_label' => $product->label,
				'barcode'       => $product->barcode,
				'status_batch'  => (int) $product->status_batch,
				'disable_stock_change' => (int) $line->disable_stock_change,
				'fk_workstation' => !empty($line->fk_default_workstation) ? (int) $line->fk_default_workstation : 0,
				'qty_planned'   => (float) $line->qty,
				'qty_consumed'  => (float) $done,
				'qty_remaining' => (float) $line->qty - $done,
			);
			if ($withLots) {
				$row['stock'] = $this->getAvailableStock($line->fk_product, (int) $product->status_batch);
			}
			$result[] = $row;
		}
		return $result;
	}

	/**
	 * Stock disponible d'un produit : par lot si géré par lot, sinon par entrepôt
	 *
	 * @param int $fk_product   ID produit
	 * @param int $status_batch 0/1/2
	 * @return array
	 */
	public function getAvailableStock($fk_product, $status_batch)
	{
		$out = array();
		if ($status_batch) {
			$sql = 'SELECT ps.fk_entrepot, e.ref as warehouse_ref, pb.batch, pb.qty, pl.eatby, pl.sellby';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'product_batch as pb';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product_stock as ps ON ps.rowid = pb.fk_product_stock';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'entrepot as e ON e.rowid = ps.fk_entrepot';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_lot as pl ON pl.fk_product = ps.fk_product AND pl.batch = pb.batch';
			$sql .= ' WHERE ps.fk_product = '.((int) $fk_product);
			$sql .= ' AND pb.qty > 0';
			$sql .= ' ORDER BY pl.eatby ASC, pb.batch ASC, e.ref ASC';
		} else {
			$sql = 'SELECT ps.fk_entrepot, e.ref as warehouse_ref, NULL as batch, ps.reel as qty, NULL as eatby, NULL as sellby';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'product_stock as ps';
			$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'entrepot as e ON e.rowid = ps.fk_entrepot';
			$sql .= ' WHERE ps.fk_product = '.((int) $fk_product);
			$sql .= ' AND ps.reel > 0';
			$sql .= ' ORDER BY e.ref ASC';
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = array(
					'fk_warehouse'  => (int) $obj->fk_entrepot,
					'warehouse_ref' => $obj->warehouse_ref,
					'batch'         => $obj->batch,
					'qty'           => (float) $obj->qty,
					'eatby'         => $obj->eatby ? $this->db->jdate($obj->eatby) : '',
					'sellby'        => $obj->sellby ? $this->db->jdate($obj->sellby) : '',
				);
			}
			$this->db->free($resql);
		}
		return $out;
	}

	/**
	 * Résout un code scanné : code-barre produit, réf produit, ou n° de lot
	 *
	 * @param string $code Code scanné
	 * @param Mo     $mo   OF (pour limiter aux produits de l'OF en cas d'ambiguïté)
	 * @return array{fk_product:int,batch:string}|null
	 */
	public function resolveScannedCode($code, Mo $mo)
	{
		$code = trim($code);
		if ($code === '') {
			return null;
		}

		// Code GS1-128 / DataMatrix : GTIN + lot + dates dans un seul code
		$gs1 = self::parseGs1($code);
		if ($gs1 && !empty($gs1['gtin'])) {
			$fk = $this->findProductByGtin($gs1['gtin']);
			if ($fk) {
				return array('fk_product' => $fk, 'batch' => isset($gs1['batch']) ? $gs1['batch'] : '', 'gs1' => $gs1);
			}
		}

		$productsInMo = array();
		foreach ($mo->lines as $line) {
			if ($line->role == 'toconsume' || $line->role == 'toproduce') {
				$productsInMo[(int) $line->fk_product] = true;
			}
		}
		$codeSql = $this->db->escape($code);

		// 1. Code-barre ou référence produit
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product';
		$sql .= " WHERE (barcode = '".$codeSql."' OR ref = '".$codeSql."')";
		$sql .= ' AND entity IN ('.getEntity('product').')';
		$found = $this->pickProduct($sql, $productsInMo);
		if ($found) {
			return array('fk_product' => $found, 'batch' => '');
		}

		// 2. Numéro de lot / série
		$sql = 'SELECT fk_product as rowid FROM '.MAIN_DB_PREFIX.'product_lot';
		$sql .= " WHERE batch = '".$codeSql."'";
		$sql .= ' AND entity IN ('.getEntity('product').')';
		$found = $this->pickProduct($sql, $productsInMo);
		if ($found) {
			return array('fk_product' => $found, 'batch' => $code);
		}

		return null;
	}

	/**
	 * Exécute une requête de recherche produit et privilégie un produit présent dans l'OF
	 *
	 * @param string $sql          Requête retournant rowid
	 * @param array  $productsInMo Produits de l'OF
	 * @return int
	 */
	private function pickProduct($sql, $productsInMo)
	{
		$first = 0;
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$id = (int) $obj->rowid;
				if (isset($productsInMo[$id])) {
					$this->db->free($resql);
					return $id;
				}
				if (!$first) {
					$first = $id;
				}
			}
			$this->db->free($resql);
		}
		return $first;
	}

	/**
	 * Enregistre des consommations sur un OF (transaction unique)
	 *
	 * @param Mo     $mo             OF chargé (avec lignes)
	 * @param array  $lines          [{objectid|fk_product, qty, fk_warehouse, batch?, eatby?, sellby?}]
	 * @param User   $user           Utilisateur
	 * @param string $labelmovement  Libellé du mouvement de stock
	 * @param string $codemovement   Code du mouvement de stock
	 * @return array Lignes créées
	 * @throws Exception
	 */
	public function consume(Mo $mo, array $lines, User $user, $labelmovement = '', $codemovement = '')
	{
		global $langs;

		if (!$user->hasRight('mrp', 'write')) {
			throw new Exception('Droit MRP en écriture requis', 403);
		}
		if (!$user->hasRight('stock', 'mouvement', 'creer')) {
			throw new Exception('Droit de création de mouvements de stock requis', 403);
		}
		if (!in_array((int) $mo->status, array(Mo::STATUS_VALIDATED, Mo::STATUS_INPROGRESS))) {
			throw new Exception("L'OF doit être validé ou en cours", 400);
		}
		if (empty($lines)) {
			throw new Exception('Aucune ligne à consommer', 400);
		}

		if ($labelmovement === '') {
			$labelmovement = 'Consommation OF '.$mo->ref;
		}
		if ($codemovement === '') {
			$codemovement = dol_print_date(dol_now(), 'dayhourlog');
		}

		$pos = 0;
		foreach ($mo->lines as $l) {
			$pos = max($pos, (int) $l->position);
		}

		$created = array();
		$this->db->begin();

		try {
			// L'OF passe « En cours » au premier enregistrement (annulé avec le reste en cas d'erreur)
			$this->setInProgress($mo, $user);

			foreach (array_values($lines) as $i => $value) {
				$n = $i + 1;

				$line = $this->findToConsumeLine($mo, $value);
				if (!$line) {
					throw new Exception("Ligne $n : ce produit ne fait pas partie des composants de l'OF", 400);
				}

				$qty = isset($value['qty']) ? (float) price2num($value['qty']) : 0;
				if ($qty <= 0) {
					throw new Exception("Ligne $n : la quantité doit être > 0", 400);
				}
				$fk_warehouse = isset($value['fk_warehouse']) ? (int) $value['fk_warehouse'] : 0;
				$batch = isset($value['batch']) ? trim((string) $value['batch']) : '';

				$product = new Product($this->db);
				if ($product->fetch($line->fk_product) <= 0) {
					throw new Exception("Ligne $n : produit ".$line->fk_product.' introuvable', 500);
				}
				if ($product->status_batch && $batch === '') {
					throw new Exception("Ligne $n : le produit ".$product->ref.' est géré par lot, lot obligatoire', 400);
				}
				if (!$product->status_batch && $batch !== '') {
					throw new Exception("Ligne $n : le produit ".$product->ref." n'est pas géré par lot", 400);
				}
				if ($product->status_batch == 2 && $qty != 1) {
					throw new Exception("Ligne $n : produit à n° de série, quantité = 1 par numéro", 400);
				}

				// DLC / DLUO reprises du lot
				$eatby = '';
				$sellby = '';
				if ($batch !== '') {
					$lot = new Productlot($this->db);
					if ($lot->fetch(0, $line->fk_product, $batch) <= 0) {
						throw new Exception("Ligne $n : lot \"$batch\" inconnu pour le produit ".$product->ref, 400);
					}
					$eatby = !empty($lot->eatby) ? $lot->eatby : '';
					$sellby = !empty($lot->sellby) ? $lot->sellby : '';
				}
				if (!empty($value['eatby'])) {
					$eatby = dol_stringtotime($value['eatby'].' 00:00:00');
				}
				if (!empty($value['sellby'])) {
					$sellby = dol_stringtotime($value['sellby'].' 00:00:00');
				}

				// Mouvement de stock
				$idstockmove = 0;
				if (empty($line->disable_stock_change)) {
					if ($fk_warehouse <= 0) {
						throw new Exception("Ligne $n : entrepôt obligatoire", 400);
					}
					$stockmove = new MouvementStock($this->db);
					$stockmove->setOrigin($mo->element, $mo->id);
					$stockmove->context['mrp_role'] = 'toconsume';

					$idstockmove = $stockmove->livraison(
						$user,
						$line->fk_product,
						$fk_warehouse,
						$qty,
						0,
						$labelmovement,
						dol_now(),
						$eatby,
						$sellby,
						$batch,
						0,
						$codemovement
					);
					if ($idstockmove <= 0) {
						$langs->load('errors');
						$err = $stockmove->error ? $langs->trans($stockmove->error) : implode(', ', $stockmove->errors);
						throw new Exception("Ligne $n : erreur mouvement de stock : ".$err, 500);
					}
				}

				// Ligne « consommé »
				$pos++;
				$moline = new MoLine($this->db);
				$moline->fk_mo = $mo->id;
				$moline->position = $pos;
				$moline->fk_product = $line->fk_product;
				$moline->fk_warehouse = $fk_warehouse > 0 ? $fk_warehouse : null;
				$moline->qty = $qty;
				$moline->batch = $batch;
				$moline->role = 'consumed';
				$moline->fk_mrp_production = $line->id;
				$moline->fk_stock_movement = $idstockmove > 0 ? $idstockmove : null;
				$moline->fk_user_creat = $user->id;

				$idmoline = $moline->create($user);
				if ($idmoline <= 0) {
					throw new Exception("Ligne $n : erreur création ligne consommée : ".$moline->error.' '.implode(', ', $moline->errors), 500);
				}

				$ev = $this->newMoEvent($mo, 'MRPBATCH_MO_CONSUME', $product);
				$ev->batch = $batch;
				$ev->qty = $qty;
				$ev->eatby = $eatby ? dol_print_date($eatby, '%Y-%m-%d') : '';
				$ev->sellby = $sellby ? dol_print_date($sellby, '%Y-%m-%d') : '';
				$ev->fk_warehouse = $fk_warehouse;
				$ev->fk_workstation = !empty($line->fk_default_workstation) ? (int) $line->fk_default_workstation : 0;
				$ev->data = array('moline_id' => (int) $idmoline, 'fk_mrp_production' => (int) $line->id, 'stock_movement_id' => (int) $idstockmove, 'qty_planned' => (float) $line->qty);
				$ev->record($user);

				$created[] = array(
					'moline_id'         => (int) $idmoline,
					'fk_mrp_production' => (int) $line->id,
					'product_ref'       => $product->ref,
					'batch'             => $batch,
					'qty'               => $qty,
					'fk_warehouse'      => $fk_warehouse,
					'stock_movement_id' => (int) $idstockmove,
				);
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		return $created;
	}

	/**
	 * Ligne « toconsume » à partir de objectid ou fk_product
	 *
	 * @param Mo    $mo    OF
	 * @param array $value Ligne demandée
	 * @return MoLine|null
	 */
	public function findToConsumeLine(Mo $mo, $value)
	{
		foreach ($mo->lines as $line) {
			if ($line->role != 'toconsume') {
				continue;
			}
			if (!empty($value['objectid']) && (int) $line->id == (int) $value['objectid']) {
				return $line;
			}
			if (empty($value['objectid']) && !empty($value['fk_product']) && (int) $line->fk_product == (int) $value['fk_product']) {
				return $line;
			}
		}
		return null;
	}

	/**
	 * Passe l'OF « En cours » s'il était seulement validé (même appel que l'écran Production de Dolibarr)
	 *
	 * @param Mo $mo OF
	 * @return void
	 * @throws Exception
	 */
	private function setInProgress(Mo $mo, User $user)
	{
		if ((int) $mo->status == Mo::STATUS_VALIDATED) {
			if ($mo->setStatut(Mo::STATUS_INPROGRESS, 0, '', 'MRP_MO_PRODUCED') <= 0) {
				throw new Exception("Erreur lors du passage de l'OF en cours : ".$mo->error, 500);
			}
			$this->newMoEvent($mo, 'MRPBATCH_MO_START')->record($user);
		}
	}

	/**
	 * Prépare un évènement atelier rattaché à un OF
	 *
	 * @param Mo           $mo      OF
	 * @param string       $code    Code MRPBATCH_…
	 * @param Product|null $product Produit concerné
	 * @return MrpbatchEvent
	 */
	public function newMoEvent(Mo $mo, $code, $product = null)
	{
		$ev = new MrpbatchEvent($this->db);
		$ev->code = $code;
		$ev->element_type = 'mo';
		$ev->fk_element = (int) $mo->id;
		$ev->element_ref = $mo->ref;
		$ev->fk_soc = !empty($mo->fk_soc) ? (int) $mo->fk_soc : 0;
		if ($product) {
			$ev->fk_product = (int) $product->id;
			$ev->product_ref = $product->ref;
			$ev->product_label = $product->label;
		}
		$ev->data = array('mo_status' => (int) $mo->status, 'mo_qty' => (float) $mo->qty);
		return $ev;
	}

	/* ===================================================================
	 * PRODUCTION
	 * =================================================================== */

	/**
	 * Lignes « à produire » d'un OF avec prévu / produit / reste
	 *
	 * @param Mo $mo OF chargé (avec lignes)
	 * @return array
	 */
	public function getProduceLines(Mo $mo)
	{
		$produced = array();
		foreach ($mo->lines as $line) {
			if ($line->role == 'produced' && $line->fk_mrp_production > 0) {
				$produced[$line->fk_mrp_production] = (isset($produced[$line->fk_mrp_production]) ? $produced[$line->fk_mrp_production] : 0) + (float) $line->qty;
			}
		}

		$result = array();
		foreach ($mo->lines as $line) {
			if ($line->role != 'toproduce') {
				continue;
			}
			$product = new Product($this->db);
			$product->fetch($line->fk_product);
			$done = isset($produced[$line->id]) ? $produced[$line->id] : 0;

			$result[] = array(
				'objectid'      => (int) $line->id,
				'fk_product'    => (int) $line->fk_product,
				'product_ref'   => $product->ref,
				'product_label' => $product->label,
				'barcode'       => $product->barcode,
				'status_batch'  => (int) $product->status_batch,
				'disable_stock_change' => (int) $line->disable_stock_change,
				'qty_planned'   => (float) $line->qty,
				'qty_produced'  => (float) $done,
				'qty_remaining' => (float) $line->qty - $done,
			);
		}
		return $result;
	}

	/**
	 * Suggère le prochain n° de lot / série d'un produit.
	 *  1. Modèle de numérotation natif de Dolibarr (Produits > Lots/Séries : PRODUCTBATCH_LOT_ADDON / PRODUCTBATCH_SN_ADDON)
	 *     s'il est actif et n'est pas le modèle « libre » ;
	 *  2. sinon masque du module (MRPBATCH_LOT_MASK / MRPBATCH_SN_MASK), même syntaxe que les factures/devis.
	 *
	 * @param Product $product Produit
	 * @param string  $source  Rempli avec l'origine de la suggestion ('native', 'mask' ou '')
	 * @return string N° suggéré ou ''
	 */
	public function suggestBatch(Product $product, &$source = '')
	{
		global $conf, $mysoc;

		$source = '';
		if (empty($product->status_batch) || !getDolGlobalInt('MRPBATCH_SUGGEST_BATCH', 1)) {
			return '';
		}
		$isSerial = ($product->status_batch == 2);

		// 1. Modèle natif
		$addon = getDolGlobalString($isSerial ? 'PRODUCTBATCH_SN_ADDON' : 'PRODUCTBATCH_LOT_ADDON');
		if ($addon !== '' && preg_match('/^[a-z0-9_]+$/i', $addon) && !preg_match('/_free$/', $addon)) {
			$dirmodels = array('/');
			if (!empty($conf->modules_parts['models']) && is_array($conf->modules_parts['models'])) {
				$dirmodels = array_merge($dirmodels, $conf->modules_parts['models']);
			}
			foreach ($dirmodels as $reldir) {
				if (class_exists($addon)) {
					break;
				}
				$file = dol_buildpath($reldir.'core/modules/product_batch/'.$addon.'.php', 0);
				if (is_readable($file)) {
					include_once $file;
				}
			}
			if (class_exists($addon)) {
				$numbering = new $addon();
				$lot = new Productlot($this->db);
				$lot->fk_product = $product->id;
				$lot->entity = $conf->entity;
				$num = $numbering->getNextValue($mysoc, $lot);
				if ($this->isValidNumber($num)) {
					$source = 'native';
					return $num;
				}
			}
		}

		// 2. Masque du module
		$mask = $this->getMask($isSerial);
		if ($mask === '') {
			return '';
		}
		require_once DOL_DOCUMENT_ROOT.'/core/lib/functions2.lib.php';
		$num = get_next_value($this->db, $mask, 'product_lot', 'batch', '', null, dol_now());
		if ($this->isValidNumber($num)) {
			$source = 'mask';
			return $num;
		}
		dol_syslog('MrpbatchConsume::suggestBatch masque invalide "'.$mask.'" : '.$num, LOG_WARNING);
		return '';
	}

	/**
	 * Masque de numérotation du module
	 *
	 * @param bool $isSerial N° de série (sinon lot)
	 * @return string
	 */
	public function getMask($isSerial)
	{
		return $isSerial
			? getDolGlobalString('MRPBATCH_SN_MASK', 'SN{yy}{mm}{dd}-{00000}')
			: getDolGlobalString('MRPBATCH_LOT_MASK', 'LOT{yy}{mm}{dd}-{0000}');
	}

	/**
	 * Valeur de numérotation exploitable ?
	 *
	 * @param mixed $num Valeur
	 * @return bool
	 */
	private function isValidNumber($num)
	{
		return is_string($num) && $num !== '' && $num !== '0' && $num !== '-1' && !preg_match('/^(Error|NotAvailable|Bad)/i', $num);
	}

	/**
	 * Dates DLC / DLUO proposées par défaut pour un produit fabriqué aujourd'hui
	 * (extrafields produit « mrpbatch_dlc_days » / « mrpbatch_dluo_days », sinon réglage global du module)
	 *
	 * @param Product $product Produit
	 * @return array{eatby:int|string,sellby:int|string,eatby_mandatory:bool,sellby_mandatory:bool}
	 */
	public function getDefaultDates(Product $product)
	{
		$now = dol_now();
		$opts = is_array($product->array_options) ? $product->array_options : array();

		$dlc = !empty($opts['options_mrpbatch_dlc_days']) ? (int) $opts['options_mrpbatch_dlc_days'] : getDolGlobalInt('MRPBATCH_DEFAULT_EATBY_DAYS');
		$dluo = !empty($opts['options_mrpbatch_dluo_days']) ? (int) $opts['options_mrpbatch_dluo_days'] : getDolGlobalInt('MRPBATCH_DEFAULT_SELLBY_DAYS');

		$mandatory = isset($product->sell_or_eat_by_mandatory) ? (int) $product->sell_or_eat_by_mandatory : 0;

		return array(
			'eatby'            => $dlc > 0 ? dol_time_plus_duree($now, $dlc, 'd') : '',
			'sellby'           => $dluo > 0 ? dol_time_plus_duree($now, $dluo, 'd') : '',
			'eatby_mandatory'  => in_array($mandatory, array(2, 3)),
			'sellby_mandatory' => in_array($mandatory, array(1, 3)),
			'eatby_days'       => $dlc,
			'sellby_days'      => $dluo,
		);
	}

	/**
	 * Entrepôt de production proposé par défaut
	 *
	 * @param Mo $mo OF
	 * @return int
	 */
	public function getDefaultProduceWarehouse(Mo $mo)
	{
		if (getDolGlobalInt('MRPBATCH_PRODUCE_WAREHOUSE') > 0) {
			return getDolGlobalInt('MRPBATCH_PRODUCE_WAREHOUSE');
		}
		if (!empty($mo->fk_warehouse)) {
			return (int) $mo->fk_warehouse;
		}
		$list = $this->getWarehouses();
		return !empty($list) ? (int) $list[0]['id'] : 0;
	}

	/**
	 * Entrepôts
	 *
	 * @param bool $onlyOpen Uniquement les entrepôts ouverts
	 * @return array<int,array{id:int,ref:string,label:string}>
	 */
	public function getWarehouses($onlyOpen = true)
	{
		$out = array();
		$sql = 'SELECT rowid, ref, lieu FROM '.MAIN_DB_PREFIX.'entrepot';
		$sql .= ' WHERE entity IN ('.getEntity('stock').')';
		if ($onlyOpen) {
			$sql .= ' AND statut > 0';
		}
		$sql .= ' ORDER BY ref ASC';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'label' => (string) $obj->lieu);
			}
			$this->db->free($resql);
		}
		return $out;
	}

	/**
	 * Proposition complète pour déclarer une production sur une ligne
	 *
	 * @param Mo    $mo   OF
	 * @param array $line Ligne issue de getProduceLines
	 * @return array
	 */
	public function getProduceProposal(Mo $mo, $line)
	{
		$product = new Product($this->db);
		$product->fetch($line['fk_product']);
		$dates = $this->getDefaultDates($product);

		$qty = $line['status_batch'] == 2 ? 1 : max(0, $line['qty_remaining']);

		return array(
			'line'             => $line,
			'batch'            => $this->suggestBatch($product),
			'qty'              => $qty,
			'eatby'            => $dates['eatby'] ? dol_print_date($dates['eatby'], '%Y-%m-%d') : '',
			'sellby'           => $dates['sellby'] ? dol_print_date($dates['sellby'], '%Y-%m-%d') : '',
			'eatby_days'       => $dates['eatby_days'],
			'sellby_days'      => $dates['sellby_days'],
			'eatby_mandatory'  => $dates['eatby_mandatory'],
			'sellby_mandatory' => $dates['sellby_mandatory'],
			'fk_warehouse'     => $this->getDefaultProduceWarehouse($mo),
			'warehouses'       => $this->getWarehouses(),
		);
	}

	/**
	 * Convertit une date AAAA-MM-JJ en timestamp ('' si vide)
	 *
	 * @param string $value Date
	 * @param string $what  Libellé pour message d'erreur
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
			throw new Exception($what.' invalide : « '.$value.' » (format AAAA-MM-JJ)', 400);
		}
		return dol_mktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
	}

	/**
	 * Enregistre des productions sur un OF (transaction unique)
	 *
	 * @param Mo     $mo             OF chargé (avec lignes)
	 * @param array  $lines          [{objectid|fk_product, qty, fk_warehouse, batch?, eatby?, sellby?}]
	 * @param User   $user           Utilisateur
	 * @param string $labelmovement  Libellé du mouvement de stock
	 * @param string $codemovement   Code du mouvement de stock
	 * @return array Lignes créées
	 * @throws Exception
	 */
	public function produce(Mo $mo, array $lines, User $user, $labelmovement = '', $codemovement = '')
	{
		global $langs;

		if (!$user->hasRight('mrp', 'write')) {
			throw new Exception('Droit MRP en écriture requis', 403);
		}
		if (!$user->hasRight('stock', 'mouvement', 'creer')) {
			throw new Exception('Droit de création de mouvements de stock requis', 403);
		}
		if (!in_array((int) $mo->status, array(Mo::STATUS_VALIDATED, Mo::STATUS_INPROGRESS))) {
			throw new Exception("L'OF doit être validé ou en cours", 400);
		}
		if (empty($lines)) {
			throw new Exception('Aucune ligne à produire', 400);
		}
		if ($labelmovement === '') {
			$labelmovement = 'Production OF '.$mo->ref;
		}
		if ($codemovement === '') {
			$codemovement = dol_print_date(dol_now(), 'dayhourlog');
		}

		$pos = 0;
		foreach ($mo->lines as $l) {
			$pos = max($pos, (int) $l->position);
		}

		$created = array();
		$this->db->begin();

		try {
			// L'OF passe « En cours » au premier enregistrement (annulé avec le reste en cas d'erreur)
			$this->setInProgress($mo, $user);

			foreach (array_values($lines) as $i => $value) {
				$n = $i + 1;

				$line = null;
				foreach ($mo->lines as $l) {
					if ($l->role != 'toproduce') {
						continue;
					}
					if (!empty($value['objectid']) && (int) $l->id == (int) $value['objectid']) {
						$line = $l;
						break;
					}
					if (empty($value['objectid']) && !empty($value['fk_product']) && (int) $l->fk_product == (int) $value['fk_product']) {
						$line = $l;
						break;
					}
				}
				if (!$line) {
					throw new Exception("Ligne $n : aucun produit à fabriquer correspondant dans l'OF", 400);
				}

				$qty = isset($value['qty']) ? (float) price2num($value['qty']) : 0;
				if ($qty <= 0) {
					throw new Exception("Ligne $n : la quantité doit être > 0", 400);
				}
				$fk_warehouse = isset($value['fk_warehouse']) ? (int) $value['fk_warehouse'] : 0;
				$batch = isset($value['batch']) ? trim((string) $value['batch']) : '';

				$product = new Product($this->db);
				if ($product->fetch($line->fk_product) <= 0) {
					throw new Exception("Ligne $n : produit ".$line->fk_product.' introuvable', 500);
				}
				if ($product->status_batch && $batch === '') {
					throw new Exception("Ligne $n : le produit ".$product->ref.' est géré par lot, n° de lot obligatoire', 400);
				}
				if (!$product->status_batch && $batch !== '') {
					throw new Exception("Ligne $n : le produit ".$product->ref." n'est pas géré par lot", 400);
				}
				if ($product->status_batch == 2 && $qty != 1) {
					throw new Exception("Ligne $n : produit à n° de série, quantité = 1 par numéro", 400);
				}

				$eatby = $this->parseDate(isset($value['eatby']) ? $value['eatby'] : '', 'DLC');
				$sellby = $this->parseDate(isset($value['sellby']) ? $value['sellby'] : '', 'DLUO');

				// Lot déjà existant : réutiliser ses dates, refuser des dates différentes, refuser un n° de série déjà utilisé
				if ($batch !== '') {
					$lot = new Productlot($this->db);
					if ($lot->fetch(0, $line->fk_product, $batch) > 0) {
						if ($product->status_batch == 2) {
							throw new Exception("Ligne $n : le n° de série « $batch » existe déjà pour ".$product->ref, 400);
						}
						$lotEat = !empty($lot->eatby) ? dol_print_date($lot->eatby, '%Y-%m-%d') : '';
						$lotSell = !empty($lot->sellby) ? dol_print_date($lot->sellby, '%Y-%m-%d') : '';
						if ($eatby !== '' && $lotEat !== '' && dol_print_date($eatby, '%Y-%m-%d') !== $lotEat) {
							throw new Exception("Ligne $n : le lot « $batch » existe déjà avec la DLC ".dol_print_date($lot->eatby, 'day'), 400);
						}
						if ($sellby !== '' && $lotSell !== '' && dol_print_date($sellby, '%Y-%m-%d') !== $lotSell) {
							throw new Exception("Ligne $n : le lot « $batch » existe déjà avec la DLUO ".dol_print_date($lot->sellby, 'day'), 400);
						}
						if ($eatby === '' && !empty($lot->eatby)) {
							$eatby = $lot->eatby;
						}
						if ($sellby === '' && !empty($lot->sellby)) {
							$sellby = $lot->sellby;
						}
					}
				}

				$mandatory = isset($product->sell_or_eat_by_mandatory) ? (int) $product->sell_or_eat_by_mandatory : 0;
				if ($batch !== '' && in_array($mandatory, array(2, 3)) && $eatby === '') {
					throw new Exception("Ligne $n : DLC obligatoire pour ".$product->ref, 400);
				}
				if ($batch !== '' && in_array($mandatory, array(1, 3)) && $sellby === '') {
					throw new Exception("Ligne $n : DLUO obligatoire pour ".$product->ref, 400);
				}

				// Prix d'entrée en stock (0 = PMP inchangé)
				$price = 0;
				$priceMode = getDolGlobalString('MRPBATCH_PRODUCE_PRICE', 'none');
				if ($priceMode == 'costprice' && $product->cost_price > 0) {
					$price = (float) $product->cost_price;
				} elseif ($priceMode == 'pmp' && $product->pmp > 0) {
					$price = (float) $product->pmp;
				}

				$idstockmove = 0;
				if (empty($line->disable_stock_change)) {
					if ($fk_warehouse <= 0) {
						throw new Exception("Ligne $n : entrepôt obligatoire", 400);
					}
					$stockmove = new MouvementStock($this->db);
					$stockmove->setOrigin($mo->element, $mo->id);
					$stockmove->context['mrp_role'] = 'toproduce';

					$idstockmove = $stockmove->reception(
						$user,
						$line->fk_product,
						$fk_warehouse,
						$qty,
						$price,
						$labelmovement,
						$eatby,
						$sellby,
						$batch,
						dol_now(),
						0,
						$codemovement
					);
					if ($idstockmove <= 0) {
						$langs->load('errors');
						$err = $stockmove->error ? $langs->trans($stockmove->error) : implode(', ', $stockmove->errors);
						throw new Exception("Ligne $n : erreur mouvement de stock : ".$err, 500);
					}
				}

				$pos++;
				$moline = new MoLine($this->db);
				$moline->fk_mo = $mo->id;
				$moline->position = $pos;
				$moline->fk_product = $line->fk_product;
				$moline->fk_warehouse = $fk_warehouse > 0 ? $fk_warehouse : null;
				$moline->qty = $qty;
				$moline->batch = $batch;
				$moline->role = 'produced';
				$moline->fk_mrp_production = $line->id;
				$moline->fk_stock_movement = $idstockmove > 0 ? $idstockmove : null;
				$moline->fk_user_creat = $user->id;

				$idmoline = $moline->create($user);
				if ($idmoline <= 0) {
					throw new Exception("Ligne $n : erreur création ligne produite : ".$moline->error.' '.implode(', ', $moline->errors), 500);
				}

				$ev = $this->newMoEvent($mo, 'MRPBATCH_MO_PRODUCE', $product);
				$ev->batch = $batch;
				$ev->qty = $qty;
				$ev->eatby = $eatby ? dol_print_date($eatby, '%Y-%m-%d') : '';
				$ev->sellby = $sellby ? dol_print_date($sellby, '%Y-%m-%d') : '';
				$ev->fk_warehouse = $fk_warehouse;
				$ev->data = array('moline_id' => (int) $idmoline, 'fk_mrp_production' => (int) $line->id, 'stock_movement_id' => (int) $idstockmove, 'qty_planned' => (float) $line->qty);
				$ev->record($user);

				$created[] = array(
					'moline_id'         => (int) $idmoline,
					'fk_mrp_production' => (int) $line->id,
					'product_ref'       => $product->ref,
					'batch'             => $batch,
					'qty'               => $qty,
					'eatby'             => $eatby ? dol_print_date($eatby, '%Y-%m-%d') : '',
					'sellby'            => $sellby ? dol_print_date($sellby, '%Y-%m-%d') : '',
					'fk_warehouse'      => $fk_warehouse,
					'stock_movement_id' => (int) $idstockmove,
				);
			}

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}

		return $created;
	}

	/**
	 * Marque l'OF comme « Fabriqué » (clôture)
	 *
	 * @param Mo   $mo   OF
	 * @param User $user Utilisateur
	 * @return void
	 * @throws Exception
	 */
	public function closeMo(Mo $mo, User $user)
	{
		if (!$user->hasRight('mrp', 'write')) {
			throw new Exception('Droit MRP en écriture requis', 403);
		}
		if (!in_array((int) $mo->status, array(Mo::STATUS_VALIDATED, Mo::STATUS_INPROGRESS))) {
			throw new Exception("L'OF doit être validé ou en cours pour être clôturé", 400);
		}
		$this->db->begin();
		try {
			if ($mo->setStatut(Mo::STATUS_PRODUCED, 0, '', 'MRP_MO_PRODUCED') <= 0) {
				throw new Exception("Erreur lors de la clôture de l'OF : ".$mo->error, 500);
			}
			$this->newMoEvent($mo, 'MRPBATCH_MO_CLOSE')->record($user);
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
	}

	/**
	 * Historique des lignes consommées ou produites
	 *
	 * @param Mo     $mo   OF
	 * @param string $role 'consumed' ou 'produced'
	 * @return array
	 */
	public function getHistory(Mo $mo, $role)
	{
		$warehouses = array();
		foreach ($this->getWarehouses(false) as $w) {
			$warehouses[$w['id']] = $w['ref'];
		}
		$refs = array();
		$out = array();
		foreach ($mo->lines as $ml) {
			if ($ml->role != $role) {
				continue;
			}
			if (!isset($refs[$ml->fk_product])) {
				$p = new Product($this->db);
				$p->fetch($ml->fk_product);
				$refs[$ml->fk_product] = $p->ref;
			}
			$eatby = '';
			$sellby = '';
			if ($role == 'produced' && $ml->batch !== '' && $ml->batch !== null) {
				$lot = new Productlot($this->db);
				if ($lot->fetch(0, $ml->fk_product, $ml->batch) > 0) {
					$eatby = $lot->eatby ? dol_print_date($lot->eatby, 'day') : '';
					$sellby = $lot->sellby ? dol_print_date($lot->sellby, 'day') : '';
				}
			}
			$out[] = array(
				'id'          => (int) $ml->id,
				'date'        => dol_print_date($ml->date_creation, 'dayhour'),
				'product_ref' => $refs[$ml->fk_product],
				'batch'       => (string) $ml->batch,
				'warehouse'   => isset($warehouses[(int) $ml->fk_warehouse]) ? $warehouses[(int) $ml->fk_warehouse] : '',
				'qty'         => (float) $ml->qty,
				'eatby'       => $eatby,
				'sellby'      => $sellby,
			);
		}
		usort($out, function ($a, $b) {
			return $b['id'] - $a['id'];
		});
		return $out;
	}

	/* ===================================================================
	 * GS1 (codes logistiques des fournisseurs)
	 * =================================================================== */

	/**
	 * Décode un code GS1-128 / GS1 DataMatrix (AI 01 GTIN, 10 lot, 21 série, 11 fabrication, 15 DDM, 17 DLC, 37 quantité, 310n poids net).
	 * Le séparateur FNC1 peut être transmis par la scanette comme caractère GS (0x1D) ou absent : dans ce cas,
	 * une date valide en fin de champ variable est détachée (cas fréquent « 01…10LOT17AAMMJJ »).
	 *
	 * @param string $code Code lu
	 * @return array|null Tableau (gtin, batch, serial, eatby, sellby, proddate, count, weight) ou null si ce n'est pas un code GS1
	 */
	public static function parseGs1($code)
	{
		$code = preg_replace('/^\](C1|e0|d2|Q3)/', '', trim((string) $code));
		if (strpos($code, '(') !== false) {
			// Saisie lisible « (01)0376…(17)261231(10)L123 »
			$code = preg_replace('/\((\d{2,4})\)/', "\x1D".'$1', $code);
		}
		$code = ltrim($code, "\x1D");
		if (!preg_match('/^(01|02)\d{14}/', $code)) {
			return null;
		}
		$fixed = array('00' => 18, '01' => 14, '02' => 14, '11' => 6, '12' => 6, '13' => 6, '15' => 6, '16' => 6, '17' => 6);
		$out = array();
		$i = 0;
		$len = strlen($code);
		$guard = 0;
		while ($i < $len && $guard++ < 20) {
			if ($code[$i] === "\x1D") {
				$i++;
				continue;
			}
			$ai = substr($code, $i, 2);
			if (isset($fixed[$ai])) {
				$val = substr($code, $i + 2, $fixed[$ai]);
				$i += 2 + $fixed[$ai];
			} elseif (preg_match('/^(31\d\d|33\d\d)/', substr($code, $i, 4))) {
				$ai = substr($code, $i, 4);
				$val = substr($code, $i + 4, 6);
				$i += 10;
			} elseif (in_array($ai, array('10', '21', '37', '30'))) {
				$rest = substr($code, $i + 2);
				$gs = strpos($rest, "\x1D");
				$val = $gs === false ? $rest : substr($rest, 0, $gs);
				// Pas de séparateur : détacher une date (11/13/15/17 + AAMMJJ) collée en fin de champ
				if ($gs === false && preg_match('/^(.+?)(1[1357])(\d{2})(\d{2})(\d{2})$/', $val, $m) && checkdate((int) $m[4], max(1, (int) $m[5]), 2000 + (int) $m[3])) {
					$val = $m[1];
					$out = self::gs1Set($out, $m[2], $m[3].$m[4].$m[5]);
				}
				$i += 2 + strlen($val) + ($gs === false ? 0 : 1);
				if ($gs === false && $i < $len) {
					$i = $len;
				}
			} else {
				break;
			}
			$out = self::gs1Set($out, $ai, $val);
		}
		return empty($out['gtin']) ? null : $out;
	}

	/**
	 * Range une valeur GS1 décodée
	 *
	 * @param array  $out Résultat
	 * @param string $ai  Identifiant d'application
	 * @param string $val Valeur
	 * @return array
	 */
	private static function gs1Set($out, $ai, $val)
	{
		$date = function ($v) {
			if (!preg_match('/^(\d{2})(\d{2})(\d{2})$/', $v, $m)) {
				return '';
			}
			$y = 2000 + (int) $m[1];
			$mo = (int) $m[2];
			$d = (int) $m[3];
			if ($d == 0) {
				$d = cal_days_in_month_safe($mo, $y);
			}
			return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
		};
		switch (substr($ai, 0, 2)) {
			case '01':
			case '02':
				$out['gtin'] = $val;
				break;
			case '10':
				$out['batch'] = $val;
				break;
			case '21':
				$out['serial'] = $val;
				break;
			case '11':
				$out['proddate'] = $date($val);
				break;
			case '15':
				$out['sellby'] = $date($val);
				break;
			case '17':
				$out['eatby'] = $date($val);
				break;
			case '37':
			case '30':
				$out['count'] = (float) $val;
				break;
			case '31':
				if (substr($ai, 0, 3) == '310') {
					$out['weight'] = (float) $val / pow(10, (int) substr($ai, 3, 1));
				}
				break;
		}
		return $out;
	}

	/**
	 * Produit correspondant à un GTIN (code-barre produit EAN13/GTIN14, ou code-barre fournisseur)
	 *
	 * @param string $gtin GTIN 14 chiffres
	 * @return int ID produit ou 0
	 */
	public function findProductByGtin($gtin)
	{
		$cands = array($gtin, ltrim($gtin, '0'));
		if (strlen($gtin) == 14 && $gtin[0] === '0') {
			$cands[] = substr($gtin, 1);
		}
		$in = array();
		foreach (array_unique($cands) as $c) {
			if ($c !== '') {
				$in[] = "'".$this->db->escape($c)."'";
			}
		}
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX.'product WHERE barcode IN ('.implode(',', $in).') AND entity IN ('.getEntity('product').')';
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		// Code-barre saisi sur le prix fournisseur (Dolibarr 14+)
		$sql = 'SELECT fk_product as rowid FROM '.MAIN_DB_PREFIX.'product_fournisseur_price WHERE barcode IN ('.implode(',', $in).') AND entity IN ('.getEntity('product').')';
		$resql = @$this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * Recherche simple d'un produit par code-barre, référence, code fournisseur ou GS1
	 *
	 * @param string $code Code lu
	 * @return array{fk_product:int,batch:string,gs1:array}|null
	 */
	public function resolveProductCode($code)
	{
		$code = trim((string) $code);
		if ($code === '') {
			return null;
		}
		$gs1 = self::parseGs1($code);
		if ($gs1) {
			$fk = $this->findProductByGtin($gs1['gtin']);
			return $fk ? array('fk_product' => $fk, 'batch' => isset($gs1['batch']) ? $gs1['batch'] : (isset($gs1['serial']) ? $gs1['serial'] : ''), 'gs1' => $gs1) : null;
		}
		$c = $this->db->escape($code);
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX."product WHERE (barcode = '".$c."' OR ref = '".$c."') AND entity IN (".getEntity('product').')';
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array('fk_product' => (int) $obj->rowid, 'batch' => '', 'gs1' => array());
		}
		$sql = 'SELECT fk_product FROM '.MAIN_DB_PREFIX."product_fournisseur_price WHERE (ref_fourn = '".$c."' OR barcode = '".$c."') AND entity IN (".getEntity('product').')';
		$resql = @$this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array('fk_product' => (int) $obj->fk_product, 'batch' => '', 'gs1' => array());
		}
		return null;
	}

	/* ===================================================================
	 * POSTES DE TRAVAIL
	 * =================================================================== */

	/**
	 * Postes de travail actifs (module Postes de travail de Dolibarr)
	 *
	 * @return array<int,array{id:int,ref:string,label:string,type:string}>
	 */
	public function getWorkstations()
	{
		$out = array();
		if (!isModEnabled('workstation')) {
			return $out;
		}
		$sql = 'SELECT rowid, ref, label, type FROM '.MAIN_DB_PREFIX.'workstation_workstation';
		$sql .= ' WHERE entity IN ('.getEntity('workstation').') AND status = 1 ORDER BY ref ASC';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$out[] = array('id' => (int) $obj->rowid, 'ref' => (string) $obj->ref, 'label' => (string) $obj->label, 'type' => (string) $obj->type);
			}
			$this->db->free($resql);
		}
		return $out;
	}

	/**
	 * Postes de travail de plusieurs OF : opérations de la gamme (lignes d'OF) + champ « Poste de travail » de l'OF
	 *
	 * @param int[] $moIds IDs des OF
	 * @return array<int,int[]> fk_mo => liste d'ID de postes
	 */
	public function getMoWorkstationMap($moIds)
	{
		$map = array();
		$ids = array_filter(array_map('intval', (array) $moIds));
		if (empty($ids) || !isModEnabled('workstation')) {
			return $map;
		}
		$sql = 'SELECT DISTINCT fk_mo, fk_default_workstation as ws FROM '.MAIN_DB_PREFIX.'mrp_production';
		$sql .= ' WHERE fk_default_workstation > 0 AND fk_mo IN ('.implode(',', $ids).')';
		$sql .= ' UNION SELECT fk_object as fk_mo, mrpbatch_workstation as ws FROM '.MAIN_DB_PREFIX.'mrp_mo_extrafields';
		$sql .= ' WHERE mrpbatch_workstation > 0 AND fk_object IN ('.implode(',', $ids).')';
		$resql = @$this->db->query($sql);
		if (!$resql) {
			// Champ complémentaire absent : uniquement les opérations
			$sql = 'SELECT DISTINCT fk_mo, fk_default_workstation as ws FROM '.MAIN_DB_PREFIX.'mrp_production';
			$sql .= ' WHERE fk_default_workstation > 0 AND fk_mo IN ('.implode(',', $ids).')';
			$resql = @$this->db->query($sql);
		}
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$map[(int) $obj->fk_mo][] = (int) $obj->ws;
			}
			foreach ($map as $k => $v) {
				$map[$k] = array_values(array_unique($v));
			}
		}
		return $map;
	}

	/**
	 * Condition SQL « l'OF m concerne le poste $ws » (-1 = OF sans poste)
	 *
	 * @param int $ws ID du poste, -1 pour « sans poste »
	 * @return string
	 */
	public function sqlWorkstationFilter($ws)
	{
		$ws = (int) $ws;
		if (!$ws || !isModEnabled('workstation')) {
			return '';
		}
		$hasEf = $this->hasMoWorkstationField();
		if ($ws < 0) {
			$sql = ' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'mrp_production as mpw WHERE mpw.fk_mo = m.rowid AND mpw.fk_default_workstation > 0)';
			if ($hasEf) {
				$sql .= ' AND NOT EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'mrp_mo_extrafields as mew WHERE mew.fk_object = m.rowid AND mew.mrpbatch_workstation > 0)';
			}
			return $sql;
		}
		$sql = ' AND (EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'mrp_production as mpw WHERE mpw.fk_mo = m.rowid AND mpw.fk_default_workstation = '.$ws.')';
		if ($hasEf) {
			$sql .= ' OR EXISTS (SELECT 1 FROM '.MAIN_DB_PREFIX.'mrp_mo_extrafields as mew WHERE mew.fk_object = m.rowid AND mew.mrpbatch_workstation = '.$ws.')';
		}
		return $sql.')';
	}

	/**
	 * Le champ complémentaire « Poste de travail » de l'OF existe-t-il ?
	 *
	 * @return bool
	 */
	public function hasMoWorkstationField()
	{
		static $has = null;
		if ($has === null) {
			$resql = $this->db->query('SELECT COUNT(*) as nb FROM '.MAIN_DB_PREFIX."extrafields WHERE elementtype = 'mrp_mo' AND name = 'mrpbatch_workstation'");
			$has = ($resql && ($obj = $this->db->fetch_object($resql)) && $obj->nb > 0);
		}
		return $has;
	}

	/* ===================================================================
	 * LISTE DES OF (tableau de bord + planning + ics)
	 * =================================================================== */

	/**
	 * Liste des OF avec avancement
	 *
	 * @param array $f Filtres : statuses (int[]), ws (int), from (ts), to (ts), unplanned (bool), limit (int)
	 * @return array
	 */
	public function listMos($f = array())
	{
		$statuses = !empty($f['statuses']) ? array_map('intval', $f['statuses']) : array(Mo::STATUS_VALIDATED, Mo::STATUS_INPROGRESS);
		$sql = 'SELECT m.rowid, m.ref, m.label, m.qty, m.status, m.date_start_planned, m.date_end_planned, m.fk_product, m.fk_soc, m.fk_warehouse,';
		$sql .= ' p.ref as pref, p.label as plabel, s.nom as socname,';
		$sql .= " (SELECT SUM(mp.qty) FROM ".MAIN_DB_PREFIX."mrp_production as mp WHERE mp.fk_mo = m.rowid AND mp.role = 'toconsume') as qty_planned,";
		$sql .= " (SELECT SUM(mp2.qty) FROM ".MAIN_DB_PREFIX."mrp_production as mp2 WHERE mp2.fk_mo = m.rowid AND mp2.role = 'consumed') as qty_consumed,";
		$sql .= " (SELECT SUM(mp3.qty) FROM ".MAIN_DB_PREFIX."mrp_production as mp3 WHERE mp3.fk_mo = m.rowid AND mp3.role = 'produced') as qty_produced";
		$sql .= ' FROM '.MAIN_DB_PREFIX.'mrp_mo as m';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = m.fk_product';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = m.fk_soc';
		$sql .= ' WHERE m.entity IN ('.getEntity('mo').')';
		$sql .= ' AND m.status IN ('.implode(',', $statuses).')';
		if (!empty($f['ws'])) {
			$sql .= $this->sqlWorkstationFilter($f['ws']);
		}
		if (!empty($f['from']) && !empty($f['to'])) {
			// Chevauchement de la période ; OF sans date de fin : on prend la date de début
			$sql .= " AND m.date_start_planned IS NOT NULL AND m.date_start_planned < '".$this->db->idate($f['to'])."'";
			$sql .= " AND COALESCE(m.date_end_planned, m.date_start_planned) >= '".$this->db->idate($f['from'])."'";
		} elseif (!empty($f['unplanned'])) {
			$sql .= ' AND m.date_start_planned IS NULL';
		}
		$sql .= ' ORDER BY m.date_start_planned ASC, m.ref ASC';
		$sql .= $this->db->plimit(!empty($f['limit']) ? (int) $f['limit'] : 500);

		$out = array();
		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror(), 500);
		}
		$now = dol_now();
		$today = dol_mktime(0, 0, 0, (int) dol_print_date($now, '%m'), (int) dol_print_date($now, '%d'), (int) dol_print_date($now, '%Y'));
		while ($obj = $this->db->fetch_object($resql)) {
			$planned = (float) $obj->qty_planned;
			$consumed = (float) $obj->qty_consumed;
			$start = $obj->date_start_planned ? $this->db->jdate($obj->date_start_planned) : 0;
			$end = $obj->date_end_planned ? $this->db->jdate($obj->date_end_planned) : 0;
			$late = 0;
			if ((int) $obj->status == Mo::STATUS_VALIDATED && $start && $start < $today) {
				$late = 1; // aurait dû démarrer
			}
			if (in_array((int) $obj->status, array(Mo::STATUS_VALIDATED, Mo::STATUS_INPROGRESS)) && $end && $end < $now) {
				$late = 2; // aurait dû être terminé
			}
			$out[] = array(
				'id'            => (int) $obj->rowid,
				'ref'           => $obj->ref,
				'label'         => (string) $obj->label,
				'product_ref'   => (string) $obj->pref,
				'product_label' => (string) $obj->plabel,
				'thirdparty'    => (string) $obj->socname,
				'qty'           => (float) $obj->qty,
				'produced'      => (float) $obj->qty_produced,
				'status'        => (int) $obj->status,
				'start'         => $start ? (int) $start : 0,
				'end'           => $end ? (int) $end : 0,
				'start_iso'     => $start ? dol_print_date($start, '%Y-%m-%dT%H:%M') : '',
				'end_iso'       => $end ? dol_print_date($end, '%Y-%m-%dT%H:%M') : '',
				'date_start'    => $start ? dol_print_date($start, 'dayhour') : '',
				'date_end'      => $end ? dol_print_date($end, 'dayhour') : '',
				'late'          => $late,
				'pct'           => $planned > 0 ? (int) min(100, round(100 * $consumed / $planned)) : 0,
				'produce_pct'   => $obj->qty > 0 ? (int) min(100, round(100 * (float) $obj->qty_produced / (float) $obj->qty)) : 0,
				'workstations'  => array(),
			);
		}
		$this->db->free($resql);

		$map = $this->getMoWorkstationMap(array_column($out, 'id'));
		foreach ($out as $k => $m) {
			$out[$k]['workstations'] = isset($map[$m['id']]) ? $map[$m['id']] : array();
		}
		return $out;
	}

	/**
	 * Replanifie un OF (dates de début / fin prévues)
	 *
	 * @param Mo     $mo    OF
	 * @param int    $start Timestamp début
	 * @param int    $end   Timestamp fin (0 = vide)
	 * @param User   $user  Utilisateur
	 * @return void
	 * @throws Exception
	 */
	public function rescheduleMo(Mo $mo, $start, $end, User $user)
	{
		if (!$user->hasRight('mrp', 'write')) {
			throw new Exception('Droit MRP en écriture requis', 403);
		}
		if (in_array((int) $mo->status, array(Mo::STATUS_PRODUCED, Mo::STATUS_CANCELED))) {
			throw new Exception('Un OF fabriqué ou annulé ne peut pas être replanifié', 400);
		}
		if ($start <= 0) {
			throw new Exception('Date de début obligatoire', 400);
		}
		if ($end > 0 && $end <= $start) {
			throw new Exception('La fin doit être postérieure au début', 400);
		}
		$old = array('start' => $mo->date_start_planned ? dol_print_date($mo->date_start_planned, '%Y-%m-%dT%H:%M') : '', 'end' => $mo->date_end_planned ? dol_print_date($mo->date_end_planned, '%Y-%m-%dT%H:%M') : '');

		$this->db->begin();
		try {
			$sql = 'UPDATE '.MAIN_DB_PREFIX.'mrp_mo SET';
			$sql .= " date_start_planned = '".$this->db->idate($start)."',";
			$sql .= ' date_end_planned = '.($end > 0 ? "'".$this->db->idate($end)."'" : 'NULL').',';
			$sql .= ' fk_user_modif = '.((int) $user->id);
			$sql .= ' WHERE rowid = '.((int) $mo->id);
			if (!$this->db->query($sql)) {
				throw new Exception($this->db->lasterror(), 500);
			}
			$mo->date_start_planned = $start;
			$mo->date_end_planned = $end > 0 ? $end : '';
			$ev = $this->newMoEvent($mo, 'MRPBATCH_MO_RESCHEDULE');
			$ev->data['old'] = $old;
			$ev->data['new'] = array('start' => dol_print_date($start, '%Y-%m-%dT%H:%M'), 'end' => $end > 0 ? dol_print_date($end, '%Y-%m-%dT%H:%M') : '');
			$ev->record($user);
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
	}
}

if (!function_exists('cal_days_in_month_safe')) {
	/**
	 * Nombre de jours d'un mois (sans dépendre de l'extension calendar)
	 *
	 * @param int $m Mois
	 * @param int $y Année
	 * @return int
	 */
	function cal_days_in_month_safe($m, $y)
	{
		return (int) date('t', mktime(0, 0, 0, $m, 1, $y));
	}
}
