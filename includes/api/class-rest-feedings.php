<?php
/**
 * REST-Endpunkt für das Fütterungsprotokoll (Liste, paginiert).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_REST_Feedings extends RM_REST_Controller {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/feedings',
			array(
				'summary' => __( 'Fütterungen auflisten oder anlegen.', 'reptilien-manager' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_feedings' ),
					'permission_callback' => array( __CLASS__, 'permission_read' ),
					'args'                => array(
						'per_page'  => array(
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Einträge pro Seite (1–100).', 'reptilien-manager' ),
						),
						'page'      => array(
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Seitenzahl.', 'reptilien-manager' ),
						),
						'animal_id' => array(
							'type'        => 'integer',
							'description' => __( 'Nur Fütterungen eines bestimmten Tieres.', 'reptilien-manager' ),
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_feeding' ),
					'permission_callback' => array( __CLASS__, 'permission_write' ),
					'args'                => array(
						'animal_ids'  => array(
							'type'        => 'array',
							'required'    => true,
							'description' => __( 'Beitrags-IDs der gefütterten Tiere.', 'reptilien-manager' ),
						),
						'date'        => array(
							'type'        => 'string',
							'description' => __( 'Datum (YYYY-MM-DD), Standard heute.', 'reptilien-manager' ),
						),
						'foods'       => array(
							'type'        => 'array',
							'required'    => true,
							'description' => __( 'Futterarten-Schlüssel.', 'reptilien-manager' ),
						),
						'amount'      => array(
							'type'        => 'string',
							'description' => __( 'Mengenangabe.', 'reptilien-manager' ),
						),
						'supplements' => array(
							'type'        => 'array',
							'description' => __( 'Supplement-Schlüssel.', 'reptilien-manager' ),
						),
						'notes'       => array(
							'type'        => 'string',
							'description' => __( 'Notizen.', 'reptilien-manager' ),
						),
					),
				),
			)
		);
	}

	public static function permission_read( $request ) {
		return current_user_can( 'read' ) ? true : self::error( 'rm_forbidden', __( 'Keine Berechtigung.', 'reptilien-manager' ), 403 );
	}

	public static function permission_write( $request ) {
		return current_user_can( 'edit_posts' ) ? true : self::error( 'rm_forbidden', __( 'Keine Berechtigung.', 'reptilien-manager' ), 403 );
	}

	/**
	 * GET /feedings – paginierte Liste (eigene Fütterungen bzw. alle mit
	 * edit_others_posts), optional gefiltert nach Tier.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function list_feedings( $request ) {
		list( $per_page, $page ) = self::pagination_args( $request );

		$query_args = array(
			'post_type'      => 'rm_feeding_log',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'orderby'        => 'meta_value',
			'meta_key'       => '_rm_feed_date', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'DESC',
		);

		$animal_id = (int) $request->get_param( 'animal_id' );
		if ( $animal_id ) {
			$query_args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => '_rm_feed_animal',
					'value' => $animal_id,
				),
			);
		}

		$query_args = array_merge( $query_args, self::scope_query_args() );

		$query = new WP_Query( $query_args );

		$feedings = array();
		foreach ( $query->posts as $log ) {
			$feedings[] = self::feeding_summary( $log );
		}

		$payload = array(
			'feedings' => $feedings,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
		);

		$response = self::with_etag( $request, $payload );
		self::add_pagination_headers( $response, $query->found_posts, $per_page );
		return $response;
	}

	/**
	 * POST /feedings – neuen Fütterungs-Eintrag anlegen (dieselbe
	 * Speicherlogik wie der Schnelleintrag im Backend/Frontend, siehe
	 * RM_Feeding::create_log()).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_feeding( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		$requested_ids = isset( $params['animal_ids'] ) ? array_map( 'absint', (array) $params['animal_ids'] ) : array();
		$animal_ids    = array();
		foreach ( array_unique( array_filter( $requested_ids ) ) as $animal_id ) {
			$animal = get_post( $animal_id );
			if ( self::can_manage( $animal, 'rm_animal' ) ) {
				$animal_ids[] = $animal_id;
			}
		}

		if ( ! $animal_ids ) {
			return self::error( 'rm_invalid_animals', __( 'Kein zugängliches Tier angegeben.', 'reptilien-manager' ), 400 );
		}

		$foods       = isset( $params['foods'] ) ? array_map( 'sanitize_key', (array) $params['foods'] ) : array();
		$supplements = isset( $params['supplements'] ) ? array_map( 'sanitize_key', (array) $params['supplements'] ) : array();
		$date        = isset( $params['date'] ) ? sanitize_text_field( $params['date'] ) : '';
		$amount      = isset( $params['amount'] ) ? sanitize_text_field( $params['amount'] ) : '';
		$notes       = isset( $params['notes'] ) ? sanitize_textarea_field( $params['notes'] ) : '';

		if ( ! class_exists( 'RM_Feeding' ) ) {
			return self::error( 'rm_unavailable', __( 'Fütterungs-Modul nicht verfügbar.', 'reptilien-manager' ), 500 );
		}

		$log_id = RM_Feeding::create_log( $animal_ids, $date, $foods, $amount, $supplements, $notes );

		if ( is_wp_error( $log_id ) ) {
			return self::error( 'rm_invalid_feeding', $log_id->get_error_message(), 400 );
		}

		return new WP_REST_Response( self::feeding_summary( get_post( $log_id ) ), 201 );
	}

	/**
	 * Kurzform eines Fütterungs-Eintrags.
	 *
	 * @param WP_Post $log Beitrag.
	 * @return array
	 */
	private static function feeding_summary( $log ) {
		$animal_ids = class_exists( 'RM_Feeding' ) ? RM_Feeding::animals_for_log( $log->ID ) : array();
		$animals    = array();
		foreach ( $animal_ids as $animal_id ) {
			$animals[] = array(
				'id'   => $animal_id,
				'name' => get_the_title( $animal_id ),
			);
		}

		return array(
			'id'          => $log->ID,
			'title'       => $log->post_title,
			'date'        => get_post_meta( $log->ID, '_rm_feed_date', true ),
			'animals'     => $animals,
			'foods'       => class_exists( 'RM_Feeding' ) ? RM_Feeding::foods_for_log( $log->ID ) : array(),
			'amount'      => get_post_meta( $log->ID, '_rm_feed_amount', true ),
			'supplements' => (array) get_post_meta( $log->ID, '_rm_feed_supplements', true ),
			'notes'       => get_post_meta( $log->ID, '_rm_feed_notes', true ),
		);
	}

	/**
	 * OpenAPI-3.0-Pfadfragment dieses Controllers.
	 *
	 * @return array
	 */
	public static function openapi_paths() {
		return array(
			'/feedings' => array(
				'get' => array(
					'summary'    => __( 'Fütterungen auflisten (paginiert, optional nach Tier gefiltert).', 'reptilien-manager' ),
					'parameters' => array(
						array(
							'name'   => 'per_page',
							'in'     => 'query',
							'schema' => array(
								'type'    => 'integer',
								'default' => 20,
							),
						),
						array(
							'name'   => 'page',
							'in'     => 'query',
							'schema' => array(
								'type'    => 'integer',
								'default' => 1,
							),
						),
						array(
							'name'   => 'animal_id',
							'in'     => 'query',
							'schema' => array( 'type' => 'integer' ),
						),
					),
					'responses'  => array(
						'200' => array(
							'description' => __( 'Erfolgreich.', 'reptilien-manager' ),
							'content'     => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'properties' => array(
											'feedings' => array(
												'type'  => 'array',
												'items' => array(
													'type'       => 'object',
													'properties' => array(
														'id'          => array( 'type' => 'integer' ),
														'title'       => array( 'type' => 'string' ),
														'date'        => array( 'type' => 'string' ),
														'animals'     => array( 'type' => 'array' ),
														'foods'       => array( 'type' => 'array' ),
														'amount'      => array( 'type' => 'string' ),
														'supplements' => array( 'type' => 'array' ),
														'notes'       => array( 'type' => 'string' ),
													),
												),
											),
											'total'    => array( 'type' => 'integer' ),
											'pages'    => array( 'type' => 'integer' ),
										),
									),
								),
							),
						),
						'401' => array( 'description' => __( 'Fehlender oder ungültiger API-Key.', 'reptilien-manager' ) ),
					),
				),
				'post' => array(
					'summary'     => __( 'Fütterung anlegen (Quick-Feeding).', 'reptilien-manager' ),
					'requestBody' => array(
						'required' => true,
						'content'  => array(
							'application/json' => array(
								'schema' => array(
									'type'       => 'object',
									'required'   => array( 'animal_ids', 'foods' ),
									'properties' => array(
										'animal_ids'  => array(
											'type'  => 'array',
											'items' => array( 'type' => 'integer' ),
										),
										'date'        => array( 'type' => 'string' ),
										'foods'       => array(
											'type'  => 'array',
											'items' => array( 'type' => 'string' ),
										),
										'amount'      => array( 'type' => 'string' ),
										'supplements' => array(
											'type'  => 'array',
											'items' => array( 'type' => 'string' ),
										),
										'notes'       => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'responses'   => array(
						'201' => array(
							'description' => __( 'Angelegt.', 'reptilien-manager' ),
							'content'     => array( 'application/json' => array( 'schema' => array( 'type' => 'object' ) ) ),
						),
						'400' => array( 'description' => __( 'Ungültige Eingabe oder kein zugängliches Tier.', 'reptilien-manager' ) ),
						'401' => array( 'description' => __( 'Fehlender oder ungültiger API-Key.', 'reptilien-manager' ) ),
					),
				),
			),
		);
	}
}
