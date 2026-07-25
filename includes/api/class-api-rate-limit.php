<?php
/**
 * Rate-Limiting für die Reptilien-Manager-REST-API: 60 Anfragen pro Minute
 * je API-Key, per Transient in einem fixen Ein-Minuten-Fenster (Schlüssel
 * enthält die aktuelle Kalenderminute, ähnlich einem Fixed-Window-Limiter).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Api_Rate_Limit {

	/** Maximale Anfragen pro Minute und API-Key. */
	const LIMIT = 60;

	/**
	 * Prüft und zählt eine Anfrage für den gegebenen API-Key. Gibt false
	 * zurück, sobald das Limit für die aktuelle Minute erreicht ist.
	 *
	 * @param string $key API-Key.
	 * @return bool
	 */
	public static function allow( $key ) {
		$bucket = self::bucket_key( $key );
		$count  = get_transient( $bucket );
		$count  = false === $count ? 0 : (int) $count;

		if ( $count >= self::LIMIT ) {
			return false;
		}

		// TTL etwas großzügiger als das Fenster selbst, damit ein knapp vor
		// der Minutengrenze gesetzter Transient nicht vorzeitig verschwindet.
		set_transient( $bucket, $count + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}

	/**
	 * Verbleibende Anfragen in der aktuellen Minute.
	 *
	 * @param string $key API-Key.
	 * @return int
	 */
	public static function remaining( $key ) {
		$count = get_transient( self::bucket_key( $key ) );
		$count = false === $count ? 0 : (int) $count;
		return max( 0, self::LIMIT - $count );
	}

	/**
	 * Transient-Schlüssel für einen API-Key in der aktuellen Minute.
	 * Der Key selbst wird gehasht, damit der Options-Eintrag unabhängig von
	 * Länge/Zeichen des Keys stabil und kurz bleibt.
	 *
	 * @param string $key API-Key.
	 * @return string
	 */
	private static function bucket_key( $key ) {
		return 'rm_api_rate_limit_' . md5( $key ) . '_' . gmdate( 'YmdHi' );
	}
}
