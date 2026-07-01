<?php
/**
 * Plugin Name: Tutor Quiz Resume
 * Description: Rende i quiz Tutor LMS riprendibili
 * Version:     1.6
 * Author:      Francesco
 *
 * 
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Tutor_Quiz_Resume {

	const META_PREFIX = '_tutor_quiz_draft_';
	const MAX_BYTES   = 200000;
	const CRON_HOOK   = 'tqr_gc_event';

	public function __construct() {
		add_action( 'wp_footer', array( $this, 'print_frontend' ), 99 );
		add_action( 'wp_ajax_tqr_get_draft',  array( $this, 'ajax_get_draft' ) );
		add_action( 'wp_ajax_tqr_save_draft', array( $this, 'ajax_save_draft' ) );
		add_action( 'tutor_quiz/attempt_ended', array( $this, 'clear_on_attempt_ended' ), 10, 1 );

		add_action( self::CRON_HOOK, array( $this, 'gc_orphan_drafts' ) );
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	public static function on_deactivate() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	private function meta_key( $attempt_id ) {
		return self::META_PREFIX . (int) $attempt_id;
	}

	private function valid_attempt( $attempt_id ) {
		$attempt_id = (int) $attempt_id;
		if ( ! $attempt_id || ! function_exists( 'tutor_utils' ) ) {
			return 0;
		}
		$attempt = tutor_utils()->get_attempt( $attempt_id );
		if ( ! $attempt ) {
			return 0;
		}
		$owner = isset( $attempt->user_id ) ? (int) $attempt->user_id : 0;
		return ( $owner === get_current_user_id() ) ? $attempt_id : 0;
	}

	/* ------------------------------------------------------------------ AJAX */

	public function ajax_get_draft() {
		check_ajax_referer( 'tqr_nonce', 'nonce' );
		$attempt_id = $this->valid_attempt( $_POST['attempt_id'] ?? 0 );
		if ( ! $attempt_id ) {
			wp_send_json_error();
		}
		$draft = get_user_meta( get_current_user_id(), $this->meta_key( $attempt_id ), true );
		wp_send_json_success( array( 'draft' => $draft ? $draft : '' ) );
	}

	public function ajax_save_draft() {
		check_ajax_referer( 'tqr_nonce', 'nonce' );
		$attempt_id = $this->valid_attempt( $_POST['attempt_id'] ?? 0 );
		$draft      = (string) wp_unslash( $_POST['draft'] ?? '' );

		if ( ! $attempt_id ) {
			wp_send_json_error();
		}
		if ( strlen( $draft ) > self::MAX_BYTES ) {
			wp_send_json_error( array( 'reason' => 'too_big' ) );
		}
		$decoded = json_decode( $draft, true );
		if ( null === $decoded && 'null' !== trim( $draft ) ) {
			wp_send_json_error( array( 'reason' => 'invalid_json' ) );
		}
		update_user_meta( get_current_user_id(), $this->meta_key( $attempt_id ), wp_slash( $draft ) );
		wp_send_json_success();
	}

	/* --------------------------------------------------------------- Cleanup */

	public function clear_on_attempt_ended( $attempt_id ) {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return;
		}
		$attempt = tutor_utils()->get_attempt( $attempt_id );
		if ( ! $attempt ) {
			return;
		}
		$user_id = isset( $attempt->user_id ) ? (int) $attempt->user_id : 0;
		if ( $user_id ) {
			delete_user_meta( $user_id, $this->meta_key( (int) $attempt_id ) );
		}
	}

	public function gc_orphan_drafts() {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return;
		}
		global $wpdb;
		$like = $wpdb->esc_like( self::META_PREFIX ) . '%';
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $like )
		);
		if ( empty( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			$attempt_id = (int) substr( $row->meta_key, strlen( self::META_PREFIX ) );
			$keep       = false;
			if ( $attempt_id ) {
				$attempt = tutor_utils()->get_attempt( $attempt_id );
				if ( $attempt && isset( $attempt->attempt_status ) && 'attempt_started' === $attempt->attempt_status ) {
					$keep = true;
				}
			}
			if ( ! $keep ) {
				delete_user_meta( (int) $row->user_id, $row->meta_key );
			}
		}
	}

	/* -------------------------------------------------------------- Frontend */

	public function print_frontend() {
		if ( ! is_user_logged_in() || ! function_exists( 'tutor' ) ) {
			return;
		}
		$cfg = array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'tqr_nonce' ),
		);
		?>
		<script>
		(function () {
			var TQR = <?php echo wp_json_encode( $cfg ); ?>;

			/* ===== Selettori (modificabili) ===== */
			var FORM_SELECTORS = [
				'form#tutor-answering-quiz',
				'form.tutor-quiz-answer-form',
				'.tutor-quiz-wrapper form'
			];
			var QUESTION_SELECTOR = '.quiz-attempt-single-question';
			var COUNTER_SELECTOR  = '.tutor-quiz-question-counter';
			var LEAVE_SELECTOR    = '#tutor-popup-leave';
			/* ==================================== */

			function pick(sels) {
				for (var i = 0; i < sels.length; i++) {
					var el = document.querySelector(sels[i]);
					if (el) return el;
				}
				return null;
			}

			function normName(name) { return name.replace(/^attempt\[\d+\]/, 'attempt[]'); }

			function post(action, extra) {
				var body = new URLSearchParams();
				body.set('action', action);
				body.set('nonce', TQR.nonce);
				for (var k in extra) body.set(k, extra[k]);
				return fetch(TQR.ajaxurl, {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: body.toString()
				});
			}

			function leaveUrl() {
				var p = location.pathname;
				var i = p.indexOf('/tutor_quiz/');
				if (i !== -1) return location.origin + p.substring(0, i) + '/';
				return document.referrer || location.origin;
			}

			function setup(form) {
				var attInput  = form.querySelector('[name="attempt_id"]');
				var attemptId = attInput ? attInput.value : '';
				if (!attemptId) return;
				var LS_KEY    = 'tutor_quiz_draft_' + attemptId;
				var saveTimer = null, syncTimer = null, positioned = false, restored = false;

				function visibleQuestion() {
					var qs = form.querySelectorAll(QUESTION_SELECTOR);
					for (var i = 0; i < qs.length; i++) {
						var st = qs[i].getAttribute('style') || '';
						if (qs[i].offsetParent !== null || /display\s*:\s*block/.test(st)) return qs[i];
					}
					return null;
				}

				function syncCounter() {
					var q = visibleQuestion();
					if (!q) return;
					var idx = q.getAttribute('data-question_index');
					if (!idx) return;
					var counter = document.querySelector(COUNTER_SELECTOR);
					if (!counter) return;
					var span = counter.querySelector('span');
					if (span) span.textContent = idx;
				}

				function isAnswered(qDiv) {
					var inputs = qDiv.querySelectorAll('input, textarea, select');
					for (var i = 0; i < inputs.length; i++) {
						var el = inputs[i];
						if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') continue;
						if (el.type === 'radio' || el.type === 'checkbox') { if (el.checked) return true; }
						else if ((el.value || '').trim() !== '') return true;
					}
					return false;
				}

				function firstUnanswered() {
					var qs = form.querySelectorAll(QUESTION_SELECTOR);
					for (var i = 0; i < qs.length; i++) { if (!isAnswered(qs[i])) return qs[i]; }
					return qs.length ? qs[qs.length - 1] : null;
				}

				function goToQuestion(qDiv) {
					if (!qDiv) return;
					form.querySelectorAll(QUESTION_SELECTOR).forEach(function (q) { q.style.display = 'none'; });
					qDiv.style.display = 'block';
					qDiv.scrollIntoView({ block: 'start' });
					syncCounter();
				}

				// Ritorna true se ha riempito almeno un campo.
				function applyAnswers(data) {
					if (!data || !data.fields) return false;
					var did = false;
					form.querySelectorAll('input, textarea, select').forEach(function (el) {
						if (!el.name || el.type === 'hidden') return;
						var key = normName(el.name);
						if (el.type === 'radio' || el.type === 'checkbox') {
							if (data.fields[key + '||' + (el.value || '')] === 1) {
								el.checked = true; did = true;
								el.dispatchEvent(new Event('change', { bubbles: true }));
							}
						} else if (data.fields[key] !== undefined && data.fields[key] !== '') {
							el.value = data.fields[key]; did = true;
							el.dispatchEvent(new Event('input', { bubbles: true }));
						}
					});
					return did;
				}

				// Riposiziona SOLO se stiamo davvero riprendendo qualcosa.
				// Un quiz nuovo resta identico allo stock Tutor.
				function positionOnce() {
					if (positioned) return;
					positioned = true;
					if (!restored) return;
					setTimeout(function () { goToQuestion(firstUnanswered()); }, 450);
				}

				function snapshot() {
					var data = { fields: {}, ts: Date.now() };
					form.querySelectorAll('input, textarea, select').forEach(function (el) {
						if (!el.name || el.type === 'hidden' || el.type === 'submit' || el.type === 'button') return;
						var key = normName(el.name);
						if (el.type === 'radio' || el.type === 'checkbox') {
							data.fields[key + '||' + (el.value || '')] = el.checked ? 1 : 0;
						} else {
							data.fields[key] = el.value;
						}
					});
					return data;
				}

				function saveLocal(snap) { try { localStorage.setItem(LS_KEY, JSON.stringify(snap)); } catch (e) {} }

				function saveDebounced() {
					var snap = snapshot();
					saveLocal(snap);
					clearTimeout(saveTimer);
					saveTimer = setTimeout(function () {
						post('tqr_save_draft', { attempt_id: attemptId, draft: JSON.stringify(snap) });
					}, 800);
				}

				function saveOnExit() {
					var snap = snapshot();
					saveLocal(snap);
					try {
						var fd = new FormData();
						fd.append('action', 'tqr_save_draft');
						fd.append('nonce', TQR.nonce);
						fd.append('attempt_id', attemptId);
						fd.append('draft', JSON.stringify(snap));
						navigator.sendBeacon(TQR.ajaxurl, fd);
					} catch (e) {}
				}

				/* --- Ripristino: subito dal browser (stesso device), poi il server (cross-device) --- */
				var localData = null;
				try { localData = JSON.parse(localStorage.getItem(LS_KEY) || 'null'); } catch (e) {}
				if (localData && applyAnswers(localData)) restored = true;

				post('tqr_get_draft', { attempt_id: attemptId })
					.then(function (r) { return r.json(); })
					.then(function (res) {
						var draft = (res && res.success && res.data) ? res.data.draft : '';
						if (draft) {
							var server = null;
							try { server = JSON.parse(draft); } catch (e) {}
							// Il server vince solo se piu' recente del locale.
							if (server && (!localData || (server.ts || 0) >= (localData.ts || 0))) {
								if (applyAnswers(server)) restored = true;
							}
						}
						positionOnce();
					})
					.catch(function () { positionOnce(); });

				/* --- Il contatore segue la domanda visibile --- */
				try {
					var mo = new MutationObserver(function () {
						clearTimeout(syncTimer);
						syncTimer = setTimeout(syncCounter, 30);
					});
					form.querySelectorAll(QUESTION_SELECTOR).forEach(function (q) {
						mo.observe(q, { attributes: true, attributeFilter: ['style'] });
					});
				} catch (e) {}

				/* --- Salvataggi --- */
				form.addEventListener('input', saveDebounced, true);
				form.addEventListener('change', saveDebounced, true);
				window.addEventListener('pagehide', saveOnExit);
				window.addEventListener('beforeunload', saveOnExit);

				/* --- "Sì, lascia quiz": non inviare, salva ed esci --- */
				document.addEventListener('click', function (e) {
					if (!e.target || !e.target.closest) return;
					var btn = e.target.closest(LEAVE_SELECTOR);
					if (!btn) return;
					e.preventDefault();
					e.stopImmediatePropagation();
					saveOnExit();
					window.location.href = leaveUrl();
				}, true);
			}

			var tries = 0;
			(function init() {
				var form = pick(FORM_SELECTORS);
				if (form) { setup(form); return; }
				if (++tries < 20) setTimeout(init, 250);
			})();
		})();
		</script>
		<?php
	}
}

register_deactivation_hook( __FILE__, array( 'Tutor_Quiz_Resume', 'on_deactivate' ) );
new Tutor_Quiz_Resume();