/* Module Mrpbatch — application atelier (web dans Dolibarr + tablette plein écran)
 *
 * Écrans : Production (OF), Planning, Réception matières, Commandes clients.
 * Configuration injectée par la page : window.MRPBATCH_CFG
 */
(function () {
	"use strict";

	var CFG = window.MRPBATCH_CFG;
	var root = document.getElementById("mbx");
	if (!CFG || !root) { return; }
	var DESK = CFG.mode === "desk";

	/* ================================================================
	 * Icônes
	 * ================================================================ */
	function svg(p, w) { return '<svg aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="' + (w || 2.2) + '" stroke-linecap="round" stroke-linejoin="round">' + p + '</svg>'; }
	var ICON = {
		ok: svg('<path d="M20 6L9 17l-5-5"/>', 2.6),
		err: svg('<path d="M18 6L6 18M6 6l12 12"/>', 2.6),
		x: svg('<path d="M18 6L6 18M6 6l12 12"/>', 2.6),
		plus: svg('<path d="M12 5v14M5 12h14"/>', 2.6),
		back: svg('<path d="M15 18l-6-6 6-6"/>', 2.6),
		left: svg('<path d="M15 18l-6-6 6-6"/>', 2.4),
		right: svg('<path d="M9 18l6-6-6-6"/>', 2.4),
		kbd: svg('<rect x="2" y="6" width="20" height="12" rx="2"/><path d="M6 10h.01M10 10h.01M14 10h.01M18 10h.01M7 14h10"/>', 2),
		refresh: svg('<path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/>'),
		theme: svg('<circle cx="12" cy="12" r="4.5"/><path d="M12 2v2M12 20v2M2 12h2M20 12h2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>'),
		full: svg('<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>'),
		scan: svg('<path d="M3 7V5a2 2 0 0 1 2-2h2M17 3h2a2 2 0 0 1 2 2v2M21 17v2a2 2 0 0 1-2 2h-2M7 21H5a2 2 0 0 1-2-2v-2M7 8v8M10 8v8M13 8v8M17 8v8"/>', 2),
		search: svg('<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>'),
		prod: svg('<path d="M2 20h20M4 20V9l5 3V9l5 3V5l6 3v12"/>'),
		plan: svg('<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4M7 13h4M7 17h7"/>'),
		rc: svg('<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/>'),
		od: svg('<path d="M6 2l-3 4v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4H6z"/><path d="M3 6h18M16 10a4 4 0 0 1-8 0"/>'),
		ws: svg('<rect x="3" y="4" width="18" height="12" rx="2"/><path d="M8 20h8M12 16v4"/>'),
		ext: svg('<path d="M14 3h7v7M10 14L21 3M21 14v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5"/>'),
		cal: svg('<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>'),
		tablet: svg('<rect x="5" y="2" width="14" height="20" rx="2"/><path d="M11 18h2"/>')
	};

	/* ================================================================
	 * Utilitaires
	 * ================================================================ */
	function $(id) { return document.getElementById(id); }
	function esc(s) {
		return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
			return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
		});
	}
	function fmt(n) { return Number(n || 0).toLocaleString("fr-FR", { maximumFractionDigits: 3 }); }
	function raw(n) { return fmt(n).replace(/[\s  ]/g, ""); }
	function num(v) { var n = parseFloat(String(v).replace(",", ".").trim()); return isFinite(n) ? n : NaN; }
	function store(k, v) {
		try {
			if (v === undefined) { return localStorage.getItem("mrpbatch_" + k); }
			localStorage.setItem("mrpbatch_" + k, v);
		} catch (e) { return null; }
		return null;
	}
	function pad2(n) { return String(n).padStart(2, "0"); }
	function isoDay(d) { return d.getFullYear() + "-" + pad2(d.getMonth() + 1) + "-" + pad2(d.getDate()); }
	function isoLocal(d) { return isoDay(d) + "T" + pad2(d.getHours()) + ":" + pad2(d.getMinutes()); }
	function addDays(iso, n) {
		var d = iso ? new Date(iso + "T12:00:00") : new Date();
		d.setDate(d.getDate() + n);
		return isoDay(d);
	}
	function midnight(d) { var x = new Date(d.getTime()); x.setHours(0, 0, 0, 0); return x; }
	var DAYS = ["dim.", "lun.", "mar.", "mer.", "jeu.", "ven.", "sam."];
	var MONTHS = ["janv.", "févr.", "mars", "avr.", "mai", "juin", "juil.", "août", "sept.", "oct.", "nov.", "déc."];
	function dayLabel(d) { return DAYS[d.getDay()] + " " + d.getDate() + " " + MONTHS[d.getMonth()]; }
	function hm(d) { return pad2(d.getHours()) + ":" + pad2(d.getMinutes()); }

	var audioCtx = null;
	function beep(ok) {
		try {
			audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
			var o = audioCtx.createOscillator(), g = audioCtx.createGain();
			o.type = ok ? "sine" : "square";
			o.frequency.value = ok ? 1175 : 196;
			g.gain.value = ok ? 0.2 : 0.12;
			o.connect(g); g.connect(audioCtx.destination);
			o.start(); o.stop(audioCtx.currentTime + (ok ? 0.13 : 0.4));
		} catch (e) { /* audio indisponible */ }
		if (navigator.vibrate) { navigator.vibrate(ok ? 50 : [90, 60, 90]); }
	}
	function flash(ok) {
		var f = $("mbxFlash");
		f.className = "flash";
		f.innerHTML = ok ? ICON.ok : ICON.err;
		void f.offsetWidth;
		f.className = "flash on " + (ok ? "f-ok" : "f-err");
	}
	var toastTimer = null;
	function toast(msg, kind) {
		var t = $("mbxToast");
		t.setAttribute("role", kind === "err" ? "alert" : "status");
		t.setAttribute("aria-live", kind === "err" ? "assertive" : "polite");
		t.textContent = msg;
		t.className = "toast show t-" + (kind || "ok");
		clearTimeout(toastTimer);
		toastTimer = setTimeout(function () { t.className = "toast"; }, kind === "err" ? 5500 : 3200);
	}
	function spin(on) { $("mbxSpin").classList.toggle("on", !!on); }
	function fail(err) {
		spin(false); beep(false); flash(false);
		toast(err && err.message ? err.message : "Erreur réseau", "err");
	}
	function okFeedback(msg) { beep(true); flash(true); toast("✓ " + msg, "ok"); }
	function koFeedback(msg) { beep(false); flash(false); toast(msg, "err"); }

	function api(params, post) {
		params.token = CFG.token;
		var body = new URLSearchParams(params);
		var url = CFG.ajax + (post ? "" : "?" + body.toString());
		return fetch(url, {
			method: post ? "POST" : "GET",
			credentials: "same-origin",
			headers: post ? { "Content-Type": "application/x-www-form-urlencoded" } : {},
			body: post ? body.toString() : undefined
		}).then(function (r) {
			return r.text().then(function (txt) {
				try { return JSON.parse(txt); }
				catch (e) { throw new Error("Session expirée ou erreur serveur : rechargez la page"); }
			});
		});
	}

	/* Modale générique (jamais alert / confirm) */
	var modalReturn = null;
	function modal(html, onReady) {
		if (!modalOn()) { modalReturn = document.activeElement; }
		$("mbxModalBox").innerHTML = html;
		$("mbxModal").classList.add("on");
		var box = $("mbxModalBox");
		var h3 = box.querySelector("h3");
		if (h3) { h3.id = "mbxModalTitle"; box.setAttribute("aria-labelledby", "mbxModalTitle"); }
		box.querySelectorAll("[data-close]").forEach(function (b) { b.onclick = closeModal; });
		if (onReady) { onReady(box); }
		// Focus dans la modale (action principale, sinon premier contrôle)
		setTimeout(function () {
			if (box.contains(document.activeElement)) { return; }
			var f = box.querySelector(".btn.ok, input, textarea, button");
			if (f) { f.focus({ preventScroll: true }); }
		}, 30);
	}
	function closeModal() {
		$("mbxModal").classList.remove("on");
		var r = modalReturn; modalReturn = null;
		setTimeout(function () { if (r && document.body.contains(r) && r !== scanInput) { r.focus({ preventScroll: true }); } else { focusScan(true); } }, 30);
	}
	// Piège à focus : Tab reste dans la modale ouverte
	document.addEventListener("keydown", function (e) {
		if (e.key !== "Tab" || !modalOn()) { return; }
		var f = Array.prototype.filter.call($("mbxModalBox").querySelectorAll("button, a[href], input, textarea, select"), function (x) { return !x.disabled && x.offsetParent !== null; });
		if (!f.length) { return; }
		var first = f[0], last = f[f.length - 1];
		if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
		else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
	});
	function modalOn() { return $("mbxModal").classList.contains("on"); }
	function confirmBox(title, text, okLabel, onOk, cls) {
		modal('<h3>' + esc(title) + '</h3><p>' + esc(text) + '</p><div class="acts"><button class="btn cancel" data-close style="flex:1">Annuler</button>' +
			'<button class="btn ok ' + (cls || "") + '" id="mbxYes">' + esc(okLabel) + '</button></div>', function () {
			$("mbxYes").onclick = function () { closeModal(); onOk(); };
		});
	}

	/* ================================================================
	 * Coquille : en-tête, navigation, scan, superpositions
	 * ================================================================ */
	var SCREENS = [
		{ key: "production", label: "Production", icon: ICON.prod, scan: true },
		{ key: "planning", label: "Planning", icon: ICON.plan, scan: false },
		{ key: "reception", label: "Réception", icon: ICON.rc, scan: true },
		{ key: "orders", label: "Commandes", icon: ICON.od, scan: true }
	].filter(function (s) { return CFG.screens.indexOf(s.key) >= 0; });

	function buildShell() {
		var nav = '<nav class="nav" id="mbxNav" aria-label="Écrans atelier">' + SCREENS.map(function (s) {
			return '<button data-s="' + s.key + '">' + s.icon + '<span>' + esc(s.label) + '</span></button>';
		}).join("") + '</nav>';
		var wsBtn = '<button class="' + (DESK ? "tbtn" : "ibtn") + '" id="mbxWs" title="Poste de travail" style="display:none">' + ICON.ws + '<span class="lbl" id="mbxWsLbl"></span></button>';
		var scan = '<div class="scanbar" id="mbxScanbar" role="search" aria-label="Scan"><form class="scanbox" id="mbxScanForm" autocomplete="off">' + ICON.scan +
			'<input id="mbxScan" aria-label="Code scanné" aria-describedby="mbxHint" type="text" inputmode="' + (DESK ? "text" : "none") + '" enterkeyhint="go" autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false" placeholder="Scanner…">' +
			'</form><div class="scanhint" id="mbxHint"></div></div>';
		var h;
		if (DESK) {
			h = '<div class="dtool"><h1 id="mbxTitle">Atelier</h1>' + nav + wsBtn +
				'<button class="tbtn" id="mbxRefresh" title="Actualiser" aria-label="Actualiser">' + ICON.refresh + '</button>' +
				'<a class="tbtn" href="' + esc(CFG.tabletUrl) + '" target="mrpbatch_tablet">' + ICON.tablet + '<span>Tablette</span></a></div>' +
				'<div class="infoline" id="mbxSub"></div>' + scan;
		} else {
			h = '<div class="stick" id="mbxStick"><header class="top">' +
				'<button class="ibtn" id="mbxBack" title="Retour" aria-label="Retour" style="display:none">' + ICON.back + '</button>' +
				'<div class="title"><b id="mbxTitle">Atelier</b><small id="mbxSub"></small></div>' + wsBtn +
				'<button class="ibtn" id="mbxKbd" title="Clavier à l\'écran" aria-label="Clavier à l\'écran">' + ICON.kbd + '</button>' +
				'<button class="ibtn" id="mbxRefresh" title="Actualiser" aria-label="Actualiser">' + ICON.refresh + '</button>' +
				'<button class="ibtn hide-s" id="mbxTheme" title="Thème clair / sombre" aria-label="Thème clair / sombre">' + ICON.theme + '</button>' +
				'<button class="ibtn hide-s" id="mbxFull" title="Plein écran" aria-label="Plein écran">' + ICON.full + '</button>' +
				'<a class="ibtn hide-s" href="' + esc(CFG.dolibarr) + '" title="Retour à Dolibarr">Dolibarr</a>' +
				'</header>' + (SCREENS.length > 1 ? nav : "") + scan + '</div>';
		}
		h += '<main class="main" id="mbxView"></main>' +
			'<div class="flash" id="mbxFlash" aria-hidden="true"></div><div class="toast" id="mbxToast" role="status" aria-live="polite" aria-atomic="true"></div>' +
			'<div class="modal" id="mbxModal"><div class="mbox" id="mbxModalBox" role="dialog" aria-modal="true"></div></div>' +
			'<div class="spin" id="mbxSpin" role="progressbar" aria-label="Chargement"><i></i></div>';
		root.innerHTML = h;
	}
	buildShell();
	var scanInput = $("mbxScan");

	function setHead(title, sub) {
		$("mbxTitle").textContent = title;
		var s = $("mbxSub");
		if (DESK) { s.innerHTML = sub || ""; s.style.display = sub ? "" : "none"; } else { s.textContent = String(sub || "").replace(/<[^>]+>/g, ""); }
	}
	function setScan(placeholder, hint) {
		var bar = $("mbxScanbar");
		bar.style.display = placeholder ? "" : "none";
		scanInput.placeholder = placeholder || "";
		$("mbxHint").textContent = hint || "";
		measure();
	}

	/* Mise en page selon la largeur réelle du conteneur */
	function measure() {
		var w = root.getBoundingClientRect().width;
		root.classList.toggle("w-m", w >= 680);
		root.classList.toggle("w-l", w >= (DESK ? 980 : 1000));
		root.classList.toggle("w-xl", w >= 1360);
		var st = $("mbxStick");
		root.style.setProperty("--stick", (st ? st.offsetHeight : 0) + "px");
	}
	if (window.ResizeObserver) { new ResizeObserver(measure).observe(root); }
	window.addEventListener("resize", measure);
	window.addEventListener("orientationchange", function () { setTimeout(measure, 300); });
	measure();
	function wide() { return root.classList.contains("w-l"); }

	/* Thème (tablette) */
	function applyTheme(t) { root.setAttribute("data-theme", t); store("theme", t); }
	if (!DESK) {
		applyTheme(store("theme") || "light");
		$("mbxTheme").onclick = function () { applyTheme(root.getAttribute("data-theme") === "dark" ? "light" : "dark"); };
		$("mbxFull").onclick = function () {
			var d = document, el = d.documentElement;
			if (!d.fullscreenElement && !d.webkitFullscreenElement) { (el.requestFullscreen || el.webkitRequestFullscreen || function () {}).call(el); }
			else { (d.exitFullscreen || d.webkitExitFullscreen || function () {}).call(d); }
		};
	}

	/* ================================================================
	 * Poste de travail
	 * ================================================================ */
	var WS = CFG.workstations || [];
	var S = { ws: parseInt(store("ws") || "0", 10) || 0, kbd: false, panel: null, route: null, timer: null };
	if (S.ws > 0 && !WS.some(function (w) { return w.id === S.ws; })) { S.ws = 0; }
	function wsName(id) {
		if (id === -1) { return "Sans poste"; }
		for (var i = 0; i < WS.length; i++) { if (WS[i].id === id) { return WS[i].label || WS[i].ref; } }
		return "";
	}
	function wsChips(ids) {
		return (ids || []).map(function (id) { var n = wsName(id); return n ? '<span class="pill ws">' + esc(n) + '</span>' : ""; }).join(" ");
	}
	function updateWsBtn(visible) {
		var b = $("mbxWs");
		b.style.display = (visible && WS.length) ? "" : "none";
		$("mbxWsLbl").textContent = S.ws ? wsName(S.ws) : "Tous les postes";
		b.classList.toggle("on", !!S.ws && !DESK);
	}
	$("mbxWs").onclick = function () {
		var items = [{ id: 0, t: "Tous les postes", s: "Tous les OF validés et en cours" }].concat(WS.map(function (w) {
			return { id: w.id, t: w.label || w.ref, s: w.ref + (w.type ? " · " + ({ HUMAN: "Humain", MACHINE: "Machine", BOTH: "Humain + machine" }[w.type] || w.type) : "") };
		})).concat([{ id: -1, t: "Sans poste", s: "OF sans poste de travail" }]);
		modal('<h3>Poste de travail</h3><p>Les tableaux de bord et le planning n\'affichent que les OF du poste choisi.</p><div class="list">' +
			items.map(function (it) { return '<button data-ws="' + it.id + '" class="' + (it.id === S.ws ? "sel" : "") + '">' + esc(it.t) + '<small>' + esc(it.s) + '</small></button>'; }).join("") +
			'</div><div class="acts"><button class="btn cancel" data-close style="flex:1">Fermer</button></div>', function (box) {
			box.querySelectorAll("[data-ws]").forEach(function (b) {
				b.onclick = function () {
					S.ws = parseInt(b.getAttribute("data-ws"), 10);
					store("ws", String(S.ws));
					closeModal();
					updateWsBtn(true);
					if (cur() && cur().refresh) { cur().refresh(); }
				};
			});
		});
	};

	/* ================================================================
	 * Scan (scanette clavier) et focus
	 * ================================================================ */
	function setKbd(on) {
		S.kbd = on;
		scanInput.setAttribute("inputmode", on ? "text" : "none");
		$("mbxKbd").classList.toggle("on", on);
		scanInput.blur();
		focusScan(true);
	}
	if (!DESK) { $("mbxKbd").onclick = function () { setKbd(!S.kbd); }; }
	function otherFieldFocused() {
		var a = document.activeElement;
		return a && a !== scanInput && (a.tagName === "INPUT" || a.tagName === "SELECT" || a.tagName === "TEXTAREA");
	}
	function focusScan(force) {
		if ($("mbxScanbar").style.display === "none" || modalOn()) { return; }
		if (!force && otherFieldFocused()) { return; }
		if (DESK && !force) { return; }
		scanInput.focus({ preventScroll: true });
	}
	scanInput.addEventListener("focus", function () { $("mbxScanForm").classList.add("focus"); });
	scanInput.addEventListener("blur", function () { $("mbxScanForm").classList.remove("focus"); });
	if (!DESK) {
		document.addEventListener("click", function (e) {
			if (!e.target.closest("input, a, select, textarea")) { setTimeout(focusScan, 0); }
		});
		setInterval(function () {
			if (document.visibilityState === "visible" && document.activeElement !== scanInput && !otherFieldFocused()) { focusScan(); }
		}, 1500);
	}

	// Rafale de scanette : frappes très rapides terminées par Entrée, même si le focus est ailleurs
	var burst = { buf: "", start: 0, last: 0, target: null, saved: null, lastScan: 0 };
	document.addEventListener("keydown", function (e) {
		var now = Date.now();
		if (e.key === "Enter") {
			var fast = burst.buf.length >= 4 && (now - burst.start) / burst.buf.length < 40;
			if (fast && (burst.target !== scanInput || S.panel)) {
				e.preventDefault();
				e.stopPropagation();
				if (burst.target && burst.saved !== null) { burst.target.value = burst.saved; }
				var code = burst.buf;
				burst.buf = "";
				burst.lastScan = now;
				handleScan(code);
				return;
			}
			burst.buf = "";
			return;
		}
		if (e.key.length !== 1 || e.ctrlKey || e.metaKey || e.altKey) { return; }
		if (now - burst.last > 60) {
			burst.buf = "";
			burst.start = now;
			var a = document.activeElement;
			burst.target = a && (a.tagName === "INPUT" || a.tagName === "TEXTAREA") ? a : null;
			burst.saved = burst.target ? burst.target.value : null;
		}
		burst.buf += e.key;
		burst.last = now;
		if (!burst.target && !DESK && $("mbxScanbar").style.display !== "none") { scanInput.focus({ preventScroll: true }); }
	}, true);

	$("mbxScanForm").addEventListener("submit", function (e) {
		e.preventDefault();
		var code = scanInput.value.trim();
		scanInput.value = "";
		burst.buf = "";
		if (code) { handleScan(code); }
	});

	/* Décodage GS1 côté navigateur (pour remplir le lot / les dates d'un panneau ouvert) */
	function gs1(code) {
		code = String(code).replace(/^\](C1|e0|d2|Q3)/, "").replace(/\((\d{2,4})\)/g, "\x1D$1").replace(/^\x1D/, "");
		if (!/^(01|02)\d{14}/.test(code)) { return null; }
		var out = {}, i = 0, fixed = { "00": 18, "01": 14, "02": 14, "11": 6, "13": 6, "15": 6, "17": 6 }, guard = 0;
		function d6(v) {
			var m = /^(\d{2})(\d{2})(\d{2})$/.exec(v); if (!m) { return ""; }
			var y = 2000 + +m[1], mo = +m[2], d = +m[3];
			if (!d) { d = new Date(y, mo, 0).getDate(); }
			return y + "-" + pad2(mo) + "-" + pad2(d);
		}
		function set(ai, v) {
			if (ai === "01" || ai === "02") { out.gtin = v; } else if (ai === "10") { out.batch = v; } else if (ai === "21") { out.serial = v; }
			else if (ai === "17") { out.eatby = d6(v); } else if (ai === "15") { out.sellby = d6(v); }
		}
		while (i < code.length && guard++ < 20) {
			if (code[i] === "\x1D") { i++; continue; }
			var ai = code.substr(i, 2), v;
			if (fixed[ai]) { v = code.substr(i + 2, fixed[ai]); i += 2 + fixed[ai]; }
			else if (/^3[13]\d\d/.test(code.substr(i, 4))) { i += 10; continue; }
			else if (["10", "21", "37", "30"].indexOf(ai) >= 0) {
				var rest = code.substr(i + 2), gsi = rest.indexOf("\x1D");
				v = gsi < 0 ? rest : rest.substr(0, gsi);
				var m = gsi < 0 ? /^(.+?)(1[1357])(\d{6})$/.exec(v) : null;
				if (m) { v = m[1]; set(m[2], m[3]); }
				i = gsi < 0 ? code.length : i + 3 + gsi;
			} else { break; }
			set(ai, v);
		}
		return out.gtin ? out : null;
	}

	function handleScan(code) {
		code = String(code).trim();
		scanInput.value = "";
		if (!code) { return; }
		var p = S.panel;
		// Étiquette de lot attendue dans un panneau de production / réception
		if (p && (p.mode === "produce" || p.mode === "receive") && (p.armLot || p.editLot)) {
			var g = gs1(code);
			p.batch = g ? (g.batch || g.serial || p.batch) : code;
			if (g && g.eatby) { p.eatby = g.eatby; }
			if (g && g.sellby) { p.sellby = g.sellby; }
			p.armLot = false; p.editLot = false;
			beep(true);
			renderPanel();
			return;
		}
		var sc = cur();
		if (sc && sc.onScan) { sc.onScan(code); }
	}

	/* ================================================================
	 * Routage : #/ecran[/type/id]
	 * ================================================================ */
	var REG = {};
	function cur() { return S.route ? REG[S.route.screen] : null; }
	function go(hash) { if (location.hash === hash) { route(); } else { location.hash = hash; } }
	window.addEventListener("hashchange", route);
	function route() {
		var m = location.hash.match(/^#\/([a-z]+)(?:\/([a-z]+)(?:\/(\d+))?)?/);
		var screen = m && REG[m[1]] && CFG.screens.indexOf(m[1]) >= 0 ? m[1] : null;
		if (!screen) {
			var def = CFG.screen && CFG.screens.indexOf(CFG.screen) >= 0 ? CFG.screen : (store("screen") || "");
			if (CFG.screens.indexOf(def) < 0) { def = CFG.screens[0]; }
			go("#/" + def);
			return;
		}
		S.route = { screen: screen, kind: m[2] || "", id: m[3] ? parseInt(m[3], 10) : 0 };
		store("screen", screen);
		S.panel = null;
		document.body.classList.remove("lock");
		clearInterval(S.timer);
		$("mbxNav") && $("mbxNav").querySelectorAll("button").forEach(function (b) { var on = b.getAttribute("data-s") === screen; b.classList.toggle("on", on); if (on) { b.setAttribute("aria-current", "page"); } else { b.removeAttribute("aria-current"); } });
		if ($("mbxBack")) { $("mbxBack").style.display = S.route.kind ? "" : "none"; }
		$("mbxView").innerHTML = "";
		REG[screen].enter(S.route);
		measure();
		window.scrollTo(0, 0);
	}
	if ($("mbxNav")) {
		$("mbxNav").querySelectorAll("button").forEach(function (b) { b.onclick = function () { go("#/" + b.getAttribute("data-s")); }; });
	}
	if ($("mbxBack")) {
		$("mbxBack").onclick = function () { if (S.panel) { closePanel(); } else { go("#/" + S.route.screen); } };
	}
	$("mbxRefresh").onclick = function () { if (cur() && cur().refresh) { cur().refresh(); } };
	function autoRefresh(fn, sec) {
		clearInterval(S.timer);
		S.timer = setInterval(function () {
			if (document.visibilityState === "visible" && !S.panel && !modalOn()) { fn(true); }
		}, (sec || 60) * 1000);
	}

	/* ================================================================
	 * Panneau de saisie (consommer, produire, réceptionner, préparer)
	 * ================================================================ */
	var MODE = {
		consume: { k: "Déclarer une consommation", done: "Consommé", cls: "" },
		produce: { k: "Déclarer une production", done: "Produit", cls: "prod" },
		receive: { k: "Réceptionner", done: "Reçu", cls: "rc" },
		pick: { k: "Préparer", done: "Préparé", cls: "od" }
	};

	function sideEl() { return $("mbxSide"); }

	/* r : réponse serveur (mode, line, …) ; ctx : contexte écran (submit, onDone) */
	function openPanel(r, ctx) {
		var l = r.line;
		var p = { mode: r.mode, data: r, line: l, ctx: ctx || {}, needStock: !l.disable_stock_change, armLot: false, editLot: false, fresh: true };
		if (r.mode === "consume") {
			p.sel = r.selected;
			var def = l.status_batch === 2 ? 1 : Math.max(0, l.qty_remaining);
			var s0 = r.stock[r.selected];
			if (s0 && l.status_batch !== 2 && s0.qty < def) { def = s0.qty; }
			p.qty = def > 0 ? raw(def) : "";
		} else if (r.mode === "produce" || r.mode === "receive") {
			p.qty = r.qty > 0 ? raw(r.qty) : "";
			p.batch = r.batch || "";
			p.eatby = r.eatby || "";
			p.sellby = r.sellby || "";
			p.wh = r.fk_warehouse || (r.warehouses[0] ? r.warehouses[0].id : 0);
			p.conform = true;
			p.note = "";
			if (r.mode === "receive" && l.status_batch && !p.batch) { p.armLot = true; }
		} else if (r.mode === "pick") {
			p.qty = l.qty_remaining > 0 ? raw(l.qty_remaining) : "";
			p.lot = r.batch || "";
			if (!p.lot && r.lots && r.lots.length) { p.lot = r.lots[0].batch; }
			p.needStock = false;
		}
		S.panel = p;
		renderSideOrPanel();
		var side = sideEl();
		if (side) { side.scrollTop = 0; }
		if (!wide()) { window.scrollTo(0, 0); }
	}

	function closePanel() {
		S.panel = null;
		renderSideOrPanel();
		setTimeout(function () { focusScan(true); }, 30);
	}

	function renderSideOrPanel() {
		var side = sideEl();
		if (!side) { return; }
		document.body.classList.toggle("lock", !!S.panel && !wide());
		if (side.parentNode) { side.parentNode.classList.toggle("haspanel", !!S.panel); }
		if (S.panel) {
			side.className = "side active " + S.panel.mode;
			renderPanel();
			return;
		}
		side.className = "side";
		var sc = cur();
		side.innerHTML = sc && sc.sideHtml ? sc.sideHtml() : "";
		if (sc && sc.bindSide) { sc.bindSide(side); }
	}

	function histHtml(title, list, kind) {
		var h = '<h2 class="sec" style="margin-top:0">' + esc(title) + '</h2><div class="card">';
		if (!list || !list.length) { h += '<div class="empty">Rien pour le moment</div>'; }
		(list || []).slice(0, 30).forEach(function (x) {
			var ko = x.status === 0;
			h += '<div class="hist' + (ko ? " ko" : "") + '"><div><b>' + esc(x.product_ref || x.label) + '</b>' + (x.batch ? ' · ' + esc(x.batch) : '') +
				'<span class="l">' + esc(x.date) + (x.element_ref && kind !== "self" ? ' · ' + esc(x.element_ref) : '') + (x.thirdparty ? ' · ' + esc(x.thirdparty) : '') +
				(x.warehouse ? ' · ' + esc(x.warehouse) : '') + (x.eatby ? ' · DLC ' + esc(x.eatby) : '') + (x.sellby ? ' · DLUO ' + esc(x.sellby) : '') +
				(x.user ? ' · ' + esc(x.user) : '') + (ko ? ' · NON CONFORME' : '') + (x.note ? ' · ' + esc(x.note) : '') + '</span></div>' +
				(x.qty ? '<div class="q">' + fmt(x.qty) + '</div>' : '') + '</div>';
		});
		return h + '</div>';
	}

	function quick(field) {
		var h = '<span class="quick" data-f="' + field + '">';
		[1, 3, 7, 30, 90, 365].forEach(function (n) { h += '<button type="button" data-d="' + n + '">J+' + n + '</button>'; });
		return h + '<button type="button" data-d="">✕</button></span>';
	}

	function renderPanel() {
		var p = S.panel, l = p.line, r = p.data, side = sideEl();
		if (!p || !side) { return; }
		var M = MODE[p.mode];
		var h = '<div class="panel ' + p.mode + '" role="dialog" aria-labelledby="pTitle">';
		h += '<div class="ph"><div class="t"><div class="k">' + M.k + '</div>' +
			'<b id="pTitle">' + esc(l.product_ref) + '</b><span class="l">' + esc(l.product_label) + (l.barcode ? ' · ' + esc(l.barcode) : '') + '</span></div>' +
			'<button class="xbtn" id="pClose" aria-label="Fermer">' + ICON.x + '</button></div><div class="pb"><div class="pmain">';

		var doneQty = p.mode === "consume" ? l.qty_consumed : (p.mode === "produce" ? l.qty_produced : l.qty_done);
		h += '<div class="chips">';
		if (l.qty_planned > 0 || p.mode !== "receive") {
			h += '<div class="chip">' + (p.mode === "receive" || p.mode === "pick" ? "Commandé" : "Prévu") + ' <b>' + fmt(l.qty_planned) + '</b></div>' +
				'<div class="chip">' + M.done + ' <b>' + fmt(doneQty) + '</b></div>' +
				'<div class="chip">Reste <b>' + fmt(Math.max(0, l.qty_remaining)) + '</b></div>';
		} else {
			h += '<div class="chip"><b>Réception sans commande</b></div>';
		}
		if (p.mode === "pick") { h += '<div class="chip">Stock <b>' + fmt(l.stock) + '</b></div>'; }
		h += (l.status_batch === 2 ? '<div class="chip"><b>N° de série</b></div>' : (l.status_batch === 1 ? '<div class="chip"><b>Lot</b></div>' : '')) + '</div>';

		var blocked = false;
		if (p.mode === "consume") {
			if (r.warning) { h += '<div class="alert a-warn">' + esc(r.warning) + '</div>'; }
			if (p.needStock && !r.stock.length) {
				h += '<div class="alert a-err">Aucun stock disponible pour ce produit.</div>';
				blocked = true;
			} else if (p.needStock) {
				h += '<div class="lbl2">' + (l.status_batch ? 'Lot à consommer (le plus ancien en premier)' : 'Entrepôt') + '</div><div class="opts">';
				r.stock.forEach(function (s, i) {
					h += '<button class="opt' + (i === p.sel ? ' sel' : '') + (s.expired ? ' expired' : '') + '" data-i="' + i + '">' +
						(l.status_batch ? '<b>' + esc(s.batch) + '</b><span class="l">' + esc(s.warehouse_ref) + '</span>' : '<b>' + esc(s.warehouse_ref) + '</b>') +
						(s.eatby ? '<span class="l' + (s.expired ? ' bad' : '') + '">DLC ' + esc(s.eatby) + (s.expired ? ' — dépassée' : '') + '</span>' : '') +
						(s.sellby ? '<span class="l">DLUO ' + esc(s.sellby) + '</span>' : '') +
						'<div class="q">' + fmt(s.qty) + '</div></button>';
				});
				h += '</div>';
			}
		} else if (p.mode === "produce" || p.mode === "receive") {
			if (p.mode === "receive") {
				h += '<div class="lbl2">Contrôle à réception</div><div class="field">' +
					'<button class="sbtn' + (p.conform ? ' on' : '') + '" data-conf="1">✓ Conforme</button>' +
					'<button class="sbtn' + (!p.conform ? ' off' : '') + '" data-conf="0">✕ Non conforme</button></div>';
				if (!p.conform) {
					h += '<div class="alert a-warn">' + (r.quarantine ? 'La marchandise sera entrée dans l\'entrepôt de quarantaine.' : 'Réception refusée : aucune entrée en stock, l\'évènement est tracé.') + '</div>' +
						'<textarea class="note" id="pNote" placeholder="Motif (température, emballage, DLC courte…)">' + esc(p.note) + '</textarea>';
				}
			}
			if (l.status_batch) {
				h += '<div class="lbl2">' + (l.status_batch === 2 ? 'N° de série' : (p.mode === "receive" ? 'N° de lot fournisseur' : 'N° de lot')) + '</div><div class="field">';
				if (p.editLot) {
					h += '<div class="lotval"><input id="pLotInput" type="text" inputmode="text" autocomplete="off" value="' + esc(p.batch) + '"></div>' +
						'<button class="sbtn" id="pLotDone">OK</button>';
				} else if (p.armLot) {
					h += '<div class="lotval arm">Scannez l\'étiquette du lot…</div><button class="sbtn" id="pLotEdit">Saisir</button>' + (p.batch ? '<button class="sbtn" id="pLotCancel">Annuler</button>' : '');
				} else {
					h += '<div class="lotval">' + (p.batch ? esc(p.batch) : '<span class="muted">—</span>') + '</div>' +
						'<button class="sbtn" id="pLotEdit">Modifier</button><button class="sbtn" id="pLotScan">Scanner</button>';
				}
				h += '</div>';
				if (r.lot_known) { h += '<div class="alert a-info">Lot déjà connu : ses dates sont reprises.</div>'; }
				var today = r.today || isoDay(new Date());
				var minIso = r.min_days ? addDays(today, r.min_days) : today;
				var eatBad = p.eatby && p.eatby < minIso;
				h += '<div class="dates">' +
					'<label class="date' + (eatBad ? ' bad' : (r.eatby_mandatory && !p.eatby ? ' req' : '')) + '"><span class="l">DLC' + (r.eatby_mandatory ? ' *' : '') + (r.eatby_days ? ' · fab. + ' + r.eatby_days + ' j' : '') + '</span>' +
					'<input type="date" id="pEat" value="' + esc(p.eatby) + '">' + quick("eatby") + '</label>' +
					'<label class="date' + (r.sellby_mandatory && !p.sellby ? ' req' : '') + '"><span class="l">DLUO / DDM' + (r.sellby_mandatory ? ' *' : '') + (r.sellby_days ? ' · fab. + ' + r.sellby_days + ' j' : '') + '</span>' +
					'<input type="date" id="pSell" value="' + esc(p.sellby) + '">' + quick("sellby") + '</label></div>';
				if (eatBad) {
					h += '<div class="alert a-warn">' + (p.eatby < today ? 'DLC dépassée !' : 'DLC trop courte (minimum ' + r.min_days + ' jours à réception).') + '</div>';
				}
			}
			if (p.needStock && !(p.mode === "receive" && !p.conform)) {
				h += '<div class="lbl2">' + (p.mode === "receive" ? 'Entrepôt de réception' : 'Entrepôt de réception du produit fini') + '</div><div class="opts whs">';
				r.warehouses.forEach(function (w) {
					h += '<button class="opt' + (w.id === p.wh ? ' sel' : '') + '" data-wh="' + w.id + '"><b>' + esc(w.ref) + '</b>' + (w.label ? '<span class="l">' + esc(w.label) + '</span>' : '') + '</button>';
				});
				h += '</div>';
			}
		} else if (p.mode === "pick") {
			if (r.lots && r.lots.length) {
				h += '<div class="lbl2">Lot prélevé (facultatif, le plus ancien en premier)</div><div class="opts">';
				r.lots.forEach(function (s) {
					h += '<button class="opt' + (s.batch === p.lot ? ' sel' : '') + '" data-lot="' + esc(s.batch) + '"><b>' + esc(s.batch) + '</b><span class="l">' + esc(s.warehouse_ref) + '</span>' +
						(s.eatby ? '<span class="l">DLC ' + esc(s.eatby) + '</span>' : '') + '<div class="q">' + fmt(s.qty) + '</div></button>';
				});
				h += '</div>';
			}
			if (l.stock < l.qty_remaining) { h += '<div class="alert a-warn">Stock insuffisant : ' + fmt(l.stock) + ' pour un reste de ' + fmt(l.qty_remaining) + '.</div>'; }
			var b = l.batches || {};
			var bk = Object.keys(b);
			if (bk.length) { h += '<div class="chips">' + bk.map(function (k) { return '<div class="chip">' + esc(k) + ' <b>' + fmt(b[k]) + '</b></div>'; }).join("") + '</div>'; }
		}

		h += '</div>';
		if (!blocked) {
			h += '<div class="pqty"><div class="lbl2">Quantité' + (p.mode === "pick" ? ' préparée' : '') + '</div><div class="qtybox">' +
				'<div class="qtydisp"><span class="v" id="pQty" aria-live="polite" aria-label="Quantité">' + esc(p.qty || "0") + '</span><span class="l">' + (l.status_batch === 2 ? 'unité' : '') + '</span></div>' +
				'<div class="qtywarn" id="pWarn"></div>' +
				'<div class="pad" id="pad">' +
				'<button data-k="7">7</button><button data-k="8">8</button><button data-k="9">9</button><button data-k="del" class="s" aria-label="Effacer le dernier chiffre">⌫</button>' +
				'<button data-k="4">4</button><button data-k="5">5</button><button data-k="6">6</button><button data-k="clr" class="s">Effacer</button>' +
				'<button data-k="1">1</button><button data-k="2">2</button><button data-k="3">3</button><button data-k="rest" class="s">' + (p.mode === "receive" && !l.qty_planned ? "1" : "Reste") + '</button>' +
				'<button data-k="dec" class="s" aria-label="Moins 1">−1</button><button data-k="0">0</button><button data-k=",">,</button><button data-k="inc" class="s" aria-label="Plus 1">+1</button>' +
				'</div></div></div>';
		}
		h += '</div><div class="pf"><button class="btn cancel" id="pCancel">Annuler</button>';
		if (p.mode === "pick" && l.qty_done > 0) { h += '<button class="btn cancel" id="pReset">Remise à 0</button>'; }
		if (!blocked) {
			var okLbl = p.mode === "receive" && !p.conform ? "Enregistrer le refus" : "Valider";
			h += '<button class="btn ok ' + M.cls + (p.mode === "receive" && !p.conform ? " danger" : "") + '" id="pOk">' + okLbl + '</button>';
		}
		h += '</div></div>';
		side.innerHTML = h;
		bindPanel(side);
	}

	function bindPanel(side) {
		var p = S.panel, r = p.data;
		side.querySelectorAll(".opt, [data-conf]").forEach(function (b) { b.setAttribute("aria-pressed", b.classList.contains("sel") || b.classList.contains("on") || b.classList.contains("off") ? "true" : "false"); });
		$("pClose").onclick = closePanel;
		$("pCancel").onclick = closePanel;
		side.querySelectorAll(".opt[data-i]").forEach(function (b) { b.onclick = function () { p.sel = parseInt(b.getAttribute("data-i"), 10); renderPanel(); }; });
		side.querySelectorAll(".opt[data-wh]").forEach(function (b) { b.onclick = function () { p.wh = parseInt(b.getAttribute("data-wh"), 10); renderPanel(); }; });
		side.querySelectorAll(".opt[data-lot]").forEach(function (b) { b.onclick = function () { var v = b.getAttribute("data-lot"); p.lot = p.lot === v ? "" : v; renderPanel(); }; });
		side.querySelectorAll("[data-conf]").forEach(function (b) { b.onclick = function () { p.conform = b.getAttribute("data-conf") === "1"; renderPanel(); }; });
		if ($("pNote")) { $("pNote").oninput = function () { p.note = this.value; }; }
		if ($("pLotEdit")) { $("pLotEdit").onclick = function () { p.editLot = true; p.armLot = false; renderPanel(); var i = $("pLotInput"); i.focus(); i.select(); }; }
		if ($("pLotScan")) { $("pLotScan").onclick = function () { p.armLot = true; renderPanel(); focusScan(true); }; }
		if ($("pLotCancel")) { $("pLotCancel").onclick = function () { p.armLot = false; renderPanel(); }; }
		if ($("pLotDone")) {
			var commitLot = function () { p.batch = $("pLotInput").value.trim(); p.editLot = false; renderPanel(); focusScan(true); };
			$("pLotDone").onclick = commitLot;
			$("pLotInput").addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); commitLot(); } });
		}
		side.querySelectorAll(".quick button").forEach(function (b) {
			b.onclick = function (e) {
				e.preventDefault();
				var f = b.parentNode.getAttribute("data-f"), d = b.getAttribute("data-d");
				p[f] = d === "" ? "" : addDays(r.today, parseInt(d, 10));
				renderPanel();
			};
		});
		if ($("pEat")) { $("pEat").onchange = function () { p.eatby = this.value; renderPanel(); }; }
		if ($("pSell")) { $("pSell").onchange = function () { p.sellby = this.value; renderPanel(); }; }
		if ($("pad")) { $("pad").querySelectorAll("button").forEach(function (b) { b.onclick = function () { key(b.getAttribute("data-k")); }; }); }
		if ($("pReset")) {
			$("pReset").onclick = function () {
				confirmBox("Remettre la ligne à zéro ?", "La quantité préparée (" + fmt(p.line.qty_done) + ") sera effacée.", "Remettre à zéro", function () {
					if (p.ctx.reset) { p.ctx.reset(p); }
				}, "danger");
			};
		}
		if ($("pOk")) { $("pOk").onclick = submitPanel; }
		check();
	}

	function key(k) {
		var p = S.panel;
		if (!p) { return; }
		var v = p.fresh ? "" : String(p.qty || "");
		var n;
		if (/^[0-9]$/.test(k)) { v = (v === "0" ? "" : v) + k; }
		else if (k === ",") { if (v.indexOf(",") < 0) { v = (v || "0") + ","; } }
		else if (k === "del") { v = String(p.qty || "").slice(0, -1); }
		else if (k === "clr") { v = ""; }
		else if (k === "rest") { v = (p.mode === "receive" && !p.line.qty_planned) ? "1" : raw(Math.max(0, p.line.qty_remaining)); }
		else if (k === "inc" || k === "dec") { n = num(p.qty); n = isNaN(n) ? 0 : n; v = raw(Math.max(0, n + (k === "inc" ? 1 : -1))); }
		if (v.replace(/\D/g, "").length > 7) { return; }
		p.fresh = false;
		p.qty = v;
		$("pQty").textContent = v || "0";
		check();
	}

	function check() {
		var p = S.panel;
		if (!p || !$("pOk")) { return; }
		var q = num(p.qty), l = p.line, msg = "";
		if (p.mode === "consume") {
			var s = p.data.stock[p.sel];
			if (q > l.qty_remaining) { msg = "Au-delà du reste à consommer"; }
			if (s && p.needStock && q > s.qty) { msg = "Supérieur au stock du lot (" + fmt(s.qty) + ")"; }
		} else if ((p.mode === "produce" || p.mode === "pick") && q > l.qty_remaining && l.qty_remaining >= 0) {
			msg = p.mode === "pick" ? "Au-delà de la quantité commandée" : "Au-delà de la quantité prévue";
		} else if (p.mode === "receive" && l.qty_planned > 0 && q > l.qty_remaining) {
			msg = "Au-delà de la quantité commandée";
		}
		if (l.status_batch === 2 && q !== 1 && p.mode !== "pick") { msg = "N° de série : quantité = 1"; }
		$("pWarn").textContent = msg;
		$("pWarn").style.display = msg ? "block" : "none";
		$("pOk").disabled = !(q > 0) || (l.status_batch === 2 && q !== 1 && p.mode !== "pick");
	}

	function submitPanel() {
		var p = S.panel, l = p.line, r = p.data;
		var q = num(p.qty);
		if (!(q > 0)) { toast("Quantité invalide", "err"); return; }
		var params = { qty: String(q) };
		if (p.mode === "consume") {
			var s = r.stock[p.sel];
			if (p.needStock) {
				if (!s) { koFeedback("Choisissez un lot"); return; }
				params.fk_warehouse = s.fk_warehouse;
				if (s.batch) { params.batch = s.batch; }
			}
		} else if (p.mode === "produce" || p.mode === "receive") {
			if (l.status_batch) {
				if (!p.batch) { koFeedback("N° de lot obligatoire"); return; }
				if (r.eatby_mandatory && !p.eatby) { koFeedback("DLC obligatoire"); return; }
				if (r.sellby_mandatory && !p.sellby) { koFeedback("DLUO obligatoire"); return; }
				params.batch = p.batch;
				params.eatby = p.eatby || "";
				params.sellby = p.sellby || "";
			}
			var needWh = p.needStock && !(p.mode === "receive" && !p.conform);
			if (needWh) {
				if (!p.wh) { koFeedback("Choisissez un entrepôt"); return; }
				params.fk_warehouse = p.wh;
			}
			if (p.mode === "receive") {
				params.conform = p.conform ? "1" : "0";
				params.note = p.note || "";
				params.fk_product = l.fk_product;
				if (!p.conform && !params.note) { koFeedback("Indiquez le motif de non-conformité"); return; }
			}
		} else if (p.mode === "pick") {
			if (p.lot) { params.batch = p.lot; }
		}
		params.lineid = l.objectid;
		$("pOk").disabled = true;
		spin(true);
		p.ctx.submit(params).then(function (res) {
			spin(false);
			if (res.ok) {
				okFeedback(res.message);
				S.panel = null;
				if (p.ctx.done) { p.ctx.done(res); }
				setTimeout(function () { focusScan(true); }, 30);
			} else {
				koFeedback(res.error);
				if ($("pOk")) { $("pOk").disabled = false; }
			}
		}).catch(function (err) { fail(err); if ($("pOk")) { $("pOk").disabled = false; } });
	}

	// Clavier physique dans le panneau : chiffres, virgule, retour arrière, Entrée, Échap
	document.addEventListener("keydown", function (e) {
		if (modalOn()) { if (e.key === "Escape") { closeModal(); } return; }
		if (!S.panel) { return; }
		var a = document.activeElement;
		if (a && a !== scanInput && (a.tagName === "INPUT" || a.tagName === "TEXTAREA")) { return; }
		if (e.key === "Escape") { e.preventDefault(); closePanel(); return; }
		if (burst.buf.length > 1 && Date.now() - burst.last < 60) { return; }
		if (/^[0-9]$/.test(e.key) || e.key === "," || e.key === ".") {
			e.preventDefault();
			setTimeout(function () {
				if (burst.buf.length <= 1 && Date.now() - burst.lastScan > 250 && S.panel) { key(e.key === "." ? "," : e.key); scanInput.value = ""; }
			}, 70);
		} else if (e.key === "Backspace") {
			e.preventDefault(); key("del");
		} else if (e.key === "Enter" && burst.buf.length < 4) {
			if ($("pOk") && !$("pOk").disabled) { e.preventDefault(); submitPanel(); }
		}
	});

	function progress(cap, pct, txt, cls) {
		return '<div class="meter"><div class="lbl"><span>' + cap + '</span><b>' + txt + '</b></div><div class="bar ' + (cls || "") + '"><i style="width:' + Math.min(100, Math.max(0, pct)) + '%"></i></div></div>';
	}
	var MO_STATUS = { 0: "Brouillon", 1: "Validé", 2: "En cours", 3: "Fabriqué", 9: "Annulé" };

	/* ================================================================
	 * ÉCRAN PRODUCTION
	 * ================================================================ */
	var P = { mos: [], filter: "all", search: "", mo: null, tab: "consume" };
	REG.production = {
		enter: function (rt) {
			updateWsBtn(true);
			if (rt.kind === "mo" && rt.id) { this.loadMo(rt.id); }
			else { this.loadHome(); }
		},
		refresh: function () {
			if (S.route.kind === "mo" && P.mo) { this.loadMo(P.mo.id); } else { this.loadHome(); }
		},
		loadHome: function (silent) {
			var self = this;
			setHead("Production", (S.ws ? "Poste : " + esc(wsName(S.ws)) + " · " : "") + esc(CFG.company + " · " + CFG.user));
			setScan("Scanner un OF…", "Scannez la référence d'un OF ou touchez une carte.");
			if (!silent) { spin(true); }
			api({ what: "list", ws: S.ws }).then(function (r) {
				spin(false);
				if (!r.ok) { toast(r.error, "err"); return; }
				P.mos = r.mos;
				if (S.route.screen === "production" && !S.route.kind) { self.renderHome(); }
			}).catch(fail);
			autoRefresh(function () { self.loadHome(true); });
		},
		renderHome: function () {
			var self = this, all = P.mos;
			var cnt = { all: all.length, 2: 0, 1: 0, late: 0 };
			all.forEach(function (m) { cnt[m.status] = (cnt[m.status] || 0) + 1; if (m.late) { cnt.late++; } });
			var q = P.search.toLowerCase();
			var list = all.filter(function (m) {
				var okf = P.filter === "all" || (P.filter === "late" ? m.late : String(m.status) === P.filter);
				var oks = !q || (m.ref + " " + m.product_ref + " " + m.product_label + " " + m.thirdparty).toLowerCase().indexOf(q) >= 0;
				return okf && oks;
			});
			var h = '<div class="bar2"><div class="seg" id="pFilters">' +
				[["all", "Tous", cnt.all], ["2", "En cours", cnt[2]], ["1", "À démarrer", cnt[1]], ["late", "En retard", cnt.late]].map(function (f) {
					return '<button data-f="' + f[0] + '" class="' + (P.filter === f[0] ? "on" : "") + '">' + f[1] + '<span class="n">' + f[2] + '</span></button>';
				}).join("") + '</div>' +
				'<label class="search">' + ICON.search + '<input id="pSearch" type="search" inputmode="search" placeholder="Rechercher (OF, produit, client)" value="' + esc(P.search) + '"></label></div>';
			if (S.ws) { h += '<div class="alert a-info">Poste « ' + esc(wsName(S.ws)) + ' » : ' + all.length + ' OF. <a href="#" id="pAllWs">Voir tous les postes</a></div>'; }
			if (!list.length) {
				h += '<div class="card empty">Aucun OF à afficher.</div>';
			} else {
				h += '<div class="grid">';
				list.forEach(function (m) {
					var lateTxt = m.late === 2 ? "Fin dépassée" : (m.late === 1 ? "Retard" : "");
					h += '<button class="card tcard' + (m.late ? " isLate" : "") + '" data-id="' + m.id + '">' +
						'<div class="meta"><span class="ref">' + esc(m.ref) + '</span><span>' + (lateTxt ? '<span class="pill late">' + lateTxt + '</span> ' : '') +
						'<span class="pill s' + m.status + '">' + MO_STATUS[m.status] + '</span></span></div>' +
						'<div class="prod">' + esc(m.product_ref) + (m.product_label ? ' — ' + esc(m.product_label) : '') + ' × ' + fmt(m.qty) + '</div>' +
						'<div class="sub">' + (m.date_start ? 'Début ' + esc(m.date_start) : 'Non planifié') + (m.date_end ? ' → ' + esc(m.date_end) : '') + (m.thirdparty ? ' · ' + esc(m.thirdparty) : '') + '</div>' +
						(m.workstations.length ? '<div class="chipsline">' + wsChips(m.workstations) + '</div>' : '') +
						progress("Consommation", m.pct, m.pct + " %") +
						progress("Production", m.produce_pct, fmt(m.produced) + " / " + fmt(m.qty), "prod") +
						'</button>';
				});
				h += '</div>';
			}
			var v = $("mbxView");
			v.innerHTML = h;
			v.querySelectorAll("#pFilters button").forEach(function (b) { b.onclick = function () { P.filter = b.getAttribute("data-f"); self.renderHome(); }; });
			v.querySelectorAll(".tcard").forEach(function (b) { b.onclick = function () { go("#/production/mo/" + b.getAttribute("data-id")); }; });
			$("pSearch").oninput = function () { P.search = this.value; var pos = this.selectionStart; self.renderHome(); var i = $("pSearch"); i.focus(); try { i.setSelectionRange(pos, pos); } catch (e) { /* type search */ } };
			if ($("pAllWs")) { $("pAllWs").onclick = function (e) { e.preventDefault(); S.ws = 0; store("ws", "0"); updateWsBtn(true); self.loadHome(); }; }
		},
		loadMo: function (id) {
			var self = this;
			setScan("Scanner un composant, le produit fini ou un lot…", "Composant → consommation · produit fini → production · ou touchez une ligne.");
			spin(true);
			api({ what: "mo", id: id }).then(function (r) {
				spin(false);
				if (!r.ok) { toast(r.error, "err"); go("#/production"); return; }
				P.mo = r.mo;
				self.renderMo();
			}).catch(fail);
		},
		canAct: function () { return P.mo && P.mo.consumable && CFG.rights.mrpWrite; },
		renderMo: function () {
			var self = this, mo = P.mo;
			setHead(mo.ref, esc(mo.product_ref + (mo.product_label ? " — " + mo.product_label : "") + " × " + fmt(mo.qty)));
			var toConsume = mo.lines.filter(function (l) { return l.qty_remaining > 0; }).length;
			var toProduce = mo.produce_lines.filter(function (l) { return l.qty_remaining > 0; }).length;
			// Synthèse compacte : statut + deux jauges sur une seule carte (le travail, ce sont les lignes)
			var h = '<div class="card mosum"><div class="mosum-st"><span class="pill s' + mo.status + '">' + (MO_STATUS[mo.status] || mo.status) + '</span></div>' +
				'<div class="mosum-m"><span class="cap">Consommation</span><b>' + mo.pct + ' %</b><div class="bar"><i style="width:' + Math.min(100, mo.pct) + '%"></i></div></div>' +
				'<div class="mosum-m"><span class="cap">Production</span><b>' + fmt(mo.produced) + ' <span class="muted">/ ' + fmt(mo.produce_planned) + '</span></b><div class="bar prod"><i style="width:' + mo.produce_pct + '%"></i></div></div></div>';
			h += '<div class="infoline">' + (mo.date_start ? '<span>Prévu ' + esc(mo.date_start) + (mo.date_end ? ' → ' + esc(mo.date_end) : '') + '</span>' : '<span>Non planifié</span>') +
				(mo.thirdparty ? '<span>Client : <b>' + esc(mo.thirdparty) + '</b></span>' : '') + wsChips(mo.workstations) +
				'<a href="' + esc(mo.url) + '" target="' + (DESK ? "_self" : "_blank") + '">Fiche OF ↗</a></div>';
			if (!mo.consumable) { h += '<div class="alert a-err">Cet OF n\'est ni validé ni en cours : saisie impossible.</div>'; }
			else if (!CFG.rights.mrpWrite) { h += '<div class="alert a-warn">Vous n\'avez pas les droits pour enregistrer une consommation ou une production.</div>'; }
			h += '<div class="seg" id="pTabs" style="margin-bottom:.8em">' +
				'<button data-t="consume" class="' + (P.tab === "consume" ? "on" : "") + '">Consommer<span class="n">' + toConsume + '</span></button>' +
				'<button data-t="produce" class="prod ' + (P.tab === "produce" ? "on" : "") + '">Produire<span class="n">' + toProduce + '</span></button></div>';
			h += '<div class="work"><div><div class="card">';
			var rows = P.tab === "consume" ? mo.lines : mo.produce_lines;
			if (!rows.length) { h += '<div class="empty">Aucune ligne</div>'; }
			rows.forEach(function (l) {
				var done = l.qty_remaining <= 0;
				var doneQty = P.tab === "consume" ? l.qty_consumed : l.qty_produced;
				var tag = l.status_batch === 2 ? '<span class="tag">SÉRIE</span>' : (l.status_batch === 1 ? '<span class="tag">LOT</span>' : '');
				h += '<button class="row' + (P.tab === "produce" ? ' prod' : '') + (done ? ' done' : '') + '" data-line="' + l.objectid + '">' +
					'<div class="inf"><b>' + esc(l.product_ref) + tag + '</b><span class="l">' + esc(l.product_label) + (l.barcode ? ' · ' + esc(l.barcode) : '') +
					(l.fk_workstation ? ' · ' + esc(wsName(l.fk_workstation)) : '') + '</span></div>' +
					'<div class="nums"><b>' + (done ? '✓ ' : '') + fmt(Math.max(0, l.qty_remaining)) + '</b><span class="l">' + fmt(doneQty) + ' / ' + fmt(l.qty_planned) + '</span></div>' +
					(self.canAct() ? '<span class="go">' + ICON.plus + '</span>' : '') + '</button>';
			});
			h += '</div>';
			if (self.canAct()) {
				// Clôture : action secondaire en bas, mise en avant seulement quand la production est complète
				var ready = mo.produce_pct >= 100;
				h += '<button class="btn ' + (ready ? 'ok' : 'ghost') + ' closemo" id="pCloseMo">' + (ready ? 'Terminer l\'OF' : 'Terminer l\'OF (production incomplète)…') + '</button>';
			}
			h += '</div><aside class="side" id="mbxSide"></aside></div>';
			var v = $("mbxView");
			v.innerHTML = h;
			v.querySelectorAll("#pTabs button").forEach(function (b) { b.onclick = function () { P.tab = b.getAttribute("data-t"); S.panel = null; self.renderMo(); }; });
			v.querySelectorAll(".row").forEach(function (b) {
				b.onclick = function () {
					if (!self.canAct()) { return; }
					spin(true);
					api({ what: "line", id: mo.id, lineid: b.getAttribute("data-line") }).then(function (r) {
						spin(false);
						if (r.ok) { self.open(r); } else { koFeedback(r.error); }
					}).catch(fail);
				};
			});
			if ($("pCloseMo")) {
				$("pCloseMo").onclick = function () {
					var warn = mo.produce_pct < 100 ? "La production n'est pas terminée (" + fmt(mo.produced) + " / " + fmt(mo.produce_planned) + "). " : "";
					confirmBox("Terminer l'OF " + mo.ref + " ?", warn + "L'OF passera au statut « Fabriqué » et disparaîtra de la liste.", "Terminer", function () {
						spin(true);
						api({ what: "close", id: mo.id }, true).then(function (r) {
							spin(false);
							if (r.ok) { okFeedback(r.message); go("#/production"); } else { koFeedback(r.error); }
						}).catch(fail);
					});
				};
			}
			renderSideOrPanel();
			measure();
		},
		open: function (r) {
			var self = this;
			if (!self.canAct()) { toast("Saisie impossible sur cet OF", "err"); return; }
			if (P.tab !== r.mode) { P.tab = r.mode; S.panel = null; self.renderMo(); }
			openPanel(r, {
				submit: function (params) { params.what = r.mode; params.id = P.mo.id; return api(params, true); },
				done: function (res) { P.mo = res.mo; self.renderMo(); }
			});
		},
		sideHtml: function () {
			var produce = P.tab === "produce";
			return histHtml(produce ? "Dernières productions" : "Dernières consommations", produce ? P.mo.history_produced : P.mo.history, "self");
		},
		onScan: function (code) {
			var self = this;
			if (!S.route.kind) {
				spin(true);
				api({ what: "findmo", code: code }).then(function (r) {
					spin(false);
					if (r.ok) { beep(true); go("#/production/mo/" + r.id); } else { koFeedback(r.error); }
				}).catch(fail);
				return;
			}
			spin(true);
			api({ what: "scan", id: P.mo.id, code: code }).then(function (r) {
				spin(false);
				if (r.ok) { beep(true); self.open(r); return; }
				return api({ what: "findmo", code: code }).then(function (r2) {
					if (r2.ok && r2.id !== P.mo.id) { beep(true); go("#/production/mo/" + r2.id); } else { koFeedback(r.error); }
				});
			}).catch(fail);
		}
	};

	/* ================================================================
	 * ÉCRAN PLANNING
	 * ================================================================ */
	var PL = { start: null, days: 0, data: null };
	REG.planning = {
		enter: function () {
			updateWsBtn(true);
			setScan("", "");
			if (!PL.start) { PL.start = midnight(new Date()); }
			if (!PL.days) { PL.days = parseInt(store("plandays") || "0", 10) || (wide() ? 7 : (root.classList.contains("w-m") ? 3 : 1)); }
			if (PL.days === 7) { PL.start = this.monday(PL.start); }
			this.load();
		},
		refresh: function () { this.load(); },
		monday: function (d) { var x = midnight(d); var wd = (x.getDay() + 6) % 7; x.setDate(x.getDate() - wd); return x; },
		load: function (silent) {
			var self = this;
			var to = new Date(PL.start.getTime()); to.setDate(to.getDate() + PL.days);
			setHead("Planning de production", (S.ws ? "Poste : " + esc(wsName(S.ws)) + " · " : "") + dayLabel(PL.start) + (PL.days > 1 ? " → " + dayLabel(new Date(to.getTime() - 1)) : ""));
			if (!silent) { spin(true); }
			api({ what: "planning", ws: S.ws, from: Math.floor(PL.start.getTime() / 1000), to: Math.floor(to.getTime() / 1000) }).then(function (r) {
				spin(false);
				if (!r.ok) { toast(r.error, "err"); return; }
				PL.data = r.planning;
				if (S.route.screen === "planning") { self.render(); }
			}).catch(fail);
			autoRefresh(function () { self.load(true); }, 120);
		},
		shift: function (n) {
			if (n === 0) { PL.start = PL.days === 7 ? this.monday(new Date()) : midnight(new Date()); }
			else { PL.start = new Date(PL.start.getTime()); PL.start.setDate(PL.start.getDate() + n * PL.days); }
			this.load();
		},
		render: function () {
			var self = this, d = PL.data;
			var to = new Date(PL.start.getTime()); to.setDate(to.getDate() + PL.days);
			var h = '<div class="plan-tool">' +
				'<button class="tbtn" id="plPrev" aria-label="Précédent">' + ICON.left + '</button>' +
				'<button class="tbtn" id="plToday">Aujourd\'hui</button>' +
				'<button class="tbtn" id="plNext" aria-label="Suivant">' + ICON.right + '</button>' +
				'<span class="when">' + (PL.days === 1 ? dayLabel(PL.start) : dayLabel(PL.start) + " → " + dayLabel(new Date(to.getTime() - 1))) + '</span>' +
				'<div class="seg" style="flex:0 1 auto">' + [[1, "Jour"], [3, "3 jours"], [7, "Semaine"]].map(function (x) {
					return '<button data-d="' + x[0] + '" class="' + (PL.days === x[0] ? "on" : "") + '">' + x[1] + '</button>';
				}).join("") + '</div>' +
				(CFG.ics ? '<button class="tbtn" id="plIcs">' + ICON.cal + '<span>Agenda (.ics)</span></button>' : '') + '</div>';

			var mos = d.mos;
			if (!root.classList.contains("w-m")) {
				h += self.agenda(mos);
			} else {
				h += self.gantt(mos, d);
			}
			h += '<div class="legend"><span><i style="background:#b45309"></i>À démarrer</span><span><i style="background:var(--accent)"></i>En cours</span>' +
				'<span><i style="background:#3f8f5f"></i>Fabriqué</span><span><i style="background:#fff;outline:3px solid var(--err);outline-offset:-3px"></i>En retard</span>' +
				'<span><i style="background:repeating-linear-gradient(135deg,#888 0 3px,#bbb 3px 6px)"></i>Fin estimée</span></div>';
			if (d.unplanned.length) {
				h += '<h2 class="sec">OF validés non planifiés (' + d.unplanned.length + ')</h2><div class="card">';
				d.unplanned.forEach(function (m) {
					h += '<button class="row" data-mo="' + m.id + '" data-u="1"><div class="inf"><b>' + esc(m.ref) + '</b><span class="l">' + esc(m.product_ref) + ' × ' + fmt(m.qty) +
						(m.thirdparty ? ' · ' + esc(m.thirdparty) : '') + '</span></div>' + wsChips(m.workstations) + '<span class="go">' + ICON.cal + '</span></button>';
				});
				h += '</div>';
			}
			var v = $("mbxView");
			v.innerHTML = h;
			$("plPrev").onclick = function () { self.shift(-1); };
			$("plNext").onclick = function () { self.shift(1); };
			$("plToday").onclick = function () { self.shift(0); };
			v.querySelectorAll(".plan-tool .seg button").forEach(function (b) {
				b.onclick = function () {
					PL.days = parseInt(b.getAttribute("data-d"), 10);
					store("plandays", String(PL.days));
					if (PL.days === 7) { PL.start = self.monday(PL.start); }
					self.load();
				};
			});
			if ($("plIcs")) { $("plIcs").onclick = function () { self.icsModal(); }; }
			var byId = {};
			d.mos.concat(d.unplanned).forEach(function (m) { byId[m.id] = m; });
			v.querySelectorAll("[data-mo]").forEach(function (b) { b.onclick = function () { self.details(byId[b.getAttribute("data-mo")]); }; });
			// Défilement initial sur « maintenant » en vue jour
			var g = v.querySelector(".gchart");
			var nowEl = v.querySelector(".g-now");
			if (g && nowEl) { g.scrollLeft = Math.max(0, nowEl.offsetLeft - g.clientWidth / 3); }
		},
		/* Position horizontale (0..1) d'un instant, en ne comptant que la plage horaire affichée de chaque jour */
		x: function (ts) {
			var d = PL.data, hs = d.hour_start, he = d.hour_end, t = new Date(ts * 1000);
			var day = Math.floor((midnight(t).getTime() - PL.start.getTime()) / 86400000 + 0.5);
			if (day < 0) { return 0; }
			if (day >= PL.days) { return 1; }
			var hr = t.getHours() + t.getMinutes() / 60;
			var f = Math.min(1, Math.max(0, (hr - hs) / (he - hs)));
			return (day + f) / PL.days;
		},
		gantt: function (mos, d) {
			var self = this;
			var rows = [];
			if (S.ws) {
				rows.push({ id: S.ws, name: wsName(S.ws), sub: "" });
			} else if (d.workstations.length) {
				d.workstations.forEach(function (w) { rows.push({ id: w.id, name: w.label || w.ref, sub: w.label ? w.ref : "" }); });
				rows.push({ id: -1, name: "Sans poste", sub: "" });
			} else {
				rows.push({ id: 0, name: "Production", sub: "" });
			}
			var today = isoDay(new Date());
			var cols = PL.days === 1 ? (d.hour_end - d.hour_start) : PL.days;
			var head = '<div class="g-head"><div class="g-lab">' + (d.workstations.length ? "Poste" : "") + '</div><div class="g-days" style="grid-template-columns:repeat(' + cols + ',1fr)">';
			var i, dd;
			if (PL.days === 1) {
				for (i = d.hour_start; i < d.hour_end; i++) { head += '<div class="g-day">' + pad2(i) + 'h</div>'; }
			} else {
				for (i = 0; i < PL.days; i++) {
					dd = new Date(PL.start.getTime()); dd.setDate(dd.getDate() + i);
					head += '<div class="g-day' + (isoDay(dd) === today ? " today" : "") + '">' + DAYS[dd.getDay()] + ' ' + dd.getDate() + '<small>' + MONTHS[dd.getMonth()] + '</small></div>';
				}
			}
			head += '</div></div>';
			var grid = '<div class="g-grid" style="grid-template-columns:repeat(' + cols + ',1fr)">';
			for (i = 0; i < cols; i++) {
				dd = new Date(PL.start.getTime()); dd.setDate(dd.getDate() + i);
				grid += '<i class="' + (PL.days > 1 && isoDay(dd) === today ? "today" : "") + '"></i>';
			}
			grid += '</div>';
			var nowTs = Date.now() / 1000;
			var nowX = (nowTs >= PL.start.getTime() / 1000 && nowTs < PL.start.getTime() / 1000 + PL.days * 86400) ? self.x(nowTs) : -1;

			var body = "";
			var any = false;
			rows.forEach(function (row) {
				var items = mos.filter(function (m) {
					if (row.id === 0) { return true; }
					if (row.id === -1) { return !m.workstations.length; }
					return m.workstations.indexOf(row.id) >= 0;
				});
				if (row.id === -1 && !items.length) { return; }
				any = any || items.length > 0;
				// Empilement des barres qui se chevauchent
				var lanes = [];
				items.sort(function (a, b) { return a.start - b.start; }).forEach(function (m) {
					var x1 = self.x(m.start), x2 = Math.max(self.x(m.end), x1 + (PL.days === 1 ? 0.04 : 0.05));
					var ln = 0;
					while (lanes[ln] !== undefined && lanes[ln] > x1 + 0.0001) { ln++; }
					lanes[ln] = x2;
					m._x1 = x1; m._x2 = x2; m._ln = ln;
				});
				var hgt = Math.max(1, lanes.length) * 3 + 0.9;
				body += '<div class="g-row"><div class="g-lab">' + esc(row.name) + (row.sub ? '<small>' + esc(row.sub) + '</small>' : '') + '<small>' + items.length + ' OF</small></div>' +
					'<div class="g-lane" style="height:' + hgt + 'em">' + grid + (nowX >= 0 ? '<div class="g-now" style="left:' + (nowX * 100) + '%"></div>' : '');
				items.forEach(function (m) {
					var narrow = (m._x2 - m._x1) * PL.days < (PL.days === 1 ? 0.12 : 0.6);
					body += '<button class="g-bar s' + m.status + (m.late ? " late" : "") + (m.end_estimated ? " est" : "") + (narrow ? " short" : "") + '" data-mo="' + m.id + '" title="' + esc(m.ref + " · " + m.product_ref + " × " + fmt(m.qty)) + '" style="left:' + (m._x1 * 100) + '%;width:' + ((m._x2 - m._x1) * 100) + '%;top:' + (0.45 + m._ln * 3) + 'em">' +
						'<b>' + esc(narrow ? m.ref.replace(/^.*-/, "") : m.ref) + '</b><span>' + esc(m.product_ref) + ' × ' + fmt(m.qty) + '</span>' +
						(m.produce_pct ? '<i class="g-prog" style="width:' + m.produce_pct + '%"></i>' : '') + '</button>';
				});
				body += '</div></div>';
			});
			var h = '<div class="card gchart"><div class="gchart-in"' + (PL.days === 1 ? ' style="min-width:' + Math.max(46, (d.hour_end - d.hour_start) * 4.2) + 'em"' : (PL.days === 7 ? ' style="min-width:60em"' : ' style="min-width:52em"')) + '>' + head + body + '</div></div>';
			if (!any) { h += '<div class="card empty" style="margin-top:.8em">Aucun OF planifié sur cette période' + (S.ws ? ' pour ce poste' : '') + '.</div>'; }
			return h;
		},
		agenda: function (mos) {
			var h = '<div class="agenda">';
			var today = isoDay(new Date());
			for (var i = 0; i < PL.days; i++) {
				var dd = new Date(PL.start.getTime()); dd.setDate(dd.getDate() + i);
				var d0 = dd.getTime() / 1000, d1 = d0 + 86400;
				var items = mos.filter(function (m) { return m.start < d1 && m.end >= d0; }).sort(function (a, b) { return a.start - b.start; });
				h += '<div class="aday' + (isoDay(dd) === today ? " today" : "") + '"><h2>' + dayLabel(dd) + (isoDay(dd) === today ? " · aujourd'hui" : "") + '</h2><div class="card">';
				if (!items.length) { h += '<div class="empty" style="padding:1em">Rien de planifié</div>'; }
				items.forEach(function (m) {
					var s = new Date(m.start * 1000), e = new Date(m.end * 1000);
					h += '<button class="aitem" data-mo="' + m.id + '"><span class="hr">' + (m.start < d0 ? "…" : hm(s)) + '</span><span class="dot s' + m.status + '"></span>' +
						'<span class="inf"><b>' + esc(m.ref) + (m.late ? ' <span class="pill late">Retard</span>' : '') + '</b><span class="l">' + esc(m.product_ref) + ' × ' + fmt(m.qty) + ' · jusqu\'à ' + (m.end >= d1 ? dayLabel(e) + " " : "") + hm(e) + '</span>' +
						(m.workstations.length ? '<span class="l">' + m.workstations.map(wsName).join(", ") + '</span>' : '') + '</span></button>';
				});
				h += '</div></div>';
			}
			return h + '</div>';
		},
		details: function (m) {
			var self = this;
			if (!m) { return; }
			var canPlan = CFG.rights.mrpWrite && m.status !== 3;
			modal('<h3>' + esc(m.ref) + ' <span class="pill s' + m.status + '">' + MO_STATUS[m.status] + '</span></h3>' +
				'<dl class="kv"><dt>Produit</dt><dd>' + esc(m.product_ref) + (m.product_label ? ' — ' + esc(m.product_label) : '') + '</dd>' +
				'<dt>Quantité</dt><dd>' + fmt(m.qty) + ' (produit : ' + fmt(m.produced) + ')</dd>' +
				'<dt>Début</dt><dd>' + (m.date_start ? esc(m.date_start) : 'Non planifié') + '</dd>' +
				'<dt>Fin</dt><dd>' + (m.date_end ? esc(m.date_end) : (m.start ? 'non renseignée (estimée)' : '—')) + '</dd>' +
				(m.thirdparty ? '<dt>Client</dt><dd>' + esc(m.thirdparty) + '</dd>' : '') +
				(m.workstations.length ? '<dt>Poste(s)</dt><dd>' + esc(m.workstations.map(wsName).join(", ")) + '</dd>' : '') + '</dl>' +
				progress("Consommation", m.pct, m.pct + " %") + progress("Production", m.produce_pct, fmt(m.produced) + " / " + fmt(m.qty), "prod") +
				'<div class="acts" style="flex-wrap:wrap"><button class="btn cancel" data-close style="flex:1 1 30%">Fermer</button>' +
				(canPlan ? '<button class="btn cancel" id="mResched" style="flex:1 1 30%">Replanifier</button>' : '') +
				(CFG.screens.indexOf("production") >= 0 && (m.status === 1 || m.status === 2) ? '<button class="btn ok" id="mOpen" style="flex:1 1 30%">Ouvrir à l\'atelier</button>' : '') + '</div>' +
				'<p style="margin:.8em 0 0"><a href="' + esc(CFG.dolibarr + 'mrp/mo_card.php?id=' + m.id) + '" target="' + (DESK ? "_self" : "_blank") + '">Fiche OF dans Dolibarr ↗</a></p>', function () {
				if ($("mOpen")) { $("mOpen").onclick = function () { closeModal(); go("#/production/mo/" + m.id); }; }
				if ($("mResched")) { $("mResched").onclick = function () { self.reschedule(m); }; }
			});
		},
		reschedule: function (m) {
			var self = this;
			var s = m.start ? new Date(m.start * 1000) : new Date(PL.start.getTime() + PL.data.hour_start * 3600000);
			var e = m.end && !m.end_estimated ? new Date(m.end * 1000) : null;
			modal('<h3>Replanifier ' + esc(m.ref) + '</h3><p>' + esc(m.product_ref) + ' × ' + fmt(m.qty) + '</p>' +
				'<label class="f"><span>Début</span><input type="datetime-local" id="rsS" value="' + isoLocal(s) + '"></label>' +
				'<label class="f"><span>Fin (facultative)</span><input type="datetime-local" id="rsE" value="' + (e ? isoLocal(e) : '') + '"></label>' +
				'<div class="quick" style="margin-bottom:.6em">' + [["-1440", "−1 j"], ["-60", "−1 h"], ["60", "+1 h"], ["1440", "+1 j"]].map(function (x) {
					return '<button type="button" data-shift="' + x[0] + '">' + x[1] + '</button>';
				}).join("") + '</div>' +
				'<div class="acts"><button class="btn cancel" data-close style="flex:1">Annuler</button><button class="btn ok" id="rsOk">Enregistrer</button></div>', function (box) {
				box.querySelectorAll("[data-shift]").forEach(function (b) {
					b.onclick = function () {
						var mn = parseInt(b.getAttribute("data-shift"), 10);
						["rsS", "rsE"].forEach(function (id) {
							var el = $(id);
							if (!el.value) { return; }
							var dt = new Date(el.value); dt.setMinutes(dt.getMinutes() + mn); el.value = isoLocal(dt);
						});
					};
				});
				$("rsOk").onclick = function () {
					var vs = $("rsS").value, ve = $("rsE").value;
					if (!vs) { koFeedback("Date de début obligatoire"); return; }
					spin(true);
					api({ what: "reschedule", id: m.id, start: Math.floor(new Date(vs).getTime() / 1000), end: ve ? Math.floor(new Date(ve).getTime() / 1000) : 0 }, true).then(function (r) {
						spin(false);
						if (r.ok) { closeModal(); okFeedback(r.message); self.load(); } else { koFeedback(r.error); }
					}).catch(fail);
				};
			});
		},
		icsModal: function () {
			var urls = [["Tous les postes", CFG.ics]];
			if (S.ws > 0) { urls.unshift(["Poste « " + wsName(S.ws) + " »", CFG.ics + "&ws=" + S.ws]); }
			urls.push(["Production + livraisons clients", CFG.ics + "&orders=1"]);
			modal('<h3>Abonnement agenda (.ics)</h3><p>Copiez l\'adresse et ajoutez-la dans Google Agenda (Autres agendas → « + » → À partir de l\'URL), Outlook (Ajouter un calendrier → À partir d\'Internet) ou sur un téléphone. Mise à jour automatique (délai selon l\'application).</p>' +
				urls.map(function (u, i) {
					return '<div class="lbl2">' + esc(u[0]) + '</div><div class="url" id="icsU' + i + '">' + esc(u[1]) + '</div><div class="acts" style="margin:.4em 0 .9em"><button class="sbtn" data-copy="' + i + '">Copier</button>' +
						'<a class="sbtn" style="display:inline-flex;align-items:center;text-decoration:none;color:inherit" href="' + esc(u[1].replace(/^https?:/, "webcal:")) + '">Ouvrir (webcal)</a></div>';
				}).join("") +
				'<p class="muted" style="font-size:.85em">Ce lien donne accès en lecture au planning sans connexion : ne le diffusez qu\'en interne. Il peut être régénéré dans la configuration du module.</p>' +
				'<div class="acts"><button class="btn cancel" data-close style="flex:1">Fermer</button></div>', function (box) {
				box.querySelectorAll("[data-copy]").forEach(function (b) {
					b.onclick = function () {
						var t = urls[parseInt(b.getAttribute("data-copy"), 10)][1];
						var done = function () { toast("Adresse copiée", "ok"); };
						if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(t).then(done, function () { toast("Copie impossible : sélectionnez l'adresse", "warn"); }); }
						else { toast("Sélectionnez l'adresse puis copiez-la", "warn"); }
					};
				});
			});
		},
		onScan: function (code) {
			api({ what: "findmo", code: code }).then(function (r) {
				if (r.ok) { beep(true); go("#/production/mo/" + r.id); } else { koFeedback(r.error); }
			}).catch(fail);
		}
	};

	/* ================================================================
	 * ÉCRAN RÉCEPTION DES MATIÈRES PREMIÈRES
	 * ================================================================ */
	var RC = { orders: [], history: [], search: "", order: null, products: [], q: "" };
	REG.reception = {
		enter: function (rt) {
			updateWsBtn(false);
			if (rt.kind === "order" && rt.id) { this.loadOrder(rt.id); }
			else if (rt.kind === "free") { this.renderFree(); this.loadHistory(); }
			else { this.loadHome(); }
		},
		refresh: function () {
			if (S.route.kind === "order" && RC.order) { this.loadOrder(RC.order.id); }
			else if (S.route.kind === "free") { this.loadHistory(); }
			else { this.loadHome(); }
		},
		loadHome: function (silent) {
			var self = this;
			setHead("Réception des matières", esc(CFG.company + " · " + CFG.user));
			setScan("Scanner une commande fournisseur ou un produit…", "Commande fournisseur → réception attendue · produit (EAN, GS1) → réception sans commande.");
			if (!silent) { spin(true); }
			api({ what: "rc_list" }).then(function (r) {
				spin(false);
				if (!r.ok) { toast(r.error, "err"); return; }
				RC.orders = r.orders; RC.history = r.history;
				if (S.route.screen === "reception" && !S.route.kind) { self.renderHome(); }
			}).catch(fail);
			autoRefresh(function () { self.loadHome(true); });
		},
		loadHistory: function () {
			api({ what: "rc_list" }).then(function (r) {
				if (r.ok) { RC.history = r.history; if (S.route.kind === "free" && !S.panel) { renderSideOrPanel(); } }
			});
		},
		renderHome: function () {
			var self = this;
			var q = RC.search.toLowerCase();
			var list = RC.orders.filter(function (o) { return !q || (o.ref + " " + o.ref_supplier + " " + o.supplier).toLowerCase().indexOf(q) >= 0; });
			var h = '<div class="bar2">' + (CFG.rights.rcFree ? '<button class="tbtn" id="rcFree" style="min-height:3.1em;font-size:1.05em;background:var(--f-rc);color:#fff;border-color:var(--f-rc)">' + ICON.plus + '<span>Réception sans commande</span></button>' : '') +
				'<label class="search">' + ICON.search + '<input id="rcSearch" type="search" placeholder="Rechercher (commande, fournisseur)" value="' + esc(RC.search) + '"></label></div>';
			h += '<div class="work"><div><h2 class="sec" style="margin-top:0">Commandes fournisseur attendues (' + list.length + ')</h2>';
			if (!list.length) {
				h += '<div class="card empty">Aucune commande fournisseur « commandée » ou « reçue partiellement ».</div>';
			} else {
				h += '<div class="grid">';
				list.forEach(function (o) {
					h += '<button class="card tcard' + (o.late ? " isLate" : "") + '" data-id="' + o.id + '"><div class="meta"><span class="ref">' + esc(o.ref) + '</span>' +
						(o.late ? '<span class="pill late">Livraison dépassée</span>' : (o.status === 4 ? '<span class="pill s2">Reçue partiellement</span>' : '<span class="pill s1">Commandée</span>')) + '</div>' +
						'<div class="prod">' + esc(o.supplier) + '</div><div class="sub">' + (o.delivery ? 'Livraison prévue ' + esc(o.delivery) : 'Date de livraison non renseignée') +
						(o.ref_supplier ? ' · réf. ' + esc(o.ref_supplier) : '') + ' · ' + o.nblines + ' ligne(s)</div>' +
						progress("Reçu", o.pct, o.pct + " %", "rc") + '</button>';
				});
				h += '</div>';
			}
			h += '</div><aside class="side" id="mbxSide"></aside></div>';
			var v = $("mbxView");
			v.innerHTML = h;
			if ($("rcFree")) { $("rcFree").onclick = function () { go("#/reception/free"); }; }
			v.querySelectorAll(".tcard").forEach(function (b) { b.onclick = function () { go("#/reception/order/" + b.getAttribute("data-id")); }; });
			$("rcSearch").oninput = function () { RC.search = this.value; var pos = this.selectionStart; self.renderHome(); var i = $("rcSearch"); i.focus(); try { i.setSelectionRange(pos, pos); } catch (e) { /* type search */ } };
			renderSideOrPanel();
		},
		sideHtml: function () {
			if (S.route.kind === "order" && RC.order) { return histHtml("Réceptions de cette commande", RC.order.history, "self"); }
			return histHtml("Dernières réceptions", RC.history);
		},
		loadOrder: function (id) {
			var self = this;
			setScan("Scanner un produit (EAN, GS1) ou un lot…", "Le code GS1 du carton remplit produit, lot et DLC en un seul scan.");
			spin(true);
			api({ what: "rc_order", id: id }).then(function (r) {
				spin(false);
				if (!r.ok) { toast(r.error, "err"); go("#/reception"); return; }
				RC.order = r.order;
				self.renderOrder();
			}).catch(fail);
		},
		renderOrder: function () {
			var self = this, o = RC.order;
			setHead(o.ref, esc(o.supplier + (o.ref_supplier ? " · réf. " + o.ref_supplier : "")));
			var tot = 0, done = 0;
			o.lines.forEach(function (l) { tot += l.qty_planned; done += Math.min(l.qty_done, l.qty_planned); });
			var pct = tot > 0 ? Math.round(100 * done / tot) : 0;
			var h = '<div class="tiles"><div class="card tile"><div class="cap">Fournisseur</div><div class="mid">' + esc(o.supplier) + '</div></div>' +
				'<div class="card tile"><div class="cap">Livraison prévue</div><div class="mid">' + (o.delivery ? esc(o.delivery) : '—') + '</div></div>' +
				'<div class="card tile"><div class="cap">Reçu</div><div class="big">' + pct + ' %</div><div class="bar rc"><i style="width:' + pct + '%"></i></div></div></div>';
			h += '<div class="infoline"><a href="' + esc(o.url) + '" target="' + (DESK ? "_self" : "_blank") + '">Fiche commande ↗</a></div>';
			if (!o.receivable) { h += '<div class="alert a-err">Cette commande n\'est pas au statut « Commandée » : réception impossible.</div>'; }
			if (!o.stock_on_dispatch) { h += '<div class="alert a-warn">L\'option « Augmenter le stock réel à la ventilation des commandes fournisseur » est désactivée : les quantités seront ventilées sur la commande mais le stock ne bougera qu\'à la validation d\'une réception Dolibarr.</div>'; }
			if (!CFG.rights.rcOrder) { h += '<div class="alert a-warn">Droit « Réceptionner les commandes fournisseur » manquant.</div>'; }
			h += '<div class="work"><div><div class="card">';
			if (!o.lines.length) { h += '<div class="empty">Aucune ligne produit</div>'; }
			o.lines.forEach(function (l) {
				var dn = l.qty_remaining <= 0;
				h += '<button class="row rc' + (dn ? ' done' : '') + '" data-line="' + l.objectid + '"><div class="inf"><b>' + esc(l.product_ref) + (l.status_batch ? '<span class="tag">' + (l.status_batch === 2 ? 'SÉRIE' : 'LOT') + '</span>' : '') + '</b>' +
					'<span class="l">' + esc(l.product_label) + (l.ref_supplier ? ' · réf. fourn. ' + esc(l.ref_supplier) : '') + (l.barcode ? ' · ' + esc(l.barcode) : '') + '</span></div>' +
					'<div class="nums"><b>' + (dn ? '✓ ' : '') + fmt(Math.max(0, l.qty_remaining)) + '</b><span class="l">' + fmt(l.qty_done) + ' / ' + fmt(l.qty_planned) + '</span></div>' +
					(o.receivable && CFG.rights.rcOrder ? '<span class="go">' + ICON.plus + '</span>' : '') + '</button>';
			});
			h += '</div></div><aside class="side" id="mbxSide"></aside></div>';
			var v = $("mbxView");
			v.innerHTML = h;
			v.querySelectorAll(".row").forEach(function (b) {
				b.onclick = function () {
					if (!o.receivable || !CFG.rights.rcOrder) { return; }
					spin(true);
					api({ what: "rc_line", id: o.id, lineid: b.getAttribute("data-line") }).then(function (r) {
						spin(false);
						if (r.ok) { self.open(r); } else { koFeedback(r.error); }
					}).catch(fail);
				};
			});
			renderSideOrPanel();
		},
		renderFree: function () {
			var self = this;
			setHead("Réception sans commande", "Scannez ou recherchez la matière reçue");
			setScan("Scanner un produit (EAN, GS1, réf. fournisseur)…", "Le code GS1 du carton remplit produit, lot et DLC en un seul scan.");
			var h = '<div class="work"><div>' + (!CFG.rights.rcFree ? '<div class="alert a-err">Droit de création de mouvements de stock requis.</div>' : '') +
				'<label class="search" style="margin-bottom:.8em">' + ICON.search + '<input id="rcQ" type="search" inputmode="search" placeholder="Rechercher un produit (réf., libellé, code-barre)" value="' + esc(RC.q) + '"></label>' +
				'<div class="card" id="rcRes"></div></div><aside class="side" id="mbxSide"></aside></div>';
			$("mbxView").innerHTML = h;
			self.renderResults();
			var t = null;
			$("rcQ").oninput = function () {
				RC.q = this.value;
				clearTimeout(t);
				t = setTimeout(function () { self.search(); }, 250);
			};
			renderSideOrPanel();
		},
		search: function () {
			var self = this;
			if (RC.q.trim().length < 2) { RC.products = []; self.renderResults(); return; }
			api({ what: "rc_search", q: RC.q.trim() }).then(function (r) {
				if (r.ok) { RC.products = r.products; self.renderResults(); }
			}).catch(fail);
		},
		renderResults: function () {
			var self = this, el = $("rcRes");
			if (!el) { return; }
			if (!RC.products.length) { el.innerHTML = '<div class="empty">' + (RC.q.trim().length < 2 ? 'Scannez le produit ou tapez au moins 2 caractères' : 'Aucun produit acheté correspondant') + '</div>'; return; }
			el.innerHTML = RC.products.map(function (p) {
				return '<button class="row rc" data-p="' + p.id + '"><div class="inf"><b>' + esc(p.ref) + (p.status_batch ? '<span class="tag">' + (p.status_batch === 2 ? 'SÉRIE' : 'LOT') + '</span>' : '') + '</b><span class="l">' + esc(p.label) + (p.barcode ? ' · ' + esc(p.barcode) : '') + '</span></div><span class="go">' + ICON.plus + '</span></button>';
			}).join("");
			el.querySelectorAll("[data-p]").forEach(function (b) {
				b.onclick = function () {
					if (!CFG.rights.rcFree) { koFeedback("Droit de création de mouvements de stock requis"); return; }
					spin(true);
					api({ what: "rc_line", fk_product: b.getAttribute("data-p") }).then(function (r) {
						spin(false);
						if (r.ok) { self.open(r); } else { koFeedback(r.error); }
					}).catch(fail);
				};
			});
		},
		open: function (r) {
			var self = this;
			var orderId = S.route.kind === "order" && RC.order ? RC.order.id : 0;
			if (orderId && !CFG.rights.rcOrder) { koFeedback("Droit « Réceptionner les commandes fournisseur » requis"); return; }
			if (!orderId && !CFG.rights.rcFree) { koFeedback("Droit de création de mouvements de stock requis"); return; }
			openPanel(r, {
				submit: function (params) { params.what = "rc_receive"; params.id = orderId; if (!orderId) { params.lineid = 0; } return api(params, true); },
				done: function (res) {
					if (res.order) { RC.order = res.order; self.renderOrder(); }
					else { RC.history = res.history || RC.history; renderSideOrPanel(); }
				}
			});
		},
		onScan: function (code) {
			var self = this;
			var orderId = S.route.kind === "order" && RC.order ? RC.order.id : 0;
			spin(true);
			api({ what: "rc_scan", id: orderId, code: code }).then(function (r) {
				spin(false);
				if (!r.ok) { koFeedback(r.error); return; }
				beep(true);
				if (r.goto === "order") { go("#/reception/order/" + r.id); return; }
				if (!orderId && S.route.kind !== "free") { location.hash = "#/reception/free"; setTimeout(function () { self.open(r); }, 120); return; }
				self.open(r);
			}).catch(fail);
		}
	};

	/* ================================================================
	 * ÉCRAN COMMANDES CLIENTS (préparation, conditionnement)
	 * ================================================================ */
	var OD = { orders: [], steps: [], packStep: "", filter: store("odfilter") || "all", search: "", step: "", order: null };
	function stepLabel(code) { for (var i = 0; i < OD.steps.length; i++) { if (OD.steps[i].code === code) { return OD.steps[i].label; } } return code; }
	function stepIndex(code) { for (var i = 0; i < OD.steps.length; i++) { if (OD.steps[i].code === code) { return i; } } return 0; }
	REG.orders = {
		enter: function (rt) {
			updateWsBtn(false);
			if (rt.kind === "order" && rt.id) { this.loadOrder(rt.id); } else { this.loadHome(); }
		},
		refresh: function () { if (S.route.kind === "order" && OD.order) { this.loadOrder(OD.order.id); } else { this.loadHome(); } },
		loadHome: function (silent) {
			var self = this;
			setHead("Commandes clients", esc(CFG.company + " · " + CFG.user));
			setScan("Scanner une commande client…", "Scannez le bon de commande ou touchez une carte.");
			if (!silent) { spin(true); }
			api({ what: "od_list", days: OD.filter === "all" ? "" : (OD.filter === "3" ? 3 : 0) }).then(function (r) {
				spin(false);
				if (!r.ok) { toast(r.error, "err"); return; }
				OD.orders = r.orders; OD.steps = r.steps; OD.packStep = r.pack_step;
				if (!OD.step || stepIndex(OD.step) < 0) { OD.step = OD.steps[0].code; }
				if (S.route.screen === "orders" && !S.route.kind) { self.renderHome(); }
			}).catch(fail);
			autoRefresh(function () { self.loadHome(true); });
		},
		card: function (o) {
			var due = o.due === "late" ? '<span class="pill late">Retard ' + esc(o.delivery) + '</span>' : (o.due === "today" ? '<span class="pill today">Aujourd\'hui</span>' : (o.delivery ? '<span class="pill s0">' + esc(o.delivery) + '</span>' : ''));
			return '<button class="card tcard' + (o.due === "late" ? " isLate" : "") + '" data-id="' + o.id + '"><div class="meta"><span class="ref">' + esc(o.ref) + '</span>' + due + '</div>' +
				'<div class="prod">' + esc(o.customer) + '</div><div class="sub">' + o.nblines + ' ligne(s)' + (o.ref_client ? ' · réf. ' + esc(o.ref_client) : '') + (o.nb_packages ? ' · ' + o.nb_packages + ' colis' : '') + '</div>' +
				((o.shortage || o.mos.length) ? '<div class="chipsline">' + (o.shortage ? '<span class="pill late">' + o.shortage + ' rupture(s)</span>' : '') +
				o.mos.map(function (m) { return '<span class="pill s' + m.status + '">' + esc(m.ref) + '</span>'; }).join("") + '</div>' : '') +
				progress("Préparé", o.pct, o.pct + " %", "od") + '</button>';
		},
		renderHome: function () {
			var self = this;
			var q = OD.search.toLowerCase();
			var list = OD.orders.filter(function (o) { return !q || (o.ref + " " + o.ref_client + " " + o.customer).toLowerCase().indexOf(q) >= 0; });
			var h = '<div class="bar2"><div class="seg" id="odF">' + [["all", "Toutes"], ["3", "À livrer sous 3 j"], ["0", "Retard + aujourd'hui"]].map(function (f) {
				return '<button data-f="' + f[0] + '" class="' + (OD.filter === f[0] ? "on" : "") + '">' + f[1] + '</button>';
			}).join("") + '</div><label class="search">' + ICON.search + '<input id="odSearch" type="search" placeholder="Rechercher (commande, client)" value="' + esc(OD.search) + '"></label></div>';
			var by = {};
			OD.steps.forEach(function (s) { by[s.code] = []; });
			list.forEach(function (o) { (by[o.step] || by[OD.steps[0].code]).push(o); });
			// Recherche : afficher l'étape qui contient des résultats plutôt qu'une étape vide
			if (q && by[OD.step] && !by[OD.step].length) {
				for (var si = 0; si < OD.steps.length; si++) { if (by[OD.steps[si].code].length) { OD.step = OD.steps[si].code; break; } }
			}
			if (wide()) {
				h += '<div class="kanban">' + OD.steps.map(function (s) {
					return '<section class="kcol" aria-label="' + esc(s.label) + '"><h2><span>' + esc(s.label) + '</span><span class="n">' + by[s.code].length + '</span></h2>' +
						(by[s.code].length ? by[s.code].map(self.card).join("") : '<div class="empty" style="padding:1em;font-size:.9em">—</div>') + '</section>';
				}).join("") + '</div>';
			} else {
				h += '<div class="seg" id="odSteps" style="margin-bottom:.8em">' + OD.steps.map(function (s) {
					return '<button data-s="' + s.code + '" class="' + (OD.step === s.code ? "on" : "") + '">' + esc(s.label) + '<span class="n">' + by[s.code].length + '</span></button>';
				}).join("") + '</div>';
				h += by[OD.step] && by[OD.step].length ? '<div class="grid">' + by[OD.step].map(self.card).join("") + '</div>' : '<div class="card empty">' + (!OD.orders.length ? (OD.filter === "all" ? 'Aucune commande client validée à préparer.' : 'Aucune commande à livrer sur cette période.') : (q && !list.length ? 'Aucune commande ne correspond à la recherche.' : 'Aucune commande à cette étape.')) + '</div>';
			}
			var v = $("mbxView");
			v.innerHTML = h;
			v.querySelectorAll("#odF button").forEach(function (b) { b.onclick = function () { OD.filter = b.getAttribute("data-f"); store("odfilter", OD.filter); self.loadHome(); }; });
			v.querySelectorAll("#odSteps button").forEach(function (b) { b.onclick = function () { OD.step = b.getAttribute("data-s"); self.renderHome(); }; });
			v.querySelectorAll(".tcard").forEach(function (b) { b.onclick = function () { go("#/orders/order/" + b.getAttribute("data-id")); }; });
			$("odSearch").oninput = function () { OD.search = this.value; var pos = this.selectionStart; self.renderHome(); var i = $("odSearch"); i.focus(); try { i.setSelectionRange(pos, pos); } catch (e) { /* type search */ } };
		},
		loadOrder: function (id) {
			var self = this;
			setScan("Scanner un produit ou un lot à préparer…", "Chaque scan ajoute la quantité préparée sur la ligne correspondante.");
			spin(true);
			var p1 = OD.steps.length ? Promise.resolve() : api({ what: "od_list", days: "" }).then(function (r) { if (r.ok) { OD.steps = r.steps; OD.packStep = r.pack_step; } });
			p1.then(function () { return api({ what: "od_order", id: id }); }).then(function (r) {
				spin(false);
				if (!r.ok) { toast(r.error, "err"); go("#/orders"); return; }
				OD.order = r.order;
				self.renderOrder();
			}).catch(fail);
		},
		renderOrder: function () {
			var self = this, o = OD.order;
			setHead(o.ref, esc(o.customer + (o.ref_client ? " · réf. " + o.ref_client : "")));
			var tot = 0, done = 0, all = true;
			o.lines.forEach(function (l) { tot += l.qty_planned; done += Math.min(l.qty_done, l.qty_planned); if (l.qty_remaining > 0) { all = false; } });
			var pct = tot > 0 ? Math.round(100 * done / tot) : 0;
			var si = stepIndex(o.step), next = OD.steps[si + 1];
			var canW = CFG.rights.odWrite && (o.status === 1 || o.status === 2);
			var h = '<div class="tiles"><div class="card tile"><div class="cap">Client</div><div class="mid">' + esc(o.customer) + '</div></div>' +
				'<div class="card tile"><div class="cap">Livraison prévue</div><div class="mid">' + (o.delivery ? esc(o.delivery) : '—') + '</div></div>' +
				'<div class="card tile"><div class="cap">Préparé</div><div class="big">' + pct + ' %</div><div class="bar od"><i style="width:' + pct + '%"></i></div></div>';
			if (canW && next) { h += '<button class="card tile action od" id="odNext">' + '→ ' + esc(next.label) + '</button>'; }
			h += '</div>';
			h += '<div class="stepper" id="odStepper">' + OD.steps.map(function (s, i) {
				return '<button data-s="' + s.code + '" class="' + (i < si ? "done" : (i === si ? "cur" : "")) + '"' + (canW ? '' : ' disabled') + '>' + (i < si ? "✓ " : "") + esc(s.label) + '</button>';
			}).join("") + '</div>';
			h += '<div class="infoline"><a href="' + esc(o.url) + '" target="' + (DESK ? "_self" : "_blank") + '">Fiche commande ↗</a>' +
				(o.shipping_url ? '<a href="' + esc(o.shipping_url) + '" target="' + (DESK ? "_self" : "_blank") + '">Expéditions ↗</a>' : '') +
				(o.nb_packages ? '<span><b>' + o.nb_packages + '</b> colis' + (o.weight ? ' · ' + fmt(o.weight) + ' kg' : '') + '</span>' : '') +
				o.mos.map(function (m) { return '<a href="#/production/mo/' + m.id + '"><span class="pill s' + m.status + '">' + esc(m.ref) + ' · ' + MO_STATUS[m.status] + '</span></a>'; }).join("") + '</div>';
			if (o.note_public) { h += '<div class="alert a-info">' + esc(o.note_public) + '</div>'; }
			if (all && o.lines.length && next && si < stepIndex("prepared")) { h += '<div class="alert a-info">Toutes les lignes sont préparées : passez la commande à l\'étape suivante.</div>'; }
			if (!CFG.rights.odWrite) { h += '<div class="alert a-warn">Lecture seule : droit de modification des commandes ou de création d\'expéditions requis.</div>'; }
			h += '<div class="work"><div><div class="card">';
			if (!o.lines.length) { h += '<div class="empty">Aucune ligne produit</div>'; }
			o.lines.forEach(function (l) {
				var dn = l.qty_remaining <= 0, short = l.stock < l.qty_remaining;
				h += '<button class="row od' + (dn ? ' done' : '') + '" data-line="' + l.objectid + '"><div class="inf"><b>' + esc(l.product_ref) + (l.status_batch ? '<span class="tag">LOT</span>' : '') + '</b>' +
					'<span class="l">' + esc(l.product_label) + (Object.keys(l.batches || {}).length ? ' · lots ' + esc(Object.keys(l.batches).join(", ")) : '') + '</span></div>' +
					'<div class="nums"><b>' + (dn ? '✓ ' : '') + fmt(Math.max(0, l.qty_remaining)) + '</b><span class="l">' + fmt(l.qty_done) + ' / ' + fmt(l.qty_planned) + '</span>' +
					'<span class="l' + (short ? ' bad' : '') + '">stock ' + fmt(l.stock) + '</span></div>' + (canW ? '<span class="go">' + ICON.plus + '</span>' : '') + '</button>';
			});
			h += '</div></div><aside class="side" id="mbxSide"></aside></div>';
			var v = $("mbxView");
			v.innerHTML = h;
			v.querySelectorAll(".row").forEach(function (b) {
				b.onclick = function () {
					if (!canW) { return; }
					spin(true);
					api({ what: "od_line", id: o.id, lineid: b.getAttribute("data-line") }).then(function (r) {
						spin(false);
						if (r.ok) { self.open(r); } else { koFeedback(r.error); }
					}).catch(fail);
				};
			});
			v.querySelectorAll("#odStepper button").forEach(function (b) { b.onclick = function () { self.setStep(b.getAttribute("data-s")); }; });
			if ($("odNext")) { $("odNext").onclick = function () { self.setStep(next.code); }; }
			renderSideOrPanel();
		},
		sideHtml: function () { return histHtml("Historique de la commande", OD.order ? OD.order.history : [], "self"); },
		setStep: function (code) {
			var self = this, o = OD.order;
			if (code === o.step) { return; }
			var send = function (extra) {
				var params = { what: "od_step", id: o.id, step: code };
				Object.keys(extra || {}).forEach(function (k) { params[k] = extra[k]; });
				spin(true);
				api(params, true).then(function (r) {
					spin(false);
					if (r.ok) { closeModal(); okFeedback(r.message); OD.order = r.order; self.renderOrder(); } else { koFeedback(r.error); }
				}).catch(fail);
			};
			if (code === OD.packStep) {
				modal('<h3>' + esc(stepLabel(code)) + ' — ' + esc(o.ref) + '</h3><p>Indiquez le conditionnement (facultatif).</p>' +
					'<label class="f"><span>Nombre de colis / palettes</span><input type="number" min="0" step="1" inputmode="numeric" id="odNb" value="' + (o.nb_packages || "") + '"></label>' +
					'<label class="f"><span>Poids total (kg)</span><input type="number" min="0" step="0.001" inputmode="decimal" id="odWg" value="' + (o.weight || "") + '"></label>' +
					'<label class="f"><span>Note</span><textarea id="odNote" rows="2">' + esc(o.note) + '</textarea></label>' +
					'<div class="acts"><button class="btn cancel" data-close style="flex:1">Annuler</button><button class="btn ok od" id="odOk">Valider</button></div>', function () {
					$("odOk").onclick = function () { send({ nb_packages: $("odNb").value, weight: $("odWg").value, note: $("odNote").value }); };
					setTimeout(function () { $("odNb").focus(); }, 50);
				});
				return;
			}
			var back = stepIndex(code) < stepIndex(o.step);
			confirmBox((back ? "Revenir à « " : "Passer à « ") + stepLabel(code) + " » ?", o.ref + " — " + o.customer, back ? "Revenir" : "Valider", function () { send({}); }, back ? "" : "od");
		},
		open: function (r) {
			var self = this, o = OD.order;
			openPanel(r, {
				submit: function (params) { params.what = "od_pick"; params.id = o.id; return api(params, true); },
				done: function (res) { OD.order = res.order; self.renderOrder(); },
				reset: function (p) {
					spin(true);
					api({ what: "od_pick", id: o.id, lineid: p.line.objectid, reset: 1 }, true).then(function (res) {
						spin(false);
						if (res.ok) { okFeedback(res.message); S.panel = null; OD.order = res.order; self.renderOrder(); } else { koFeedback(res.error); }
					}).catch(fail);
				}
			});
		},
		onScan: function (code) {
			var self = this;
			if (!S.route.kind) {
				spin(true);
				api({ what: "od_find", code: code }).then(function (r) {
					spin(false);
					if (r.ok) { beep(true); go("#/orders/order/" + r.id); } else { koFeedback(r.error); }
				}).catch(fail);
				return;
			}
			if (!(CFG.rights.odWrite && (OD.order.status === 1 || OD.order.status === 2))) { koFeedback("Préparation impossible sur cette commande"); return; }
			spin(true);
			api({ what: "od_scan", id: OD.order.id, code: code }).then(function (r) {
				spin(false);
				if (!r.ok) { koFeedback(r.error); return; }
				beep(true);
				if (r.goto === "order") { go("#/orders/order/" + r.id); return; }
				self.open(r);
			}).catch(fail);
		}
	};

	/* Barres défilantes (filtres, étapes) : dégradé sur le bord quand il reste du contenu caché, onglet actif rendu visible */
	function markOverflow() {
		$("mbxView").querySelectorAll(".search input").forEach(function (i) { i.parentNode.classList.toggle("has", !!i.value); });
		$("mbxView").querySelectorAll(".seg, .stepper").forEach(function (el) {
			var upd = function () {
				var more = el.scrollWidth - el.clientWidth - el.scrollLeft > 4;
				el.classList.toggle("ovf-r", more);
				el.classList.toggle("ovf-l", el.scrollLeft > 4);
			};
			if (!el._mbxOvf) {
				el._mbxOvf = true;
				el.addEventListener("scroll", upd, { passive: true });
				var on = el.querySelector(".on, .cur");
				if (on && on.offsetLeft + on.offsetWidth > el.clientWidth) { el.scrollLeft = on.offsetLeft - 16; }
			}
			upd();
		});
	}
	window.addEventListener("resize", function () { markOverflow(); });
	document.addEventListener("input", function (e) {
		if (e.target.closest && e.target.closest(".search")) { e.target.closest(".search").classList.toggle("has", !!e.target.value); }
	});

	/* État « sélectionné » des boutons segmentés exposé aux lecteurs d'écran */
	if (window.MutationObserver) {
		var syncing = false;
		new MutationObserver(function () {
			if (syncing) { return; }
			syncing = true;
			markOverflow();
			$("mbxView").querySelectorAll(".seg button, .stepper button").forEach(function (b) {
				var v = b.classList.contains("on") || b.classList.contains("cur") ? "true" : "false";
				if (b.getAttribute("aria-pressed") !== v) { b.setAttribute("aria-pressed", v); }
			});
			syncing = false;
		}).observe($("mbxView"), { childList: true, subtree: true });
	}

	/* ================================================================
	 * Démarrage
	 * ================================================================ */
	function keepAwake() {
		if (!DESK && "wakeLock" in navigator) { navigator.wakeLock.request("screen").catch(function () { /* refusé */ }); }
	}
	document.addEventListener("visibilitychange", function () {
		if (document.visibilityState === "visible") {
			keepAwake();
			if (!S.panel && !modalOn() && cur() && cur().refresh) { cur().refresh(); }
		}
	});
	keepAwake();
	route();
	focusScan(true);
})();
