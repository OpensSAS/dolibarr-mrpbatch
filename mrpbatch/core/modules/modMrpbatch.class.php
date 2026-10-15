<?php
/* Module Mrpbatch — Atelier : production par scan, réception, planning, préparation de commandes
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Descripteur du module Mrpbatch
 */
class modMrpbatch extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;

		// Identifiant unique : à changer si collision avec un autre module externe
		$this->numero = 517200;
		$this->rights_class = 'mrpbatch';
		$this->family = 'products';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Atelier : production par scan (lots, DLC), réception des matières premières, planning, préparation des commandes clients";
		$this->descriptionlong = "Écrans web et tablette pour l'atelier : consommation et production sur les OF par scan (lots, séries, DLC/DLUO, GS1), réception des matières premières, planning par poste de travail avec flux iCalendar, tableau de bord de préparation et de conditionnement des commandes clients, évènements pour le module Webhook et API REST /api/index.php/mrpbatchapi/...";
		$this->editor_name = 'Opens';
		$this->editor_url = 'https://www.opens.fr';
		$this->version = '2.0.4';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'mrp';

		$this->module_parts = array();
		$this->dirs = array();
		$this->config_page_url = array('setup.php@mrpbatch');
		$this->hidden = false;

		$this->depends = array('modMrp', 'modStock', 'modProductBatch');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array();

		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(17, 0);

		$this->const = array(
			array('MRPBATCH_SUGGEST_BATCH', 'chaine', '1', 'Suggérer le n° de lot à la production', 0, 'current', 0),
			array('MRPBATCH_PRODUCE_PRICE', 'chaine', 'none', 'Prix d\'entrée en stock des produits fabriqués', 0, 'current', 0),
			array('MRPBATCH_LOT_MASK', 'chaine', 'LOT{yy}{mm}{dd}-{0000}', 'Masque de numérotation des lots (si pas de modèle natif)', 0, 'current', 0),
			array('MRPBATCH_SN_MASK', 'chaine', 'SN{yy}{mm}{dd}-{00000}', 'Masque de numérotation des n° de série (si pas de modèle natif)', 0, 'current', 0),
			array('MRPBATCH_PLANNING_HOURS', 'chaine', '6-20', 'Plage horaire affichée dans le planning', 0, 'current', 0),
			array('MRPBATCH_DEFAULT_MO_HOURS', 'chaine', '2', 'Durée par défaut d\'un OF sans date de fin (heures)', 0, 'current', 0),
			array('MRPBATCH_ORDER_PACK_STEP', 'chaine', 'packed', 'Étape de commande où l\'on saisit colis et poids', 0, 'current', 0),
		);
		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();

		$this->menu = array();
		$r = 0;
		$base = array('langs' => '', 'target' => '', 'user' => 0, 'enabled' => 'isModEnabled("mrpbatch")');

		// Barre du haut (comme le Point de vente) : ouvre la version tablette dans un onglet dédié
		$this->menu[$r++] = array_merge($base, array(
			'fk_menu'  => '',
			'type'     => 'top',
			'titre'    => 'Atelier',
			'prefix'   => img_picto('', 'fa-industry', 'class="pictofixedwidth"'),
			'mainmenu' => 'mrpbatchtablet',
			'leftmenu' => '',
			'url'      => '/mrpbatch/tablet.php',
			'position' => 1001,
			'perms'    => '$user->hasRight("mrp", "read") || $user->hasRight("stock", "lire") || $user->hasRight("commande", "lire")',
			'target'   => 'mrpbatch_tablet',
		));

		// Bloc « Atelier » dans le menu de gauche GPAO
		$this->menu[$r++] = array_merge($base, array(
			'fk_menu'  => 'fk_mainmenu=mrp',
			'type'     => 'left',
			'titre'    => 'Atelier',
			'prefix'   => img_picto('', 'fa-industry', 'class="pictofixedwidth"'),
			'mainmenu' => 'mrp',
			'leftmenu' => 'mrpbatch',
			'url'      => '/mrpbatch/index.php?screen=production&mainmenu=mrp&leftmenu=mrpbatch',
			'position' => 1100,
			'perms'    => '$user->hasRight("mrp", "read")',
		));
		$items = array(
			array('Production par scan', 'production', '$user->hasRight("mrp", "read")', 'isModEnabled("mrpbatch")'),
			array('Planning', 'planning', '$user->hasRight("mrp", "read")', 'isModEnabled("mrpbatch")'),
			array('Réception matières', 'reception', '$user->hasRight("stock", "lire")', 'isModEnabled("mrpbatch")'),
			array('Commandes clients', 'orders', '$user->hasRight("commande", "lire")', 'isModEnabled("mrpbatch") && isModEnabled("commande")'),
		);
		foreach ($items as $i => $it) {
			$this->menu[$r++] = array_merge($base, array(
				'fk_menu'  => 'fk_mainmenu=mrp,fk_leftmenu=mrpbatch',
				'type'     => 'left',
				'titre'    => $it[0],
				'mainmenu' => 'mrp',
				'leftmenu' => 'mrpbatch_'.$it[1],
				'url'      => '/mrpbatch/index.php?screen='.$it[1].'&mainmenu=mrp&leftmenu=mrpbatch',
				'position' => 1101 + $i,
				'perms'    => $it[2],
				'enabled'  => $it[3],
			));
		}
		$this->menu[$r++] = array_merge($base, array(
			'fk_menu'  => 'fk_mainmenu=mrp,fk_leftmenu=mrpbatch',
			'type'     => 'left',
			'titre'    => 'Version tablette',
			'mainmenu' => 'mrp',
			'leftmenu' => 'mrpbatch_tablet',
			'url'      => '/mrpbatch/tablet.php',
			'position' => 1110,
			'perms'    => '$user->hasRight("mrp", "read")',
			'target'   => 'mrpbatch_tablet',
		));
		$this->menu[$r++] = array_merge($base, array(
			'fk_menu'  => 'fk_mainmenu=mrp,fk_leftmenu=mrpbatch',
			'type'     => 'left',
			'titre'    => 'Configuration',
			'mainmenu' => 'mrp',
			'leftmenu' => 'mrpbatch_setup',
			'url'      => '/mrpbatch/admin/setup.php',
			'position' => 1111,
			'perms'    => '$user->admin',
		));

		// Raccourcis dans les menus natifs Stocks et Commandes
		$this->menu[$r++] = array_merge($base, array(
			'fk_menu'  => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type'     => 'left',
			'titre'    => 'Réception par scan',
			'mainmenu' => 'products',
			'leftmenu' => 'mrpbatch_reception',
			'url'      => '/mrpbatch/index.php?screen=reception&mainmenu=products&leftmenu=stock',
			'position' => 1120,
			'perms'    => '$user->hasRight("stock", "lire")',
		));
		$this->menu[$r++] = array_merge($base, array(
			'fk_menu'  => 'fk_mainmenu=commercial,fk_leftmenu=orders',
			'type'     => 'left',
			'titre'    => 'Préparation (atelier)',
			'mainmenu' => 'commercial',
			'leftmenu' => 'mrpbatch_orders',
			'url'      => '/mrpbatch/index.php?screen=orders&mainmenu=commercial&leftmenu=orders',
			'position' => 1121,
			'perms'    => '$user->hasRight("commande", "lire")',
			'enabled'  => 'isModEnabled("mrpbatch") && isModEnabled("commande")',
		));
	}

	/**
	 * Activation du module
	 *
	 * @param string $options Options
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/mrpbatch/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->createExtrafields();

		dol_include_once('/mrpbatch/class/mrpbatchevent.class.php');
		MrpbatchEvent::registerTriggerCodes($this->db);

		if (!getDolGlobalString('MRPBATCH_ICS_KEY')) {
			global $conf;
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			$key = function_exists('random_bytes') ? bin2hex(random_bytes(20)) : md5(uniqid((string) mt_rand(), true));
			dolibarr_set_const($this->db, 'MRPBATCH_ICS_KEY', $key, 'chaine', 0, 'Clé du flux ics de production', $conf->entity);
		}

		return $this->_init(array(), $options);
	}

	/**
	 * Champs complémentaires produit : durées de vie (surcharge des valeurs par défaut du module)
	 *
	 * @return int Nombre d'erreurs
	 */
	public function createExtrafields()
	{
		include_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$err = 0;
		$r = $extrafields->addExtraField('mrpbatch_dlc_days', 'Durée DLC (jours)', 'int', 1000, 6, 'product', 0, 0, '', '', 1, '', '1', 'Nombre de jours ajoutés à la date de fabrication pour proposer la DLC', '', '', '', 'isModEnabled("mrpbatch")');
		$err += ($r < 0 ? 1 : 0);
		$r = $extrafields->addExtraField('mrpbatch_dluo_days', 'Durée DLUO (jours)', 'int', 1001, 6, 'product', 0, 0, '', '', 1, '', '1', 'Nombre de jours ajoutés à la date de fabrication pour proposer la DLUO / DDM', '', '', '', 'isModEnabled("mrpbatch")');
		$err += ($r < 0 ? 1 : 0);
		// Poste de travail principal d'un OF (en plus des postes portés par les opérations de la gamme)
		if (isModEnabled('workstation')) {
			$r = $extrafields->addExtraField('mrpbatch_workstation', 'Poste de travail (atelier)', 'sellist', 100, '', 'mrp_mo', 0, 0, '', array('options' => array('workstation_workstation:ref:rowid' => null)), 1, '', '1', 'Poste affiché dans l\'écran atelier, le planning et le flux ics', '', '', '', 'isModEnabled("mrpbatch") && isModEnabled("workstation")');
			$err += ($r < 0 ? 1 : 0);
		}
		if ($err) {
			$this->error = $extrafields->error;
		}
		return $err;
	}

	/**
	 * Désactivation du module
	 *
	 * @param string $options Options
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		dol_include_once('/mrpbatch/class/mrpbatchevent.class.php');
		MrpbatchEvent::unregisterTriggerCodes($this->db);
		return $this->_remove(array(), $options);
	}
}
