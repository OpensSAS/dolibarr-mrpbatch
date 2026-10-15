<?php
/* Module Mrpbatch — Planning de production et flux iCalendar (.ics)
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/date.lib.php';
dol_include_once('/mrpbatch/class/mrpbatchconsume.class.php');

/**
 * Planning des OF par poste de travail + export iCalendar (Google Agenda, Outlook, Thunderbird, téléphone…)
 */
class MrpbatchPlanning
{
	/** @var DoliDB */
	public $db;

	/** @var MrpbatchConsume */
	public $svc;

	/**
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
		$this->svc = new MrpbatchConsume($db);
	}

	/**
	 * Statuts d'OF affichés dans le planning / le flux ics
	 *
	 * @return int[]
	 */
	public static function getStatuses()
	{
		$st = array(Mo::STATUS_VALIDATED, Mo::STATUS_INPROGRESS, Mo::STATUS_PRODUCED);
		if (getDolGlobalInt('MRPBATCH_PLANNING_SHOW_DRAFT')) {
			$st[] = Mo::STATUS_DRAFT;
		}
		return $st;
	}

	/**
	 * Durée par défaut d'un OF sans date de fin (secondes)
	 *
	 * @return int
	 */
	public static function getDefaultDuration()
	{
		$h = (float) str_replace(',', '.', getDolGlobalString('MRPBATCH_DEFAULT_MO_HOURS', '2'));
		return (int) max(900, round(($h > 0 ? $h : 2) * 3600));
	}

	/**
	 * Données du planning
	 *
	 * @param int $from Début (timestamp)
	 * @param int $to   Fin (timestamp)
	 * @param int $ws   Poste (0 = tous, -1 = sans poste)
	 * @return array
	 */
	public function getPlanning($from, $to, $ws = 0)
	{
		$mos = $this->svc->listMos(array('statuses' => self::getStatuses(), 'from' => $from - self::getDefaultDuration(), 'to' => $to, 'ws' => $ws, 'limit' => 1000));
		$dur = self::getDefaultDuration();
		foreach ($mos as $k => $m) {
			if (!$m['end']) {
				$mos[$k]['end'] = $m['start'] + $dur;
				$mos[$k]['end_estimated'] = 1;
			}
		}
		$mos = array_values(array_filter($mos, function ($m) use ($from) {
			return $m['end'] >= $from;
		}));
		$unplanned = $this->svc->listMos(array('statuses' => array(Mo::STATUS_VALIDATED, Mo::STATUS_INPROGRESS), 'unplanned' => 1, 'ws' => $ws, 'limit' => 200));

		$hours = getDolGlobalString('MRPBATCH_PLANNING_HOURS', '6-20');
		$hs = 6;
		$he = 20;
		if (preg_match('/^(\d{1,2})\s*-\s*(\d{1,2})$/', $hours, $m) && (int) $m[1] < (int) $m[2] && (int) $m[2] <= 24) {
			$hs = (int) $m[1];
			$he = (int) $m[2];
		}
		return array(
			'from'         => (int) $from,
			'to'           => (int) $to,
			'hour_start'   => $hs,
			'hour_end'     => $he,
			'workstations' => $this->svc->getWorkstations(),
			'mos'          => $mos,
			'unplanned'    => $unplanned,
		);
	}

	/**
	 * Clé secrète du flux ics (créée si absente)
	 *
	 * @param bool $regenerate Forcer une nouvelle clé
	 * @return string
	 */
	public function getIcsKey($regenerate = false)
	{
		global $conf;
		$key = getDolGlobalString('MRPBATCH_ICS_KEY');
		if ($key === '' || $regenerate) {
			$key = function_exists('random_bytes') ? bin2hex(random_bytes(20)) : dol_hash(uniqid((string) mt_rand(), true), 'sha256');
			require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
			dolibarr_set_const($this->db, 'MRPBATCH_ICS_KEY', $key, 'chaine', 0, 'Clé du flux ics de production', $conf->entity);
		}
		return $key;
	}

