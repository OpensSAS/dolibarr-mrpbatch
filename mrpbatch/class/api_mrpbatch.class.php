<?php
/* Module Mrpbatch — API REST de consommation par lot sur les ordres de fabrication
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

use Luracast\Restler\RestException;

dol_include_once('/mrpbatch/class/mrpbatchconsume.class.php');
dol_include_once('/mrpbatch/class/mrpbatchreception.class.php');
dol_include_once('/mrpbatch/class/mrpbatchorders.class.php');
dol_include_once('/mrpbatch/class/mrpbatchplanning.class.php');

/**
 * API Mrpbatch : consommation de lots / numéros de série sur les OF
 *
 * URL de base : /api/index.php/mrpbatchapi/   (classe MrpbatchApi → préfixe « mrpbatchapi », règle Dolibarr)
 *
 * @access protected
 * @class  DolibarrApiAccess {@requires user,external}
 */
class MrpbatchApi extends DolibarrApi
{
	/** @var Mo */
	private $mo;

	/** @var MrpbatchConsume */
	private $service;

	/**
	 * Constructor
	 */
	public function __construct()
	{
		global $db;
		$this->db = $db;
		$this->mo = new Mo($this->db);
		$this->service = new MrpbatchConsume($this->db);
	}

	/**
	 * Liste les lignes « à consommer » d'un OF, avec le déjà consommé et le stock disponible (par lot)
	 *
	 * @param int $id ID de l'ordre de fabrication
	 * @return array
	 *
	 * @url GET mos/{id}/consumelines
	 *
	 * @throws RestException 403
	 * @throws RestException 404
	 */
	public function getConsumeLines($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('mrp', 'read')) {
			throw new RestException(403);
		}
		$this->loadMo($id);
		return $this->service->getConsumeLines($this->mo, true);
	}

	/**
	 * Déclare la consommation d'un ou plusieurs lots sur un OF
	 *
	 * Exemple de corps :
	 * {
	 *   "inventorylabel": "Consommation OF",      (optionnel)
	 *   "inventorycode": "CONSO-20261002",        (optionnel)
	 *   "lines": [
	 *     { "objectid": 152, "qty": 2, "fk_warehouse": 1, "batch": "LOT-2026-0915" },
	 *     { "fk_product": 45, "qty": 1, "fk_warehouse": 1, "batch": "SN-000123", "eatby": "2027-03-31" }
	 *   ]
	 * }
	 *
	 * @param int   $id           ID de l'ordre de fabrication
	 * @param array $request_data Corps de la requête
	 * @return array
	 *
	 * @url POST mos/{id}/consumebatch
	 *
	 * @throws RestException 400
	 * @throws RestException 403
	 * @throws RestException 404
	 * @throws RestException 500
	 */
	public function consumeBatch($id, $request_data = null)
	{
		$this->loadMo($id);
		if (empty($request_data['lines']) || !is_array($request_data['lines'])) {
			throw new RestException(400, 'Champ "lines" obligatoire (tableau non vide)');
		}

		try {
			$created = $this->service->consume(
				$this->mo,
				$request_data['lines'],
				DolibarrApiAccess::$user,
				!empty($request_data['inventorylabel']) ? (string) $request_data['inventorylabel'] : '',
				!empty($request_data['inventorycode']) ? (string) $request_data['inventorycode'] : ''
			);
		} catch (Exception $e) {
			$code = in_array((int) $e->getCode(), array(400, 403, 404)) ? (int) $e->getCode() : 500;
			throw new RestException($code, $e->getMessage());
		}

		return array(
			'mo_id'    => (int) $this->mo->id,
			'mo_ref'   => $this->mo->ref,
			'consumed' => $created,
		);
	}

	/**
	 * Liste les produits à fabriquer d'un OF avec le déjà produit et une proposition
	 * (n° de lot suggéré, DLC/DLUO par défaut, entrepôt)
	 *
	 * @param int $id ID de l'ordre de fabrication
	 * @return array
	 *
	 * @url GET mos/{id}/producelines
	 *
	 * @throws RestException 403
	 * @throws RestException 404
	 */
	public function getProduceLines($id)
	{
		if (!DolibarrApiAccess::$user->hasRight('mrp', 'read')) {
			throw new RestException(403);
		}
		$this->loadMo($id);
		$out = array();
		foreach ($this->service->getProduceLines($this->mo) as $line) {
			$out[] = $this->service->getProduceProposal($this->mo, $line);
		}
		return $out;
	}

	/**
	 * Déclare la production d'un ou plusieurs lots sur un OF
	 *
	 * Exemple de corps :
	 * {
	 *   "inventorylabel": "Production OF",        (optionnel)
	 *   "inventorycode": "PROD-20261003",         (optionnel)
	 *   "lines": [
	 *     { "objectid": 150, "qty": 48, "fk_warehouse": 1, "batch": "LOT261003-001", "eatby": "2026-10-10", "sellby": "" }
	 *   ]
	 * }
	 *
	 * @param int   $id           ID de l'ordre de fabrication
	 * @param array $request_data Corps de la requête
	 * @return array
	 *
	 * @url POST mos/{id}/producebatch
	 *
	 * @throws RestException 400
	 * @throws RestException 403
	 * @throws RestException 404
	 * @throws RestException 500
	 */
	public function produceBatch($id, $request_data = null)
	{
		$this->loadMo($id);
		if (empty($request_data['lines']) || !is_array($request_data['lines'])) {
			throw new RestException(400, 'Champ "lines" obligatoire (tableau non vide)');
		}
		try {
			$created = $this->service->produce(
				$this->mo,
				$request_data['lines'],
				DolibarrApiAccess::$user,
				!empty($request_data['inventorylabel']) ? (string) $request_data['inventorylabel'] : '',
				!empty($request_data['inventorycode']) ? (string) $request_data['inventorycode'] : ''
			);
		} catch (Exception $e) {
			$code = in_array((int) $e->getCode(), array(400, 403, 404)) ? (int) $e->getCode() : 500;
			throw new RestException($code, $e->getMessage());
		}
		return array(
			'mo_id'    => (int) $this->mo->id,
			'mo_ref'   => $this->mo->ref,
			'produced' => $created,
		);
	}

	/**
	 * Clôture l'OF (statut « Fabriqué »)
	 *
	 * @param int $id ID de l'ordre de fabrication
	 * @return array
	 *
	 * @url POST mos/{id}/close
	 *
	 * @throws RestException 400
	 * @throws RestException 403
	 * @throws RestException 404
	 * @throws RestException 500
	 */
	public function closeMo($id)
	{
		$this->loadMo($id);
		try {
			$this->service->closeMo($this->mo, DolibarrApiAccess::$user);
		} catch (Exception $e) {
			$code = in_array((int) $e->getCode(), array(400, 403, 404)) ? (int) $e->getCode() : 500;
			throw new RestException($code, $e->getMessage());
		}
		return array('mo_id' => (int) $this->mo->id, 'mo_ref' => $this->mo->ref, 'status' => (int) $this->mo->status);
	}

	/**
	 * Postes de travail actifs
	 *
	 * @return array
	 *
	 * @url GET workstations
	 *
	 * @throws RestException 403
	 */
	public function getWorkstations()
	{
		if (!DolibarrApiAccess::$user->hasRight('mrp', 'read')) {
			throw new RestException(403);
		}
		return $this->service->getWorkstations();
	}

	/**
	 * Tableau de bord : OF validés et en cours, avec avancement et postes de travail
	 *
	 * @param int $ws ID du poste de travail (0 = tous, -1 = OF sans poste)
	 * @return array
	 *
	 * @url GET mos
	 *
	 * @throws RestException 403
	 */
	public function listMos($ws = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('mrp', 'read')) {
			throw new RestException(403);
		}
		return $this->call(function () use ($ws) {
			return $this->service->listMos(array('ws' => (int) $ws));
		});
	}

	/**
	 * Planning de production sur une période (60 jours maximum)
	 *
	 * @param string $from Date de début AAAA-MM-JJ
	 * @param string $to   Date de fin (exclue) AAAA-MM-JJ
	 * @param int    $ws   ID du poste de travail (0 = tous, -1 = sans poste)
	 * @return array
	 *
	 * @url GET planning
	 *
	 * @throws RestException 400
	 * @throws RestException 403
	 */
	public function getPlanning($from, $to, $ws = 0)
	{
		if (!DolibarrApiAccess::$user->hasRight('mrp', 'read')) {
			throw new RestException(403);
		}
		$f = dol_stringtotime($from.' 00:00:00');
		$t = dol_stringtotime($to.' 00:00:00');
		if (!$f || !$t || $t <= $f || $t - $f > 62 * 86400) {
			throw new RestException(400, 'Période invalide (from < to, 60 jours maximum, format AAAA-MM-JJ)');
		}
		$planning = new MrpbatchPlanning($this->db);
		return $this->call(function () use ($planning, $f, $t, $ws) {
			return $planning->getPlanning($f, $t, (int) $ws);
		});
	}

	/**
	 * Réception d'une matière première (avec ou sans commande fournisseur)
	 *
	 * Exemple : { "barcode": "0103760123450011172610271010L2610-97", "qty": 60, "fk_warehouse": 1, "order_id": 31 }
	 * ou       { "fk_product": 5, "qty": 60, "fk_warehouse": 1, "batch": "L2610-97", "eatby": "2026-10-27", "conform": 1 }
	 *
	 * @param array $request_data fk_product ou barcode (EAN, GS1), qty, fk_warehouse, batch, eatby, sellby, order_id, order_line_id, conform (0/1), note
	 * @return array
	 *
	 * @url POST receptions
	 *
	 * @throws RestException 400
	 * @throws RestException 403
	 * @throws RestException 404
	 * @throws RestException 500
	 */
	public function postReception($request_data = null)
	{
		$v = is_array($request_data) ? $request_data : array();
		if (empty($v['fk_product']) && !empty($v['barcode'])) {
			$res = $this->service->resolveProductCode($v['barcode']);
			if (!$res) {
				throw new RestException(404, 'Code « '.$v['barcode'].' » inconnu');
			}
			$v['fk_product'] = $res['fk_product'];
			if (empty($v['batch']) && $res['batch'] !== '') {
				$v['batch'] = $res['batch'];
			}
			if (empty($v['eatby']) && !empty($res['gs1']['eatby'])) {
				$v['eatby'] = $res['gs1']['eatby'];
			}
			if (empty($v['sellby']) && !empty($res['gs1']['sellby'])) {
				$v['sellby'] = $res['gs1']['sellby'];
			}
		}
		$rc = new MrpbatchReception($this->db);
		return $this->call(function () use ($rc, $v) {
			return $rc->receive($v, DolibarrApiAccess::$user);
		});
	}

	/**
	 * Commandes clients à préparer, avec étape, avancement, ruptures et OF liés
	 *
	 * @param string $search Recherche (réf., client)
	 * @param string $days   Livraison prévue au plus tard dans N jours (vide = toutes)
	 * @return array
	 *
	 * @url GET orders
	 *
	 * @throws RestException 403
	 */
	public function listOrders($search = '', $days = '')
	{
		$r = MrpbatchOrders::rights(DolibarrApiAccess::$user);
		if (!$r['read']) {
			throw new RestException(403);
		}
		$od = new MrpbatchOrders($this->db);
		return $this->call(function () use ($od, $search, $days) {
			return array('steps' => MrpbatchOrders::getSteps(), 'orders' => $od->listOrders(array('search' => $search, 'days' => $days === '' ? '' : (int) $days)));
		});
	}

	/**
	 * Change l'étape de préparation d'une commande client
	 *
	 * @param int   $id           ID de la commande client
	 * @param array $request_data { "step": "packed", "nb_packages": 3, "weight": 12.5, "note": "…" }
	 * @return array
	 *
	 * @url POST orders/{id}/step
	 *
	 * @throws RestException 400
	 * @throws RestException 403
	 * @throws RestException 404
	 */
	public function setOrderStep($id, $request_data = null)
	{
		$v = is_array($request_data) ? $request_data : array();
		$od = new MrpbatchOrders($this->db);
		return $this->call(function () use ($od, $id, $v) {
			return $od->setStep((int) $id, isset($v['step']) ? (string) $v['step'] : '', isset($v['nb_packages']) ? (int) $v['nb_packages'] : null, isset($v['weight']) ? (float) $v['weight'] : null, isset($v['note']) ? (string) $v['note'] : null, DolibarrApiAccess::$user);
		});
	}

	/**
	 * Déclare une quantité préparée sur une ligne de commande client (cumulée)
	 *
	 * @param int   $id           ID de la commande client
	 * @param array $request_data { "lineid": 401, "qty": 12, "batch": "LOT…" } ou { "lineid": 401, "reset": 1 }
	 * @return array
	 *
	 * @url POST orders/{id}/pick
	 *
	 * @throws RestException 400
	 * @throws RestException 403
	 * @throws RestException 404
	 */
	public function pickOrderLine($id, $request_data = null)
	{
		$v = is_array($request_data) ? $request_data : array();
		$od = new MrpbatchOrders($this->db);
		return $this->call(function () use ($od, $id, $v) {
			return $od->pick((int) $id, isset($v['lineid']) ? (int) $v['lineid'] : 0, isset($v['qty']) ? $v['qty'] : 0, isset($v['batch']) ? $v['batch'] : '', DolibarrApiAccess::$user, !empty($v['reset']));
		});
	}

	/**
	 * Journal des évènements atelier
	 *
	 * @param string $code  Code(s) séparés par des virgules (ex. MRPBATCH_RECEPTION,MRPBATCH_MO_PRODUCE)
	 * @param int    $limit Nombre maximum
	 * @return array
	 *
	 * @url GET events
	 *
	 * @throws RestException 403
	 */
	public function listEvents($code = '', $limit = 50)
	{
		$u = DolibarrApiAccess::$user;
		if (!$u->hasRight('mrp', 'read') && !$u->hasRight('stock', 'lire') && !$u->hasRight('commande', 'lire')) {
			throw new RestException(403);
		}
		$codes = $code !== '' ? array_filter(array_map('trim', explode(',', $code))) : array_keys(MrpbatchEvent::getTriggerList());
		return MrpbatchEvent::fetchLast($this->db, $codes, max(1, min(500, (int) $limit)));
	}

	/**
	 * Exécute un appel du service et traduit les exceptions en erreurs REST
	 *
	 * @param callable $fn Fonction
	 * @return mixed
	 * @throws RestException
	 */
	private function call($fn)
	{
		try {
			return $fn();
		} catch (RestException $e) {
			throw $e;
		} catch (Exception $e) {
			$code = in_array((int) $e->getCode(), array(400, 403, 404)) ? (int) $e->getCode() : 500;
			throw new RestException($code, $e->getMessage());
		}
	}

	/**
	 * Charge l'OF et contrôle l'accès
	 *
	 * @param int $id ID de l'OF
	 * @return void
	 * @throws RestException
	 */
	private function loadMo($id)
	{
		if ($this->mo->fetch((int) $id) <= 0) {
			throw new RestException(404, 'OF introuvable');
		}
		if (!DolibarrApi::_checkAccessToResource('mrp', $this->mo->id, 'mrp_mo')) {
			throw new RestException(403, 'Accès refusé à cet OF pour '.DolibarrApiAccess::$user->login);
		}
		if (empty($this->mo->lines)) {
			$this->mo->fetchLines();
		}
	}
}
