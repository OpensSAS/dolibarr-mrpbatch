<?php
/* Module Mrpbatch — Préparation et conditionnement des commandes clients
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/mrpbatch/class/mrpbatchevent.class.php');
dol_include_once('/mrpbatch/class/mrpbatchconsume.class.php');

/**
 * Suivi atelier des commandes clients validées : étape (à préparer → … → prête), quantités préparées par ligne
 * (scan des produits, lots prélevés), nombre de colis / poids au conditionnement, OF liés.
 *
 * Ne crée pas d'expédition Dolibarr : la commande reste gérée nativement (expéditions, factures).
 */
class MrpbatchOrders
{
	const DEFAULT_STEPS = "toprepare|À préparer\npreparing|En préparation\nprepared|Préparée\npacked|Conditionnée\nready|Prête à expédier";

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
	 * Droits
	 *
	 * @param User $user Utilisateur
	 * @return array{read:bool,write:bool}
	 */
	public static function rights(User $user)
	{
		$enabled = isModEnabled('commande') || isModEnabled('order');
		$read = $enabled && $user->hasRight('commande', 'lire');
		$write = $read && ($user->hasRight('commande', 'creer') || (isModEnabled('expedition') && $user->hasRight('expedition', 'creer')));
		return array('read' => $read, 'write' => $write);
	}

	/**
	 * Étapes configurées (code => libellé), dans l'ordre
	 *
	 * @return array<string,string>
	 */
	public static function getSteps()
	{
		$raw = getDolGlobalString('MRPBATCH_ORDER_STEPS', self::DEFAULT_STEPS);
		$steps = array();
		foreach (preg_split('/\r\n|\r|\n/', $raw) as $l) {
			$l = trim($l);
			if ($l === '' || strpos($l, '|') === false) {
				continue;
			}
			list($code, $label) = array_map('trim', explode('|', $l, 2));
			$code = preg_replace('/[^a-z0-9_]/', '', strtolower($code));
			if ($code !== '' && $label !== '') {
				$steps[$code] = $label;
			}
		}
		if (count($steps) < 2) {
			return self::getStepsFrom(self::DEFAULT_STEPS);
		}
		return $steps;
	}

	/**
	 * Étapes à partir d'un texte
	 *
	 * @param string $raw Texte code|libellé par ligne
	 * @return array<string,string>
	 */
	private static function getStepsFrom($raw)
	{
		$steps = array();
		foreach (explode("\n", $raw) as $l) {
			list($code, $label) = explode('|', $l, 2);
			$steps[$code] = $label;
		}
		return $steps;
	}

	/**
	 * Étape à partir de laquelle on demande colis / poids
	 *
	 * @return string
	 */
	public static function getPackStep()
	{
		$steps = self::getSteps();
		$code = getDolGlobalString('MRPBATCH_ORDER_PACK_STEP', 'packed');
		return isset($steps[$code]) ? $code : '';
	}

