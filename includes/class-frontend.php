<?php
/**
 * Frontend: pulsante "Note LU3G" nella barra admin e script del livello delle note.
 *
 * Tutto si carica solo per gli utenti collegati con il permesso delle impostazioni: i visitatori non scaricano
 * nessun file del plugin. Nell'anteprima dell'editor di Elementor e del Personalizza il livello non serve e
 * resta spento.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_PN_Frontend {

	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_bar_menu', array( __CLASS__, 'admin_bar' ), 90 );
	}

	/** Pagina del sito in cui il livello ha senso? */
	public static function active() {
		if ( is_admin() || ! LU3G_PN_Notes::can_use() ) {
			return false;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- solo lettura
		if ( isset( $_GET['elementor-preview'] ) || is_customize_preview() || wp_doing_ajax() ) {
			return false;
		}
		// phpcs:enable
		return (bool) apply_filters( 'lu3g_page_notes_active', true );
	}

	public static function assets() {
		if ( ! self::active() ) {
			return;
		}
		wp_enqueue_style( 'lu3g-page-notes', LU3G_PN_URL . 'assets/css/page-notes.css', array(), LU3G_PN_VERSION );
		wp_enqueue_script( 'lu3g-page-notes', LU3G_PN_URL . 'assets/js/page-notes.js', array(), LU3G_PN_VERSION, true );
		wp_add_inline_script(
			'lu3g-page-notes',
			'window.LU3G_PAGE_NOTES = ' . wp_json_encode(
				array(
					'rest'    => esc_url_raw( rest_url( LU3G_PN_Rest::NS . '/' ) ),
					'nonce'   => wp_create_nonce( 'wp_rest' ),
					'user'    => get_current_user_id(),
					'isAdmin' => current_user_can( 'manage_options' ),
					'admin'   => esc_url_raw( admin_url( 'edit.php?post_type=' . LU3G_PN_Notes::TYPE ) ),
				)
			) . ';',
			'before'
		);
	}

	/** Pulsante nella barra admin: accende e spegne le note (lo gestisce lo script). */
	public static function admin_bar( $bar ) {
		if ( ! self::active() ) {
			return;
		}
		$bar->add_node(
			array(
				'id'    => 'lu3g-page-notes',
				'title' => '<span class="ab-icon dashicons dashicons-format-chat" aria-hidden="true"></span><span class="ab-label">Note LU3G</span><span class="lu3g-pn-ab-count" hidden></span>',
				'href'  => '#lu3g-page-notes',
				'meta'  => array(
					'title' => 'Mostra le note di questa pagina e aggiungine di nuove',
				),
			)
		);
	}
}
