<?php
/**
 * Zucht-Intelligenz: Stammbaum, Inzucht-Koeffizient (nach Wright),
 * Verpaarungs-Empfehlungen und Zuchtstatistik pro Tier.
 *
 * Die Abstammung wird über das Meta `_rm_parent_pairing` (Eltern-Verpaarung
 * → Vater/Mutter) rekonstruiert. Tiere ohne Eltern-Verpaarung gelten als
 * Gründertiere (nicht verwandt).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Breeding {

	/**
	 * COI-Schwellen für die Warnstufen.
	 */
	const COI_MEDIUM = 0.125; // Halbgeschwister / Cousin-Ebene.
	const COI_HIGH   = 0.25;  // Eltern-Kind / Vollgeschwister.

	/**
	 * Eltern eines Tieres (aus der Eltern-Verpaarung).
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return int[] [ sire_id, dam_id ] (0 = unbekannt/Gründer).
	 */
	public static function parents( $animal_id ) {
		$pairing = (int) get_post_meta( $animal_id, '_rm_parent_pairing', true );
		if ( ! $pairing ) {
			return array( 0, 0 );
		}
		return array(
			(int) get_post_meta( $pairing, '_rm_sire', true ),
			(int) get_post_meta( $pairing, '_rm_dam', true ),
		);
	}

	/**
	 * Generationstiefe (längster Pfad zu einem Gründertier).
	 *
	 * @param int   $id    Tier-ID.
	 * @param array $memo  Memoisierung.
	 * @param int   $guard Rekursionsschutz.
	 * @return int
	 */
	private static function depth( $id, &$memo, $guard = 0 ) {
		if ( ! $id || $guard > 50 ) {
			return 0;
		}
		if ( isset( $memo[ $id ] ) ) {
			return $memo[ $id ];
		}
		$memo[ $id ] = 0; // Zyklen-Schutz.

		list( $s, $d ) = self::parents( $id );
		$ds = $s ? 1 + self::depth( $s, $memo, $guard + 1 ) : 0;
		$dd = $d ? 1 + self::depth( $d, $memo, $guard + 1 ) : 0;

		$memo[ $id ] = max( $ds, $dd );
		return $memo[ $id ];
	}

	/**
	 * Verwandtschafts-Koeffizient (kinship φ) zweier Tiere.
	 *
	 * φ(A,B) ist die Wahrscheinlichkeit, dass ein zufällig gewähltes Allel
	 * von A mit einem von B identisch nach Abstammung ist. Der COI eines
	 * Nachkommens von A×B entspricht φ(A,B).
	 *
	 * @param int   $a     Tier A.
	 * @param int   $b     Tier B.
	 * @param array $memo  Memoisierung (paarweise).
	 * @param int   $guard Rekursionsschutz.
	 * @return float 0–1.
	 */
	public static function kinship( $a, $b, &$memo = array(), $guard = 0 ) {
		if ( ! $a || ! $b || $guard > 80 ) {
			return 0.0;
		}

		if ( $a === $b ) {
			list( $s, $d ) = self::parents( $a );
			return 0.5 * ( 1 + ( ( $s && $d ) ? self::kinship( $s, $d, $memo, $guard + 1 ) : 0.0 ) );
		}

		$key = $a < $b ? $a . '-' . $b : $b . '-' . $a;
		if ( isset( $memo[ $key ] ) ) {
			return $memo[ $key ];
		}
		$memo[ $key ] = 0.0; // Zyklen-Schutz.

		// Auf dem tieferen (jüngeren) Tier expandieren.
		$dmemo = array();
		if ( self::depth( $a, $dmemo ) < self::depth( $b, $dmemo ) ) {
			$tmp = $a;
			$a   = $b;
			$b   = $tmp;
		}

		list( $s, $d ) = self::parents( $a );
		$val = 0.5 * ( self::kinship( $s, $b, $memo, $guard + 1 ) + self::kinship( $d, $b, $memo, $guard + 1 ) );

		$memo[ $key ] = $val;
		return $val;
	}

	/**
	 * Inzucht-Koeffizient (COI) eines Tieres = φ seiner Eltern.
	 *
	 * @param int $animal_id Tier-ID.
	 * @return float 0–1.
	 */
	public static function inbreeding_coefficient( $animal_id ) {
		list( $s, $d ) = self::parents( $animal_id );
		if ( ! $s || ! $d ) {
			return 0.0;
		}
		$memo = array();
		return self::kinship( $s, $d, $memo );
	}

	/**
	 * Erwarteter COI eines Nachkommens aus einer geplanten Verpaarung.
	 *
	 * @param int $sire_id Vater.
	 * @param int $dam_id  Mutter.
	 * @return float 0–1.
	 */
	public static function pair_coi( $sire_id, $dam_id ) {
		if ( ! $sire_id || ! $dam_id || $sire_id === $dam_id ) {
			return 0.0;
		}
		$memo = array();
		return self::kinship( $sire_id, $dam_id, $memo );
	}

	/**
	 * Warnstufe zu einem COI-Wert.
	 *
	 * @param float $coi Koeffizient 0–1.
	 * @return array { level: none|low|medium|high, label, status(css) }
	 */
	public static function coi_warning( $coi ) {
		if ( $coi >= self::COI_HIGH ) {
			return array(
				'level'  => 'high',
				'label'  => __( 'Sehr enge Verwandtschaft (Eltern/Geschwister) – von dieser Verpaarung wird abgeraten.', 'reptilien-manager' ),
				'status' => 'high',
			);
		}
		if ( $coi >= self::COI_MEDIUM ) {
			return array(
				'level'  => 'medium',
				'label'  => __( 'Deutliche Verwandtschaft (Halbgeschwister/Cousin) – nur bewusst und dosiert einsetzen.', 'reptilien-manager' ),
				'status' => 'low',
			);
		}
		if ( $coi > 0 ) {
			return array(
				'level'  => 'low',
				'label'  => __( 'Entfernte Verwandtschaft – genetisch meist unbedenklich.', 'reptilien-manager' ),
				'status' => 'ok',
			);
		}
		return array(
			'level'  => 'none',
			'label'  => __( 'Keine bekannte Verwandtschaft.', 'reptilien-manager' ),
			'status' => 'ok',
		);
	}

	/**
	 * Ist ein Tier geschlechtsreif genug für die Zucht? (Richtwerte)
	 *
	 * @param int $animal_id Tier-ID.
	 * @return bool
	 */
	public static function is_breeding_ready( $animal_id ) {
		$birth = get_post_meta( $animal_id, '_rm_birth', true );
		if ( ! $birth ) {
			return false;
		}
		$months  = RM_Animal_Meta::age_in_months( $birth );
		$species = RM_Species::key_for_animal( $animal_id );
		$min     = 'iguana' === $species ? 30 : 12;
		return null !== $months && $months >= $min;
	}

	/**
	 * Zuchtstatistik eines Tieres.
	 *
	 * @param int $animal_id Tier-ID.
	 * @return array { pairings, offspring, avg_hatch_rate|null }
	 */
	public static function animal_stats( $animal_id ) {
		$pairings = RM_Pairing::pairings_for_animal( $animal_id );

		$offspring = 0;
		$rates     = array();
		foreach ( $pairings as $pairing ) {
			$offspring += count( RM_Pairing::offspring( $pairing->ID ) );
			foreach ( RM_Pairing::get_clutches( $pairing->ID ) as $clutch ) {
				if ( empty( $clutch['eggs'] ) || ! $clutch['hatched'] ) {
					continue;
				}
				$rate = RM_Pairing::clutch_hatch_rate( $clutch );
				if ( null !== $rate ) {
					$rates[] = $rate;
				}
			}
		}

		return array(
			'pairings'        => count( $pairings ),
			'offspring'       => $offspring,
			'avg_hatch_rate'  => $rates ? array_sum( $rates ) / count( $rates ) : null,
		);
	}

	/**
	 * Verpaarungs-Empfehlungen: alle Männchen × Weibchen, nach genetischer
	 * Vielfalt (niedriger COI) und Zuchtreife bewertet.
	 *
	 * @param int $limit Maximale Anzahl Vorschläge.
	 * @return array[] Liste von { sire, dam, coi, warning, both_ready, species_match, score }.
	 */
	public static function recommend_pairings( $limit = 20 ) {
		$males   = RM_Post_Types::get_animals( 'male' );
		$females = RM_Post_Types::get_animals( 'female' );

		$suggestions = array();
		foreach ( $males as $sire ) {
			$sire_species = RM_Species::key_for_animal( $sire->ID );
			foreach ( $females as $dam ) {
				$dam_species   = RM_Species::key_for_animal( $dam->ID );
				$species_match = ( $sire_species === $dam_species );
				$coi           = self::pair_coi( $sire->ID, $dam->ID );
				$both_ready    = self::is_breeding_ready( $sire->ID ) && self::is_breeding_ready( $dam->ID );

				// Score: niedriger ist besser. COI dominiert, Reife/Art als Boni.
				$score = $coi;
				if ( ! $species_match ) {
					$score += 1.0; // Artfremde Paarung stark abwerten.
				}
				if ( ! $both_ready ) {
					$score += 0.05;
				}

				$suggestions[] = array(
					'sire'          => $sire,
					'dam'           => $dam,
					'coi'           => $coi,
					'warning'       => self::coi_warning( $coi ),
					'both_ready'    => $both_ready,
					'species_match' => $species_match,
					'score'         => $score,
				);
			}
		}

		usort(
			$suggestions,
			static function ( $a, $b ) {
				if ( abs( $a['score'] - $b['score'] ) < 1e-9 ) {
					return 0;
				}
				return $a['score'] < $b['score'] ? -1 : 1;
			}
		);

		return array_slice( $suggestions, 0, $limit );
	}

	/**
	 * COI als Prozent formatieren.
	 *
	 * @param float $coi Koeffizient.
	 * @return string
	 */
	public static function format_coi( $coi ) {
		$pct = $coi * 100;
		$dec = ( floor( $pct ) === $pct ) ? 0 : 1;
		return number_format_i18n( $pct, $dec ) . ' %';
	}
}
