<?php
/* Module Mrpbatch — fonctions communes aux pages web et tablette
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

dol_include_once('/mrpbatch/class/mrpbatchconsume.class.php');
dol_include_once('/mrpbatch/class/mrpbatchreception.class.php');
dol_include_once('/mrpbatch/class/mrpbatchorders.class.php');
dol_include_once('/mrpbatch/class/mrpbatchplanning.class.php');

/**
 * Écrans accessibles à l'utilisateur
 *
 * @param User $user Utilisateur
 * @return string[]
 */
function mrpbatch_screens($user)
{
	$s = array();
	if ($user->hasRight('mrp', 'read')) {
		$s[] = 'production';
		$s[] = 'planning';
	}
	$rc = MrpbatchReception::rights($user);
	if ($rc['read']) {
		$s[] = 'reception';
	}
	$od = MrpbatchOrders::rights($user);
	if ($od['read']) {
		$s[] = 'orders';
	}
	return $s;
}

/**
 * Configuration injectée dans l'application JS
 *
 * @param DoliDB $db     Base
 * @param User   $user   Utilisateur
 * @param string $mode   'desk' (dans Dolibarr) ou 'tablet' (plein écran)
 * @param string $screen Écran initial
 * @return array
 */
function mrpbatch_app_config($db, $user, $mode, $screen)
{
	global $mysoc;

	$svc = new MrpbatchConsume($db);
	$rc = MrpbatchReception::rights($user);
	$od = MrpbatchOrders::rights($user);
	$screens = mrpbatch_screens($user);

	$ics = '';
	if ($user->admin && in_array('planning', $screens)) {
		$planning = new MrpbatchPlanning($db);
		$ics = $planning->getIcsUrl();
	}

	return array(
		'mode'         => $mode,
		'ajax'         => dol_buildpath('/mrpbatch/ajax/app.php', 1),
		'token'        => newToken(),
		'dolibarr'     => DOL_URL_ROOT.'/',
		'tabletUrl'    => dol_buildpath('/mrpbatch/tablet.php', 1).($screen ? '#/'.$screen : ''),
		'user'         => trim($user->firstname.' '.$user->lastname) ?: $user->login,
		'company'      => (string) $mysoc->name,
		'screens'      => $screens,
		'screen'       => in_array($screen, $screens) ? $screen : '',
		'workstations' => $svc->getWorkstations(),
		'ics'          => $ics,
		'rights'       => array(
			'mrpWrite' => $user->hasRight('mrp', 'write') && $user->hasRight('stock', 'mouvement', 'creer'),
			'rcFree'   => $rc['free'],
			'rcOrder'  => $rc['order'],
			'odWrite'  => $od['write'],
		),
	);
}

/**
 * Version des fichiers (anti-cache des CSS / JS)
 *
 * @return string
 */
function mrpbatch_asset_version()
{
	$f = dol_buildpath('/mrpbatch/js/mrpbatch.js', 0);
	return is_readable($f) ? (string) filemtime($f) : '1';
}
