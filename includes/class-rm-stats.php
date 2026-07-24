<?php
/**
 * Bestands-Statistik für das Frontend-Dashboard.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Stats {

	/**
	 * Aggregierte Kennzahlen über alle veröffentlichten Tiere.
	 *
	 * @return array {
	 *     total, sex (Schlüssel=>Anzahl), age_groups (Label=>Anzahl),
	 *     morphs (Morph=>Anzahl), species (Label=>Anzahl),
	 *     avg_weight (float|null), hatch_rate (float|null)
	 * }
	 */
	public static function collect() {
		$animals = get_posts(
			array(
				'post_type'      => 'rm_animal',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'meta_query'     => array( RM_Roles::public_meta_query() ),
			)
		);

		$sexes      = RM_Animal_Meta::sexes();
		$sex_counts = array();
		foreach ( $sexes as $key => $label ) {
			$sex_counts[ $key ] = 0;
		}

		$age_groups = array(
			'juvenile' => 0, // < 6 Monate
			'subadult' => 0, // 6–18 Monate
			'adult'    => 0, // > 18 Monate
			'unknown'  => 0,
		);

		$morphs        = array();
		$species_count = array();
		$weights       = array();

		foreach ( $animals as $animal ) {
			$sex = get_post_meta( $animal->ID, '_rm_sex', true );
			if ( ! isset( $sex_counts[ $sex ] ) ) {
				$sex = 'unknown';
			}
			$sex_counts[ $sex ]++;

			$birth  = get_post_meta( $animal->ID, '_rm_birth', true );
			$months = $birth ? RM_Animal_Meta::age_in_months( $birth ) : null;
			if ( null === $months ) {
				$age_groups['unknown']++;
			} elseif ( $months < 6 ) {
				$age_groups['juvenile']++;
			} elseif ( $months < 18 ) {
				$age_groups['subadult']++;
			} else {
				$age_groups['adult']++;
			}

			$morph = RM_Genetics::animal_morph_label( $animal->ID );
			$morphs[ $morph ] = isset( $morphs[ $morph ] ) ? $morphs[ $morph ] + 1 : 1;

			$species_label = RM_Species::label( RM_Species::key_for_animal( $animal->ID ) );
			$species_count[ $species_label ] = isset( $species_count[ $species_label ] ) ? $species_count[ $species_label ] + 1 : 1;

			$w = get_post_meta( $animal->ID, '_rm_weights', true );
			if ( is_array( $w ) && $w ) {
				$last = end( $w );
				if ( ! empty( $last['grams'] ) ) {
					$weights[] = (int) $last['grams'];
				}
			}
		}

		arsort( $morphs );

		return array(
			'total'      => count( $animals ),
			'sex'        => $sex_counts,
			'age_groups' => $age_groups,
			'morphs'     => $morphs,
			'species'    => $species_count,
			'avg_weight' => $weights ? array_sum( $weights ) / count( $weights ) : null,
			'hatch_rate' => RM_Pairing::average_hatch_rate(),
		);
	}

	/**
	 * Lesbare Labels der Altersgruppen.
	 *
	 * @return array
	 */
	public static function age_group_labels() {
		return array(
			'juvenile' => __( 'Jungtier (< 6 M.)', 'reptilien-manager' ),
			'subadult' => __( 'Subadult (6–18 M.)', 'reptilien-manager' ),
			'adult'    => __( 'Adult (> 18 M.)', 'reptilien-manager' ),
			'unknown'  => __( 'Ohne Alter', 'reptilien-manager' ),
		);
	}
}
