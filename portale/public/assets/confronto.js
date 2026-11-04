/* Confronto tra le versioni: un solo script per portale e demo.
 * - Un pulsante con l'attributo data-confronto apre un popup con una scheda per ogni sezione.
 * - Un contenitore con data-confronto-inline mostra le stesse schede nella pagina (se contiene già le tabelle, le usa; senza JavaScript restano visibili).
 * Il contenuto arriva sempre da /confronto-demo, cioè dallo stesso testo di /confronto: niente copie da tenere allineate. */
(function () {
	'use strict';
	var base = (function () {
		var s = document.currentScript;
		try { return new URL(s.src).origin; } catch (e) { return ''; }
	})();
	var cache = null;

	var css = '' +
		'.cfr-modal{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(10,12,40,.55);font-family:Montserrat,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
		'.cfr-modal[hidden]{display:none}' +
		'.cfr-box{background:var(--surface,#fff);color:var(--fg,#14172e);width:min(960px,100%);max-height:calc(100vh - 32px);border-radius:14px;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.35);overflow:hidden}' +
		'.cfr-head{display:flex;align-items:center;gap:12px;padding:16px 20px;border-bottom:1px solid var(--line,#dbe3de)}' +
		'.cfr-head h2{margin:0;font-size:1.15rem;flex:1}' +
		'.cfr-x{border:0;background:transparent;color:inherit;font-size:26px;line-height:1;cursor:pointer;padding:4px 8px;border-radius:8px}' +
		'.cfr-x:hover{background:var(--surface-2,#edf2ef)}' +
		'.cfr-body{padding:14px 20px 20px;overflow:auto}' +
		'.cfr-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:0 0 12px}' +
		'.cfr-tab{border:1px solid var(--line,#dbe3de);background:var(--surface,#fff);color:inherit;font:600 14px/1 inherit;padding:9px 13px;border-radius:999px;cursor:pointer}' +
		'.cfr-tab[aria-selected=true]{background:var(--brand,#1f218c);border-color:var(--brand,#1f218c);color:var(--brand-fg,#fff)}' +
		'.cfr-find{width:100%;box-sizing:border-box;margin:0 0 12px;padding:10px 12px;border:1px solid var(--line,#dbe3de);border-radius:10px;font:inherit;background:var(--surface,#fff);color:inherit}' +
		'.cfr-note{font-size:14px;color:var(--muted,#566069);margin:0 0 12px}' +
		'.cfr-panel[hidden]{display:none}' +
		'.cfr-panel .cmp-wrap{overflow-x:auto;margin:0}' +
		'.cfr-panel table{width:100%;border-collapse:collapse;font-size:15px}' +
		'.cfr-panel th,.cfr-panel td{padding:9px 12px;border-bottom:1px solid var(--line,#dbe3de);text-align:center;vertical-align:top}' +
		'.cfr-panel thead th{font-weight:700;white-space:nowrap;position:sticky;top:0;background:var(--surface,#fff)}' +
		'.cfr-panel tbody th{text-align:left;font-weight:500}.cfr-panel thead th:first-child{text-align:left}' +
		'.cfr-panel td.yes{color:#2f7d4f;font-weight:700}.cfr-panel td.no{color:#8b90a0}' +
		'.cfr-panel tr.cfr-off{display:none}' +
		'.cfr-empty{padding:18px;text-align:center;color:var(--muted,#566069)}' +
		'.cfr-inline .cfr-body{padding:0}' +
		'@media(max-width:600px){.cfr-box{max-height:100vh;border-radius:0}.cfr-modal{padding:0}.cfr-panel th,.cfr-panel td{padding:8px 6px;font-size:14px}}';

	function addStyle() {
		if (document.getElementById('cfr-style')) { return; }
		var st = document.createElement('style');
		st.id = 'cfr-style';
		st.textContent = css;
		document.head.appendChild(st);
	}

	/* Dal contenuto (titoli h2 e tabelle) alle schede: ogni h2 apre una sezione, il testo prima del primo h2 è la legenda. */
	function build(source) {
		var wrap = document.createElement('div');
		wrap.className = 'cfr-body-in';
		var intro = [], sections = [], cur = null, i, n;
		for (i = 0; i < source.childNodes.length; i++) {
			n = source.childNodes[i];
			if (n.nodeType !== 1) { continue; }
			if (n.tagName === 'H2') { cur = { title: n.textContent, nodes: [] }; sections.push(cur); }
			else if (n.tagName === 'STYLE' || n.tagName === 'SCRIPT') { continue; }
			else if (cur) { cur.nodes.push(n.cloneNode(true)); }
			else { intro.push(n.cloneNode(true)); }
		}
		if (!sections.length) { wrap.appendChild(source.cloneNode(true)); return wrap; }
		for (i = 0; i < intro.length; i++) {
			if (intro[i].tagName === 'P') { intro[i].className = 'cfr-note'; wrap.appendChild(intro[i]); }
		}
		var find = document.createElement('input');
		find.type = 'search'; find.className = 'cfr-find'; find.placeholder = 'Cerca una funzione in tutte le sezioni…'; find.setAttribute('aria-label', 'Cerca una funzione');
		wrap.appendChild(find);
		var tabs = document.createElement('div');
		tabs.className = 'cfr-tabs'; tabs.setAttribute('role', 'tablist');
		wrap.appendChild(tabs);
		var panels = [], btns = [];
		sections.forEach(function (s, k) {
			var b = document.createElement('button');
			b.type = 'button'; b.className = 'cfr-tab'; b.setAttribute('role', 'tab'); b.textContent = s.title;
			tabs.appendChild(b); btns.push(b);
			var p = document.createElement('div');
			p.className = 'cfr-panel'; p.setAttribute('role', 'tabpanel');
			s.nodes.forEach(function (x) { p.appendChild(x); });
			wrap.appendChild(p); panels.push(p);
			b.addEventListener('click', function () { show(k); });
		});
		var empty = document.createElement('p');
		empty.className = 'cfr-empty'; empty.hidden = true; empty.textContent = 'Nessuna funzione trovata.';
		wrap.appendChild(empty);
		function show(k) {
			btns.forEach(function (b, j) { b.setAttribute('aria-selected', j === k ? 'true' : 'false'); panels[j].hidden = j !== k; });
		}
		show(0);
		find.addEventListener('input', function () {
			var q = find.value.trim().toLowerCase(), hits = 0, first = -1;
			panels.forEach(function (p, j) {
				var rows = p.querySelectorAll('tbody tr'), here = 0;
				Array.prototype.forEach.call(rows, function (r) {
					var ok = !q || r.textContent.toLowerCase().indexOf(q) > -1;
					r.className = ok ? '' : 'cfr-off';
					if (ok) { here++; }
				});
				btns[j].style.display = (q && !here) ? 'none' : '';
				if (here && first < 0) { first = j; }
				hits += here;
			});
			empty.hidden = !q || hits > 0;
			if (q && first > -1) {
				var cur2 = btns.findIndex ? btns.findIndex(function (b) { return b.getAttribute('aria-selected') === 'true'; }) : 0;
				if (btns[cur2] && btns[cur2].style.display === 'none') { show(first); }
			}
		});
		return wrap;
	}

	function load(done) {
		if (cache) { done(cache); return; }
		var x = new XMLHttpRequest();
		x.open('GET', base + '/confronto-demo');
		x.onload = function () {
			var d = document.createElement('div');
			d.innerHTML = x.status === 200 ? x.responseText : '';
			var root = d.querySelector('.cmp-page') || d;
			cache = root.children.length ? root : null;
			done(cache);
		};
		x.onerror = function () { done(null); };
		x.send();
	}

	var modal = null, lastFocus = null;
	function open() {
		addStyle();
		lastFocus = document.activeElement;
		if (!modal) {
			modal = document.createElement('div');
			modal.className = 'cfr-modal'; modal.hidden = true;
			modal.innerHTML = '<div class="cfr-box" role="dialog" aria-modal="true" aria-label="Confronto tra le versioni"><div class="cfr-head"><h2>Confronto tra le versioni</h2><button type="button" class="cfr-x" aria-label="Chiudi">&times;</button></div><div class="cfr-body"><p class="cfr-empty">Un momento…</p></div></div>';
			document.body.appendChild(modal);
			modal.addEventListener('click', function (e) { if (e.target === modal) { close(); } });
			modal.querySelector('.cfr-x').addEventListener('click', close);
			document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !modal.hidden) { close(); } });
		}
		modal.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		var body = modal.querySelector('.cfr-body');
		load(function (src) {
			body.innerHTML = '';
			if (!src) {
				var a = document.createElement('p');
				a.className = 'cfr-empty';
				a.innerHTML = 'Non riesco a caricare il confronto. <a href="' + base + '/confronto">Aprilo qui</a>.';
				body.appendChild(a);
				return;
			}
			body.appendChild(build(src));
		});
		modal.querySelector('.cfr-x').focus();
	}
	function close() {
		if (!modal) { return; }
		modal.hidden = true;
		document.documentElement.style.overflow = '';
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	function inline(el) {
		addStyle();
		el.classList.add('cfr-inline');
		var go = function (src) { if (src) { var b = document.createElement('div'); b.className = 'cfr-body'; b.appendChild(build(src)); el.innerHTML = ''; el.appendChild(b); } };
		if (el.children.length) { go(el); } else { load(go); }
	}

	function init() {
		document.addEventListener('click', function (e) {
			var t = e.target.closest ? e.target.closest('[data-confronto]') : null;
			if (t) { e.preventDefault(); open(); }
		});
		Array.prototype.forEach.call(document.querySelectorAll('[data-confronto-inline]'), inline);
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', init); } else { init(); }
})();
