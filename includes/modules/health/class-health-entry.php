<?php
/**
 * Gesundheits-Logbuch: Custom Post Type „reptile_health_entry“ inkl.
 * Meta-Feldern, Symptom-Katalog und Abfrage-Hilfsfunktionen.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Health_Entry {

	const POST_TYPE = 'reptile_health_entry';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	/**
	 * Registriert den Post-Type für Gesundheits-Einträge.
	 */
	public static function register() {
		$labels = array(
			'name'               => __( 'Gesundheits-Einträge', 'reptilien-manager' ),
			'singular_name'      => __( 'Gesundheits-Eintrag', 'reptilien-manager' ),
			'add_new'            => __( 'Eintrag hinzufügen', 'reptilien-manager' ),
			'add_new_item'       => __( 'Neuen Gesundheits-Eintrag anlegen', 'reptilien-manager' ),
			'edit_item'          => __( 'Gesundheits-Eintrag bearbeiten', 'reptilien-manager' ),
			'search_items'       => __( 'Gesundheits-Einträge durchsuchen', 'reptilien-manager' ),
			'not_found'          => __( 'Keine Gesundheits-Einträge gefunden.', 'reptilien-manager' ),
			'all_items'          => __( 'Gesundheits-Logbuch', 'reptilien-manager' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=rm_animal',
				'supports'        => array( 'title', 'author' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Katalog der wählbaren Symptome.
	 *
	 * @return array<string,string> Schlüssel => Label.
	 */
	public static function symptoms() {
		return array(
			'stau'            => __( 'Stau (Kotstau)', 'reptilien-manager' ),
			'bindehautentz'   => __( 'Bindehautentzündung', 'reptilien-manager' ),
			'zahnstein'       => __( 'Zahnstein', 'reptilien-manager' ),
			'durchfall'       => __( 'Durchfall', 'reptilien-manager' ),
			'apathie'         => __( 'Apathie', 'reptilien-manager' ),
		);
	}

	/**
	 * Label eines Symptom-Schlüssels.
	 *
	 * @param string $key Symptom-Schlüssel.
	 * @return string
	 */
	public static function symptom_label( $key ) {
		$symptoms = self::symptoms();
		return isset( $symptoms[ $key ] ) ? $symptoms[ $key ] : $key;
	}

	/**
	 * Sprechender Titel „Gesundheit [Tier] [Datum]“.
	 *
	 * @param int    $animal_id Beitrags-ID des Tieres.
	 * @param string $date      Datum (Y-m-d).
	 * @return string
	 */
	public static function build_title( $animal_id, $date ) {
		$animal_name = $animal_id ? get_the_title( $animal_id ) : '';
		$date_label  = ( $date && strtotime( $date ) ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : date_i18n( get_option( 'date_format' ) );

		/* translators: 1: Tiername, 2: Datum */
		return trim( sprintf( __( 'Gesundheit %1$s %2$s', 'reptilien-manager' ), $animal_name, $date_label ) );
	}

	/**
	 * Darf der aktuelle Nutzer die Gesundheitsdaten eines Tieres sehen/bearbeiten?
	 * Ausschließlich der Tier-Besitzer (bzw. Nutzer mit edit_others_posts).
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return bool
	 */
	public static function can_manage_for_animal( $animal_id ) {
		if ( ! is_user_logged_in() ) {
			return false;
		}

		$animal = get_post( $animal_id );
		if ( ! $animal || 'rm_animal' !== $animal->post_type ) {
			return false;
		}

		if ( (int) $animal->post_author === get_current_user_id() ) {
			return true;
		}

		return current_user_can( 'edit_others_posts' );
	}

	/**
	 * Gesundheits-Einträge eines Tieres, neueste zuerst.
	 *
	 * @param int   $animal_id Beitrags-ID des Tieres.
	 * @param array $args      Optional: 'limit' (int, Standard -1), 'symptom' (string),
	 *                          'status' ('active'|'resolved').
	 * @return WP_Post[]
	 */
	public static function entries_for_animal( $animal_id, $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'limit'   => -1,
				'symptom' => '',
				'status'  => '',
			)
		);

		$meta_query = array(
			array(
				'key'   => '_reptile_health_animal_id',
				'value' => $animal_id,
			),
		);
		self::apply_symptom_status_filters( $meta_query, $args );

		return get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'posts_per_page' => (int) $args['limit'],
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'meta_key'       => '_reptile_health_date',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			)
		);
	}

	/**
	 * Alle Gesundheits-Einträge (für die Übersichtsseite), optional gefiltert
	 * und auf die eigenen Tiere beschränkt (siehe RM_Roles::author_query_args()).
	 *
	 * @param array $args 'animal' (int), 'symptom' (string), 'status' (string).
	 * @return WP_Post[]
	 */
	public static function all_entries( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'animal'  => 0,
				'symptom' => '',
				'status'  => '',
			)
		);

		$meta_query = array();
		if ( $args['animal'] ) {
			$meta_query[] = array(
				'key'   => '_reptile_health_animal_id',
				'value' => (int) $args['animal'],
			);
		}
		self::apply_symptom_status_filters( $meta_query, $args );

		$query_args = array(
			'post_type'      => self::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'meta_key'       => '_reptile_health_date',
			'orderby'        => 'meta_value',
			'order'          => 'DESC',
		);
		if ( $meta_query ) {
			$query_args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( class_exists( 'RM_Roles' ) ) {
			$query_args = array_merge( $query_args, RM_Roles::author_query_args() );
		}

		return get_posts( $query_args );
	}

	/**
	 * Symptom-/Status-Filter zu einer bestehenden Meta-Query hinzufügen.
	 *
	 * @param array $meta_query Referenz auf die Meta-Query.
	 * @param array $args       'symptom' und 'status'.
	 */
	private static function apply_symptom_status_filters( &$meta_query, $args ) {
		if ( ! empty( $args['symptom'] ) ) {
			$meta_query[] = array(
				'key'     => '_reptile_health_symptoms',
				'value'   => '"' . $args['symptom'] . '"',
				'compare' => 'LIKE',
			);
		}

		if ( 'resolved' === $args['status'] ) {
			$meta_query[] = array(
				'key'   => '_reptile_health_resolved',
				'value' => '1',
			);
		} elseif ( 'active' === $args['status'] ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_reptile_health_resolved',
					'value'   => '1',
					'compare' => '!=',
				),
				array(
					'key'     => '_reptile_health_resolved',
					'compare' => 'NOT EXISTS',
				),
			);
		}
	}

	/**
	 * Letzter Eintrag eines Tieres.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return WP_Post|null
	 */
	public static function last_entry( $animal_id ) {
		$entries = self::entries_for_animal( $animal_id, array( 'limit' => 1 ) );
		return $entries ? $entries[0] : null;
	}

	/**
	 * Kurzfassung „Letzte Symptome: X, Y (vor 30 Tagen)“ für ein Tier.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return string Leer, wenn noch keine Einträge vorliegen.
	 */
	public static function last_symptoms_summary( $animal_id ) {
		$entry = self::last_entry( $animal_id );
		if ( ! $entry ) {
			return '';
		}

		$symptoms = get_post_meta( $entry->ID, '_reptile_health_symptoms', true );
		$symptoms = is_array( $symptoms ) ? $symptoms : array();

		$labels = array();
		foreach ( $symptoms as $key ) {
			$labels[] = self::symptom_label( $key );
		}

		$date  = get_post_meta( $entry->ID, '_reptile_health_date', true );
		$since = self::days_ago_label( $date );

		if ( ! $labels ) {
			return $since;
		}

		/* translators: 1: Symptomliste, 2: „vor N Tagen“ */
		return trim( sprintf( __( '%1$s (%2$s)', 'reptilien-manager' ), implode( ', ', $labels ), $since ) );
	}

	/**
	 * „vor N Tagen“-Angabe zu einem Datum.
	 *
	 * @param string $date Datum (Y-m-d).
	 * @return string
	 */
	public static function days_ago_label( $date ) {
		$ts = $date ? strtotime( $date ) : false;
		if ( ! $ts ) {
			return '';
		}

		$days = (int) floor( ( time() - $ts ) / DAY_IN_SECONDS );
		if ( $days <= 0 ) {
			return __( 'heute', 'reptilien-manager' );
		}

		/* translators: %d: Anzahl Tage */
		return sprintf( _n( 'vor %d Tag', 'vor %d Tagen', $days, 'reptilien-manager' ), $days );
	}
}
