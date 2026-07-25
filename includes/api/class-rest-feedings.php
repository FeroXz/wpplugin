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
				'summary' => __( 'Fütterungen auflisten.', 'reptilien-manager' ),
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
			)
		);
	}

	public static function permission_read( $request ) {
		return current_user_can( 'read' ) ? true : self::error( 'rm_forbidden', __( 'Keine Berechtigung.', 'reptilien-manager' ), 403 );
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
			),
		);
	}
}
