<?php
/* Module Mrpbatch — Évènements atelier : journal + déclenchement des triggers (module Webhook, notifications…)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonobject.class.php';

/**
 * Un évènement atelier.
 *
 * L'objet est enregistré dans llx_mrpbatch_event puis transmis tel quel aux triggers Dolibarr :
 * le module Webhook envoie alors {"triggercode": "MRPBATCH_…", "object": {…propriétés ci-dessous…}}.
 */
class MrpbatchEvent extends CommonObject
{
	/** @var string */
	public $element = 'mrpbatch_event';

	/** @var string */
	public $table_element = 'mrpbatch_event';

	/** @var string Code du trigger (MRPBATCH_…) */
	public $code;

	/** @var string Libellé lisible */
	public $label;

	/** @var int Date de l'évènement (timestamp) */
	public $datec;

	/** @var string Type de l'objet concerné : mo, order_supplier, commande, product */
	public $element_type;

	/** @var int ID de l'objet concerné */
	public $fk_element;

	/** @var string Référence de l'objet concerné */
	public $element_ref;

	/** @var int Tiers (fournisseur ou client) */
	public $fk_soc;

	/** @var string Nom du tiers */
	public $thirdparty_name;

	/** @var int */
	public $fk_product;

	/** @var string */
	public $product_ref;

	/** @var string */
	public $product_label;

	/** @var string N° de lot / série */
	public $batch;

	/** @var float */
	public $qty;

	/** @var string DLC (AAAA-MM-JJ) */
	public $eatby;

	/** @var string DLUO / DDM (AAAA-MM-JJ) */
	public $sellby;

	/** @var int */
	public $fk_warehouse;

	/** @var string */
	public $warehouse_ref;

	/** @var int Poste de travail */
	public $fk_workstation;

	/** @var int 1 = OK, 0 = refusé / non conforme */
	public $status = 1;

	/** @var string */
	public $note;

	/** @var array Données complémentaires propres à chaque évènement */
	public $data = array();

	/** @var int */
	public $fk_user;

	/** @var string */
	public $user_login;

	/**
	 * Liste des évènements émis par le module (code => array(libellé, description))
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function getTriggerList()
	{
		return array(
			'MRPBATCH_MO_START'         => array('OF démarré (atelier)', 'Premier scan de consommation ou de production : l\'OF passe « En cours »'),
			'MRPBATCH_MO_CONSUME'       => array('Consommation déclarée sur un OF', 'Un composant (lot, série ou produit) a été consommé'),
			'MRPBATCH_MO_PRODUCE'       => array('Production déclarée sur un OF', 'Un produit fini a été déclaré produit (lot, DLC, DLUO)'),
			'MRPBATCH_MO_CLOSE'         => array('OF terminé depuis l\'atelier', 'L\'OF est passé « Fabriqué » depuis l\'écran atelier'),
			'MRPBATCH_MO_RESCHEDULE'    => array('OF replanifié', 'Les dates prévues d\'un OF ont été modifiées depuis le planning'),
			'MRPBATCH_RECEPTION'        => array('Réception de matière première', 'Entrée en stock d\'une matière première (lot, DLC…) avec ou sans commande fournisseur'),
			'MRPBATCH_RECEPTION_REFUSED' => array('Réception refusée (non conforme)', 'Contrôle à réception non conforme : marchandise refusée ou mise en quarantaine'),
			'MRPBATCH_ORDER_PICK'       => array('Ligne de commande client préparée', 'Une quantité a été préparée sur une ligne de commande client'),
			'MRPBATCH_ORDER_STEP'       => array('Étape de préparation de commande modifiée', 'Une commande client change d\'étape (préparation, conditionnement…)'),
			'MRPBATCH_ORDER_READY'      => array('Commande client prête', 'La commande client atteint la dernière étape de préparation'),
		);
	}

	/**
	 * Enregistre les codes dans llx_c_action_trigger pour qu'ils soient proposés dans le module Webhook
	 *
	 * @param DoliDB $db Base
	 * @return int Nombre d'erreurs
	 */
	public static function registerTriggerCodes($db)
	{
		$err = 0;
		$rang = 5170;
		foreach (self::getTriggerList() as $code => $def) {
			$db->query('DELETE FROM '.MAIN_DB_PREFIX."c_action_trigger WHERE code = '".$db->escape($code)."'");
			$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'c_action_trigger (code, label, description, elementtype, rang)';
			$sql .= " VALUES ('".$db->escape($code)."', '".$db->escape($def[0])."', '".$db->escape($def[1])."', 'mrpbatch', ".((int) $rang++).')';
			if (!$db->query($sql)) {
				$err++;
				dol_syslog('MrpbatchEvent::registerTriggerCodes '.$db->lasterror(), LOG_ERR);
			}
		}
		return $err;
	}

