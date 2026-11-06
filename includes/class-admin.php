<?php
/**
 * Bacheca: elenco delle note (menu "Note LU3G") e impostazioni (Impostazioni → Note LU3G).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_PN_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_setting' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( LU3G_PN_FILE ), array( __CLASS__, 'action_links' ) );

		$type = LU3G_PN_Notes::TYPE;
		add_filter( "manage_{$type}_posts_columns", array( __CLASS__, 'columns' ) );
		add_action( "manage_{$type}_posts_custom_column", array( __CLASS__, 'column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'add_meta_boxes_' . $type, array( __CLASS__, 'meta_box' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'status_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'apply_status_filter' ) );
	}

	/** Indirizzo della pagina con la nota aperta: lo script accende le note e apre quella. */
	public static function view_url( $post_id ) {
		$url = (string) get_post_meta( $post_id, '_lu3g_pn_url', true );
		return $url ? add_query_arg( 'lu3g-note', (int) $post_id, $url ) : '';
	}

	/* ------------------------------------------------------------------ impostazioni */

	public static function menu() {
		add_options_page( 'Note LU3G', 'Note LU3G', 'manage_options', 'lu3g-page-notes', array( __CLASS__, 'settings_page' ) );
	}

	public static function register_setting() {
		register_setting(
			'lu3g_page_notes',
			LU3G_PN_Notes::OPT_CAP,
			array(
				'type'              => 'string',
				'default'           => 'edit_pages',
				'sanitize_callback' => function ( $value ) {
					return isset( LU3G_PN_Notes::capabilities()[ $value ] ) ? $value : 'edit_pages';
				},
			)
		);
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=lu3g-page-notes' ) ) . '">Impostazioni</a>' );
		return $links;
	}

	public static function settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$current = (string) get_option( LU3G_PN_Notes::OPT_CAP, 'edit_pages' );
		?>
		<div class="wrap">
			<h1>Note LU3G</h1>
			<p>Le note si aggiungono guardando il sito: nella barra in alto premi <strong>Note LU3G</strong>, poi <strong>Nuova nota</strong> e trascina per disegnare la zona.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'lu3g_page_notes' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">Chi vede e scrive le note</th>
						<td>
							<fieldset>
								<?php foreach ( LU3G_PN_Notes::capabilities() as $cap => $label ) : ?>
									<label style="display:block;margin-bottom:6px">
										<input type="radio" name="<?php echo esc_attr( LU3G_PN_Notes::OPT_CAP ); ?>" value="<?php echo esc_attr( $cap ); ?>" <?php checked( $current, $cap ); ?>>
										<?php echo esc_html( $label ); ?>
									</label>
								<?php endforeach; ?>
								<p class="description">Solo utenti collegati. I visitatori del sito non vedono né le note né il pulsante. Una nota la modifica o la elimina chi l'ha scritta o un amministratore; chiunque possa usare le note può segnarla come risolta.</p>
							</fieldset>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ------------------------------------------------------------------ elenco */

	public static function columns( $columns ) {
		$out = array();
		foreach ( $columns as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'title' === $key ) {
				$out['lu3g_page']    = 'Pagina';
				$out['lu3g_status']  = 'Stato';
				$out['lu3g_replies'] = 'Risposte';
			}
		}
		return $out;
	}

	public static function column( $column, $post_id ) {
		switch ( $column ) {
			case 'lu3g_page':
				$page = (string) get_post_meta( $post_id, '_lu3g_pn_page', true );
				$url  = self::view_url( $post_id );
				if ( $url ) {
					printf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( $url ), esc_html( '/' === $page ? 'Home' : '/' . $page ) );
				}
				break;
			case 'lu3g_status':
				echo 'resolved' === get_post_meta( $post_id, '_lu3g_pn_status', true ) ? 'Risolta' : '<strong>Aperta</strong>';
				break;
			case 'lu3g_replies':
				echo (int) LU3G_PN_Notes::reply_count( $post_id );
				break;
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( LU3G_PN_Notes::TYPE === $post->post_type && 'trash' !== $post->post_status ) {
			$url = self::view_url( $post->ID );
			if ( $url ) {
				$actions = array( 'lu3g_view' => '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">Apri sul sito</a>' ) + $actions;
			}
			unset( $actions['inline hide-if-no-js'] ); // la modifica rapida non ha senso per le note
		}
		return $actions;
	}

	/** Filtro "Aperte / Risolte" sopra l'elenco. */
	public static function status_filter( $post_type ) {
		if ( LU3G_PN_Notes::TYPE !== $post_type ) {
			return;
		}
		$value = isset( $_GET['lu3g_status'] ) ? sanitize_key( $_GET['lu3g_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		echo '<select name="lu3g_status"><option value="">Tutti gli stati</option>';
		printf( '<option value="open" %s>Aperte</option>', selected( $value, 'open', false ) );
		printf( '<option value="resolved" %s>Risolte</option>', selected( $value, 'resolved', false ) );
		echo '</select>';
	}

	public static function apply_status_filter( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() || LU3G_PN_Notes::TYPE !== $query->get( 'post_type' ) ) {
			return;
		}
		$value = isset( $_GET['lu3g_status'] ) ? sanitize_key( $_GET['lu3g_status'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'resolved' === $value ) {
			$query->set( 'meta_query', array( array( 'key' => '_lu3g_pn_status', 'value' => 'resolved' ) ) );
		} elseif ( 'open' === $value ) {
			$query->set( 'meta_query', array( array( 'key' => '_lu3g_pn_status', 'value' => 'resolved', 'compare' => '!=' ) ) );
		}
	}

	/* ------------------------------------------------------------------ modifica */

	public static function meta_box() {
		add_meta_box( 'lu3g-pn-info', 'Pagina e risposte', array( __CLASS__, 'render_meta_box' ), LU3G_PN_Notes::TYPE, 'side' );
	}

	public static function render_meta_box( $post ) {
		$url = self::view_url( $post->ID );
		if ( $url ) {
			printf( '<p><a class="button" href="%s" target="_blank" rel="noopener">Apri sul sito</a></p>', esc_url( $url ) );
		}
		$replies = LU3G_PN_Notes::replies( $post->ID );
		if ( ! $replies ) {
			echo '<p>Nessuna risposta.</p>';
			return;
		}
		foreach ( $replies as $reply ) {
			$author = LU3G_PN_Notes::author( $reply->post_author );
			printf(
				'<p><strong>%s</strong> · %s<br>%s</p>',
				esc_html( $author['name'] ),
				esc_html( get_the_date( '', $reply ) ),
				nl2br( esc_html( $reply->post_content ) )
			);
		}
	}
}
