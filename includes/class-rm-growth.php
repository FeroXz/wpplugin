<?php
/**
 * Wachstumsmodell: erwartete Gewichtskurven pro Art und Anomalie-Erkennung.
 * Grundlage für die Gewichtsverlauf-Graphik (Chart.js).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Growth {

	/**
	 * Untere Anomalie-Schwelle (Anteil des Erwartungswerts).
	 */
	const LOW_RATIO = 0.7;

	/**
	 * Obere Anomalie-Schwelle (Anteil des Erwartungswerts).
	 */
	const HIGH_RATIO = 1.3;

	/**
	 * Referenz-Wachstumskurve als Stützpunkte (Alter in Monaten => Gewicht in Gramm).
	 * Richtwerte für gesunde Tiere; zwischen den Punkten wird linear interpoliert.
	 *
	 * @param string $species Art-Schlüssel.
	 * @return array<int,int>
	 */
	public static function reference_curve( $species = 'pogona' ) {
		if ( 'iguana' === $species ) {
			// Grüner Leguan – wächst deutlich größer und länger.
			return array(
				0  => 12,
				3  => 80,
				6  => 200,
				12 => 400,
				18 => 700,
				24 => 1200,
				36 => 2200,
				48 => 3500,
				60 => 4500,
			);
		}

		// Bartagame (Pogona vitticeps).
		return array(
			0  => 4,
			1  => 12,
			2  => 25,
			3  => 45,
			4  => 70,
			6  => 160,
			9  => 280,
			12 => 360,
			15 => 400,
			18 => 430,
			24 => 450,
			36 => 470,
		);
	}

	/**
	 * Erwartetes Gewicht für ein Alter (lineare Interpolation der Referenzkurve).
	 *
	 * @param float  $months  Alter in Monaten.
	 * @param string $species Art-Schlüssel.
	 * @return float|null Gramm oder null, wenn außerhalb der Kurve.
	 */
	public static function expected_weight( $months, $species = 'pogona' ) {
		if ( $months < 0 ) {
			return null;
		}

		$curve  = self::reference_curve( $species );
		$points = array_keys( $curve );
		$max    = end( $points );

		// Über das letzte Stützalter hinaus: adultes Plateau annehmen.
		if ( $months >= $max ) {
			return (float) $curve[ $max ];
		}

		$prev_age = 0;
		$prev_val = $curve[0];
		foreach ( $curve as $age => $val ) {
			if ( $months <= $age ) {
				if ( $age === $prev_age ) {
					return (float) $val;
				}
				$ratio = ( $months - $prev_age ) / ( $age - $prev_age );
				return $prev_val + $ratio * ( $val - $prev_val );
			}
			$prev_age = $age;
			$prev_val = $val;
		}

		return (float) $prev_val;
	}

	/**
	 * Anomalie-Status eines Messpunkts gegenüber dem Erwartungswert.
	 *
	 * @param float|null $months  Alter in Monaten (null = unbekannt).
	 * @param int        $grams   Gemessenes Gewicht.
	 * @param string     $species Art-Schlüssel.
	 * @return array { status: low|normal|high|unknown, expected: float|null, ratio: float|null }
	 */
	public static function anomaly_status( $months, $grams, $species = 'pogona' ) {
		if ( null === $months || $grams <= 0 ) {
			return array(
				'status'   => 'unknown',
				'expected' => null,
				'ratio'    => null,
			);
		}

		$expected = self::expected_weight( $months, $species );
		if ( ! $expected ) {
			return array(
				'status'   => 'unknown',
				'expected' => null,
				'ratio'    => null,
			);
		}

		$ratio = $grams / $expected;

		if ( $ratio < self::LOW_RATIO ) {
			$status = 'low';
		} elseif ( $ratio > self::HIGH_RATIO ) {
			$status = 'high';
		} else {
			$status = 'normal';
		}

		return array(
			'status'   => $status,
			'expected' => round( $expected ),
			'ratio'    => $ratio,
		);
	}

	/**
	 * Bereitet die Chart.js-Daten für den Gewichtsverlauf eines Tieres auf.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return array {
	 *     @type array  points   Liste von { x: Monate, y: Gramm, date, status, expected }.
	 *     @type array  expected Liste von { x: Monate, y: Gramm } für die Referenzlinie.
	 *     @type string species  Art-Schlüssel.
	 *     @type bool   has_age  Ob ein Schlupfdatum vorliegt.
	 * }
	 */
	public static function chart_data( $animal_id ) {
		$species = RM_Species::key_for_animal( $animal_id );
		$birth   = get_post_meta( $animal_id, '_rm_birth', true );
		$birth_ts = $birth ? strtotime( $birth ) : false;

		$weights = get_post_meta( $animal_id, '_rm_weights', true );
		if ( ! is_array( $weights ) ) {
			$weights = array();
		}

		$points   = array();
		$max_month = 0;

		foreach ( $weights as $entry ) {
			$grams = isset( $entry['grams'] ) ? (int) $entry['grams'] : 0;
			$date  = isset( $entry['date'] ) ? $entry['date'] : '';
			if ( ! $grams || ! $date ) {
				continue;
			}

			$months = null;
			if ( $birth_ts && strtotime( $date ) ) {
				$months = self::months_between( $birth_ts, strtotime( $date ) );
			}

			$anomaly = self::anomaly_status( $months, $grams, $species );

			$points[] = array(
				'x'        => null === $months ? null : round( $months, 1 ),
				'y'        => $grams,
				'date'     => $date,
				'status'   => $anomaly['status'],
				'expected' => $anomaly['expected'],
			);

			if ( null !== $months && $months > $max_month ) {
				$max_month = $months;
			}
		}

		// Referenzkurve bis etwas über das höchste gemessene Alter (mind. bis 12 Monate).
		$expected = array();
		if ( $birth_ts ) {
			$curve  = self::reference_curve( $species );
			$points_ages = array_keys( $curve );
			$limit  = max( 12, ceil( $max_month ) + 2, 'iguana' === $species ? 24 : 12 );
			$limit  = min( $limit, end( $points_ages ) );

			foreach ( $curve as $age => $val ) {
				if ( $age > $limit ) {
					break;
				}
				$expected[] = array(
					'x' => $age,
					'y' => $val,
				);
			}
		}

		return array(
			'points'   => $points,
			'expected' => $expected,
			'species'  => $species,
			'has_age'  => (bool) $birth_ts,
		);
	}

	/**
	 * Monate (mit Nachkommastellen) zwischen zwei Zeitstempeln.
	 *
	 * @param int $from_ts Start.
	 * @param int $to_ts   Ende.
	 * @return float
	 */
	private static function months_between( $from_ts, $to_ts ) {
		$days = ( $to_ts - $from_ts ) / DAY_IN_SECONDS;
		return $days / 30.44; // Durchschnittliche Monatslänge.
	}
}