	/**
	 * Supprime les codes de llx_c_action_trigger
	 *
	 * @param DoliDB $db Base
	 * @return void
	 */
	public static function unregisterTriggerCodes($db)
	{
		$db->query('DELETE FROM '.MAIN_DB_PREFIX."c_action_trigger WHERE code LIKE 'MRPBATCH\\_%'");
	}

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Enregistre l'évènement puis appelle les triggers. À appeler dans la transaction de l'opération :
	 * un webhook « bloquant » en échec fait alors échouer (et annuler) l'opération.
	 *
	 * @param User $user Utilisateur
	 * @return int ID créé
	 * @throws Exception
	 */
	public function record(User $user)
	{
		global $conf;

		$this->datec = dol_now();
		$this->fk_user = (int) $user->id;
		$this->user_login = $user->login;
		$this->entity = (int) $conf->entity;
		if ($this->label === null || $this->label === '') {
			$list = self::getTriggerList();
			$this->label = isset($list[$this->code]) ? $list[$this->code][0] : $this->code;
		}

		$sql = 'INSERT INTO '.MAIN_DB_PREFIX.'mrpbatch_event (entity, datec, code, label, element_type, fk_element, element_ref, fk_soc, fk_product, batch, qty, eatby, sellby, fk_warehouse, fk_workstation, status, note, data, fk_user) VALUES (';
		$sql .= ((int) $this->entity).", '".$this->db->idate($this->datec)."', '".$this->db->escape($this->code)."', '".$this->db->escape(dol_trunc((string) $this->label, 250, 'right', 'UTF-8', 1))."',";
		$sql .= ' '.$this->str($this->element_type).', '.$this->int($this->fk_element).', '.$this->str($this->element_ref).', '.$this->int($this->fk_soc).',';
		$sql .= ' '.$this->int($this->fk_product).', '.$this->str($this->batch).', '.($this->qty !== null ? (float) $this->qty : 'NULL').',';
		$sql .= ' '.$this->str($this->eatby ? $this->eatby : null).', '.$this->str($this->sellby ? $this->sellby : null).', '.$this->int($this->fk_warehouse).', '.$this->int($this->fk_workstation).',';
		$sql .= ' '.((int) $this->status).', '.$this->str($this->note).', '.$this->str(empty($this->data) ? null : json_encode($this->data)).', '.((int) $user->id).')';

		if (!$this->db->query($sql)) {
			throw new Exception('Journal atelier : '.$this->db->lasterror(), 500);
		}
		$this->id = (int) $this->db->last_insert_id(MAIN_DB_PREFIX.'mrpbatch_event');

		if (!getDolGlobalInt('MRPBATCH_DISABLE_TRIGGERS')) {
			$res = $this->call_trigger($this->code, $user);
			if ($res < 0) {
				throw new Exception('Évènement '.$this->code.' refusé par un trigger / webhook bloquant : '.implode(', ', $this->errors), 500);
			}
		}
		return $this->id;
	}

	/**
	 * Valeur SQL texte ou NULL
	 *
	 * @param mixed $v Valeur
	 * @return string
	 */
	private function str($v)
	{
		return ($v === null || $v === '') ? 'NULL' : "'".$this->db->escape((string) $v)."'";
	}

	/**
	 * Valeur SQL entière ou NULL
	 *
	 * @param mixed $v Valeur
	 * @return string
	 */
	private function int($v)
	{
		return ((int) $v > 0) ? (string) ((int) $v) : 'NULL';
	}

	/**
	 * Derniers évènements
	 *
	 * @param DoliDB       $db    Base
	 * @param string|array $codes Code(s) d'évènement
	 * @param int          $limit Nombre max
	 * @param string       $elementType Filtre type d'objet
	 * @param int          $fkElement   Filtre ID objet
	 * @return array
	 */
	public static function fetchLast($db, $codes, $limit = 30, $elementType = '', $fkElement = 0)
	{
		$codes = (array) $codes;
		$in = array();
		foreach ($codes as $c) {
			$in[] = "'".$db->escape($c)."'";
		}
		$sql = 'SELECT e.rowid, e.datec, e.code, e.label, e.element_type, e.fk_element, e.element_ref, e.batch, e.qty, e.eatby, e.sellby, e.status, e.note, e.data,';
		$sql .= ' p.ref as product_ref, p.label as product_label, w.ref as warehouse_ref, s.nom as thirdparty_name, u.login, u.firstname, u.lastname';
		$sql .= ' FROM '.MAIN_DB_PREFIX.'mrpbatch_event as e';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product as p ON p.rowid = e.fk_product';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'entrepot as w ON w.rowid = e.fk_warehouse';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = e.fk_soc';
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'user as u ON u.rowid = e.fk_user';
		$sql .= ' WHERE e.entity IN ('.getEntity('mrpbatch_event').')';
		$sql .= ' AND e.code IN ('.implode(',', $in).')';
		if ($elementType !== '') {
			$sql .= " AND e.element_type = '".$db->escape($elementType)."'";
		}
		if ($fkElement > 0) {
			$sql .= ' AND e.fk_element = '.((int) $fkElement);
		}
		$sql .= ' ORDER BY e.datec DESC, e.rowid DESC';
		$sql .= $db->plimit((int) $limit);

		$out = array();
		$resql = $db->query($sql);
		if ($resql) {
			while ($obj = $db->fetch_object($resql)) {
				$out[] = array(
					'id'            => (int) $obj->rowid,
					'date'          => dol_print_date($db->jdate($obj->datec), 'dayhour'),
					'code'          => $obj->code,
					'label'         => (string) $obj->label,
					'element_ref'   => (string) $obj->element_ref,
					'thirdparty'    => (string) $obj->thirdparty_name,
					'product_ref'   => (string) $obj->product_ref,
					'product_label' => (string) $obj->product_label,
					'batch'         => (string) $obj->batch,
					'qty'           => (float) $obj->qty,
					'eatby'         => $obj->eatby ? dol_print_date($db->jdate($obj->eatby), 'day') : '',
					'sellby'        => $obj->sellby ? dol_print_date($db->jdate($obj->sellby), 'day') : '',
					'warehouse'     => (string) $obj->warehouse_ref,
					'status'        => (int) $obj->status,
					'note'          => (string) $obj->note,
					'user'          => trim($obj->firstname.' '.$obj->lastname) ?: (string) $obj->login,
				);
			}
			$db->free($resql);
		}
		return $out;
	}
}
