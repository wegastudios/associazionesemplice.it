/* Confronto tra le versioni: un solo script per portale e demo.
 * - Un pulsante con l'attributo data-confronto apre il confronto in un popup.
 * - Un contenitore con data-confronto-inline lo mostra nella pagina (senza JavaScript restano le tabelle complete).
 * Il contenuto arriva sempre da /confronto-demo, cioè dallo stesso testo di /confronto: niente copie da tenere allineate.
 * Di default si vedono solo le funzioni che DIFFERISCONO tra i piani; le comuni stanno dietro «Mostra anche le funzioni comuni».
 * Nel testo una riga può avere «Titolo breve :: dettaglio»; senza «::» un titolo lungo viene diviso in automatico.
 * Nomi, prezzi e pulsanti dei piani arrivano dal portale (attributo data-cmp), così restano quelli del pannello. */
(function () {
	'use strict';
	var base = (function () {
		var s = document.currentScript;
		try { return new URL(s.src).origin; } catch (e) { return ''; }
	})();
	var cache = null;

	var css = '' +
		'.cfr-modal{position:fixed;inset:0;z-index:99999;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(10,12,40,.6);font-family:Montserrat,system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}' +
		'.cfr-modal[hidden]{display:none}' +
		'.cfr-box{background:var(--surface,#fff);color:var(--fg,#14172e);width:min(1040px,100%);max-height:calc(100vh - 32px);border-radius:16px;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.4);overflow:hidden}' +
		'.cfr-head{display:flex;align-items:center;gap:12px;padding:10px 12px 10px 20px;border-bottom:1px solid var(--line,#dbe3de)}' +
		'.cfr-head h2{margin:0;font-size:1.1rem;flex:1}' +
		'.cfr-x{border:0;background:transparent;color:inherit;font-size:28px;line-height:1;cursor:pointer;min-width:44px;min-height:44px;border-radius:10px}' +
		'.cfr-x:hover{background:var(--surface-2,#edf2ef)}' +
		'.cfr-body{overflow:auto;position:relative}' +
		'.cfr{padding:0 20px 24px;font-size:15px;line-height:1.45}' +
		'.cfr button,.cfr input{font:inherit;color:inherit}' +
		'.cfr :focus-visible,.cfr-x:focus-visible{outline:3px solid var(--brand,#1f218c);outline-offset:2px}' +
		'.cfr-lead{margin:18px 0 10px;font-size:1.02rem;font-weight:600}' +
		'.cfr-tools{display:flex;flex-wrap:wrap;gap:10px 18px;align-items:center;margin:0 0 10px}' +
		'.cfr-find{flex:1 1 240px;min-height:44px;padding:0 14px;border:1.5px solid var(--line,#dbe3de);border-radius:12px;background:var(--bg,#fff);box-sizing:border-box}' +
		'.cfr-sw{display:inline-flex;align-items:center;gap:10px;min-height:44px;cursor:pointer;font-weight:600}' +
		'.cfr-sw input{width:20px;height:20px;accent-color:var(--brand,#1f218c)}' +
		'.cfr-chips{display:flex;flex-wrap:wrap;gap:8px;padding:2px 0 12px}' +
		'.cfr-chip{flex:none;min-height:44px;padding:0 16px;border:1px solid var(--line,#dbe3de);border-radius:999px;background:var(--surface,#fff);cursor:pointer;font-weight:600;font-size:14px;white-space:nowrap}' +
		'.cfr-chip[aria-pressed=true]{background:var(--brand,#1f218c);border-color:var(--brand,#1f218c);color:var(--brand-fg,#fff)}' +
		'.cfr-grid{display:grid;grid-template-columns:minmax(0,1.7fr) repeat(3,minmax(0,1fr));column-gap:8px;align-items:center}' +
		'.cfr-plans{position:sticky;top:0;z-index:3;background:var(--surface,#fff);padding:10px 0;border-bottom:2px solid var(--line,#dbe3de);align-items:stretch}' +
		'.cfr-inline .cfr-plans{top:66px}' +
		'.cfr-pick{display:none}' +
		'.cfr-plan{display:flex;flex-direction:column;gap:2px;padding:10px 12px;border:1px solid var(--line,#dbe3de);border-radius:12px;background:var(--surface,#fff);text-align:left}' +
		'.cfr-plan.hot{border:2px solid var(--brand,#1f218c)}' +
		'.cfr-plan b{font-size:1rem;line-height:1.2}' +
		'.cfr-plan .p{font-size:1.35rem;font-weight:700;letter-spacing:-.02em;line-height:1.15}' +
		'.cfr-plan .p small{font-size:.75rem;font-weight:500;color:var(--muted,#566069);letter-spacing:0;margin-left:4px}' +
		'.cfr-plan .p s{font-size:.8rem;font-weight:500;color:var(--muted,#566069);margin-right:6px}' +
		'.cfr-plan .alt{font-size:.78rem;color:var(--muted,#566069);min-height:1.1em}' +
		'.cfr-plan .go{margin-top:8px;display:flex;align-items:center;justify-content:center;min-height:44px;border-radius:10px;background:var(--brand,#1f218c);color:var(--brand-fg,#fff);font-weight:700;text-decoration:none;font-size:.92rem}' +
		'.cfr-plan:not(.hot) .go{background:var(--surface,#fff);color:var(--fg,#14172e);border:1.5px solid var(--line,#dbe3de)}' +
		'.cfr-plan .go:hover{filter:brightness(1.08)}' +
		'.cfr-sec{margin:22px 0 4px;font-size:.8rem;letter-spacing:.06em;text-transform:uppercase;color:var(--muted,#566069);font-weight:700}' +
		'.cfr-sec[hidden],.cfr-row[hidden]{display:none}' +
		'.cfr-row{border-bottom:1px solid var(--line,#dbe3de);padding:4px 0}' +
		'.cfr-row.is-common .cfr-t{color:var(--muted,#566069)}' +
		'.cfr-lab{min-width:0}' +
		'.cfr-t{display:flex;align-items:center;gap:8px;width:100%;min-height:44px;padding:4px 0;border:0;background:none;text-align:left;font-weight:600;cursor:default}' +
		'.cfr-t.has-d{cursor:pointer}' +
		'.cfr-t.has-d::after{content:"+";margin-left:auto;flex:none;width:22px;height:22px;border-radius:50%;background:var(--surface-2,#edf2ef);display:grid;place-items:center;font-weight:700;color:var(--brand,#1f218c)}' +
		'.cfr-t[aria-expanded=true]::after{content:"\\2013"}' +
		'.cfr-d{grid-column:1/-1;margin:0 0 8px;padding:10px 12px;border-radius:10px;background:var(--surface-2,#edf2ef);color:var(--muted,#566069);font-size:14px}' +
		'.cfr-d[hidden]{display:none}' +
		'.cfr-c{text-align:center;font-weight:700}' +
		'.cfr-y{display:inline-grid;place-items:center;width:28px;height:28px;border-radius:50%;background:var(--brand-soft,#e3f3ea);color:var(--ok,#1f6b41);font-size:16px}' +
		'.cfr-n{color:var(--muted,#566069);font-weight:500}' +
		'.cfr-x2{font-weight:600;font-size:13px}' +
		'.cfr-vh{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}' +
		'.cfr-empty{padding:26px 10px;text-align:center;color:var(--muted,#566069)}' +
		'.cfr-extra{margin-top:30px;padding-top:6px}' +
		'.cfr-extra h3{font-size:1.05rem;margin:18px 0 8px}' +
		'.cfr-extra p,.cfr-extra li{color:var(--muted,#566069);font-size:14.5px}' +
		'.cfr-inline .cfr{padding:0}' +
		'@media(max-width:640px){' +
		'.cfr-modal{padding:0}.cfr-box{max-height:100vh;height:100vh;border-radius:0}.cfr{padding:0 14px 28px}' +
		'.cfr-chips{flex-wrap:nowrap;overflow-x:auto;margin:0 -14px;padding:2px 14px 12px;scrollbar-width:none}.cfr-grid{grid-template-columns:minmax(0,1fr) 84px}' +
		'.cfr-pick{display:grid;grid-template-columns:repeat(3,1fr);gap:4px;padding:4px;margin:0 0 8px;border:1px solid var(--line,#dbe3de);border-radius:14px;grid-column:1/-1;background:var(--surface-2,#edf2ef)}' +
		'.cfr-pick button{min-height:44px;border:0;border-radius:10px;background:transparent;font-weight:700;font-size:13px;padding:0 4px;cursor:pointer}' +
		'.cfr-pick button[aria-pressed=true]{background:var(--brand,#1f218c);color:var(--brand-fg,#fff)}' +
		'.cfr-plans{display:block;padding-top:8px}' +
		'.cfr-plans .cfr-sp{display:none}' +
		'.cfr-plan{display:none}.cfr[data-sel="0"] .cfr-plan[data-i="0"],.cfr[data-sel="1"] .cfr-plan[data-i="1"],.cfr[data-sel="2"] .cfr-plan[data-i="2"]{display:grid;grid-template-columns:1fr auto;align-items:center;column-gap:12px}' +
		'.cfr-plan .go{margin-top:0;padding:0 18px;grid-row:1/4;grid-column:2}' +
		'.cfr-c{display:none}.cfr[data-sel="0"] .cfr-c[data-i="0"],.cfr[data-sel="1"] .cfr-c[data-i="1"],.cfr[data-sel="2"] .cfr-c[data-i="2"]{display:block}' +
		'.cfr-inline .cfr-plans{top:66px}' +
		'}';

	function addStyle() {
		if (document.getElementById('cfr-style')) { return; }
		var st = document.createElement('style');
		st.id = 'cfr-style';
		st.textContent = css;
		document.head.appendChild(st);
	}

	function el(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text != null) { n.textContent = text; }
		return n;
	}

	/* Titolo breve e dettaglio: «Titolo :: dettaglio»; altrimenti, se il testo è lungo, si divide alla prima virgola, parentesi o due punti. */
	function splitLabel(t) {
		var i = t.indexOf(' :: ');
		if (i > -1) { return { title: t.slice(0, i).trim(), detail: t.slice(i + 4).trim() }; }
		if (t.length > 70) {
			var m = /[,(:]/.exec(t.slice(18));
			if (m) {
				var cut = 18 + m.index;
				return { title: t.slice(0, cut).trim(), detail: t };
			}
		}
		return { title: t, detail: '' };
	}

	/* Dal contenuto (titoli h2 e tabelle) a una struttura: piani, sezioni con righe, testi finali. */
	function parse(source) {
		var meta = null, raw = source.getAttribute && source.getAttribute('data-cmp');
		if (!raw && source.querySelector) { var m0 = source.querySelector('[data-cmp]'); raw = m0 ? m0.getAttribute('data-cmp') : ''; }
		try { meta = raw ? JSON.parse(raw) : null; } catch (e) { meta = null; }
		var sections = [], extras = [], cur = null, heads = null, i, n;
		for (i = 0; i < source.childNodes.length; i++) {
			n = source.childNodes[i];
			if (n.nodeType !== 1) { continue; }
			if (n.tagName === 'H2') { cur = { title: n.textContent.trim(), rows: [], nodes: [] }; sections.push(cur); continue; }
			if (!cur || n.tagName === 'STYLE' || n.tagName === 'SCRIPT') { continue; }
			var tb = n.querySelector ? n.querySelector('table') : null;
			if (tb) {
				if (!heads) { heads = Array.prototype.map.call(tb.querySelectorAll('thead th'), function (h) { return h.textContent.trim(); }).slice(1); }
				Array.prototype.forEach.call(tb.querySelectorAll('tbody tr'), function (tr) {
					var th = tr.querySelector('th'), tds = tr.querySelectorAll('td');
					if (!th || tds.length < 2) { return; }
					var label = th.textContent.trim();
					if (/^prezzo$/i.test(label)) { return; }
					var cells = Array.prototype.map.call(tds, function (td) { return td.textContent.trim(); });
					var common = cells.every(function (c) { return c === cells[0]; });
					var s = splitLabel(label);
					cur.rows.push({ title: s.title, detail: s.detail, cells: cells, common: common });
				});
			} else {
				cur.nodes.push(n.cloneNode(true));
			}
		}
		var plans = meta && meta.plans ? meta.plans : (heads || []).map(function (h, k) { return { name: h, hot: k === 1 }; });
		var secs = sections.filter(function (s) { return s.rows.length; });
		sections.forEach(function (s) { if (s.nodes.length) { extras.push(s); } });
		return { plans: plans, sections: secs, extras: extras };
	}

	function cellNode(c, i) {
		var d = el('div', 'cfr-c'); d.setAttribute('data-i', i);
		if (c === '✓') { d.appendChild(el('span', 'cfr-y', '✓')); d.appendChild(el('span', 'cfr-vh', 'Incluso')); }
		else if (c === '—') { d.appendChild(el('span', 'cfr-n', '—')); d.appendChild(el('span', 'cfr-vh', 'Non incluso')); }
		else { d.appendChild(el('span', 'cfr-x2', c)); }
		return d;
	}

	function build(source) {
		var data = parse(source), plans = data.plans, np = plans.length || 3;
		var root = el('div', 'cfr');
		if (!data.sections.length) { root.appendChild(source.cloneNode(true)); return root; }
		var hot = 0; plans.forEach(function (p, k) { if (p.hot) { hot = k; } });
		root.setAttribute('data-sel', String(hot));

		var lead = el('p', 'cfr-lead'); root.appendChild(lead);

		/* intestazione fissa: i tre piani (da telefono, un selettore e un piano per volta) */
		var head = el('div', 'cfr-grid cfr-plans');
		var pick = el('div', 'cfr-pick'); pick.setAttribute('role', 'group'); pick.setAttribute('aria-label', 'Scegli il piano da vedere');
		head.appendChild(pick);
		head.appendChild(el('div', 'cfr-sp'));
		var pbtns = [];
		plans.forEach(function (p, k) {
			var b = el('button', null, p.name); b.type = 'button'; b.setAttribute('aria-pressed', k === hot ? 'true' : 'false');
			b.addEventListener('click', function () { select(k); });
			pick.appendChild(b); pbtns.push(b);
			var card = el('div', 'cfr-plan' + (p.hot ? ' hot' : '')); card.setAttribute('data-i', k);
			card.appendChild(el('b', null, p.name));
			if (p.price) {
				var pr = el('div', 'p');
				if (p.was) { pr.appendChild(el('s', null, p.was)); }
				pr.appendChild(document.createTextNode(p.price));
				if (p.per) { pr.appendChild(el('small', null, p.per)); }
				card.appendChild(pr);
				card.appendChild(el('div', 'alt', p.alt || ''));
			}
			if (p.url) {
				var a = el('a', 'go', p.cta || 'Scegli'); a.href = p.url; a.setAttribute('aria-label', (p.cta || 'Scegli') + ' ' + p.name);
				if (base && p.url.indexOf(base) !== 0 && location.origin !== base) { /* link assoluti al portale: nessuna modifica */ }
				card.appendChild(a);
			}
			head.appendChild(card);
		});
		root.appendChild(head);
		function select(k) {
			root.setAttribute('data-sel', String(k));
			pbtns.forEach(function (b, j) { b.setAttribute('aria-pressed', j === k ? 'true' : 'false'); });
		}

		/* strumenti: ricerca, funzioni comuni, sezioni */
		var tools = el('div', 'cfr-tools');
		var find = el('input', 'cfr-find'); find.type = 'search'; find.placeholder = 'Cerca una funzione'; find.setAttribute('aria-label', 'Cerca una funzione');
		var sw = el('label', 'cfr-sw'); var cb = el('input'); cb.type = 'checkbox'; sw.appendChild(cb); sw.appendChild(document.createTextNode('Mostra anche le funzioni comuni'));
		tools.appendChild(find); tools.appendChild(sw);
		root.insertBefore(tools, head);
		var chips = el('div', 'cfr-chips'); chips.setAttribute('role', 'group'); chips.setAttribute('aria-label', 'Filtra per area');
		root.insertBefore(chips, head);
		var only = -1, cbtns = [];
		function chip(label, k) {
			var b = el('button', 'cfr-chip', label); b.type = 'button'; b.setAttribute('aria-pressed', k === -1 ? 'true' : 'false');
			b.addEventListener('click', function () { only = k; cbtns.forEach(function (x, j) { x.setAttribute('aria-pressed', j - 1 === k ? 'true' : 'false'); }); refresh(); });
			chips.appendChild(b); cbtns.push(b);
		}
		chip('Tutte', -1);
		data.sections.forEach(function (s, k) { chip(s.title, k); });

		/* elenco */
		var list = el('div', 'cfr-list'); root.appendChild(list);
		var secEls = [], rowEls = [];
		var diff = 0, same = 0;
		data.sections.forEach(function (s, k) {
			var h = el('h3', 'cfr-sec', s.title); list.appendChild(h);
			var rows = [];
			s.rows.forEach(function (r) {
				if (r.common) { same++; } else { diff++; }
				var row = el('div', 'cfr-row cfr-grid' + (r.common ? ' is-common' : ''));
				var lab = el('div', 'cfr-lab');
				var t = el('button', 'cfr-t' + (r.detail ? ' has-d' : ''), r.title); t.type = 'button';
				lab.appendChild(t);
				row.appendChild(lab);
				r.cells.slice(0, np).forEach(function (c, i) { row.appendChild(cellNode(c, i)); });
				if (r.detail) {
					var d = el('div', 'cfr-d', r.detail); d.hidden = true;
					d.id = 'cfr-d-' + k + '-' + rows.length;
					t.setAttribute('aria-expanded', 'false'); t.setAttribute('aria-controls', d.id);
					t.addEventListener('click', function () { var o = d.hidden; d.hidden = !o; t.setAttribute('aria-expanded', o ? 'true' : 'false'); });
					row.appendChild(d);
				}
				list.appendChild(row);
				rows.push({ el: row, common: r.common, text: (r.title + ' ' + r.detail).toLowerCase() });
			});
			secEls.push(h); rowEls.push(rows);
		});
		var empty = el('p', 'cfr-empty', 'Nessuna funzione trovata.'); empty.hidden = true; root.appendChild(empty);

		function refresh() {
			var q = find.value.trim().toLowerCase(), all = cb.checked, shown = 0;
			rowEls.forEach(function (rows, k) {
				var here = 0;
				rows.forEach(function (r) {
					var ok = (only === -1 || only === k) && (q ? r.text.indexOf(q) > -1 : (all || !r.common));
					r.el.hidden = !ok;
					if (ok) { here++; }
				});
				secEls[k].hidden = !here;
				shown += here;
			});
			empty.hidden = shown > 0;
			lead.textContent = q ? shown + (shown === 1 ? ' funzione trovata' : ' funzioni trovate')
				: (all ? 'Tutte le ' + (diff + same) + ' funzioni, con quelle comuni a tutti i piani.' : diff + ' funzioni cambiano tra le versioni. Le altre ' + same + ' ci sono in tutte.');
		}
		find.addEventListener('input', refresh);
		cb.addEventListener('change', refresh);
		refresh();

		/* testi finali (per esempio «Passare da una versione all'altra») */
		if (data.extras.length) {
			var ex = el('div', 'cfr-extra');
			data.extras.forEach(function (s) {
				ex.appendChild(el('h3', null, s.title));
				s.nodes.forEach(function (n) { ex.appendChild(n); });
			});
			root.appendChild(ex);
		}
		return root;
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
	function focusables() {
		return modal.querySelectorAll('button:not([disabled]):not([hidden]),a[href],input:not([disabled])');
	}
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
			document.addEventListener('keydown', function (e) {
				if (modal.hidden) { return; }
				if (e.key === 'Escape') { close(); return; }
				if (e.key === 'Tab') {
					var f = focusables();
					if (!f.length) { return; }
					var first = f[0], last = f[f.length - 1];
					if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
					else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
				}
			});
		}
		modal.hidden = false;
		document.documentElement.style.overflow = 'hidden';
		var body = modal.querySelector('.cfr-body');
		load(function (src) {
			body.innerHTML = '';
			if (!src) {
				var a = el('p', 'cfr-empty');
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

	function inline(elm) {
		addStyle();
		elm.classList.add('cfr-inline');
		var go = function (src) { if (src) { var b = el('div', 'cfr-body'); b.style.overflow = 'visible'; b.appendChild(build(src)); elm.innerHTML = ''; elm.appendChild(b); } };
		if (elm.children.length) { go(elm); } else { load(go); }
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
