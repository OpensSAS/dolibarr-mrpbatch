<?php
/* Module Mrpbatch — Version tablette atelier (plein écran) : production, planning, réception, commandes
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}

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

$cfg = mrpbatch_app_config($db, $user, 'tablet', GETPOST('screen', 'aZ09'));
$v = mrpbatch_asset_version();

top_httphead('text/html');
?>
<!doctype html>
<html lang="fr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="theme-color" content="#14304f">
<title>Atelier</title>
<link rel="stylesheet" href="<?php echo dol_buildpath('/mrpbatch/css/mrpbatch.css', 1).'?v='.$v; ?>">
</head>
<body id="mbx" class="mbx mbx-tablet" data-theme="light">
<script>window.MRPBATCH_CFG = <?php echo json_encode($cfg); ?>;</script>
<script src="<?php echo dol_buildpath('/mrpbatch/js/mrpbatch.js', 1).'?v='.$v; ?>"></script>
</body>
</html>
<?php
$db->close();
