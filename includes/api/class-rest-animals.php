<?php
/**
 * REST-Endpunkte für Tiere (CRUD) sowie die Bestands-Statistik.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_REST_Animals extends RM_REST_Controller {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/animals',
			array(
				'summary'     => __( 'Tiere auflisten oder anlegen.', 'reptilien-manager' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'list_animals' ),
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
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'create_animal' ),
					'permission_callback' => array( __CLASS__, 'permission_write' ),
					'args'                => self::animal_schema(),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/animals/(?P<id>\d+)',
			array(
				'summary'     => __( 'Ein einzelnes Tier lesen, aktualisieren oder löschen.', 'reptilien-manager' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'get_animal' ),
					'permission_callback' => array( __CLASS__, 'permission_read' ),
					'args'                => array(
						'id' => array(
							'type'        => 'integer',
							'required'    => true,
							'description' => __( 'Beitrags-ID des Tieres.', 'reptilien-manager' ),
						),
					),
				),
				array(
					'methods'             => 'PUT',
					'callback'            => array( __CLASS__, 'update_animal' ),
					'permission_callback' => array( __CLASS__, 'permission_write' ),
					'args'                => array_merge(
						array(
							'id' => array(
								'type'        => 'integer',
								'required'    => true,
								'description' => __( 'Beitrags-ID des Tieres.', 'reptilien-manager' ),
							),
						),
						self::animal_schema( false )
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( __CLASS__, 'delete_animal' ),
					'permission_callback' => array( __CLASS__, 'permission_write' ),
					'args'                => array(
						'id'    => array(
							'type'        => 'integer',
							'required'    => true,
							'description' => __( 'Beitrags-ID des Tieres.', 'reptilien-manager' ),
						),
						'force' => array(
							'type'        => 'boolean',
							'default'     => false,
							'description' => __( 'true = endgültig löschen statt in den Papierkorb zu verschieben.', 'reptilien-manager' ),
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/stats',
			array(
				'summary' => __( 'Bestands-Statistik (eigene Tiere bzw. alle mit edit_others_posts).', 'reptilien-manager' ),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'stats' ),
					'permission_callback' => array( __CLASS__, 'permission_read' ),
				),
			)
		);
	}

	/**
	 * OpenAPI-3.0-Pfadfragment dieses Controllers (aggregiert von
	 * RM_OpenAPI_Generator). Lebt bewusst direkt neben register_routes(),
	 * damit Endpunkt und Dokumentation nicht auseinanderlaufen.
	 *
	 * @return array Pfad => { Methode => Operation }.
	 */
	public static function openapi_paths() {
		$animal_properties = array(
			'id'         => array( 'type' => 'integer' ),
			'name'       => array( 'type' => 'string' ),
			'species'    => array( 'type' => 'string' ),
			'sex'        => array(
				'type' => 'string',
				'enum' => array( 'male', 'female', 'unknown' ),
			),
			'hatch_date' => array( 'type' => 'string', 'format' => 'date' ),
			'weight'     => array( 'type' => 'integer', 'nullable' => true ),
			'status'     => array( 'type' => 'string' ),
			'photo'      => array(
				'type'     => 'string',
				'nullable' => true,
			),
		);

		$animal_schema = array(
			'type'       => 'object',
			'properties' => $animal_properties,
		);

		$detail_schema = array(
			'type'       => 'object',
			'properties' => array_merge(
				$animal_properties,
				array(
					'origin'         => array( 'type' => 'string' ),
					'length'         => array( 'type' => 'number' ),
					'public'         => array( 'type' => 'boolean' ),
					'morph'          => array( 'type' => 'string' ),
					'genetics'       => array( 'type' => 'object' ),
					'weight_history' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'date'  => array( 'type' => 'string' ),
								'grams' => array( 'type' => 'integer' ),
							),
						),
					),
					'notes'          => array( 'type' => 'string' ),
					'sales_status'   => array( 'type' => 'string' ),
					'owner_id'       => array( 'type' => 'integer' ),
				)
			),
		);

		$id_param = array(
			'name'     => 'id',
			'in'       => 'path',
			'required' => true,
			'schema'   => array( 'type' => 'integer' ),
		);

		$not_found = array(
			'description' => __( 'Nicht gefunden oder keine Berechtigung.', 'reptilien-manager' ),
		);
		$unauthorized = array(
			'description' => __( 'Fehlender oder ungültiger API-Key.', 'reptilien-manager' ),
		);

		return array(
			'/animals'      => array(
				'get'  => array(
					'summary'    => __( 'Tiere auflisten (paginiert).', 'reptilien-manager' ),
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
											'animals' => array(
												'type'  => 'array',
												'items' => $animal_schema,
											),
											'total'   => array( 'type' => 'integer' ),
											'pages'   => array( 'type' => 'integer' ),
										),
									),
								),
							),
						),
						'401' => $unauthorized,
					),
				),
				'post' => array(
					'summary'     => __( 'Neues Tier anlegen.', 'reptilien-manager' ),
					'requestBody' => array(
						'required' => true,
						'content'  => array(
							'application/json' => array( 'schema' => $detail_schema ),
						),
					),
					'responses'   => array(
						'201' => array(
							'description' => __( 'Angelegt.', 'reptilien-manager' ),
							'content'     => array( 'application/json' => array( 'schema' => $detail_schema ) ),
						),
						'400' => array( 'description' => __( 'Ungültige Eingabe.', 'reptilien-manager' ) ),
						'401' => $unauthorized,
					),
				),
			),
			'/animals/{id}' => array(
				'get'    => array(
					'summary'    => __( 'Ein Tier lesen.', 'reptilien-manager' ),
					'parameters' => array( $id_param ),
					'responses'  => array(
						'200' => array(
							'description' => __( 'Erfolgreich.', 'reptilien-manager' ),
							'content'     => array( 'application/json' => array( 'schema' => $detail_schema ) ),
						),
						'404' => $not_found,
						'401' => $unauthorized,
					),
				),
				'put'    => array(
					'summary'     => __( 'Ein Tier aktualisieren.', 'reptilien-manager' ),
					'parameters'  => array( $id_param ),
					'requestBody' => array(
						'required' => false,
						'content'  => array(
							'application/json' => array( 'schema' => $detail_schema ),
						),
					),
					'responses'   => array(
						'200' => array(
							'description' => __( 'Aktualisiert.', 'reptilien-manager' ),
							'content'     => array( 'application/json' => array( 'schema' => $detail_schema ) ),
						),
						'403' => array( 'description' => __( 'Keine Berechtigung für dieses Tier.', 'reptilien-manager' ) ),
						'404' => $not_found,
					),
				),
				'delete' => array(
					'summary'    => __( 'Ein Tier löschen.', 'reptilien-manager' ),
					'parameters' => array(
						$id_param,
						array(
							'name'   => 'force',
							'in'     => 'query',
							'schema' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						),
					),
					'responses'  => array(
						'200' => array(
							'description' => __( 'Gelöscht.', 'reptilien-manager' ),
							'content'     => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'properties' => array(
											'deleted' => array( 'type' => 'boolean' ),
											'id'      => array( 'type' => 'integer' ),
										),
									),
								),
							),
						),
						'403' => array( 'description' => __( 'Keine Berechtigung für dieses Tier.', 'reptilien-manager' ) ),
						'404' => $not_found,
					),
				),
			),
			'/stats'        => array(
				'get' => array(
					'summary'   => __( 'Bestands-Statistik.', 'reptilien-manager' ),
					'responses' => array(
						'200' => array(
							'description' => __( 'Erfolgreich.', 'reptilien-manager' ),
							'content'     => array(
								'application/json' => array(
									'schema' => array(
										'type'       => 'object',
										'properties' => array(
											'total_animals'     => array( 'type' => 'integer' ),
											'female'             => array( 'type' => 'integer' ),
											'male'               => array( 'type' => 'integer' ),
											'unknown'            => array( 'type' => 'integer' ),
											'avg_weight'         => array(
												'type'     => 'integer',
												'nullable' => true,
											),
											'avg_age_days'       => array(
												'type'     => 'integer',
												'nullable' => true,
											),
											'species_breakdown'  => array( 'type' => 'object' ),
											'morphs'             => array(
												'type'  => 'array',
												'items' => array(
													'type'       => 'object',
													'properties' => array(
														'name'  => array( 'type' => 'string' ),
														'count' => array( 'type' => 'integer' ),
													),
												),
											),
										),
									),
								),
							),
						),
						'401' => $unauthorized,
					),
				),
			),
		);
	}

	/**
	 * Request-Schema für Anlegen/Aktualisieren eines Tieres.
	 *
	 * @param bool $required_name Ob "name" Pflicht ist (nein beim Update).
	 * @return array
	 */
	private static function animal_schema( $required_name = true ) {
		return array(
			'name'       => array(
				'type'              => 'string',
				'required'          => $required_name,
				'description'       => __( 'Name des Tieres.', 'reptilien-manager' ),
				'sanitize_callback' => 'sanitize_text_field',
			),
			'species'    => array(
				'type'        => 'string',
				'description' => __( 'Art (z. B. "Pogona vitticeps" oder "Iguana iguana").', 'reptilien-manager' ),
			),
			'sex'        => array(
				'type'        => 'string',
				'enum'        => array( 'male', 'female', 'unknown' ),
				'description' => __( 'Geschlecht.', 'reptilien-manager' ),
			),
			'hatch_date' => array(
				'type'        => 'string',
				'description' => __( 'Schlupfdatum (YYYY-MM-DD).', 'reptilien-manager' ),
			),
			'origin'     => array(
				'type'        => 'string',
				'description' => __( 'Herkunft/Züchter.', 'reptilien-manager' ),
			),
			'length'     => array(
				'type'        => 'number',
				'description' => __( 'Gesamtlänge in cm.', 'reptilien-manager' ),
			),
			'weight'     => array(
				'type'        => 'integer',
				'description' => __( 'Aktuelles Gewicht in Gramm (wird dem Gewichtsverlauf hinzugefügt).', 'reptilien-manager' ),
			),
			'public'     => array(
				'type'        => 'boolean',
				'description' => __( 'Im Frontend öffentlich sichtbar.', 'reptilien-manager' ),
			),
			'genetics'   => array(
				'type'        => 'object',
				'description' => __( 'Gen-Schlüssel => Zustand ("het" oder "homo"), passend zur Art.', 'reptilien-manager' ),
			),
			'notes'      => array(
				'type'              => 'string',
				'description'       => __( 'Beitragstext/Notizen.', 'reptilien-manager' ),
				'sanitize_callback' => 'wp_kses_post',
			),
		);
	}

	/* ---------------------------------------------------------------------
	 * Berechtigungen
	 * ------------------------------------------------------------------ */

	public static function permission_read( $request ) {
		return current_user_can( 'read' ) ? true : self::error( 'rm_forbidden', __( 'Keine Berechtigung.', 'reptilien-manager' ), 403 );
	}

	public static function permission_write( $request ) {
		return current_user_can( 'edit_posts' ) ? true : self::error( 'rm_forbidden', __( 'Keine Berechtigung.', 'reptilien-manager' ), 403 );
	}

	/* ---------------------------------------------------------------------
	 * Callbacks
	 * ------------------------------------------------------------------ */

	/**
	 * GET /animals – paginierte Liste.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function list_animals( $request ) {
		list( $per_page, $page ) = self::pagination_args( $request );

		$query_args = array_merge(
			array(
				'post_type'      => 'rm_animal',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
			),
			self::scope_query_args()
		);

		$query = new WP_Query( $query_args );

		$animals = array();
		foreach ( $query->posts as $animal ) {
			$animals[] = self::animal_summary( $animal );
		}

		$payload = array(
			'animals' => $animals,
			'total'   => (int) $query->found_posts,
			'pages'   => (int) $query->max_num_pages,
		);

		$response = self::with_etag( $request, $payload );
		self::add_pagination_headers( $response, $query->found_posts, $per_page );
		return $response;
	}

	/**
	 * GET /animals/{id} – Detail.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function get_animal( $request ) {
		$animal = get_post( (int) $request->get_param( 'id' ) );

		if ( ! self::can_read( $animal, 'rm_animal' ) ) {
			return self::error( 'rm_animal_not_found', __( 'Tier nicht gefunden oder keine Berechtigung.', 'reptilien-manager' ), 404 );
		}

		return self::with_etag( $request, self::animal_detail( $animal ) );
	}

	/**
	 * POST /animals – anlegen.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_animal( $request ) {
		$name = trim( (string) $request->get_param( 'name' ) );
		if ( '' === $name ) {
			return self::error( 'rm_invalid_name', __( 'Name ist erforderlich.', 'reptilien-manager' ), 400 );
		}

		$animal_id = wp_insert_post(
			array(
				'post_type'   => 'rm_animal',
				'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
				'post_title'  => $name,
				'post_author' => get_current_user_id(),
			),
			true
		);

		if ( is_wp_error( $animal_id ) ) {
			return self::error( 'rm_create_failed', $animal_id->get_error_message(), 500 );
		}

		self::apply_fields( $animal_id, $request );

		$animal = get_post( $animal_id );
		return new WP_REST_Response( self::animal_detail( $animal ), 201 );
	}

	/**
	 * PUT /animals/{id} – aktualisieren.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_animal( $request ) {
		$animal = get_post( (int) $request->get_param( 'id' ) );

		if ( ! $animal || 'rm_animal' !== $animal->post_type ) {
			return self::error( 'rm_animal_not_found', __( 'Tier nicht gefunden.', 'reptilien-manager' ), 404 );
		}
		if ( ! self::can_manage( $animal, 'rm_animal' ) ) {
			return self::error( 'rm_forbidden', __( 'Keine Berechtigung für dieses Tier.', 'reptilien-manager' ), 403 );
		}

		$name = $request->get_param( 'name' );
		if ( null !== $name && '' !== trim( (string) $name ) ) {
			wp_update_post(
				array(
					'ID'         => $animal->ID,
					'post_title' => sanitize_text_field( $name ),
				)
			);
		}

		self::apply_fields( $animal->ID, $request );

		return new WP_REST_Response( self::animal_detail( get_post( $animal->ID ) ), 200 );
	}

	/**
	 * DELETE /animals/{id}.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_animal( $request ) {
		$animal = get_post( (int) $request->get_param( 'id' ) );

		if ( ! $animal || 'rm_animal' !== $animal->post_type ) {
			return self::error( 'rm_animal_not_found', __( 'Tier nicht gefunden.', 'reptilien-manager' ), 404 );
		}
		if ( ! self::can_manage( $animal, 'rm_animal' ) ) {
			return self::error( 'rm_forbidden', __( 'Keine Berechtigung für dieses Tier.', 'reptilien-manager' ), 403 );
		}

		$force  = (bool) $request->get_param( 'force' );
		$result = wp_delete_post( $animal->ID, $force );

		if ( ! $result ) {
			return self::error( 'rm_delete_failed', __( 'Löschen fehlgeschlagen.', 'reptilien-manager' ), 500 );
		}

		return new WP_REST_Response(
			array(
				'deleted' => true,
				'id'      => $animal->ID,
			),
			200
		);
	}

	/**
	 * GET /stats – Bestands-Statistik (autoren-gescopet wie die Listen).
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function stats( $request ) {
		$query_args = array_merge(
			array(
				'post_type'      => 'rm_animal',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			),
			self::scope_query_args()
		);

		$animals = get_posts( $query_args );

		$sex_counts = array(
			'male'    => 0,
			'female'  => 0,
			'unknown' => 0,
		);
		$species_breakdown = array();
		$morph_counts      = array();
		$weights           = array();
		$ages              = array();

		foreach ( $animals as $animal ) {
			$sex = get_post_meta( $animal->ID, '_rm_sex', true );
			if ( ! isset( $sex_counts[ $sex ] ) ) {
				$sex = 'unknown';
			}
			++$sex_counts[ $sex ];

			$species_label = RM_Species::label( RM_Species::key_for_animal( $animal->ID ) );
			$species_breakdown[ $species_label ] = isset( $species_breakdown[ $species_label ] ) ? $species_breakdown[ $species_label ] + 1 : 1;

			$morph = RM_Genetics::animal_morph_label( $animal->ID );
			$morph_counts[ $morph ] = isset( $morph_counts[ $morph ] ) ? $morph_counts[ $morph ] + 1 : 1;

			$w = get_post_meta( $animal->ID, '_rm_weights', true );
			if ( is_array( $w ) && $w ) {
				$last = end( $w );
				if ( ! empty( $last['grams'] ) ) {
					$weights[] = (int) $last['grams'];
				}
			}

			$birth = get_post_meta( $animal->ID, '_rm_birth', true );
			if ( $birth && strtotime( $birth ) && strtotime( $birth ) <= time() ) {
				$ages[] = (int) floor( ( time() - strtotime( $birth ) ) / DAY_IN_SECONDS );
			}
		}

		arsort( $morph_counts );
		$morphs = array();
		foreach ( $morph_counts as $name => $count ) {
			$morphs[] = array(
				'name'  => $name,
				'count' => $count,
			);
		}

		$payload = array(
			'total_animals'     => count( $animals ),
			'female'            => $sex_counts['female'],
			'male'              => $sex_counts['male'],
			'unknown'           => $sex_counts['unknown'],
			'avg_weight'        => $weights ? (int) round( array_sum( $weights ) / count( $weights ) ) : null,
			'avg_age_days'      => $ages ? (int) round( array_sum( $ages ) / count( $ages ) ) : null,
			'species_breakdown' => $species_breakdown,
			'morphs'            => $morphs,
		);

		return self::with_etag( $request, $payload );
	}

	/* ---------------------------------------------------------------------
	 * Feld-Verarbeitung / Serialisierung
	 * ------------------------------------------------------------------ */

	/**
	 * Übernimmt die Formularfelder eines Requests in die Post-Meta eines
	 * Tieres (gemeinsam für Anlegen und Aktualisieren).
	 *
	 * @param int              $animal_id Beitrags-ID.
	 * @param WP_REST_Request  $request   Anfrage.
	 */
	private static function apply_fields( $animal_id, $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) ) {
			$params = array();
		}

		if ( array_key_exists( 'sex', $params ) ) {
			$sex = sanitize_key( $params['sex'] );
			if ( array_key_exists( $sex, RM_Animal_Meta::sexes() ) ) {
				update_post_meta( $animal_id, '_rm_sex', $sex );
			}
		}

		if ( array_key_exists( 'hatch_date', $params ) ) {
			$date = sanitize_text_field( $params['hatch_date'] );
			if ( '' === $date || strtotime( $date ) ) {
				update_post_meta( $animal_id, '_rm_birth', $date );
			}
		}

		if ( array_key_exists( 'origin', $params ) ) {
			update_post_meta( $animal_id, '_rm_origin', sanitize_text_field( $params['origin'] ) );
		}

		if ( array_key_exists( 'length', $params ) ) {
			update_post_meta( $animal_id, '_rm_length', (float) $params['length'] );
		}

		if ( array_key_exists( 'public', $params ) ) {
			update_post_meta( $animal_id, '_rm_public', $params['public'] ? '1' : '0' );
		}

		if ( array_key_exists( 'notes', $params ) ) {
			wp_update_post(
				array(
					'ID'           => $animal_id,
					'post_content' => wp_kses_post( $params['notes'] ),
				)
			);
		}

		// Tierart: menschliche Bezeichnung (z. B. "Pogona vitticeps") auf den
		// passenden Taxonomie-Begriff aus RM_Species abbilden.
		$species_key = null;
		if ( array_key_exists( 'species', $params ) && '' !== trim( (string) $params['species'] ) ) {
			$species_key = RM_Species::key_from_term_names( array( sanitize_text_field( $params['species'] ) ) );
			$profiles    = RM_Species::profiles();
			if ( isset( $profiles[ $species_key ] ) ) {
				RM_Species::ensure_terms();
				$term = term_exists( $profiles[ $species_key ]['term_name'], 'rm_species' );
				if ( $term ) {
					wp_set_object_terms( $animal_id, array( (int) $term['term_id'] ), 'rm_species' );
				}
			}
		}
		if ( null === $species_key ) {
			$species_key = RM_Species::key_for_animal( $animal_id );
		}

		if ( array_key_exists( 'genetics', $params ) && is_array( $params['genetics'] ) ) {
			$allowed = array_keys( RM_Genetics::genes( $species_key ) );
			$stored  = get_post_meta( $animal_id, '_rm_genes', true );
			$stored  = is_array( $stored ) ? $stored : array();

			foreach ( $params['genetics'] as $gene_key => $state ) {
				$gene_key = sanitize_key( $gene_key );
				$state    = sanitize_key( $state );
				if ( ! in_array( $gene_key, $allowed, true ) ) {
					continue;
				}
				if ( in_array( $state, array( 'het', 'homo' ), true ) ) {
					$stored[ $gene_key ] = $state;
				} else {
					unset( $stored[ $gene_key ] );
				}
			}
			update_post_meta( $animal_id, '_rm_genes', $stored );
		}

		if ( array_key_exists( 'weight', $params ) && is_numeric( $params['weight'] ) ) {
			$weights   = get_post_meta( $animal_id, '_rm_weights', true );
			$weights   = is_array( $weights ) ? $weights : array();
			$weights[] = array(
				'date'  => current_time( 'Y-m-d' ),
				'grams' => (int) $params['weight'],
			);
			update_post_meta( $animal_id, '_rm_weights', $weights );
		}
	}

	/**
	 * WordPress-Beitragsstatus in ein lesbares deutsches Statuslabel
	 * übersetzen (das Plugin kennt keinen eigenständigen „Verkaufsstatus“).
	 *
	 * @param string $post_status Beitragsstatus.
	 * @return string
	 */
	private static function status_label( $post_status ) {
		$labels = array(
			'publish' => 'aktiv',
			'draft'   => 'entwurf',
			'private' => 'privat',
			'pending' => 'ausstehend',
			'trash'   => 'papierkorb',
		);
		return isset( $labels[ $post_status ] ) ? $labels[ $post_status ] : $post_status;
	}

	/**
	 * Kurzform eines Tieres für die Liste.
	 *
	 * @param WP_Post $animal Beitrag.
	 * @return array
	 */
	private static function animal_summary( $animal ) {
		$weights = get_post_meta( $animal->ID, '_rm_weights', true );
		$weight  = null;
		if ( is_array( $weights ) && $weights ) {
			$last   = end( $weights );
			$weight = ! empty( $last['grams'] ) ? (int) $last['grams'] : null;
		}

		return array(
			'id'         => $animal->ID,
			'name'       => $animal->post_title,
			'species'    => RM_Species::label( RM_Species::key_for_animal( $animal->ID ) ),
			'sex'        => get_post_meta( $animal->ID, '_rm_sex', true ),
			'hatch_date' => get_post_meta( $animal->ID, '_rm_birth', true ),
			'weight'     => $weight,
			'status'     => self::status_label( $animal->post_status ),
			'photo'      => has_post_thumbnail( $animal ) ? get_the_post_thumbnail_url( $animal, 'medium' ) : null,
		);
	}

	/**
	 * Detailansicht eines Tieres.
	 *
	 * @param WP_Post $animal Beitrag.
	 * @return array
	 */
	private static function animal_detail( $animal ) {
		$summary = self::animal_summary( $animal );

		$genes  = RM_Genetics::get_animal_genes( $animal->ID );
		$genes  = array_filter( $genes );

		$weights = get_post_meta( $animal->ID, '_rm_weights', true );
		$history = array();
		if ( is_array( $weights ) ) {
			foreach ( $weights as $entry ) {
				if ( ! empty( $entry['grams'] ) ) {
					$history[] = array(
						'date'  => isset( $entry['date'] ) ? $entry['date'] : '',
						'grams' => (int) $entry['grams'],
					);
				}
			}
		}

		return array_merge(
			$summary,
			array(
				'origin'         => get_post_meta( $animal->ID, '_rm_origin', true ),
				'length'         => get_post_meta( $animal->ID, '_rm_length', true ),
				'public'         => '0' !== (string) get_post_meta( $animal->ID, '_rm_public', true ),
				'morph'          => RM_Genetics::animal_morph_label( $animal->ID ),
				'genetics'       => (object) $genes,
				'weight_history' => $history,
				'notes'          => $animal->post_content,
				'sales_status'   => $summary['status'],
				'owner_id'       => (int) $animal->post_author,
			)
		);
	}
}
