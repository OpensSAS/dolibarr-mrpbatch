<?php
/* Module Mrpbatch — Flux iCalendar public des évènements de production (abonnement Google Agenda, Outlook…)
 *
 * URL : /custom/mrpbatch/ics.php?entity=1&key=<clé>[&ws=<id poste>][&orders=1]
 * La clé se trouve (et se régénère) dans la configuration du module.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', 1);
}
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
if (!defined('NOIPCHECK')) {
	define('NOIPCHECK', 1);
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', 1);
}
// Multi-société : l'entité est choisie avant le chargement de l'environnement
$entity = isset($_GET['entity']) ? (int) $_GET['entity'] : 0;
if ($entity > 0) {
	define('DOLENTITY', $entity);
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
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

dol_include_once('/mrpbatch/class/mrpbatchplanning.class.php');

/**
 * @var DoliDB $db
 */

$key = (string) GETPOST('key', 'alphanohtml');
$expected = getDolGlobalString('MRPBATCH_ICS_KEY');
if (!isModEnabled('mrpbatch') || $expected === '' || $key === '' || !hash_equals($expected, $key)) {
	http_response_code(403);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Accès refusé : clé invalide ou flux désactivé';
	exit;
}

$ws = (int) GETPOST('ws', 'int');
$orders = (bool) GETPOST('orders', 'int');

$planning = new MrpbatchPlanning($db);
try {
	$ics = $planning->buildIcs($ws, $orders, max(0, getDolGlobalInt('MRPBATCH_ICS_DAYS_BACK', 30)), max(1, getDolGlobalInt('MRPBATCH_ICS_DAYS_FORWARD', 180)));
} catch (Exception $e) {
	dol_syslog('mrpbatch ics : '.$e->getMessage(), LOG_ERR);
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo 'Erreur de génération du calendrier';
	exit;
}

header('Content-Type: text/calendar; charset=utf-8');
header('Content-Disposition: inline; filename="production'.($ws ? '-poste'.$ws : '').'.ics"');
header('Cache-Control: private, max-age=300');
echo $ics;
$db->close();