	/**
	 * Commandes clients à préparer (validées ou en cours d'expédition)
	 *
	 * @param array $f Filtres : search, days (livraison ≤ aujourd'hui + n jours), step
	 * @return array
	 */
	public function listOrders($f = array())
	{
		$steps = self::getSteps();
		$first = key($steps);
		$sql = 'SELECT c.rowid, c.ref, c.ref_client, c.fk_statut, c.date_livraison, c.date_commande, c.fk_soc, s.nom as socname,';
		$sql .= ' op.step, op.nb_packages, op.date_step,';
		$sql .= ' (SELECT COUNT(*) FROM '.MAIN_DB_PREFIX.'commandedet as cd WHERE cd.fk_commande = c.rowid AND cd.fk_product > 0 AND cd.product_type = 0) as nblines,';
		$sql .= ' (SELECT SUM(cd2.qty) FROM '.MAIN_DB_PREFIX.'commandedet as cd2 WHERE cd2.fk_commande = c.rowid AND cd2.fk_product > 0 AND cd2.product_type = 0) as qty_ordered,';
		$sql .= ' (SELECT SUM(ol.qty_prepared) FROM '.MAIN_DB_PREFIX.'mrpbatch_orderline as ol WHERE ol.fk_commande = c.rowid) as qty_prepared';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'commande as c';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = c.fk_soc';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'mrpbatch_orderprep as op ON op.fk_commande = c.rowid';
		$sql .= ' WHERE c.entity IN ('.getEntity('commande').')';
		$sql .= ' AND c.fk_statut IN (1, 2)';
		if (!empty($f['search'])) {
			$e = $this->db->escape($f['search']);
			$sql .= " AND (c.ref LIKE '%".$e."%' OR c.ref_client LIKE '%".$e."%' OR s.nom LIKE '%".$e."%')";
		}
		if (isset($f['days']) && $f['days'] !== '' && (int) $f['days'] >= 0) {
			$limit = dol_time_plus_duree(dol_now(), (int) $f['days'], 'd');
			$sql .= " AND c.date_livraison IS NOT NULL AND c.date_livraison <= '".$this->db->idate($limit)."'";
		}
		$sql .= ' ORDER BY c.date_livraison IS NULL, c.date_livraison ASC, c.ref ASC';
		$sql .= $this->db->plimit(400);

		$resql = $this->db->query($sql);
		if (!$resql) {
			throw new Exception($this->db->lasterror(), 500);
		}
		$today = dol_print_date(dol_now(), '%Y-%m-%d');
		$out = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$step = ($obj->step && isset($steps[$obj->step])) ? $obj->step : $first;
			$ord = (float) $obj->qty_ordered;
			$prep = (float) $obj->qty_prepared;
			$dl = $obj->date_livraison ? $this->db->jdate($obj->date_livraison) : 0;
			$dli = $dl ? dol_print_date($dl, '%Y-%m-%d') : '';
			$out[] = array(
				'id'           => (int) $obj->rowid,
				'ref'          => $obj->ref,
				'ref_client'   => (string) $obj->ref_client,
				'customer'     => (string) $obj->socname,
				'status'       => (int) $obj->fk_statut,
				'step'         => $step,
				'nb_packages'  => (int) $obj->nb_packages,
				'delivery'     => $dl ? dol_print_date($dl, 'day') : '',
				'delivery_iso' => $dli,
				'due'          => $dli === '' ? '' : ($dli < $today ? 'late' : ($dli == $today ? 'today' : '')),
				'nblines'      => (int) $obj->nblines,
				'pct'          => $ord > 0 ? (int) min(100, round(100 * $prep / $ord)) : 0,
				'mos'          => array(),
				'shortage'     => 0,
			);
		}
		$this->db->free($resql);

		if (!empty($f['step'])) {
			$out = array_values(array_filter($out, function ($o) use ($f) {
				return $o['step'] === $f['step'];
			}));
		}

