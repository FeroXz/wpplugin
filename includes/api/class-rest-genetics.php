<?php
/**
 * REST-Endpunkt für den Genetik-Rechner (Punnett-Kreuzung zweier Tiere).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_REST_Genetics extends RM_REST_Controller {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/genetics/calculate',
			array(
				'summary' => __( 'Punnett-Kreuzung zweier Tiere berechnen.', 'reptilien-manager' ),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'calculate' ),
					'permission_callback' => array( __CLASS__, 'permission_read' ),
					'args'                => array(
						'parent1_id' => array(
							'type'        => 'integer',
							'required'    => true,
							'description' => __( 'Beitrags-ID des ersten Elternteils.', 'reptilien-manager' ),
						),
						'parent2_id' => array(
							'type'        => 'integer',
							'required'    => true,
							'description' => __( 'Beitrags-ID des zweiten Elternteils.', 'reptilien-manager' ),
						),
						'species'    => array(
							'type'        => 'string',
							'description' => __( 'Optionale Art-Vorgabe; ohne Angabe wird die Art des ersten Elternteils verwendet.', 'reptilien-manager' ),
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
	 * POST /genetics/calculate.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function calculate( $request ) {
		$parent1_id = (int) $request->get_param( 'parent1_id' );
		$parent2_id = (int) $request->get_param( 'parent2_id' );

		$parent1 = get_post( $parent1_id );
		$parent2 = get_post( $parent2_id );

		if ( ! self::can_read( $parent1, 'rm_animal' ) || ! self::can_read( $parent2, 'rm_animal' ) ) {
			return self::error( 'rm_animal_not_found', __( 'Eines der Elterntiere wurde nicht gefunden oder ist nicht zugänglich.', 'reptilien-manager' ), 404 );
		}

		$species = RM_Species::key_for_animal( $parent1_id );
		$requested_species = $request->get_param( 'species' );
		if ( $requested_species ) {
			$species = RM_Species::key_from_term_names( array( sanitize_text_field( $requested_species ) ) );
		}

		$genes        = RM_Genetics::genes( $species );
		$parent1_genes = RM_Genetics::get_animal_genes_for_species( $parent1_id, $species );
		$parent2_genes = RM_Genetics::get_animal_genes_for_species( $parent2_id, $species );

		$results = array();
		foreach ( $genes as $key => $gene ) {
			$sc = RM_Genetics::copies_from_state( isset( $parent1_genes[ $key ] ) ? $parent1_genes[ $key ] : '' );
			$dc = RM_Genetics::copies_from_state( isset( $parent2_genes[ $key ] ) ? $parent2_genes[ $key ] : '' );

			if ( 0 === $sc && 0 === $dc ) {
				continue;
			}

			$distribution = RM_Genetics::cross_gene( $sc, $dc );
			$row          = array();
			foreach ( $distribution as $copies => $probability ) {
				if ( 0 === $copies ) {
					$label = 'normal';
				} elseif ( 1 === $copies ) {
					$label = 'het_' . $key;
				} else {
					$label = $key;
				}
				$row[ $label ] = (int) round( $probability * 100 );
			}
			$results[ $key ] = $row;
		}

		$coi     = round( RM_Breeding::pair_coi( $parent1_id, $parent2_id ), 4 );
		$warning = RM_Breeding::coi_warning( $coi );

		$payload = array(
			'results' => $results,
			'coi'     => $coi,
			'warning' => in_array( $warning['level'], array( 'medium', 'high' ), true ) ? $warning['label'] : null,
		);

		return new WP_REST_Response( $payload, 200 );
	}

	/**
	 * OpenAPI-3.0-Pfadfragment dieses Controllers.
	 *
	 * @return array
	 */
	public static function openapi_paths() {
		return array(
			'/genetics/calculate' => array(
				'post' => array(
					'summary'     => __( 'Punnett-Kreuzung zweier Tiere berechnen.', 'reptilien-manager' ),
					'requestBody' => array(
						'required' => true,
						'content'  => array(
							'application/json' => array(
								'schema' => array(
									'type'       => 'object',
									'required'   => array( 'parent1_id', 'parent2_id' ),
									'properties' => array(
										'parent1_id' => array( 'type' => 'integer' ),
										'parent2_id' => array( 'type' => 'integer' ),
										'species'    => array( 'type' => 'string' ),
									),
								),
							),
						),
					),
					'responses'   => array(
						'200' => array(
							'description' => __( 'Erfolgreich.', 'reptilien-manager' ),
							'content'     => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'properties' => array(
											'results' => array( 'type' => 'object' ),
											'coi'     => array( 'type' => 'number' ),
											'warning' => array(
												'type'     => 'string',
												'nullable' => true,
											),
										),
									),
								),
							),
						),
						'404' => array( 'description' => __( 'Elterntier nicht gefunden oder nicht zugänglich.', 'reptilien-manager' ) ),
						'401' => array( 'description' => __( 'Fehlender oder ungültiger API-Key.', 'reptilien-manager' ) ),
					),
				),
			),
		);
	}
}
