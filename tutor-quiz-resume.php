<?php
/**
 * Plugin Name:       Tutor Quiz Resume
 * Description:       Rende i quiz Tutor LMS riprendibili, anche da un altro dispositivo. Compatibile con Tutor LMS 1.x - 4.x (interfaccia Legacy e Modern).
 * Version:           2.0.0
 * Author:            Francesco
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Requires Plugins:  tutor
 * Text Domain:       tutor-quiz-resume
 * License:           GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TQR_VERSION', '2.0.0' );
define( 'TQR_FILE', __FILE__ );

require_once __DIR__ . '/includes/class-tqr-tutor-adapter.php';
require_once __DIR__ . '/includes/class-tqr-plugin.php';

register_deactivation_hook( __FILE__, array( 'TQR_Plugin', 'on_deactivate' ) );

new TQR_Plugin();
