<?php
/**
 * Livello di compatibilità con Tutor LMS.
 *
 * Tutto ciò che il plugin sa di Tutor passa da qui. Ogni accesso usa prima
 * l'API pubblica di Tutor (se esiste) e, se manca, ricade su query dirette
 * alla tabella dei tentativi, il cui schema è stabile dalla 1.x alla 4.x.
 * Se Tutor cambia qualcosa in futuro, si interviene solo in questo file.
 *
 * @package Tutor_Quiz_Resume
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TQR_Tutor_Adapter {

	/** Stato di un tentativo ancora aperto (invariato da Tutor 1.x a 4.x). */
	const STATUS_OPEN = 'attempt_started';

	/** @var bool|null Cache: la tabella dei tentativi esiste? */
	private static $table_exists = null;

	/* ------------------------------------------------------------ Ambiente */

	public static function is_active() {
		return function_exists( 'tutor_utils' ) || defined( 'TUTOR_VERSION' );
	}

	public static function version() {
		return defined( 'TUTOR_VERSION' ) ? (string) TUTOR_VERSION : '';
	}

	/**
	 * Modalità di apprendimento di Tutor 4.x: 'modern', 'kids' o 'legacy'.
	 * Sulle versioni precedenti alla 4 restituisce 'legacy'.
	 */
	public static function learning_mode() {
		if ( version_compare( self::version(), '4.0.0-alpha', '<' ) ) {
			return 'legacy';
		}
		$utils = self::utils();
		if ( $utils && method_exists( $utils, 'is_legacy_learning_mode' ) ) {
			return $utils->is_legacy_learning_mode() ? 'legacy' : self::option( 'learning_mode', 'modern' );
		}
		return self::option( 'learning_mode', 'modern' );
	}

	public static function quiz_post_type() {
		return self::post_type( 'quiz_post_type', 'tutor_quiz' );
	}

	public static function course_post_type() {
		return self::post_type( 'course_post_type', 'courses' );
	}

	/* ------------------------------------------------------------ Tentativi */

	/**
	 * Restituisce un tentativo normalizzato (oggetto con attempt_id, quiz_id,
	 * user_id, attempt_status) oppure null.
	 */
	public static function get_attempt( $attempt_id ) {
		$attempt_id = (int) $attempt_id;
		if ( $attempt_id <= 0 ) {
			return null;
		}

		$utils = self::utils();
		if ( $utils && method_exists( $utils, 'get_attempt' ) ) {
			return self::normalize( $utils->get_attempt( $attempt_id ) );
		}

		if ( ! self::table_exists() ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		return self::normalize(
			$wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE attempt_id = %d", $attempt_id ) ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);
	}

	/**
	 * Tentativo aperto dell'utente su un quiz, oppure null.
	 */
	public static function get_open_attempt( $quiz_id, $user_id ) {
		$quiz_id = (int) $quiz_id;
		$user_id = (int) $user_id;
		if ( $quiz_id <= 0 || $user_id <= 0 ) {
			return null;
		}

		$utils = self::utils();
		if ( $utils && method_exists( $utils, 'is_started_quiz' ) && get_current_user_id() === $user_id ) {
			$attempt = self::normalize( $utils->is_started_quiz( $quiz_id ) );
			return ( $attempt && self::is_open( $attempt ) ) ? $attempt : null;
		}

		if ( ! self::table_exists() ) {
			return null;
		}
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE quiz_id = %d AND user_id = %d AND attempt_status = %s ORDER BY attempt_id DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$quiz_id,
				$user_id,
				self::STATUS_OPEN
			)
		);
		return self::normalize( $row );
	}

	public static function is_open( $attempt ) {
		if ( ! $attempt ) {
			return false;
		}
		$open = (array) apply_filters( 'tqr_open_attempt_statuses', array( self::STATUS_OPEN ) );
		return in_array( $attempt->attempt_status, $open, true );
	}

	/* --------------------------------------------------------------- Corsi */

	/**
	 * URL del corso che contiene il quiz (fallback: referrer o home).
	 */
	public static function course_url_for_quiz( $quiz_id ) {
		$course_id = self::course_id_for_quiz( $quiz_id );
		$url       = $course_id ? get_permalink( $course_id ) : '';
		return (string) apply_filters( 'tqr_leave_url', $url ? $url : '', $quiz_id, $course_id );
	}

	public static function course_id_for_quiz( $quiz_id ) {
		$quiz_id = (int) $quiz_id;
		if ( $quiz_id <= 0 ) {
			return 0;
		}

		$utils = self::utils();
		if ( $utils && method_exists( $utils, 'get_course_id_by_subcontent' ) ) {
			$id = (int) $utils->get_course_id_by_subcontent( $quiz_id );
			if ( $id ) {
				return $id;
			}
		}

		// Struttura storica di Tutor: quiz -> argomento (topic) -> corso.
		$course_type = self::course_post_type();
		$post        = get_post( $quiz_id );
		for ( $i = 0; $post && $i < 4; $i++ ) {
			if ( $course_type === $post->post_type ) {
				return (int) $post->ID;
			}
			$post = $post->post_parent ? get_post( $post->post_parent ) : null;
		}
		return 0;
	}

	/* ------------------------------------------------------------- Interni */

	private static function utils() {
		if ( ! function_exists( 'tutor_utils' ) ) {
			return null;
		}
		$utils = tutor_utils();
		return is_object( $utils ) ? $utils : null;
	}

	private static function option( $key, $default ) {
		$utils = self::utils();
		if ( $utils && method_exists( $utils, 'get_option' ) ) {
			$value = $utils->get_option( $key, $default );
			return $value ? $value : $default;
		}
		return $default;
	}

	private static function post_type( $prop, $default ) {
		$type = $default;
		if ( function_exists( 'tutor' ) ) {
			$tutor = tutor();
			if ( is_object( $tutor ) && ! empty( $tutor->$prop ) ) {
				$type = (string) $tutor->$prop;
			}
		}
		return $type;
	}

	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'tutor_quiz_attempts';
	}

	private static function table_exists() {
		if ( null === self::$table_exists ) {
			global $wpdb;
			$table              = self::table();
			self::$table_exists = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table );
		}
		return self::$table_exists;
	}

	/**
	 * Riduce qualunque forma restituita da Tutor (oggetto, array, false) a un
	 * oggetto con i soli campi che usiamo.
	 */
	private static function normalize( $row ) {
		if ( is_array( $row ) ) {
			$row = (object) $row;
		}
		if ( ! is_object( $row ) || empty( $row->attempt_id ) ) {
			return null;
		}
		return (object) array(
			'attempt_id'     => (int) $row->attempt_id,
			'quiz_id'        => isset( $row->quiz_id ) ? (int) $row->quiz_id : 0,
			'user_id'        => isset( $row->user_id ) ? (int) $row->user_id : 0,
			'attempt_status' => isset( $row->attempt_status ) ? (string) $row->attempt_status : '',
		);
	}
}
