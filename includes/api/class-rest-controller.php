<?php
/**
 * Basisklasse für die REST-API-Controller des Reptilien Managers:
 * gemeinsame Helfer für Fehlerantworten, Paginierung, ETag-Unterstützung
 * und Besitzer-Berechtigung.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_REST_Controller {

	/** REST-Namespace inkl. Version. */
	const NAMESPACE = 'reptilien/v1';

	/**
	 * Fehlerantwort im Format { code, message, data }.
	 *
	 * WP_REST_Server wandelt einen zurückgegebenen WP_Error automatisch in
	 * genau dieses JSON-Format um (code/message aus dem Error, data inkl.
	 * "status" aus den Error-Daten).
	 *
	 * @param string $code    Fehlercode (z. B. 'rm_not_found').
	 * @param string $message Lesbare Meldung.
	 * @param int    $status  HTTP-Status.
	 * @param array  $data    Zusätzliche Daten.
	 * @return WP_Error
	 */
	protected static function error( $code, $message, $status = 400, $data = array() ) {
		$data['status'] = $status;
		return new WP_Error( $code, $message, $data );
	}

	/**
	 * Paginierungs-Parameter aus dem Request lesen (begrenzt auf 1–100).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return array { per_page, page }
	 */
	protected static function pagination_args( $request ) {
		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 20;

		$page = (int) $request->get_param( 'page' );
		$page = $page > 0 ? $page : 1;

		return array( $per_page, $page );
	}

	/**
	 * Autoren-Scope für Abfragen: eigene Tiere/Verpaarungen/Fütterungen,
	 * es sei denn der Nutzer hat edit_others_posts.
	 *
	 * @return array
	 */
	protected static function scope_query_args() {
		return class_exists( 'RM_Roles' ) ? RM_Roles::author_query_args() : array();
	}

	/**
	 * Darf der aktuelle (per API-Key authentifizierte) Nutzer diesen Beitrag
	 * verwalten? Eigene Beiträge immer, fremde nur mit edit_others_posts.
	 *
	 * @param WP_Post|null $post      Beitrag.
	 * @param string       $post_type Erwarteter Post-Type.
	 * @return bool
	 */
	protected static function can_manage( $post, $post_type ) {
		if ( ! $post || $post_type !== $post->post_type ) {
			return false;
		}
		if ( (int) $post->post_author === get_current_user_id() ) {
			return true;
		}
		return current_user_can( 'edit_others_posts' );
	}

	/**
	 * Sichtbarkeits-Scope für die Abfrage eines einzelnen Beitrags: eigene
	 * Beiträge oder – mit edit_others_posts – alle.
	 *
	 * @param WP_Post|null $post      Beitrag.
	 * @param string       $post_type Erwarteter Post-Type.
	 * @return bool
	 */
	protected static function can_read( $post, $post_type ) {
		return self::can_manage( $post, $post_type );
	}

	/**
	 * Antwort mit ETag-Unterstützung für GET-Anfragen: identische Nutzlast
	 * liefert bei passendem If-None-Match-Header 304 Not Modified.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @param mixed            $data    Nutzlast.
	 * @param int              $status  HTTP-Status bei Erfolg.
	 * @return WP_REST_Response
	 */
	protected static function with_etag( $request, $data, $status = 200 ) {
		$etag = '"' . md5( wp_json_encode( $data ) ) . '"';
		$sent = trim( (string) $request->get_header( 'if_none_match' ) );

		if ( $sent && trim( $sent, '"' ) === trim( $etag, '"' ) ) {
			$response = new WP_REST_Response( null, 304 );
		} else {
			$response = new WP_REST_Response( $data, $status );
		}

		$response->header( 'ETag', $etag );
		return $response;
	}

	/**
	 * Paginierungs-Metadaten (Gesamtzahl/-seiten) als Header + Rückgabewerte,
	 * analog zur WP-REST-Konvention (X-WP-Total/X-WP-TotalPages).
	 *
	 * @param WP_REST_Response $response Antwort.
	 * @param int              $total    Gesamtzahl der Treffer.
	 * @param int              $per_page Einträge pro Seite.
	 */
	protected static function add_pagination_headers( $response, $total, $per_page ) {
		$pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 0;
		$response->header( 'X-WP-Total', (string) $total );
		$response->header( 'X-WP-TotalPages', (string) $pages );
	}
}
