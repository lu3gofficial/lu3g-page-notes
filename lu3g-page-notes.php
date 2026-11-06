<?php
/**
 * Plugin Name:       LU3G Page Notes
 * Description:       Note sulle pagine del sito, direttamente dal frontend: si disegna una zona della pagina, si scrive un commento e si risponde. Le note le vedono e le scrivono solo gli utenti collegati con il permesso scelto.
 * Version:           1.0.0
 * Plugin URI:        https://github.com/lu3gofficial/lu3g-page-notes
 * Update URI:        https://github.com/lu3gofficial/lu3g-page-notes
 * Author:            LU3G Agenzia Web
 * Author URI:        https://lu3g.it
 * Text Domain:       lu3g-page-notes
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * License:           GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'LU3G_PN_VERSION', '1.0.0' );
define( 'LU3G_PN_FILE', __FILE__ );
define( 'LU3G_PN_PATH', plugin_dir_path( __FILE__ ) );
define( 'LU3G_PN_URL', plugin_dir_url( __FILE__ ) );

require_once LU3G_PN_PATH . 'includes/class-notes.php';
require_once LU3G_PN_PATH . 'includes/class-rest.php';
require_once LU3G_PN_PATH . 'includes/class-frontend.php';
require_once LU3G_PN_PATH . 'includes/class-admin.php';

// Dati: tipi di contenuto per note e risposte.
add_action( 'init', array( 'LU3G_PN_Notes', 'register' ) );

// API usata dal frontend: /wp-json/lu3g-notes/v1/...
add_action( 'rest_api_init', array( 'LU3G_PN_Rest', 'register' ) );

// Frontend: pulsante nella barra admin e livello delle note, solo per chi ha il permesso.
LU3G_PN_Frontend::init();

// Bacheca: elenco delle note e impostazioni.
if ( is_admin() ) {
	LU3G_PN_Admin::init();
}

/**
 * Aggiornamenti automatici dalle release GitHub di https://github.com/lu3gofficial/lu3g-page-notes (vedi includes/class-updater.php).
 */
require_once __DIR__ . '/includes/class-updater.php';
LU3G_Page_Notes_Updater::init( __FILE__ );