		// OF liés et ruptures de stock (en une requête chacun)
		$ids = array_column($out, 'id');
		if ($ids) {
			$mos = $this->getLinkedMos($ids);
			$short = $this->getShortages($ids);
			foreach ($out as $k => $o) {
				$out[$k]['mos'] = isset($mos[$o['id']]) ? $mos[$o['id']] : array();
				$out[$k]['shortage'] = isset($short[$o['id']]) ? $short[$o['id']] : 0;
			}
		}
		return $out;
	}

	/**
	 * OF liés aux commandes (liens d'objets commande ↔ mo)
	 *
	 * @param int[] $ids Commandes
	 * @return array<int,array> fk_commande => [{id, ref, status}]
	 */
	public function getLinkedMos($ids)
	{
		$map = array();
		if (!isModEnabled('mrp') || empty($ids)) {
			return $map;
		}
		$in = implode(',', array_map('intval', $ids));
		$sql = 'SELECT ee.fk_source as cid, m.rowid, m.ref, m.status FROM '.MAIN_DB_PREFIX.'element_element as ee';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX."mrp_mo as m ON m.rowid = ee.fk_target AND ee.targettype = 'mo'";
		$sql .= " WHERE ee.sourcetype = 'commande' AND ee.fk_source IN (".$in.')';
		$sql .= ' UNION SELECT ee2.fk_target as cid, m2.rowid, m2.ref, m2.status FROM '.MAIN_DB_PREFIX.'element_element as ee2';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX."mrp_mo as m2 ON m2.rowid = ee2.fk_source AND ee2.sourcetype = 'mo'";
		$sql .= " WHERE ee2.targettype = 'commande' AND ee2.fk_target IN (".$in.')';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$map[(int) $obj->cid][] = array('id' => (int) $obj->rowid, 'ref' => $obj->ref, 'status' => (int) $obj->status);
			}
		}
		return $map;
	}

	/**
	 * Nombre de lignes dont le stock physique total est inférieur au reste à préparer
	 *
	 * @param int[] $ids Commandes
	 * @return array<int,int>
	 */
	public function getShortages($ids)
	{
		$map = array();
		$in = implode(',', array_map('intval', $ids));
		$sql = 'SELECT cd.fk_commande, cd.rowid, cd.qty, COALESCE(ol.qty_prepared, 0) as prep,';
		$sql .= ' (SELECT SUM(ps.reel) FROM '.MAIN_DB_PREFIX.'product_stock as ps WHERE ps.fk_product = cd.fk_product) as stock';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'commandedet as cd';
		$sql .= ' INNER JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = cd.fk_product';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'mrpbatch_orderline as ol ON ol.fk_commandedet = cd.rowid';
		$sql .= ' WHERE cd.fk_commande IN ('.$in.') AND cd.product_type = 0 AND p.fk_product_type = 0';
		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$rest = (float) $obj->qty - (float) $obj->prep;
				if ($rest > 0 && (float) $obj->stock < $rest) {
					$map[(int) $obj->fk_commande] = (isset($map[(int) $obj->fk_commande]) ? $map[(int) $obj->fk_commande] : 0) + 1;
				}
			}
		}
		return $map;
	}

	/**
	 * Commande client détaillée pour l'écran de préparation
	 *
	 * @param int $id ID commande
	 * @return array
	 * @throws Exception
	 */
	public function getOrder($id)
	{
		require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
		$cmd = new Commande($this->db);
		if ($cmd->fetch((int) $id) <= 0) {
			throw new Exception('Commande client introuvable', 404);
		}
		$cmd->fetch_thirdparty();
		$steps = self::getSteps();
		$prep = $this->getPrep($cmd->id);

		$prepared = array();
		$resql = $this->db->query('SELECT fk_commandedet, qty_prepared, batches FROM '.MAIN_DB_PREFIX.'mrpbatch_orderline WHERE fk_commande = '.((int) $cmd->id));
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$prepared[(int) $obj->fk_commandedet] = array('qty' => (float) $obj->qty_prepared, 'batches' => $obj->batches ? (array) json_decode($obj->batches, true) : array());
			}
		}

		$lines = array();
		foreach ($cmd->lines as $l) {
			if (empty($l->fk_product) || (int) $l->product_type != 0) {
				continue;
			}
			$p = new Product($this->db);
			$p->fetch($l->fk_product);
			if ((int) $p->type != 0) {
				continue;
			}
			$p->load_stock('novirtual');
			$done = isset($prepared[(int) $l->id]) ? $prepared[(int) $l->id]['qty'] : 0;
			$lines[] = array(
				'objectid'      => (int) $l->id,
				'fk_product'    => (int) $p->id,
				'product_ref'   => $p->ref,
				'product_label' => $p->label,
				'barcode'       => (string) $p->barcode,
				'status_batch'  => (int) $p->status_batch,
				'qty_planned'   => (float) $l->qty,
				'qty_done'      => (float) $done,
				'qty_remaining' => (float) $l->qty - $done,
				'stock'         => (float) $p->stock_reel,
				'batches'       => isset($prepared[(int) $l->id]) ? $prepared[(int) $l->id]['batches'] : array(),
			);
		}

		$mos = $this->getLinkedMos(array($cmd->id));
		return array(
			'id'          => (int) $cmd->id,
			'ref'         => $cmd->ref,
			'ref_client'  => (string) $cmd->ref_client,
			'customer'    => $cmd->thirdparty ? $cmd->thirdparty->name : '',
			'status'      => (int) ($cmd->status ?? $cmd->statut),
			'delivery'    => !empty($cmd->delivery_date) ? dol_print_date($cmd->delivery_date, 'day') : '',
			'step'        => ($prep && isset($steps[$prep['step']])) ? $prep['step'] : key($steps),
			'nb_packages' => $prep ? (int) $prep['nb_packages'] : 0,
			'weight'      => $prep ? (float) $prep['weight'] : 0,
			'note'        => $prep ? (string) $prep['note'] : '',
			'note_public' => dol_string_nohtmltag((string) $cmd->note_public),
			'url'         => DOL_URL_ROOT.'/commande/card.php?id='.((int) $cmd->id),
			'shipping_url' => isModEnabled('expedition') ? DOL_URL_ROOT.'/expedition/shipment.php?id='.((int) $cmd->id) : '',
			'mos'         => isset($mos[$cmd->id]) ? $mos[$cmd->id] : array(),
			'lines'       => $lines,
			'history'     => MrpbatchEvent::fetchLast($this->db, array('MRPBATCH_ORDER_PICK', 'MRPBATCH_ORDER_STEP', 'MRPBATCH_ORDER_READY'), 40, 'commande', (int) $cmd->id),
		);
	}

	/**
	 * Ligne de suivi d'une commande
	 *
	 * @param int $id Commande
	 * @return array|null
	 */
	private function getPrep($id)
	{
		$resql = $this->db->query('SELECT step, nb_packages, weight, note FROM '.MAIN_DB_PREFIX.'mrpbatch_orderprep WHERE fk_commande = '.((int) $id));
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return array('step' => $obj->step, 'nb_packages' => $obj->nb_packages, 'weight' => $obj->weight, 'note' => $obj->note);
		}
		return null;
	}

	/**
	 * Charge la commande (contrôles communs)
	 *
	 * @param int  $id   Commande
	 * @param User $user Utilisateur
	 * @return Commande
	 * @throws Exception
	 */
	private function loadForWrite($id, User $user)
	{
		$r = self::rights($user);
		if (!$r['write']) {
			throw new Exception('Droit de modification des commandes ou de création d\'expéditions requis', 403);
		}
		require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
		$cmd = new Commande($this->db);
		if ($cmd->fetch((int) $id) <= 0) {
			throw new Exception('Commande client introuvable', 404);
		}
		if (!in_array((int) ($cmd->status ?? $cmd->statut), array(1, 2))) {
			throw new Exception('La commande '.$cmd->ref.' n\'est pas validée ou en cours', 400);
		}
		return $cmd;
	}

	/**
	 * Déclare une quantité préparée sur une ligne (cumul) ; qty négative = correction ; reset = remise à zéro
	 *
	 * @param int    $id     Commande
	 * @param int    $lineid Ligne de commande
	 * @param float  $qty    Quantité préparée
	 * @param string $batch  Lot prélevé (facultatif)
	 * @param User   $user   Utilisateur
	 * @param bool   $reset  Remettre la ligne à zéro
	 * @return array
	 * @throws Exception
	 */
	public function pick($id, $lineid, $qty, $batch, User $user, $reset = false)
	{
		$cmd = $this->loadForWrite($id, $user);
		$line = null;
		foreach ($cmd->lines as $l) {
			if ((int) $l->id == (int) $lineid) {
				$line = $l;
			}
		}
		if (!$line || empty($line->fk_product)) {
			throw new Exception('Ligne de commande introuvable', 404);
		}
		$qty = (float) price2num($qty);
		if (!$reset && $qty == 0) {
			throw new Exception('Quantité nulle', 400);
		}
		$batch = trim((string) $batch);

		$this->db->begin();
		try {
			$cur = 0;
			$batches = array();
			$exists = false;
			$resql = $this->db->query('SELECT qty_prepared, batches FROM '.MAIN_DB_PREFIX.'mrpbatch_orderline WHERE fk_commandedet = '.((int) $line->id));
			if ($resql && ($obj = $this->db->fetch_object($resql))) {
				$exists = true;
				$cur = (float) $obj->qty_prepared;
				$batches = $obj->batches ? (array) json_decode($obj->batches, true) : array();
			}
			if ($reset) {
				$new = 0;
				$batches = array();
			} else {
				$new = $cur + $qty;
				if ($new < 0) {
					throw new Exception('La quantité préparée ne peut pas devenir négative', 400);
				}
				if ($batch !== '') {
					$batches[$batch] = (isset($batches[$batch]) ? (float) $batches[$batch] : 0) + $qty;
					if ($batches[$batch] <= 0) {
						unset($batches[$batch]);
					}
				}
			}
			$bj = empty($batches) ? 'NULL' : "'".$this->db->escape(json_encode($batches))."'";
			if ($exists) {
				$sql = 'UPDATE '.MAIN_DB_PREFIX.'mrpbatch_orderline SET qty_prepared = '.((float) $new).', batches = '.$bj.", date_prep = '".$this->db->idate(dol_now())."', fk_user = ".((int) $user->id);
				$sql .= ' WHERE fk_commandedet = '.((int) $line->id);
			} else {
				$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mrpbatch_orderline (fk_commande, fk_commandedet, qty_prepared, batches, date_prep, fk_user)';
				$sql .= ' VALUES ('.((int) $cmd->id).', '.((int) $line->id).', '.((float) $new).', '.$bj.", '".$this->db->idate(dol_now())."', ".((int) $user->id).')';
			}
			if (!$this->db->query($sql)) {
				throw new Exception($this->db->lasterror(), 500);
			}

			// Première préparation : la commande passe automatiquement à la 2e étape
			$steps = array_keys(self::getSteps());
			$prep = $this->getPrep($cmd->id);
			if ((!$prep || $prep['step'] === $steps[0]) && $new > 0) {
				$this->writeStep($cmd, $steps[1], null, null, null, $user, false);
			}

			$p = new Product($this->db);
			$p->fetch($line->fk_product);
			$ev = $this->newEvent($cmd, 'MRPBATCH_ORDER_PICK');
			$ev->fk_product = (int) $p->id;
			$ev->product_ref = $p->ref;
			$ev->product_label = $p->label;
			$ev->batch = $batch;
			$ev->qty = $reset ? -$cur : $qty;
			$ev->data = array('order_line_id' => (int) $line->id, 'qty_ordered' => (float) $line->qty, 'qty_prepared' => (float) $new, 'reset' => $reset ? 1 : 0);
			$ev->record($user);

			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		return array('qty_prepared' => $new, 'message' => $reset ? 'Ligne remise à zéro' : ($line->product_ref ?: '').' : '.price($new).' / '.price($line->qty).' préparé(s)');
	}

	/**
	 * Change l'étape d'une commande
	 *
	 * @param int        $id          Commande
	 * @param string     $step        Code étape
	 * @param int|null   $nbPackages  Nombre de colis
	 * @param float|null $weight      Poids (kg)
	 * @param string|null $note       Note
	 * @param User       $user        Utilisateur
	 * @return array
	 * @throws Exception
	 */
	public function setStep($id, $step, $nbPackages, $weight, $note, User $user)
	{
		$cmd = $this->loadForWrite($id, $user);
		$steps = self::getSteps();
		if (!isset($steps[$step])) {
			throw new Exception('Étape inconnue', 400);
		}
		$this->db->begin();
		try {
			$this->writeStep($cmd, $step, $nbPackages, $weight, $note, $user, true);
			$this->db->commit();
		} catch (Exception $e) {
			$this->db->rollback();
			throw $e;
		}
		return array('step' => $step, 'message' => $cmd->ref.' → '.$steps[$step]);
	}

	/**
	 * Écrit l'étape et émet les évènements
	 *
	 * @param Commande    $cmd        Commande
	 * @param string      $step       Étape
	 * @param int|null    $nbPackages Colis
	 * @param float|null  $weight     Poids
	 * @param string|null $note       Note
	 * @param User        $user       Utilisateur
	 * @param bool        $manual     Changement manuel (sinon automatique)
	 * @return void
	 * @throws Exception
	 */
	private function writeStep($cmd, $step, $nbPackages, $weight, $note, User $user, $manual)
	{
		global $conf;
		$steps = self::getSteps();
		$prev = $this->getPrep($cmd->id);
		$now = $this->db->idate(dol_now());
		if ($prev) {
			$sql = 'UPDATE '.MAIN_DB_PREFIX."mrpbatch_orderprep SET step = '".$this->db->escape($step)."', date_step = '".$now."', fk_user_step = ".((int) $user->id);
			if ($nbPackages !== null) {
				$sql .= ', nb_packages = '.((int) $nbPackages);
			}
			if ($weight !== null) {
				$sql .= ', weight = '.((float) $weight);
			}
			if ($note !== null) {
				$sql .= ", note = '".$this->db->escape($note)."'";
			}
			$sql .= ' WHERE fk_commande = '.((int) $cmd->id);
		} else {
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mrpbatch_orderprep (entity, fk_commande, step, nb_packages, weight, note, date_step, fk_user_step) VALUES (';
			$sql .= ((int) $conf->entity).', '.((int) $cmd->id).", '".$this->db->escape($step)."', ".($nbPackages !== null ? (int) $nbPackages : 'NULL').', ';
			$sql .= ($weight !== null ? (float) $weight : 'NULL').', '.($note !== null && $note !== '' ? "'".$this->db->escape($note)."'" : 'NULL').", '".$now."', ".((int) $user->id).')';
		}
		if (!$this->db->query($sql)) {
			throw new Exception($this->db->lasterror(), 500);
		}
		$prevStep = $prev ? $prev['step'] : key($steps);
		if ($prevStep === $step) {
			return;
		}
		$ev = $this->newEvent($cmd, 'MRPBATCH_ORDER_STEP');
		$ev->label = 'Commande '.$cmd->ref.' : '.$steps[$step];
		$ev->note = $note;
		$ev->data = array(
			'step'          => $step,
			'step_label'    => $steps[$step],
			'previous_step' => $prevStep,
			'automatic'     => $manual ? 0 : 1,
			'nb_packages'   => $nbPackages !== null ? (int) $nbPackages : ($prev ? (int) $prev['nb_packages'] : 0),
			'weight'        => $weight !== null ? (float) $weight : ($prev ? (float) $prev['weight'] : 0),
		);
		$ev->record($user);

		$keys = array_keys($steps);
		if ($step === end($keys)) {
			$ev2 = $this->newEvent($cmd, 'MRPBATCH_ORDER_READY');
			$ev2->data = $ev->data;
			$ev2->record($user);
		}
	}

	/**
	 * Évènement rattaché à une commande client
	 *
	 * @param Commande $cmd  Commande
	 * @param string   $code Code
	 * @return MrpbatchEvent
	 */
	private function newEvent($cmd, $code)
	{
		$ev = new MrpbatchEvent($this->db);
		$ev->code = $code;
		$ev->element_type = 'commande';
		$ev->fk_element = (int) $cmd->id;
		$ev->element_ref = $cmd->ref;
		$ev->fk_soc = (int) $cmd->socid;
		if (empty($cmd->thirdparty) && $cmd->socid > 0) {
			$cmd->fetch_thirdparty();
		}
		$ev->thirdparty_name = !empty($cmd->thirdparty) ? $cmd->thirdparty->name : '';
		return $ev;
	}

	/**
	 * Trouve une commande par sa référence
	 *
	 * @param string $code Réf. commande ou réf. client
	 * @return int
	 */
	public function findOrder($code)
	{
		$e = $this->db->escape(trim($code));
		$sql = 'SELECT rowid FROM '.MAIN_DB_PREFIX."commande WHERE (ref = '".$e."' OR ref_client = '".$e."') AND entity IN (".getEntity('commande').')';
		$resql = $this->db->query($sql);
		if ($resql && ($obj = $this->db->fetch_object($resql))) {
			return (int) $obj->rowid;
		}
		return 0;
	}

	/**
	 * Lots en stock d'un produit (pour indiquer le lot prélevé, FEFO)
	 *
	 * @param int $fk_product Produit
	 * @return array
	 */
	public function getLots($fk_product)
	{
		$svc = new MrpbatchConsume($this->db);
		$out = array();
		foreach ($svc->getAvailableStock($fk_product, 1) as $s) {
			$s['eatby'] = $s['eatby'] ? dol_print_date($s['eatby'], 'day') : '';
			$s['sellby'] = $s['sellby'] ? dol_print_date($s['sellby'], 'day') : '';
			$out[] = $s;
		}
		return $out;
	}
}
