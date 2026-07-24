<?php
/**
 * Inkubationsmodell: temperaturabhängige Schlupf-Vorhersage für Bartagamen-Gelege.
 *
 * Höhere Temperaturen verkürzen die Inkubationszeit. Die Werte sind
 * praxisorientierte Richtwerte für Pogona vitticeps (typisch 27–31 °C).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Incubation {

	/**
	 * Standard-Inkubationsdauer (Tage), wenn keine Temperatur hinterlegt ist.
	 */
	const DEFAULT_DAYS = 60;

	/**
	 * Stützpunkte: Temperatur (°C) => [minTage, maxTage].
	 * Zwischen den Punkten wird linear interpoliert.
	 *
	 * @return array<string,int[]>
	 */
	public static function temp_table() {
		return array(
			'27' => array( 65, 75 ),
			'28' => array( 58, 65 ),
			'29' => array( 55, 60 ),
			'30' => array( 52, 58 ),
			'31' => array( 50, 54 ),
			'32' => array( 48, 52 ),
		);
	}

	/**
	 * Erwartete Schlupf-Spanne (Tage) für eine Temperatur.
	 *
	 * @param float|string|null $temp Temperatur in °C, leer für Standard.
	 * @return int[] [minTage, maxTage].
	 */
	public static function hatch_range_for_temp( $temp ) {
		if ( '' === $temp || null === $temp ) {
			return array( self::DEFAULT_DAYS - 3, self::DEFAULT_DAYS + 5 );
		}

		$temp  = (float) $temp;
		$table = self::temp_table();
		$temps = array_map( 'floatval', array_keys( $table ) );

		$min_t = min( $temps );
		$max_t = max( $temps );

		// Außerhalb der Tabelle: Randwerte übernehmen.
		if ( $temp <= $min_t ) {
			return $table[ (string) (int) $min_t ];
		}
		if ( $temp >= $max_t ) {
			return $table[ (string) (int) $max_t ];
		}

		$prev_t = $min_t;
		$prev_v = $table[ (string) (int) $min_t ];
		foreach ( $table as $t_key => $range ) {
			$t = (float) $t_key;
			if ( $temp <= $t ) {
				if ( $t === $prev_t ) {
					return $range;
				}
				$ratio = ( $temp - $prev_t ) / ( $t - $prev_t );
				return array(
					(int) round( $prev_v[0] + $ratio * ( $range[0] - $prev_v[0] ) ),
					(int) round( $prev_v[1] + $ratio * ( $range[1] - $prev_v[1] ) ),
				);
			}
			$prev_t = $t;
			$prev_v = $range;
		}

		return $prev_v;
	}

	/**
	 * Vorhergesagte Schlupf-Daten (früh/spät) für ein Gelege.
	 *
	 * @param string            $lay_date Ablagedatum (Y-m-d).
	 * @param float|string|null $temp     Inkubationstemperatur.
	 * @return array|null { min_days, max_days, min_date, max_date } oder null.
	 */
	public static function predict( $lay_date, $temp = null ) {
		$ts = $lay_date ? strtotime( $lay_date ) : false;
		if ( ! $ts ) {
			return null;
		}

		$range = self::hatch_range_for_temp( $temp );

		return array(
			'min_days' => $range[0],
			'max_days' => $range[1],
			'min_date' => gmdate( 'Y-m-d', $ts + $range[0] * DAY_IN_SECONDS ),
			'max_date' => gmdate( 'Y-m-d', $ts + $range[1] * DAY_IN_SECONDS ),
		);
	}

	/**
	 * Lesbarer Vorhersage-Text, z. B. "Bei 29 °C: Schlupf Tag 55–60 (03.–08.06.2026)".
	 *
	 * @param string            $lay_date Ablagedatum.
	 * @param float|string|null $temp     Temperatur.
	 * @return string
	 */
	public static function prediction_label( $lay_date, $temp = null ) {
		$p = self::predict( $lay_date, $temp );
		if ( ! $p ) {
			return '';
		}

		$date_range = date_i18n( 'd.m.', strtotime( $p['min_date'] ) ) . '–' . date_i18n( get_option( 'date_format' ), strtotime( $p['max_date'] ) );

		if ( '' === $temp || null === $temp ) {
			/* translators: 1: min Tag, 2: max Tag, 3: Datumsspanne */
			return sprintf( __( 'Schlupf ≈ Tag %1$d–%2$d (%3$s)', 'reptilien-manager' ), $p['min_days'], $p['max_days'], $date_range );
		}

		/* translators: 1: Temperatur, 2: min Tag, 3: max Tag, 4: Datumsspanne */
		return sprintf( __( 'Bei %1$s °C: Schlupf Tag %2$d–%3$d (%4$s)', 'reptilien-manager' ), $temp, $p['min_days'], $p['max_days'], $date_range );
	}
}
