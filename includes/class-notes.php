<?php
/**
 * Note e risposte: dati, permessi e formato per l'API.
 *
 * - Una nota è un contenuto "lu3g_note": il testo nel contenuto, la pagina e la zona disegnata nei meta.
 * - Una risposta è un contenuto "lu3g_note_reply" figlio della nota (post_parent).
 * - La pagina si riconosce dal suo indirizzo, ridotto a percorso relativo alla home (vedi page_key()):
 *   così funziona per articoli, archivi, pagine di prodotto e ogni altro indirizzo del sito.
 *
 * La zona si salva in due modi (vedi assets/js/page-notes.js):
 * - "anchor": selettore CSS dell'elemento sotto la zona e coordinate in frazioni del suo riquadro, così la zona
 *   segue l'elemento anche se la larghezza della finestra cambia;
 * - "doc": coordinate nella pagina intera (x e larghezza in frazioni della larghezza, y e altezza in pixel),
 *   usate se l'elemento non si trova più o è nascosto (per esempio su mobile).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_PN_Notes {

	const TYPE       = 'lu3g_note';
	const REPLY_TYPE = 'lu3g_note_reply';
	const OPT_CAP    = 'lu3g_pn_capability';
	const MAX_TEXT   = 5000;

	/** Permessi che si possono scegliere nelle impostazioni: chi vede e scrive le note. */
	public static function capabilities() {
		return array(
			'manage_options' => 'Solo amministratori',
			'edit_pages'     => 'Editor e amministratori',
			'edit_posts'     => 'Anche autori e collaboratori',
		);
	}

	public static function capability() {
		$cap = (string) get_option( self::OPT_CAP, 'edit_pages' );
		if ( ! isset( self::capabilities()[ $cap ] ) ) {
			$cap = 'edit_pages';
		}
		/** Permesso richiesto per vedere e scrivere le note. */
		return (string) apply_filters( 'lu3g_page_notes_capability', $cap );
	}

	/** L'utente collegato può vedere e scrivere le note? */
	public static function can_use() {
		return is_user_logged_in() && current_user_can( self::capability() );
	}

	/** Modificare o eliminare una nota o una risposta: chi l'ha scritta o un amministratore. */
	public static function can_edit( $post ) {
		$post = get_post( $post );
		if ( ! $post || ! self::can_use() ) {
			return false;
		}
		return (int) $post->post_author === get_current_user_id() || current_user_can( 'manage_options' );
	}

	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'               => 'Note LU3G',
					'singular_name'      => 'Nota',
					'menu_name'          => 'Note LU3G',
					'all_items'          => 'Tutte le note',
					'edit_item'          => 'Modifica nota',
					'search_items'       => 'Cerca note',
					'not_found'          => 'Nessuna nota. Le note si aggiungono dal sito: barra in alto → Note LU3G.',
					'not_found_in_trash' => 'Nessuna nota nel cestino.',
				),
				'public'              => false,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'show_in_rest'        => false, // l'API è quella del plugin, con i suoi permessi
				'menu_icon'           => 'dashicons-format-chat',
				'menu_position'       => 26,
				'supports'            => array( 'title', 'editor', 'author' ),
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
				'capabilities'        => array( 'create_posts' => 'do_not_allow' ), // le note nascono solo dal sito
				'rewrite'             => false,
				'query_var'           => false,
			)
		);

		register_post_type(
			self::REPLY_TYPE,
			array(
				'public'       => false,
				'show_ui'      => false,
				'show_in_rest' => false,
				'supports'     => array( 'editor', 'author' ),
				'rewrite'      => false,
				'query_var'    => false,
			)
		);
	}

	/* ------------------------------------------------------------------ pagina */

	/**
	 * Chiave della pagina: percorso relativo alla home, senza barre ai lati, più la query senza i parametri
	 * di tracciamento. https://sito.it/chi-siamo/?utm_source=x → "chi-siamo"; la home è "/".
	 */
	public static function page_key( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) ) {
			return '/';
		}
		$path = isset( $parts['path'] ) ? rawurldecode( $parts['path'] ) : '/';
		$home = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home = is_string( $home ) ? $home : '/';
		if ( '/' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		$key = trim( strtolower( $path ), '/' );

		if ( ! empty( $parts['query'] ) ) {
			parse_str( $parts['query'], $query );
			foreach ( array_keys( $query ) as $name ) {
				if ( preg_match( '/^(utm_|lu3g[-_]note|fbclid$|gclid$|msclkid$|_ga$|preview|ver$|s_)/i', (string) $name ) ) {
					unset( $query[ $name ] );
				}
			}
			if ( $query ) {
				ksort( $query );
				$key .= '?' . http_build_query( $query );
			}
		}
		return '' === $key ? '/' : substr( $key, 0, 500 );
	}

	/* ------------------------------------------------------------------ lettura */

	/** Note di una pagina, dalla più vecchia (il numero sulla pagina segue quest'ordine). */
	public static function for_page( $key ) {
		$posts = get_posts(
			array(
				'post_type'      => self::TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 500,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'meta_key'       => '_lu3g_pn_page', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'no_found_rows'  => true,
			)
		);
		return array_map( array( __CLASS__, 'format' ), $posts );
	}

	public static function replies( $note_id ) {
		return get_posts(
			array(
				'post_type'      => self::REPLY_TYPE,
				'post_status'    => 'publish',
				'post_parent'    => (int) $note_id,
				'posts_per_page' => 200,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
	}

	public static function reply_count( $note_id ) {
		return count( self::replies( $note_id ) );
	}

	public static function author( $user_id ) {
		$user = get_userdata( (int) $user_id );
		return array(
			'id'     => (int) $user_id,
			'name'   => $user ? $user->display_name : 'Utente eliminato',
			'avatar' => (string) get_avatar_url( (int) $user_id, array( 'size' => 48 ) ),
		);
	}

	/** Nota nel formato dell'API, con le sue risposte. */
	public static function format( $post ) {
		$post    = get_post( $post );
		$replies = array();
		foreach ( self::replies( $post->ID ) as $reply ) {
			$replies[] = array(
				'id'       => $reply->ID,
				'text'     => $reply->post_content,
				'author'   => self::author( $reply->post_author ),
				'date'     => get_post_time( 'c', true, $reply ),
				'can_edit' => self::can_edit( $reply ),
			);
		}
		$status = get_post_meta( $post->ID, '_lu3g_pn_status', true );
		return array(
			'id'       => $post->ID,
			'text'     => $post->post_content,
			'author'   => self::author( $post->post_author ),
			'date'     => get_post_time( 'c', true, $post ),
			'status'   => 'resolved' === $status ? 'resolved' : 'open',
			'page'     => (string) get_post_meta( $post->ID, '_lu3g_pn_page', true ),
			'url'      => (string) get_post_meta( $post->ID, '_lu3g_pn_url', true ),
			'anchor'   => self::json_meta( $post->ID, '_lu3g_pn_anchor' ),
			'doc'      => self::json_meta( $post->ID, '_lu3g_pn_doc' ),
			'replies'  => $replies,
			'can_edit' => self::can_edit( $post ),
		);
	}

	private static function json_meta( $post_id, $key ) {
		$value = json_decode( (string) get_post_meta( $post_id, $key, true ), true );
		return is_array( $value ) ? $value : null;
	}

	/* ------------------------------------------------------------------ scrittura */

	public static function clean_text( $text ) {
		$text = sanitize_textarea_field( (string) $text );
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::MAX_TEXT ) : substr( $text, 0, self::MAX_TEXT );
	}

	/** Titolo per l'elenco in bacheca: inizio del testo. */
	public static function title_from( $text ) {
		return wp_trim_words( $text, 10, '…' );
	}

	/**
	 * Zona "anchor" ripulita: selettore e frazioni del riquadro dell'elemento (anche fuori da 0..1 se la zona
	 * esce un po' dall'elemento).
	 */
	public static function clean_anchor( $anchor ) {
		if ( ! is_array( $anchor ) || empty( $anchor['selector'] ) ) {
			return null;
		}
		$selector = substr( wp_strip_all_tags( (string) $anchor['selector'] ), 0, 1000 );
		$out      = array( 'selector' => $selector );
		foreach ( array( 'x', 'y', 'w', 'h' ) as $k ) {
			$out[ $k ] = isset( $anchor[ $k ] ) ? max( -10, min( 10, round( (float) $anchor[ $k ], 5 ) ) ) : 0;
		}
		return $out['w'] > 0 && $out['h'] > 0 ? $out : null;
	}

	/** Zona "doc": x e w in frazioni della larghezza della pagina, y e h in pixel, vw = larghezza della finestra. */
	public static function clean_doc( $doc ) {
		if ( ! is_array( $doc ) ) {
			return null;
		}
		$out = array(
			'x'  => isset( $doc['x'] ) ? max( 0, min( 1, round( (float) $doc['x'], 5 ) ) ) : 0,
			'w'  => isset( $doc['w'] ) ? max( 0, min( 1, round( (float) $doc['w'], 5 ) ) ) : 0,
			'y'  => isset( $doc['y'] ) ? max( 0, min( 1000000, (int) $doc['y'] ) ) : 0,
			'h'  => isset( $doc['h'] ) ? max( 0, min( 1000000, (int) $doc['h'] ) ) : 0,
			'vw' => isset( $doc['vw'] ) ? max( 0, min( 10000, (int) $doc['vw'] ) ) : 0,
		);
		return $out['w'] > 0 && $out['h'] > 0 ? $out : null;
	}

	/** Eliminando una nota (anche svuotando il cestino) si eliminano le sue risposte. */
	public static function delete_replies( $post_id ) {
		if ( self::TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		foreach ( self::replies( $post_id ) as $reply ) {
			wp_delete_post( $reply->ID, true );
		}
	}
}

add_action( 'before_delete_post', array( 'LU3G_PN_Notes', 'delete_replies' ) );
