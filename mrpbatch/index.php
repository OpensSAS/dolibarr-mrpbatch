<?php
/* Module Mrpbatch — Écrans atelier dans l'interface Dolibarr (production, planning, réception, commandes)
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

dol_include_once('/mrpbatch/lib/mrpbatch.lib.php');

/**
 * @var DoliDB $db
 * @var User $user
 */

$screens = isModEnabled('mrpbatch') ? mrpbatch_screens($user) : array();
if (empty($screens)) {
	accessforbidden();
}

// Compatibilité : anciens liens index.php?id=<OF>
$screen = GETPOST('screen', 'aZ09');
$moid = (int) GETPOST('id', 'int');

$cfg = mrpbatch_app_config($db, $user, 'desk', $screen);
$v = mrpbatch_asset_version();

$titles = array('production' => 'Production par scan', 'planning' => 'Planning de production', 'reception' => 'Réception des matières', 'orders' => 'Préparation des commandes');
$title = isset($titles[$cfg['screen']]) ? $titles[$cfg['screen']] : 'Atelier';

llxHeader('<link rel="stylesheet" href="'.dol_buildpath('/mrpbatch/css/mrpbatch.css', 1).'?v='.$v.'">', $title);

print '<div id="mbx" class="mbx mbx-desk" data-theme="light"></div>';
print '<noscript><div class="warning">JavaScript est nécessaire pour les écrans atelier.</div></noscript>';
print '<script>window.MRPBATCH_CFG = '.json_encode($cfg).';';
if ($moid > 0 && in_array('production', $screens)) {
	print 'if (!location.hash) { location.hash = "#/production/mo/'.$moid.'"; }';
}
print '</script>';
print '<script src="'.dol_buildpath('/mrpbatch/js/mrpbatch.js', 1).'?v='.$v.'"></script>';

llxFooter();
$db->close();
