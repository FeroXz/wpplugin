<?php
/**
 * Statistik-Abfragen für das Gesundheits-Logbuch: häufigste Symptome,
 * Zeitverlauf, Behandlungs-Erfolgsrate, Top-betroffene Tiere und
 * Jahres-Alerts. Ergebnisse werden pro Zeitraum/Nutzer-Scope 1 Stunde
 * im Objekt-Cache gehalten (siehe RM_Genetics::cross_animals() für das
 * gleiche Muster).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Health_Stats {

	const CACHE_GROUP = 'reptilien_manager';

	/**
	 * Aggregierte Trend-Daten für den gewählten Zeitraum.
	 *
	 * @param array $args {
	 *     @type string $from            Start (Y-m-d), Standard: vor 6 Monaten.
	 *     @type string $to              Ende (Y-m-d), Standard: heute.
	 *     @type int    $alert_threshold Mindestanzahl gleicher Symptome/Jahr für einen Alert.
	 * }
	 * @return array {
	 *     @type array $range             { from, to }.
	 *     @type int   $total_entries     Anzahl Einträge im Zeitraum.
	 *     @type array $top_symptoms      { key, label, value }[] – häufigste Symptome.
	 *     @type array $timeline          { label, value }[] – Einträge pro Monat.
	 *     @type array $treatment_success { treated, resolved, rate }.
	 *     @type array $top_animals       { animal_id, name, morph, total, active, resolved }[].
	 *     @type array $alerts            { animal_id, animal_name, symptom, symptom_label, count, year, message }[].
	 * }
	 */
	public static function collect( $args = array() ) {
		$args = wp_parse_args(
			$args,
			array(
				'from'            => gmdate( 'Y-m-d', strtotime( '-6 months' ) ),
				'to'              => current_time( 'Y-m-d' ),
				'alert_threshold' => 3,
			)
		);

		if ( ! strtotime( $args['from'] ) ) {
			$args['from'] = gmdate( 'Y-m-d', strtotime( '-6 months' ) );
		}
		if ( ! strtotime( $args['to'] ) ) {
			$args['to'] = current_time( 'Y-m-d' );
		}
		if ( strtotime( $args['from'] ) > strtotime( $args['to'] ) ) {
			list( $args['from'], $args['to'] ) = array( $args['to'], $args['from'] );
		}

		$scope = class_exists( 'RM_Roles' ) ? RM_Roles::author_query_args() : array();

		$cache_key = 'health_trends_' . md5( wp_json_encode( array( $args, $scope ) ) );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return $cached;
		}

		$entries = self::query_entries( $args['from'], $args['to'], $scope );

		$result = array(
			'range'             => array(
				'from' => $args['from'],
				'to'   => $args['to'],
			),
			'total_entries'     => count( $entries ),
			'top_symptoms'      => self::aggregate_top_symptoms( $entries ),
			'timeline'          => self::aggregate_timeline( $entries, $args['from'], $args['to'] ),
			'treatment_success' => self::aggregate_treatment_success( $entries ),
			'top_animals'       => self::aggregate_top_animals( $entries ),
			'alerts'            => self::build_alerts( $entries, (int) $args['alert_threshold'] ),
		);

		wp_cache_set( $cache_key, $result, self::CACHE_GROUP, HOUR_IN_SECONDS );

		return $result;
	}

	/**
	 * Gesundheits-Einträge im Zeitraum (ein Query für alle Auswertungen).
	 *
	 * @param string $from  Start (Y-m-d).
	 * @param string $to    Ende (Y-m-d).
	 * @param array  $scope Autoren-Scope (RM_Roles::author_query_args()).
	 * @return WP_Post[]
	 */
	private static function query_entries( $from, $to, $scope ) {
		$query_args = array(
			'post_type'      => RM_Health_Entry::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_reptile_health_date',
					'value'   => array( $from, $to ),
					'compare' => 'BETWEEN',
					'type'    => 'DATE',
				),
			),
		);

		return get_posts( array_merge( $query_args, $scope ) );
	}

	/**
	 * Häufigste Symptome im Zeitraum.
	 *
	 * @param WP_Post[] $entries Einträge.
	 * @param int       $limit   Maximale Anzahl.
	 * @return array
	 */
	private static function aggregate_top_symptoms( $entries, $limit = 8 ) {
		$counts = array();
		foreach ( $entries as $entry ) {
			foreach ( self::symptoms_of( $entry->ID ) as $key ) {
				$counts[ $key ] = isset( $counts[ $key ] ) ? $counts[ $key ] + 1 : 1;
			}
		}
		arsort( $counts );

		$rows = array();
		foreach ( array_slice( $counts, 0, $limit, true ) as $key => $count ) {
			$rows[] = array(
				'key'   => $key,
				'label' => RM_Health_Entry::symptom_label( $key ),
				'value' => $count,
			);
		}
		return $rows;
	}

	/**
	 * Einträge pro Monat im Zeitraum (für die Zeitverlauf-Grafik).
	 *
	 * @param WP_Post[] $entries Einträge.
	 * @param string    $from    Start (Y-m-d).
	 * @param string    $to      Ende (Y-m-d).
	 * @return array
	 */
	private static function aggregate_timeline( $entries, $from, $to ) {
		$months = self::month_buckets( $from, $to );
		$counts = array_fill_keys( array_keys( $months ), 0 );

		foreach ( $entries as $entry ) {
			$date = get_post_meta( $entry->ID, '_reptile_health_date', true );
			$ts   = $date ? strtotime( $date ) : false;
			if ( ! $ts ) {
				continue;
			}
			$bucket = gmdate( 'Y-m', $ts );
			if ( isset( $counts[ $bucket ] ) ) {
				++$counts[ $bucket ];
			}
		}

		$rows = array();
		foreach ( $months as $key => $label ) {
			$rows[] = array(
				'label' => $label,
				'value' => $counts[ $key ],
			);
		}
		return $rows;
	}

	/**
	 * Monats-Buckets (Y-m => lesbares Label) zwischen zwei Daten (inklusive).
	 *
	 * @param string $from Start (Y-m-d).
	 * @param string $to   Ende (Y-m-d).
	 * @return array<string,string>
	 */
	private static function month_buckets( $from, $to ) {
		$months = array();
		$cursor = new DateTime( gmdate( 'Y-m-01', strtotime( $from ) ) );
		$end    = new DateTime( gmdate( 'Y-m-01', strtotime( $to ) ) );

		// Sicherheitsgrenze, falls ein extremer Zeitraum gewählt wird.
		$guard = 0;
		while ( $cursor <= $end && $guard < 60 ) {
			$key            = $cursor->format( 'Y-m' );
			$months[ $key ] = date_i18n( 'M Y', $cursor->getTimestamp() );
			$cursor->modify( '+1 month' );
			++$guard;
		}
		return $months;
	}

	/**
	 * Behandlungs-Erfolgsrate: Anteil ausgeheilter Fälle unter den Einträgen
	 * mit hinterlegter Behandlung.
	 *
	 * @param WP_Post[] $entries Einträge.
	 * @return array { treated, resolved, rate (Prozent oder null ohne Daten) }
	 */
	private static function aggregate_treatment_success( $entries ) {
		$treated  = 0;
		$resolved = 0;

		foreach ( $entries as $entry ) {
			$treatment = get_post_meta( $entry->ID, '_reptile_health_treatment', true );
			if ( '' === trim( (string) $treatment ) ) {
				continue;
			}
			++$treated;
			if ( self::is_resolved( $entry->ID ) ) {
				++$resolved;
			}
		}

		return array(
			'treated'  => $treated,
			'resolved' => $resolved,
			'rate'     => $treated ? (int) round( $resolved / $treated * 100 ) : null,
		);
	}

	/**
	 * Am häufigsten betroffene Tiere im Zeitraum, inkl. Morph.
	 *
	 * @param WP_Post[] $entries Einträge.
	 * @param int       $limit   Maximale Anzahl.
	 * @return array
	 */
	private static function aggregate_top_animals( $entries, $limit = 10 ) {
		$by_animal = array();

		foreach ( $entries as $entry ) {
			$animal_id = (int) get_post_meta( $entry->ID, '_reptile_health_animal_id', true );
			if ( ! $animal_id ) {
				continue;
			}
			if ( ! isset( $by_animal[ $animal_id ] ) ) {
				$by_animal[ $animal_id ] = array(
					'total'    => 0,
					'active'   => 0,
					'resolved' => 0,
				);
			}
			++$by_animal[ $animal_id ]['total'];
			++$by_animal[ $animal_id ][ self::is_resolved( $entry->ID ) ? 'resolved' : 'active' ];
		}

		uasort(
			$by_animal,
			function ( $a, $b ) {
				return $b['total'] - $a['total'];
			}
		);

		$rows = array();
		foreach ( array_slice( $by_animal, 0, $limit, true ) as $animal_id => $data ) {
			$rows[] = array(
				'animal_id' => $animal_id,
				'name'      => get_the_title( $animal_id ),
				'morph'     => class_exists( 'RM_Genetics' ) ? RM_Genetics::animal_morph_label( $animal_id ) : '',
				'total'     => $data['total'],
				'active'    => $data['active'],
				'resolved'  => $data['resolved'],
			);
		}
		return $rows;
	}

	/**
	 * Alerts „Tier XY hatte Nx Symptom dieses Jahr“ – zählt je Tier und
	 * Symptom die Vorkommen im laufenden Kalenderjahr, unabhängig vom
	 * gewählten Filter-Zeitraum wird nur auf die im Zeitraum enthaltenen
	 * Einträge dieses Jahres geschaut.
	 *
	 * @param WP_Post[] $entries   Einträge (bereits im gewählten Zeitraum).
	 * @param int       $threshold Mindestanzahl für einen Alert.
	 * @return array
	 */
	private static function build_alerts( $entries, $threshold ) {
		$year   = gmdate( 'Y' );
		$counts = array();

		foreach ( $entries as $entry ) {
			$date = get_post_meta( $entry->ID, '_reptile_health_date', true );
			if ( ! $date || ! strtotime( $date ) || gmdate( 'Y', strtotime( $date ) ) !== $year ) {
				continue;
			}
			$animal_id = (int) get_post_meta( $entry->ID, '_reptile_health_animal_id', true );
			if ( ! $animal_id ) {
				continue;
			}
			foreach ( self::symptoms_of( $entry->ID ) as $key ) {
				if ( ! isset( $counts[ $animal_id ][ $key ] ) ) {
					$counts[ $animal_id ][ $key ] = 0;
				}
				++$counts[ $animal_id ][ $key ];
			}
		}

		$alerts = array();
		foreach ( $counts as $animal_id => $symptom_counts ) {
			foreach ( $symptom_counts as $key => $count ) {
				if ( $count < $threshold ) {
					continue;
				}
				$animal_name    = get_the_title( $animal_id );
				$symptom_label  = RM_Health_Entry::symptom_label( $key );
				$alerts[]       = array(
					'animal_id'     => $animal_id,
					'animal_name'   => $animal_name,
					'symptom'       => $key,
					'symptom_label' => $symptom_label,
					'count'         => $count,
					'year'          => $year,
					'message'       => sprintf(
						/* translators: 1: Tiername, 2: Anzahl, 3: Symptom, 4: Jahr */
						__( '%1$s hatte %2$dx %3$s dieses Jahr (%4$s)', 'reptilien-manager' ),
						$animal_name,
						$count,
						$symptom_label,
						$year
					),
				);
			}
		}

		usort(
			$alerts,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);

		return $alerts;
	}

	/**
	 * Symptom-Schlüssel eines Eintrags.
	 *
	 * @param int $entry_id Beitrags-ID.
	 * @return string[]
	 */
	private static function symptoms_of( $entry_id ) {
		$symptoms = get_post_meta( $entry_id, '_reptile_health_symptoms', true );
		return is_array( $symptoms ) ? $symptoms : array();
	}

	/**
	 * Ist ein Eintrag als ausgeheilt markiert?
	 *
	 * @param int $entry_id Beitrags-ID.
	 * @return bool
	 */
	private static function is_resolved( $entry_id ) {
		return '1' === (string) get_post_meta( $entry_id, '_reptile_health_resolved', true );
	}
}
