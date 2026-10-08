<?php
/**
 * Logica principale: salvataggio bozze, blocco dell'abbandono, pulizia,
 * avvisi amministrativi.
 *
 * @package Tutor_Quiz_Resume
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TQR_Plugin {

	const META_PREFIX   = '_tutor_quiz_draft_';
	const MAX_BYTES     = 200000;
	const CRON_HOOK     = 'tqr_gc_event';
	const NONCE         = 'tqr_nonce';
	const OPT_SEEN      = 'tqr_seen_tutor_version';
	const OPT_CHANGED   = 'tqr_tutor_changed';
	const OPT_HEALTH    = 'tqr_health';

	/** Ultima versione di Tutor LMS verificata con questo plugin. */
	const TESTED_TUTOR = '4.1.1';

	public function __construct() {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue' ) );

		add_action( 'wp_ajax_tqr_get_draft', array( $this, 'ajax_get_draft' ) );
		add_action( 'wp_ajax_tqr_save_draft', array( $this, 'ajax_save_draft' ) );
		add_action( 'wp_ajax_tqr_report', array( $this, 'ajax_report' ) );

		// Priorità 0: gira prima del gestore di Tutor e lo sostituisce.
		add_action( 'wp_ajax_tutor_quiz_abandon', array( $this, 'maybe_block_abandon' ), 0 );

		add_action( 'tutor_quiz/attempt_ended', array( $this, 'clear_on_attempt_ended' ), 10, 1 );

		add_action( self::CRON_HOOK, array( $this, 'gc_orphan_drafts' ) );
		add_action( 'init', array( $this, 'ensure_cron' ) );

		add_action( 'admin_init', array( $this, 'track_tutor_version' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
		add_action( 'admin_post_tqr_dismiss', array( $this, 'dismiss_notice' ) );
	}

	public static function on_deactivate() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	public function ensure_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/* ------------------------------------------------------------ Helpers */

	private function meta_key( $attempt_id ) {
		return self::META_PREFIX . (int) $attempt_id;
	}

	/**
	 * Tentativo dell'utente corrente, oppure null.
	 *
	 * @param mixed $attempt_id   ID grezzo dalla richiesta.
	 * @param bool  $require_open Se true, accetta solo tentativi ancora aperti.
	 */
	private function owned_attempt( $attempt_id, $require_open = false ) {
		$attempt = TQR_Tutor_Adapter::get_attempt( absint( $attempt_id ) );
		if ( ! $attempt || $attempt->user_id !== get_current_user_id() ) {
			return null;
		}
		if ( $require_open && ! TQR_Tutor_Adapter::is_open( $attempt ) ) {
			return null;
		}
		return $attempt;
	}

	private function is_quiz_page() {
		$is = is_singular( TQR_Tutor_Adapter::quiz_post_type() );
		return (bool) apply_filters( 'tqr_is_quiz_page', $is );
	}

	/* ----------------------------------------------------------- Frontend */

	public function enqueue() {
		if ( ! is_user_logged_in() || ! TQR_Tutor_Adapter::is_active() || ! $this->is_quiz_page() ) {
			return;
		}

		$quiz_id = (int) get_queried_object_id();
		$open    = TQR_Tutor_Adapter::get_open_attempt( $quiz_id, get_current_user_id() );

		$cfg = array(
			'ajaxurl'      => admin_url( 'admin-ajax.php' ),
			'nonce'        => wp_create_nonce( self::NONCE ),
			'attemptId'    => $open ? $open->attempt_id : 0,
			'leaveUrl'     => TQR_Tutor_Adapter::course_url_for_quiz( $quiz_id ),
			'debug'        => isset( $_GET['tqr_debug'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'tutorVersion' => TQR_Tutor_Adapter::version(),
			'selectors'    => apply_filters( 'tqr_selectors', $this->default_selectors() ),
		);

		wp_enqueue_script(
			'tqr-frontend',
			plugins_url( 'assets/js/tqr-frontend.js', TQR_FILE ),
			array(),
			TQR_VERSION,
			true
		);
		wp_add_inline_script( 'tqr-frontend', 'window.TQR_CONFIG = ' . wp_json_encode( $cfg ) . ';', 'before' );
	}

	/**
	 * Selettori noti, dalla 1.x alla 4.x. Il JS li prova in ordine e, se
	 * nessuno corrisponde, trova comunque il form dai nomi dei campi risposta.
	 * Modificabili con il filtro `tqr_selectors`.
	 */
	private function default_selectors() {
		return array(
			'forms'          => array(
				'form[id^="quiz-attempt-form-"]', // Tutor 4.x, modalità Modern/Kids.
				'form#tutor-answering-quiz',       // Tutor 1.x - 4.x, modalità Legacy.
				'form.tutor-quiz-submission',
			),
			'modernQuestion' => '[data-quiz-question-index]',
			'legacyQuestion' => '.quiz-attempt-single-question',
			'legacyCounter'  => '.tutor-quiz-question-counter',
			'legacyLeave'    => '#tutor-popup-leave',
		);
	}

	/* --------------------------------------------------------------- AJAX */

	public function ajax_get_draft() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$attempt = $this->owned_attempt( $_POST['attempt_id'] ?? 0 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $attempt ) {
			wp_send_json_error();
		}

		$key = $this->meta_key( $attempt->attempt_id );

		// Autoriparazione: se il tentativo è già chiuso (es. l'hook di fine
		// tentativo non è scattato), la bozza non serve più.
		if ( ! TQR_Tutor_Adapter::is_open( $attempt ) ) {
			delete_user_meta( get_current_user_id(), $key );
			wp_send_json_success( array( 'draft' => '' ) );
		}

		$draft = get_user_meta( get_current_user_id(), $key, true );
		wp_send_json_success( array( 'draft' => $draft ? $draft : '' ) );
	}

	public function ajax_save_draft() {
		check_ajax_referer( self::NONCE, 'nonce' );
		// Solo tentativi aperti: evita che un salvataggio in uscita, arrivato
		// dopo l'invio finale, ricrei la bozza di un quiz già consegnato.
		$attempt = $this->owned_attempt( $_POST['attempt_id'] ?? 0, true ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! $attempt ) {
			wp_send_json_error();
		}

		$draft = (string) wp_unslash( $_POST['draft'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( strlen( $draft ) > self::MAX_BYTES ) {
			wp_send_json_error( array( 'reason' => 'too_big' ) );
		}
		$decoded = json_decode( $draft, true );
		if ( ! is_array( $decoded ) || ! isset( $decoded['fields'] ) || ! is_array( $decoded['fields'] ) ) {
			wp_send_json_error( array( 'reason' => 'invalid_json' ) );
		}

		update_user_meta( get_current_user_id(), $this->meta_key( $attempt->attempt_id ), wp_slash( $draft ) );
		wp_send_json_success();
	}

	/**
	 * Segnalazione dal browser: il quiz era aperto ma il form non è stato
	 * trovato. Di solito significa che un aggiornamento di Tutor ha cambiato
	 * il markup. Viene registrata una sola volta per versione di Tutor.
	 */
	public function ajax_report() {
		check_ajax_referer( self::NONCE, 'nonce' );
		$kind = sanitize_key( $_POST['kind'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( ! in_array( $kind, array( 'form_not_found' ), true ) ) {
			wp_send_json_error();
		}
		$version = TQR_Tutor_Adapter::version();
		$current = get_option( self::OPT_HEALTH );
		if ( is_array( $current ) && $current['kind'] === $kind && $current['tutor'] === $version ) {
			wp_send_json_success();
		}
		update_option(
			self::OPT_HEALTH,
			array(
				'kind'  => $kind,
				'tutor' => $version,
				'mode'  => TQR_Tutor_Adapter::learning_mode(),
				// Solo URL di questo sito: il link finisce in un avviso per l'admin.
				'url'   => wp_validate_redirect( esc_url_raw( wp_unslash( $_POST['url'] ?? '' ) ), '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
				'time'  => time(),
			),
			false
		);
		wp_send_json_success();
	}

	/**
	 * "Lascia quiz" non deve consegnare il tentativo.
	 *
	 * Tutor (sia l'interfaccia Legacy sia la Modern della 4.x) chiama l'azione
	 * AJAX `tutor_quiz_abandon` per consegnare parzialmente il quiz quando lo
	 * studente esce. Rispondiamo noi con successo senza toccare nulla: il
	 * tentativo resta aperto e Tutor prosegue con la sua navigazione.
	 * Funziona a prescindere da pulsanti, id e markup del front-end.
	 */
	public function maybe_block_abandon() {
		if ( ! apply_filters( 'tqr_block_abandon', true ) ) {
			return;
		}
		$attempt = $this->owned_attempt( $_POST['attempt_id'] ?? 0, true ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput
		if ( ! $attempt ) {
			return; // Caso non nostro: decide Tutor.
		}
		do_action( 'tqr_abandon_blocked', $attempt->attempt_id );
		wp_send_json_success( array( 'tqr' => 'kept_open' ) );
	}

	/* ------------------------------------------------------------ Pulizia */

	public function clear_on_attempt_ended( $attempt_id ) {
		$attempt = TQR_Tutor_Adapter::get_attempt( $attempt_id );
		if ( $attempt && $attempt->user_id ) {
			delete_user_meta( $attempt->user_id, $this->meta_key( $attempt->attempt_id ) );
		}
	}

	/**
	 * Rimuove le bozze di tentativi non più aperti (consegnati, scaduti o
	 * cancellati). Funziona anche se in futuro l'hook di fine tentativo
	 * dovesse cambiare nome.
	 */
	public function gc_orphan_drafts() {
		if ( ! TQR_Tutor_Adapter::is_active() ) {
			return;
		}
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT user_id, meta_key FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
				$wpdb->esc_like( self::META_PREFIX ) . '%'
			)
		);
		foreach ( (array) $rows as $row ) {
			$attempt_id = (int) substr( $row->meta_key, strlen( self::META_PREFIX ) );
			$attempt    = $attempt_id ? TQR_Tutor_Adapter::get_attempt( $attempt_id ) : null;
			if ( ! $attempt || ! TQR_Tutor_Adapter::is_open( $attempt ) ) {
				delete_user_meta( (int) $row->user_id, $row->meta_key );
			}
		}
	}

	/* ------------------------------------------------------------- Admin */

	/**
	 * Registra quando Tutor LMS cambia versione, per avvisare l'amministratore
	 * di fare una prova veloce del quiz.
	 */
	public function track_tutor_version() {
		$current = TQR_Tutor_Adapter::version();
		if ( '' === $current ) {
			return;
		}
		$seen = (string) get_option( self::OPT_SEEN, '' );
		if ( $seen === $current ) {
			return;
		}
		if ( '' !== $seen ) {
			update_option( self::OPT_CHANGED, array( 'from' => $seen, 'to' => $current ), false );
			delete_option( self::OPT_HEALTH );
		}
		update_option( self::OPT_SEEN, $current, false );
	}

	public function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( ! TQR_Tutor_Adapter::is_active() ) {
			$this->notice( 'error', __( '<strong>Tutor Quiz Resume</strong>: Tutor LMS non è attivo, il plugin è in pausa.', 'tutor-quiz-resume' ) );
			return;
		}

		$health = get_option( self::OPT_HEALTH );
		if ( is_array( $health ) && 'form_not_found' === $health['kind'] ) {
			$msg = sprintf(
				/* translators: 1: Tutor version, 2: learning mode */
				__( '<strong>Tutor Quiz Resume</strong>: su un quiz aperto non è stato trovato il modulo delle risposte (Tutor LMS %1$s, modalità %2$s). Il salvataggio delle risposte potrebbe non funzionare: probabilmente un aggiornamento di Tutor ha cambiato il markup del quiz. Il blocco della consegna all\'uscita resta attivo.', 'tutor-quiz-resume' ),
				esc_html( $health['tutor'] ),
				esc_html( $health['mode'] )
			);
			if ( ! empty( $health['url'] ) ) {
				$msg .= ' <a href="' . esc_url( add_query_arg( 'tqr_debug', '1', $health['url'] ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Apri il quiz in modalità diagnostica', 'tutor-quiz-resume' ) . '</a>.';
			}
			$this->notice( 'error', $msg, 'health' );
		}

		$changed = get_option( self::OPT_CHANGED );
		if ( is_array( $changed ) ) {
			$newer = version_compare( $changed['to'], self::TESTED_TUTOR, '>' );
			$msg   = sprintf(
				/* translators: 1: old version, 2: new version */
				__( '<strong>Tutor Quiz Resume</strong>: Tutor LMS è passato dalla versione %1$s alla %2$s.', 'tutor-quiz-resume' ),
				esc_html( $changed['from'] ),
				esc_html( $changed['to'] )
			);
			$msg .= ' ' . ( $newer
				? sprintf(
					/* translators: %s: tested version */
					esc_html__( 'È più recente dell\'ultima versione verificata (%s): fai una prova veloce con un account studente (rispondi a un paio di domande, esci dal quiz, rientra).', 'tutor-quiz-resume' ),
					esc_html( self::TESTED_TUTOR )
				)
				: esc_html__( 'Questa versione è compatibile; una prova veloce con un account studente resta consigliata.', 'tutor-quiz-resume' ) );
			$this->notice( $newer ? 'warning' : 'info', $msg, 'changed' );
		}
	}

	private function notice( $type, $html, $dismiss_key = '' ) {
		$dismiss = '';
		if ( $dismiss_key ) {
			$url     = wp_nonce_url( admin_url( 'admin-post.php?action=tqr_dismiss&notice=' . $dismiss_key ), 'tqr_dismiss' );
			$dismiss = ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'Ho verificato, nascondi', 'tutor-quiz-resume' ) . '</a>';
		}
		printf( '<div class="notice notice-%1$s"><p>%2$s%3$s</p></div>', esc_attr( $type ), wp_kses_post( $html ), $dismiss ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	public function dismiss_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '', 403 );
		}
		check_admin_referer( 'tqr_dismiss' );
		$which = sanitize_key( $_GET['notice'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( 'changed' === $which ) {
			delete_option( self::OPT_CHANGED );
		} elseif ( 'health' === $which ) {
			delete_option( self::OPT_HEALTH );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
