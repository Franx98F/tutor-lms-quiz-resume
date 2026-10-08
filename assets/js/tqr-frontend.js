/**
 * Tutor Quiz Resume - front-end.
 *
 * Indipendente dal markup di una specifica versione di Tutor LMS:
 *  - il form del quiz si trova per selettori noti OPPURE dai nomi dei campi
 *    risposta (`attempt[ID][quiz_question][QID]...`), stabili dalla 1.x alla 4.x;
 *  - i valori ripristinati vengono comunicati al quiz con eventi `input`/`change`,
 *    quindi funzionano sia con il form classico (jQuery) sia con quello
 *    Alpine.js della 4.x;
 *  - la navigazione alla prima domanda senza risposta usa l'adattatore giusto
 *    (Modern -> API Alpine `goTo`, Legacy -> show/hide), e se nessuno dei due
 *    corrisponde si limita a scorrere la pagina.
 * Ogni funzione accessoria è opzionale: se un pezzo non viene riconosciuto,
 * il salvataggio delle risposte continua a funzionare.
 */
(function () {
	'use strict';

	var CFG = window.TQR_CONFIG || {};
	if (!CFG.ajaxurl || !CFG.nonce) return;

	var SEL = CFG.selectors || {};
	var FORM_SELECTORS  = SEL.forms || ['form[id^="quiz-attempt-form-"]', 'form#tutor-answering-quiz', 'form.tutor-quiz-submission'];
	var MODERN_QUESTION = SEL.modernQuestion || '[data-quiz-question-index]';
	var LEGACY_QUESTION = SEL.legacyQuestion || '.quiz-attempt-single-question';
	var LEGACY_COUNTER  = SEL.legacyCounter || '.tutor-quiz-question-counter';
	var LEGACY_LEAVE    = SEL.legacyLeave || '#tutor-popup-leave';

	var FIELD_RE   = /^attempt\[(\d+)\]\[quiz_question\]\[(\d+)\]/;
	var LS_PREFIX  = 'tutor_quiz_draft_';
	var LS_MAX_AGE = 30 * 24 * 3600 * 1000;
	var DRAFT_V    = 2;

	function log() {
		if (!CFG.debug || !window.console) return;
		console.log.apply(console, ['[TQR]'].concat([].slice.call(arguments)));
	}

	function post(action, extra) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', CFG.nonce);
		for (var k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) body.set(k, extra[k]);
		return fetch(CFG.ajaxurl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		});
	}

	function beacon(action, extra) {
		try {
			var fd = new FormData();
			fd.append('action', action);
			fd.append('nonce', CFG.nonce);
			for (var k in extra) if (Object.prototype.hasOwnProperty.call(extra, k)) fd.append(k, extra[k]);
			return navigator.sendBeacon(CFG.ajaxurl, fd);
		} catch (e) { return false; }
	}

	/* ------------------------------------------------------- Rilevamento */

	function answerFields(root) {
		var out = [];
		var els = root.querySelectorAll('input[name], textarea[name], select[name]');
		for (var i = 0; i < els.length; i++) {
			if (FIELD_RE.test(els[i].name)) out.push(els[i]);
		}
		return out;
	}

	function isAlpineForm(form) {
		return form.hasAttribute('x-data');
	}

	function alpineReady(form) {
		return !isAlpineForm(form) || !!form._x_dataStack;
	}

	function alpineData(form) {
		try {
			if (window.Alpine && typeof window.Alpine.$data === 'function') return window.Alpine.$data(form);
		} catch (e) {}
		return form._x_dataStack ? form._x_dataStack[0] : null;
	}

	/** Restituisce il form solo quando è pronto (campi presenti, Alpine inizializzato). */
	function findForm() {
		var i, el;
		for (i = 0; i < FORM_SELECTORS.length; i++) {
			try { el = document.querySelector(FORM_SELECTORS[i]); } catch (e) { el = null; }
			if (el && el.tagName === 'FORM' && alpineReady(el) && answerFields(el).length) {
				log('form trovato con selettore', FORM_SELECTORS[i]);
				return el;
			}
		}
		// Ripiego indipendente dal markup: il form che contiene i campi risposta.
		var any = answerFields(document)[0];
		var form = any ? (any.form || any.closest('form')) : null;
		if (form && alpineReady(form)) {
			log('form trovato dai nomi dei campi', form);
			return form;
		}
		return null;
	}

	function resolveAttemptId(form) {
		var f = answerFields(form)[0];
		var m = f ? FIELD_RE.exec(f.name) : null;
		if (m) return m[1];
		var hidden = form.querySelector('[name="attempt_id"]');
		if (hidden && hidden.value) return hidden.value;
		return CFG.attemptId ? String(CFG.attemptId) : '';
	}

	/* -------------------------------------------------- Pulizia locale */

	function purgeOldLocalDrafts() {
		try {
			var now = Date.now();
			for (var i = localStorage.length - 1; i >= 0; i--) {
				var k = localStorage.key(i);
				if (!k || k.indexOf(LS_PREFIX) !== 0) continue;
				var d = null;
				try { d = JSON.parse(localStorage.getItem(k)); } catch (e) {}
				if (!d || !d.ts || now - d.ts > LS_MAX_AGE) localStorage.removeItem(k);
			}
		} catch (e) {}
	}

	/* ------------------------------------------------------------- Setup */

	function setup(form, attemptId) {
		var LS_KEY = LS_PREFIX + attemptId;
		var saveTimer = null, submitting = false, submitTimer = null, restoring = false;

		function normName(name) { return name.replace(/^attempt\[\d+\]/, 'attempt[]'); }

		function isChoice(el) { return el.type === 'radio' || el.type === 'checkbox'; }

		function isTrackable(el) {
			return el.type !== 'hidden' && el.type !== 'submit' && el.type !== 'button' && el.type !== 'file' && !el.disabled;
		}

		/**
		 * Chiave stabile per ogni campo. Le scelte usano nome+valore; i campi di
		 * testo usano nome+posizione, perché più caselle possono condividere lo
		 * stesso nome (es. riempimento spazi nella versione Legacy).
		 */
		function eachField(cb) {
			var seen = {};
			answerFields(form).forEach(function (el) {
				if (!isTrackable(el)) return;
				var base = normName(el.name);
				if (isChoice(el)) {
					cb(el, base + '||' + (el.value || ''), null);
				} else {
					var n = seen[base] = (seen[base] || 0) + 1;
					cb(el, base + '#' + (n - 1), base);
				}
			});
		}

		function snapshot() {
			var data = { v: DRAFT_V, ts: Date.now(), fields: {} };
			eachField(function (el, key) {
				data.fields[key] = isChoice(el) ? (el.checked ? 1 : 0) : el.value;
			});
			return data;
		}

		function fire(el) {
			el.dispatchEvent(new Event('input', { bubbles: true }));
			el.dispatchEvent(new Event('change', { bubbles: true }));
		}

		/** Ritorna true se nella bozza c'era almeno una risposta da ripristinare. */
		function applyAnswers(data) {
			if (!data || !data.fields) return false;
			var legacyDraft = data.v !== DRAFT_V; // bozze salvate dalla 1.x del plugin
			var did = false;
			restoring = true;
			try {
				eachField(function (el, key, base) {
					if (isChoice(el)) {
						var want = data.fields[key];
						if (want === 1) did = true;
						// I radio si deselezionano da soli quando se ne seleziona un altro;
						// deselezionarli a mano confonderebbe lo stato del form Alpine.
						var uncheck = want === 0 && el.checked && el.type === 'checkbox';
						if ((want === 1 && !el.checked) || uncheck) {
							el.checked = want === 1; fire(el);
						}
						return;
					}
					var val = data.fields[key];
					if (val === undefined && legacyDraft && base) val = data.fields[base];
					if (typeof val === 'string' && val !== '') {
						did = true;
						if (el.value !== val) { el.value = val; fire(el); }
					}
				});
			} finally {
				restoring = false;
			}
			return did;
		}

		/* --- Domande: raggruppa i campi per ID domanda, in ordine di pagina --- */

		function questions() {
			var order = [], map = {};
			answerFields(form).forEach(function (el) {
				var qid = FIELD_RE.exec(el.name)[2];
				if (!map[qid]) { map[qid] = []; order.push(qid); }
				map[qid].push(el);
			});
			return order.map(function (qid) { return { id: qid, fields: map[qid] }; });
		}

		function isAnswered(q) {
			for (var i = 0; i < q.fields.length; i++) {
				var el = q.fields[i];
				if (!isTrackable(el)) continue;
				if (isChoice(el) ? el.checked : (el.value || '').trim() !== '') return true;
			}
			return false;
		}

		function firstUnanswered() {
			var qs = questions();
			for (var i = 0; i < qs.length; i++) if (!isAnswered(qs[i])) return qs[i];
			return qs.length ? qs[qs.length - 1] : null;
		}

		/* --- Navigazione: adattatori Modern / Legacy / generico --- */

		function legacySyncCounter() {
			var counter = document.querySelector(LEGACY_COUNTER);
			if (!counter) return;
			var qs = form.querySelectorAll(LEGACY_QUESTION);
			for (var i = 0; i < qs.length; i++) {
				if (qs[i].offsetParent === null) continue;
				var idx = qs[i].getAttribute('data-question_index');
				var span = counter.querySelector('span');
				if (idx && span) span.textContent = idx;
				return;
			}
		}

		function goToQuestion(q) {
			if (!q || !q.fields.length) return;
			var anchor = q.fields[0];

			// Tutor 4.x Modern/Kids: usa l'API del componente Alpine.
			var modern = anchor.closest(MODERN_QUESTION);
			var data = modern ? alpineData(form) : null;
			if (modern && data && typeof data.goTo === 'function') {
				var idx = parseInt(modern.getAttribute('data-quiz-question-index'), 10);
				if (idx && idx !== data.currentIndex) data.goTo(idx);
				log('navigazione (modern) alla domanda', idx);
				return;
			}

			// Tutor Legacy: una domanda visibile alla volta.
			var legacy = anchor.closest(LEGACY_QUESTION);
			if (legacy) {
				var all = form.querySelectorAll(LEGACY_QUESTION);
				for (var i = 0; i < all.length; i++) all[i].style.display = 'none';
				legacy.style.display = 'block';
				legacy.scrollIntoView({ block: 'start' });
				legacySyncCounter();
				log('navigazione (legacy) alla domanda', q.id);
				return;
			}

			// Markup sconosciuto: niente cambi di stato, solo scroll.
			anchor.scrollIntoView({ block: 'center' });
			log('navigazione (generica) alla domanda', q.id);
		}

		/* --- Salvataggi --- */

		function saveLocal(snap) { try { localStorage.setItem(LS_KEY, JSON.stringify(snap)); } catch (e) {} }

		function saveDebounced() {
			if (submitting || restoring) return;
			var snap = snapshot();
			saveLocal(snap);
			clearTimeout(saveTimer);
			saveTimer = setTimeout(function () {
				post('tqr_save_draft', { attempt_id: attemptId, draft: JSON.stringify(snap) }).catch(function () {});
			}, 800);
		}

		function saveOnExit() {
			if (submitting) return;
			clearTimeout(saveTimer);
			var snap = snapshot();
			saveLocal(snap);
			beacon('tqr_save_draft', { attempt_id: attemptId, draft: JSON.stringify(snap) });
		}

		/* --- Ripristino: subito dal browser (stesso device), poi dal server --- */

		var restored = false, localData = null;
		try { localData = JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) {}
		if (localData && applyAnswers(localData)) restored = true;

		function finishRestore() {
			if (restored) setTimeout(function () { goToQuestion(firstUnanswered()); }, 300);
			log('ripristino completato', { attemptId: attemptId, restored: restored });
		}

		post('tqr_get_draft', { attempt_id: attemptId })
			.then(function (r) { return r.json(); })
			.then(function (res) {
				var raw = (res && res.success && res.data) ? res.data.draft : '';
				var server = null;
				if (raw) { try { server = JSON.parse(raw); } catch (e) {} }
				var localTs = localData ? (localData.ts || 0) : 0;
				var serverTs = server ? (server.ts || 0) : 0;
				if (server && serverTs >= localTs) {
					// Il server vince solo se più recente della copia locale.
					if (applyAnswers(server)) restored = true;
				} else if (localData && localTs > serverTs) {
					// La copia locale è più recente (es. salvataggio in uscita perso):
					// la rimandiamo al server per gli altri dispositivi.
					post('tqr_save_draft', { attempt_id: attemptId, draft: JSON.stringify(localData) }).catch(function () {});
				}
				finishRestore();
			})
			.catch(finishRestore);

		/* --- Listener --- */

		form.addEventListener('input', saveDebounced, true);
		form.addEventListener('change', saveDebounced, true);

		// Durante l'invio finale non salviamo più: la bozza verrà cancellata.
		form.addEventListener('submit', function () {
			submitting = true;
			clearTimeout(saveTimer);
			clearTimeout(submitTimer);
			// Se l'invio viene bloccato (es. domande obbligatorie), riattiva i salvataggi.
			submitTimer = setTimeout(function () { submitting = false; }, 8000);
		}, true);

		window.addEventListener('pagehide', saveOnExit);
		document.addEventListener('visibilitychange', function () {
			if (document.visibilityState === 'hidden') saveOnExit();
		});

		// Contatore della versione Legacy: segue la domanda visibile.
		var legacyQs = form.querySelectorAll(LEGACY_QUESTION);
		if (legacyQs.length && window.MutationObserver) {
			var syncTimer = null;
			var mo = new MutationObserver(function () {
				clearTimeout(syncTimer);
				syncTimer = setTimeout(legacySyncCounter, 30);
			});
			for (var i = 0; i < legacyQs.length; i++) mo.observe(legacyQs[i], { attributes: true, attributeFilter: ['style'] });
		}

		// Legacy: "Sì, lascia quiz" porta alla pagina del corso invece di ricaricare
		// il quiz. La mancata consegna è comunque garantita lato server.
		document.addEventListener('click', function (e) {
			var btn = e.target && e.target.closest ? e.target.closest(LEGACY_LEAVE) : null;
			if (!btn) return;
			e.preventDefault();
			e.stopImmediatePropagation();
			saveOnExit();
			window.location.href = CFG.leaveUrl || document.referrer || window.location.origin;
		}, true);

		log('attivo', { attemptId: attemptId, ui: isAlpineForm(form) ? 'modern' : 'legacy', tutor: CFG.tutorVersion });
	}

	/* -------------------------------------------------------------- Avvio */

	purgeOldLocalDrafts();

	var tries = 0, MAX_TRIES = 40; // ~10 secondi
	(function init() {
		var form = findForm();
		if (form) {
			var attemptId = resolveAttemptId(form);
			if (attemptId) { setup(form, attemptId); return; }
		}
		if (++tries < MAX_TRIES) { setTimeout(init, 250); return; }

		log('form del quiz non trovato');
		// Il server sa che c'è un tentativo aperto ma il form non si trova:
		// probabile cambio di markup dopo un aggiornamento di Tutor.
		if (CFG.attemptId) {
			post('tqr_report', { kind: 'form_not_found', url: window.location.href.split('#')[0] }).catch(function () {});
		}
	})();
})();