	/**
	 * URL publique du flux ics
	 *
	 * @param int  $ws     Poste (0 = tous)
	 * @param bool $orders Inclure les livraisons de commandes clients
	 * @return string
	 */
	public function getIcsUrl($ws = 0, $orders = false)
	{
		global $conf;
		$url = dol_buildpath('/mrpbatch/ics.php', 2).'?entity='.((int) $conf->entity).'&key='.urlencode($this->getIcsKey());
		if ($ws) {
			$url .= '&ws='.((int) $ws);
		}
		if ($orders) {
			$url .= '&orders=1';
		}
		return $url;
	}

	/**
	 * Génère le calendrier iCalendar (RFC 5545)
	 *
	 * @param int  $ws       Poste (0 = tous, -1 = sans poste)
	 * @param bool $orders   Ajouter les dates de livraison des commandes clients
	 * @param int  $daysBack Historique (jours)
	 * @param int  $daysFwd  Horizon (jours)
	 * @return string
	 */
	public function buildIcs($ws = 0, $orders = false, $daysBack = 30, $daysFwd = 180)
	{
		global $mysoc;

		$now = dol_now();
		$from = $now - $daysBack * 86400;
		$to = $now + $daysFwd * 86400;
		$host = preg_replace('/[^a-z0-9.\-]/i', '', parse_url(DOL_MAIN_URL_ROOT, PHP_URL_HOST) ?: 'dolibarr');
		$base = DOL_MAIN_URL_ROOT;
		$dur = self::getDefaultDuration();

		$wsNames = array();
		foreach ($this->svc->getWorkstations() as $w) {
			$wsNames[$w['id']] = $w['label'] !== '' ? $w['label'] : $w['ref'];
		}
		$calName = 'Production'.(!empty($mysoc->name) ? ' — '.$mysoc->name : '');
		if ($ws > 0 && isset($wsNames[$ws])) {
			$calName .= ' — '.$wsNames[$ws];
		}

		$L = array();
		$L[] = 'BEGIN:VCALENDAR';
		$L[] = 'VERSION:2.0';
		$L[] = 'PRODID:-//Opens//Dolibarr mrpbatch//FR';
		$L[] = 'CALSCALE:GREGORIAN';
		$L[] = 'METHOD:PUBLISH';
		$L[] = 'X-WR-CALNAME:'.$this->esc($calName);
		$L[] = 'X-WR-TIMEZONE:'.$this->esc(date_default_timezone_get());
		$L[] = 'REFRESH-INTERVAL;VALUE=DURATION:PT30M';
		$L[] = 'X-PUBLISHED-TTL:PT30M';

		$statusLabel = array(0 => 'Brouillon', 1 => 'Validé', 2 => 'En cours', 3 => 'Fabriqué');
		$mos = $this->svc->listMos(array('statuses' => self::getStatuses(), 'from' => $from, 'to' => $to, 'ws' => $ws, 'limit' => 2000));
		$stamp = gmdate('Ymd\THis\Z', $now);
		foreach ($mos as $m) {
			$start = $m['start'];
			$end = $m['end'] ? $m['end'] : $start + $dur;
			$wsl = array();
			foreach ($m['workstations'] as $wid) {
				if (isset($wsNames[$wid])) {
					$wsl[] = $wsNames[$wid];
				}
			}
			$summary = ($m['status'] == Mo::STATUS_PRODUCED ? '✓ ' : '').$m['ref'].' · '.$m['product_ref'].' × '.price2num($m['qty'], 'MS').($wsl ? ' ['.implode(', ', $wsl).']' : '');
			$desc = array();
			$desc[] = 'OF '.$m['ref'].' — '.(isset($statusLabel[$m['status']]) ? $statusLabel[$m['status']] : $m['status']);
			$desc[] = 'Produit : '.$m['product_ref'].($m['product_label'] ? ' — '.$m['product_label'] : '');
			$desc[] = 'Quantité : '.price2num($m['qty'], 'MS').' (produit : '.price2num($m['produced'], 'MS').')';
			if ($m['thirdparty']) {
				$desc[] = 'Client : '.$m['thirdparty'];
			}
			if ($wsl) {
				$desc[] = 'Poste(s) : '.implode(', ', $wsl);
			}
			if ($m['label']) {
				$desc[] = $m['label'];
			}
			$url = $base.'/mrp/mo_card.php?id='.$m['id'];

			$L[] = 'BEGIN:VEVENT';
			$L[] = 'UID:mrpbatch-mo-'.$m['id'].'@'.$host;
			$L[] = 'DTSTAMP:'.$stamp;
			$L[] = 'DTSTART:'.gmdate('Ymd\THis\Z', $start);
			$L[] = 'DTEND:'.gmdate('Ymd\THis\Z', $end);
			$L[] = 'SUMMARY:'.$this->esc($summary);
			$L[] = 'DESCRIPTION:'.$this->esc(implode("\n", $desc)."\n".$url);
			$L[] = 'URL:'.$url;
			if ($wsl) {
				$L[] = 'LOCATION:'.$this->esc(implode(', ', $wsl));
			}
			$L[] = 'CATEGORIES:'.$this->esc('Production');
			$L[] = 'STATUS:'.($m['status'] == 0 ? 'TENTATIVE' : 'CONFIRMED');
			$L[] = 'TRANSP:OPAQUE';
			$L[] = 'END:VEVENT';
		}

		if ($orders && (isModEnabled('commande') || isModEnabled('order'))) {
			$sql = 'SELECT c.rowid, c.ref, c.ref_client, c.date_livraison, s.nom as socname FROM '.MAIN_DB_PREFIX.'commande as c';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe as s ON s.rowid = c.fk_soc';
			$sql .= ' WHERE c.entity IN ('.getEntity('commande').') AND c.fk_statut IN (1, 2, 3)';
			$sql .= " AND c.date_livraison >= '".$this->db->idate($from)."' AND c.date_livraison <= '".$this->db->idate($to)."'";
			$resql = $this->db->query($sql);
			if ($resql) {
				while ($obj = $this->db->fetch_object($resql)) {
					$d = $this->db->jdate($obj->date_livraison);
					$L[] = 'BEGIN:VEVENT';
					$L[] = 'UID:mrpbatch-order-'.((int) $obj->rowid).'@'.$host;
					$L[] = 'DTSTAMP:'.$stamp;
					$L[] = 'DTSTART;VALUE=DATE:'.dol_print_date($d, '%Y%m%d');
					$L[] = 'DTEND;VALUE=DATE:'.dol_print_date(dol_time_plus_duree($d, 1, 'd'), '%Y%m%d');
					$L[] = 'SUMMARY:'.$this->esc('Livraison '.$obj->ref.' — '.$obj->socname);
					$L[] = 'DESCRIPTION:'.$this->esc('Commande client '.$obj->ref.($obj->ref_client ? ' (réf. client '.$obj->ref_client.')' : '')."\n".$base.'/commande/card.php?id='.((int) $obj->rowid));
					$L[] = 'CATEGORIES:'.$this->esc('Livraison');
					$L[] = 'TRANSP:TRANSPARENT';
					$L[] = 'END:VEVENT';
				}
			}
		}
		$L[] = 'END:VCALENDAR';

		$out = '';
		foreach ($L as $line) {
			$out .= $this->fold($line)."\r\n";
		}
		return $out;
	}

	/**
	 * Échappement d'une valeur texte iCalendar
	 *
	 * @param string $s Texte
	 * @return string
	 */
	private function esc($s)
	{
		$s = str_replace(array('\\', ';', ',', "\r\n", "\r", "\n"), array('\\\\', '\;', '\,', '\n', '\n', '\n'), (string) $s);
		return $s;
	}

	/**
	 * Repli des lignes à 75 octets (RFC 5545 §3.1) sans couper un caractère UTF-8
	 *
	 * @param string $line Ligne
	 * @return string
	 */
	private function fold($line)
	{
		if (strlen($line) <= 75) {
			return $line;
		}
		$out = '';
		$cur = '';
		$max = 75;
		foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
			if (strlen($cur) + strlen($ch) > $max) {
				$out .= ($out === '' ? '' : "\r\n ").$cur;
				$cur = '';
				$max = 74;
			}
			$cur .= $ch;
		}
		return $out.($out === '' ? '' : "\r\n ").$cur;
	}
}
