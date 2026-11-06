<?php
/**
 * API del frontend: /wp-json/lu3g-notes/v1/...
 *
 * GET    notes?url=<indirizzo della pagina>   note della pagina con le risposte
 * POST   notes                                 nuova nota { url, text, anchor, doc }
 * POST   notes/<id>                            modifica { text?, status?, anchor?, doc? }
 * DELETE notes/<id>                            elimina la nota e le sue risposte
 * POST   notes/<id>/replies                    nuova risposta { text }
 * DELETE replies/<id>                          elimina una risposta
 *
 * Tutte le rotte chiedono un utente collegato con il permesso delle impostazioni (LU3G_PN_Notes::can_use()).
 * Il nonce "wp_rest" (intestazione X-WP-Nonce) arriva dal frontend: senza, WordPress non riconosce l'utente.
 * Testo, stato e zona di una nota li cambia chi l'ha scritta o un amministratore; lo stato (risolta/aperta)
 * lo può cambiare chiunque possa usare le note.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LU3G_PN_Rest {

	const NS = 'lu3g-notes/v1';

	public static function register() {
		$can = array( __CLASS__, 'permission' );

		register_rest_route(
			self::NS,
			'/notes',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_notes' ),
					'permission_callback' => $can,
					'args'                => array(
						'url' => array(
							'type'     => 'string',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_note' ),
					'permission_callback' => $can,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( __CLASS__, 'update_note' ),
					'permission_callback' => $can,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_note' ),
					'permission_callback' => $can,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/notes/(?P<id>\d+)/replies',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_reply' ),
				'permission_callback' => $can,
			)
		);

		register_rest_route(
			self::NS,
			'/replies/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( __CLASS__, 'delete_reply' ),
				'permission_callback' => $can,
			)
		);
	}

	public static function permission() {
		if ( LU3G_PN_Notes::can_use() ) {
			return true;
		}
		return new WP_Error( 'lu3g_pn_forbidden', 'Non hai il permesso di usare le note.', array( 'status' => is_user_logged_in() ? 403 : 401 ) );
	}

	/* ------------------------------------------------------------------ note */

	public static function list_notes( WP_REST_Request $request ) {
		$key = LU3G_PN_Notes::page_key( $request->get_param( 'url' ) );
		return rest_ensure_response(
			array(
				'page'  => $key,
				'notes' => LU3G_PN_Notes::for_page( $key ),
			)
		);
	}

	public static function create_note( WP_REST_Request $request ) {
		$text   = LU3G_PN_Notes::clean_text( $request->get_param( 'text' ) );
		$url    = esc_url_raw( (string) $request->get_param( 'url' ) );
		$anchor = LU3G_PN_Notes::clean_anchor( $request->get_param( 'anchor' ) );
		$doc    = LU3G_PN_Notes::clean_doc( $request->get_param( 'doc' ) );

		if ( '' === trim( $text ) ) {
			return new WP_Error( 'lu3g_pn_empty', 'Scrivi il testo della nota.', array( 'status' => 400 ) );
		}
		if ( '' === $url || ! $doc ) {
			return new WP_Error( 'lu3g_pn_zone', 'Zona della pagina non valida: disegnala di nuovo.', array( 'status' => 400 ) );
		}

		$id = wp_insert_post(
			array(
				'post_type'    => LU3G_PN_Notes::TYPE,
				'post_status'  => 'publish',
				'post_title'   => wp_slash( LU3G_PN_Notes::title_from( $text ) ),
				'post_content' => wp_slash( $text ),
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		update_post_meta( $id, '_lu3g_pn_page', LU3G_PN_Notes::page_key( $url ) );
		update_post_meta( $id, '_lu3g_pn_url', $url );
		update_post_meta( $id, '_lu3g_pn_status', 'open' );
		update_post_meta( $id, '_lu3g_pn_doc', wp_json_encode( $doc ) );
		if ( $anchor ) {
			update_post_meta( $id, '_lu3g_pn_anchor', wp_slash( wp_json_encode( $anchor ) ) );
		}

		/** Nota creata: per notifiche, integrazioni, ecc. */
		do_action( 'lu3g_page_notes_created', $id );

		$response = rest_ensure_response( LU3G_PN_Notes::format( $id ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function update_note( WP_REST_Request $request ) {
		$post = self::note( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$owner = LU3G_PN_Notes::can_edit( $post );

		$status = $request->get_param( 'status' );
		if ( null !== $status ) {
			update_post_meta( $post->ID, '_lu3g_pn_status', 'resolved' === $status ? 'resolved' : 'open' );
		}

		$text   = $request->get_param( 'text' );
		$anchor = $request->get_param( 'anchor' );
		$doc    = $request->get_param( 'doc' );
		if ( null !== $text || null !== $anchor || null !== $doc ) {
			if ( ! $owner ) {
				return new WP_Error( 'lu3g_pn_owner', 'Solo chi ha scritto la nota o un amministratore può modificarla.', array( 'status' => 403 ) );
			}
			if ( null !== $text ) {
				$text = LU3G_PN_Notes::clean_text( $text );
				if ( '' === trim( $text ) ) {
					return new WP_Error( 'lu3g_pn_empty', 'Il testo della nota non può essere vuoto.', array( 'status' => 400 ) );
				}
				wp_update_post(
					array(
						'ID'           => $post->ID,
						'post_title'   => wp_slash( LU3G_PN_Notes::title_from( $text ) ),
						'post_content' => wp_slash( $text ),
					)
				);
			}
			if ( null !== $doc ) {
				$doc = LU3G_PN_Notes::clean_doc( $doc );
				if ( $doc ) {
					update_post_meta( $post->ID, '_lu3g_pn_doc', wp_json_encode( $doc ) );
					$anchor = LU3G_PN_Notes::clean_anchor( $anchor );
					if ( $anchor ) {
						update_post_meta( $post->ID, '_lu3g_pn_anchor', wp_slash( wp_json_encode( $anchor ) ) );
					} else {
						delete_post_meta( $post->ID, '_lu3g_pn_anchor' );
					}
				}
			}
		}

		return rest_ensure_response( LU3G_PN_Notes::format( $post->ID ) );
	}

	public static function delete_note( WP_REST_Request $request ) {
		$post = self::note( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! LU3G_PN_Notes::can_edit( $post ) ) {
			return new WP_Error( 'lu3g_pn_owner', 'Solo chi ha scritto la nota o un amministratore può eliminarla.', array( 'status' => 403 ) );
		}
		wp_delete_post( $post->ID, true ); // le risposte se ne vanno con LU3G_PN_Notes::delete_replies()
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/* ------------------------------------------------------------------ risposte */

	public static function create_reply( WP_REST_Request $request ) {
		$post = self::note( $request['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$text = LU3G_PN_Notes::clean_text( $request->get_param( 'text' ) );
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'lu3g_pn_empty', 'Scrivi il testo della risposta.', array( 'status' => 400 ) );
		}
		$id = wp_insert_post(
			array(
				'post_type'    => LU3G_PN_Notes::REPLY_TYPE,
				'post_status'  => 'publish',
				'post_parent'  => $post->ID,
				'post_title'   => wp_slash( LU3G_PN_Notes::title_from( $text ) ),
				'post_content' => wp_slash( $text ),
				'post_author'  => get_current_user_id(),
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		/** Risposta aggiunta a una nota. */
		do_action( 'lu3g_page_notes_replied', $id, $post->ID );

		$response = rest_ensure_response( LU3G_PN_Notes::format( $post->ID ) );
		$response->set_status( 201 );
		return $response;
	}

	public static function delete_reply( WP_REST_Request $request ) {
		$reply = get_post( (int) $request['id'] );
		if ( ! $reply || LU3G_PN_Notes::REPLY_TYPE !== $reply->post_type ) {
			return new WP_Error( 'lu3g_pn_missing', 'Risposta non trovata.', array( 'status' => 404 ) );
		}
		if ( ! LU3G_PN_Notes::can_edit( $reply ) ) {
			return new WP_Error( 'lu3g_pn_owner', 'Solo chi ha scritto la risposta o un amministratore può eliminarla.', array( 'status' => 403 ) );
		}
		$note_id = (int) $reply->post_parent;
		wp_delete_post( $reply->ID, true );
		return rest_ensure_response( LU3G_PN_Notes::format( $note_id ) );
	}

	/** Nota pubblicata con questo id, o errore 404. */
	private static function note( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post || LU3G_PN_Notes::TYPE !== $post->post_type || 'publish' !== $post->post_status ) {
			return new WP_Error( 'lu3g_pn_missing', 'Nota non trovata: forse è stata eliminata.', array( 'status' => 404 ) );
		}
		return $post;
	}
}
