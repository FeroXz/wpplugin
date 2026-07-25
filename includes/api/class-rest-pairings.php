<?php
/**
 * REST-Endpunkt für Verpaarungen (Liste, paginiert).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_REST_Pairings extends RM_REST_Controller {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/pairings',
			array(
				'summary' => __( 'Verpaarungen auflisten.', 'reptilien-manager' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_pairings' ),
					'permission_callback' => array( __CLASS__, 'permission_read' ),
					'args'                => array(
						'per_page' => array(
							'type'        => 'integer',
							'default'     => 20,
							'description' => __( 'Einträge pro Seite (1–100).', 'reptilien-manager' ),
						),
						'page'     => array(
							'type'        => 'integer',
							'default'     => 1,
							'description' => __( 'Seitenzahl.', 'reptilien-manager' ),
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
	 * GET /pairings – paginierte Liste (eigene Verpaarungen bzw. alle mit
	 * edit_others_posts).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function list_pairings( $request ) {
		list( $per_page, $page ) = self::pagination_args( $request );

		$query_args = array_merge(
			array(
				'post_type'      => 'rm_pairing',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			),
			self::scope_query_args()
		);

		$query = new WP_Query( $query_args );

		$pairings = array();
		foreach ( $query->posts as $pairing ) {
			$pairings[] = self::pairing_summary( $pairing );
		}

		$payload = array(
			'pairings' => $pairings,
			'total'    => (int) $query->found_posts,
			'pages'    => (int) $query->max_num_pages,
		);

		$response = self::with_etag( $request, $payload );
		self::add_pagination_headers( $response, $query->found_posts, $per_page );
		return $response;
	}

	/**
	 * Kurzform einer Verpaarung.
	 *
	 * @param WP_Post $pairing Beitrag.
	 * @return array
	 */
	private static function pairing_summary( $pairing ) {
		$sire = (int) get_post_meta( $pairing->ID, '_rm_sire', true );
		$dam  = (int) get_post_meta( $pairing->ID, '_rm_dam', true );

		$clutches     = class_exists( 'RM_Pairing' ) ? RM_Pairing::get_clutches( $pairing->ID ) : array();
		$eggs_total   = 0;
		$hatch_total  = 0;
		foreach ( $clutches as $clutch ) {
			$eggs_total  += $clutch['eggs'];
			$hatch_total += $clutch['hatched'];
		}

		return array(
			'id'                => $pairing->ID,
			'title'             => $pairing->post_title,
			'sire_id'           => $sire,
			'sire_name'         => $sire ? get_the_title( $sire ) : '',
			'dam_id'            => $dam,
			'dam_name'          => $dam ? get_the_title( $dam ) : '',
			'pairing_date'      => get_post_meta( $pairing->ID, '_rm_pairing_date', true ),
			'incubation_temp'   => get_post_meta( $pairing->ID, '_rm_incubation_temp', true ),
			'clutch_count'      => count( $clutches ),
			'eggs_total'        => $eggs_total,
			'hatched_total'     => $hatch_total,
			'coi'               => $sire && $dam && class_exists( 'RM_Breeding' ) ? round( RM_Breeding::pair_coi( $sire, $dam ), 4 ) : null,
		);
	}

	/**
	 * OpenAPI-3.0-Pfadfragment dieses Controllers.
	 *
	 * @return array
	 */
	public static function openapi_paths() {
		return array(
			'/pairings' => array(
				'get' => array(
					'summary'    => __( 'Verpaarungen auflisten (paginiert).', 'reptilien-manager' ),
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
					),
					'responses'  => array(
						'200' => array(
							'description' => __( 'Erfolgreich.', 'reptilien-manager' ),
							'content'     => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'properties' => array(
											'pairings' => array(
												'type'  => 'array',
												'items' => array(
													'type'       => 'object',
													'properties' => array(
														'id'              => array( 'type' => 'integer' ),
														'title'           => array( 'type' => 'string' ),
														'sire_id'         => array( 'type' => 'integer' ),
														'sire_name'       => array( 'type' => 'string' ),
														'dam_id'          => array( 'type' => 'integer' ),
														'dam_name'        => array( 'type' => 'string' ),
														'pairing_date'    => array( 'type' => 'string' ),
														'incubation_temp' => array( 'type' => 'number' ),
														'clutch_count'    => array( 'type' => 'integer' ),
														'eggs_total'      => array( 'type' => 'integer' ),
														'hatched_total'   => array( 'type' => 'integer' ),
														'coi'             => array(
															'type'     => 'number',
															'nullable' => true,
														),
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
