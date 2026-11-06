<?php
/**
 * Aggiornamenti del plugin da GitHub (repository pubblico lu3gofficial/lu3g-page-notes).
 *
 * Come funziona:
 * - l'intestazione "Update URI: https://github.com/..." del plugin dice a WordPress di non cercarlo su wordpress.org
 *   e di chiedere gli aggiornamenti al filtro "update_plugins_github.com", gestito qui;
 * - il plugin legge l'ultima release del repository (API GitHub, con cache di 6 ore) e, se la versione è più nuova,
 *   WordPress mostra "Aggiornamento disponibile" come per ogni altro plugin;
 * - il pacchetto è il file "lu3g-page-notes.zip" allegato alla release (cartella lu3g-page-notes/ all'interno).
 *
 * Se il repository cambia proprietario o nome:
 * - GitHub rinvia il vecchio indirizzo al nuovo e wp_remote_get segue il rinvio, quindi il controllo trova la release;
 * - senza token il pacchetto è il link pubblico di download dell'allegato (github.com/.../releases/download/...),
 *   che WordPress scarica da solo senza passare da questo file;
 * - con il token il pacchetto è l'indirizzo API dell'allegato, riconosciuto qualunque sia il proprietario
 *   (repos/<chiunque>/lu3g-page-notes/... oppure repositories/<numero>/...).
 *
 * Il repository è pubblico: non serve nessuna configurazione. Facoltativo: un token GitHub in wp-config.php
 *   define( 'LU3G_GITHUB_TOKEN', 'github_pat_...' );
 * alza il limite di richieste all'API (60 l'ora per indirizzo IP senza token; con la cache di 6 ore basta e avanza)
 * e servirebbe di nuovo se il repository tornasse privato.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_Page_Notes_Updater {

	const REPO      = 'lu3gofficial/lu3g-page-notes';   // proprietario/nome del repository su GitHub (se cambia, GitHub rinvia)
	const ASSET     = 'lu3g-page-notes.zip';
	const SLUG      = 'lu3g-page-notes';
	const TRANSIENT = 'lu3g_page_notes_release';

	private static $file;

	public static function init( $plugin_file ) {
		self::$file = $plugin_file;
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 20, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		// dopo un aggiornamento si rilegge GitHub
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ) );
		// "Controlla di nuovo" (update-core.php?force-check=1): la cache va svuotata PRIMA del controllo di WordPress,
		// che è agganciato allo stesso evento con priorità 10; con priorità 1 si passa prima
		add_action( 'load-update-core.php', array( __CLASS__, 'flush_on_force_check' ), 1 );
		// pagina Plugin: link che rilegge subito GitHub, senza aspettare le 6 ore della cache
		add_filter( 'plugin_action_links_' . plugin_basename( $plugin_file ), array( __CLASS__, 'action_links' ) );
	}

	public static function action_links( $links ) {
		if ( current_user_can( 'update_plugins' ) ) {
			$links[] = '<a href="' . esc_url( self_admin_url( 'update-core.php?force-check=1' ) ) . '">Controlla aggiornamenti</a>';
		}
		return $links;
	}

	private static function token() {
		return defined( 'LU3G_GITHUB_TOKEN' ) ? (string) LU3G_GITHUB_TOKEN : '';
	}

	private static function headers( $accept = 'application/vnd.github+json' ) {
		$h = array(
			'Accept'               => $accept,
			'User-Agent'           => 'lu3g-page-notes-updater',
			'X-GitHub-Api-Version' => '2022-11-28',
		);
		if ( self::token() ) {
			$h['Authorization'] = 'Bearer ' . self::token();
		}
		return $h;
	}

	public static function flush() {
		delete_site_transient( self::TRANSIENT );
	}

	public static function flush_on_force_check() {
		if ( ! empty( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- solo lettura
			self::flush();
			// il controllo dei plugin di WordPress su questa pagina ripete la richiesta solo dopo 1 minuto: lo forzo
			delete_site_transient( 'update_plugins' );
		}
	}

	/** Ultima release pubblicata: versione, pacchetto (URL API dell'allegato), note, data. */
	public static function release() {
		$cached = get_site_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$res  = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'headers' => self::headers(), 'timeout' => 10 ) );
		$data = array();
		if ( ! is_wp_error( $res ) && 200 === wp_remote_retrieve_response_code( $res ) ) {
			$json = json_decode( wp_remote_retrieve_body( $res ), true );
			foreach ( (array) ( $json['assets'] ?? array() ) as $a ) {
				if ( self::ASSET === ( $a['name'] ?? '' ) ) {
					// senza token: link pubblico, scaricabile da WordPress senza intestazioni speciali
					$public = (string) ( $a['browser_download_url'] ?? '' );
					$data   = array(
						'version'   => ltrim( (string) $json['tag_name'], 'vV' ),
						'package'   => ( self::token() || ! $public ) ? $a['url'] : $public,
						'notes'     => (string) ( $json['body'] ?? '' ),
						'date'      => (string) ( $json['published_at'] ?? '' ),
						'html_url'  => (string) ( $json['html_url'] ?? '' ),
					);
				}
			}
		}
		// anche un errore va in cache (1 ora), per non chiamare GitHub a ogni pagina di wp-admin
		set_site_transient( self::TRANSIENT, $data, $data ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $data;
	}

	/** Filtro "update_plugins_github.com": risponde con l'ultima release; WordPress confronta le versioni. */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( self::$file ) !== $plugin_file ) {
			return $update;
		}
		$r = self::release();
		if ( empty( $r['version'] ) ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::SLUG,
			'plugin'       => $plugin_file,
			'version'      => $r['version'],
			'new_version'  => $r['version'],
			'url'          => $r['html_url'],
			'package'      => $r['package'],
			'requires_php' => '7.4',
		);
	}

	/** Finestra "Visualizza dettagli": note della release come registro delle modifiche. */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$r    = self::release();
		$data = get_plugin_data( self::$file, false, false );
		return (object) array(
			'name'          => $data['Name'],
			'slug'          => self::SLUG,
			'version'       => $r['version'] ?? $data['Version'],
			'author'        => $data['Author'],
			'homepage'      => $data['PluginURI'] ? $data['PluginURI'] : 'https://lu3g.it/',
			'requires_php'  => '7.4',
			'last_updated'  => $r['date'] ?? '',
			'sections'      => array(
				'description' => wpautop( esc_html( $data['Description'] ) ),
				'changelog'   => wpautop( esc_html( $r['notes'] ?? '' ) ),
			),
		);
	}

	/** Indirizzo API di un allegato di questo plugin, qualunque sia il proprietario del repository. */
	private static function is_asset_url( $url ) {
		$name = preg_quote( substr( self::REPO, strpos( self::REPO, '/' ) + 1 ), '#' );
		return (bool) preg_match( '#^https://api\.github\.com/(repos/[A-Za-z0-9_.-]+/' . $name . '|repositories/\d+)/releases/assets/\d+$#', (string) $url );
	}

	/**
	 * Scarica il pacchetto. L'allegato si chiede all'API (con il token, se c'è); GitHub risponde con un rinvio a un
	 * indirizzo temporaneo già firmato, che si scarica SENZA token (lì un token verrebbe rifiutato).
	 */
	public static function download( $reply, $package, $upgrader ) {
		if ( ! self::is_asset_url( $package ) ) {
			return $reply;
		}
		$res = wp_remote_get( $package, array( 'headers' => self::headers( 'application/octet-stream' ), 'redirection' => 0, 'timeout' => 15 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$code = wp_remote_retrieve_response_code( $res );
		$to   = wp_remote_retrieve_header( $res, 'location' );
		if ( $code < 300 || $code > 399 || ! $to ) {
			return new WP_Error( 'lu3g_download', 'GitHub non ha fornito il pacchetto (codice ' . $code . '). Riprova più tardi o scarica lo zip dalla pagina delle release.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		return download_url( $to, 300 );
	}
}
