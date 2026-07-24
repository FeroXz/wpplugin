<?php
/**
 * Arten-Registry: verknüpft die Taxonomie-Begriffe mit Art-Profilen
 * (Genetik-Set und Futterplan werden pro Art in RM_Genetics bzw.
 * RM_Feeding definiert und über den hier ermittelten Schlüssel geladen).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Species {

	/**
	 * Standard-Art, wenn keine oder eine unbekannte Art zugeordnet ist.
	 */
	const DEFAULT_KEY = 'pogona';

	/**
	 * Bekannte Arten.
	 *
	 * @return array[] Schlüssel => { label, term_name, diet, keywords }.
	 */
	public static function profiles() {
		return array(
			'pogona' => array(
				'label'     => __( 'Bartagame (Pogona vitticeps)', 'reptilien-manager' ),
				'term_name' => __( 'Bartagame (Pogona vitticeps)', 'reptilien-manager' ),
				'diet'      => 'omnivore',
				'keywords'  => array( 'pogona', 'bartagame' ),
			),
			'iguana' => array(
				'label'     => __( 'Grüner Leguan (Iguana iguana)', 'reptilien-manager' ),
				'term_name' => __( 'Grüner Leguan (Iguana iguana)', 'reptilien-manager' ),
				'diet'      => 'herbivore',
				'keywords'  => array( 'iguana', 'leguan' ),
			),
		);
	}

	/**
	 * Legt die Standard-Arten als Taxonomie-Begriffe an (idempotent).
	 */
	public static function register_terms() {
		if ( ! taxonomy_exists( 'rm_species' ) ) {
			return;
		}

		foreach ( self::profiles() as $profile ) {
			if ( ! term_exists( $profile['term_name'], 'rm_species' ) ) {
				wp_insert_term( $profile['term_name'], 'rm_species' );
			}
		}
	}

	/**
	 * Stellt sicher, dass für jede bekannte Art ein Taxonomie-Begriff existiert,
	 * und liefert alle Begriffe der Taxonomie zurück.
	 *
	 * Die Begriffe werden nur angelegt, wenn die Taxonomie bereits registriert
	 * ist – sonst liefert term_exists()/wp_insert_term() einen Fehler und die
	 * Auswahl bliebe leer.
	 *
	 * @return WP_Term[] Alle Arten-Begriffe (leer, falls keine angelegt werden konnten).
	 */
	public static function ensure_terms() {
		if ( ! taxonomy_exists( 'rm_species' ) ) {
			return array();
		}

		self::register_terms();

		$terms = get_terms(
			array(
				'taxonomy'   => 'rm_species',
				'hide_empty' => false,
			)
		);

		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Alle Arten als Schlüssel => Label.
	 *
	 * @return array
	 */
	public static function all() {
		$out = array();
		foreach ( self::profiles() as $key => $profile ) {
			$out[ $key ] = $profile['label'];
		}
		return $out;
	}

	/**
	 * Label einer Art.
	 *
	 * @param string $key Art-Schlüssel.
	 * @return string
	 */
	public static function label( $key ) {
		$profiles = self::profiles();
		return isset( $profiles[ $key ] ) ? $profiles[ $key ]['label'] : $profiles[ self::DEFAULT_KEY ]['label'];
	}

	/**
	 * Ernährungstyp einer Art ('omnivore' oder 'herbivore').
	 *
	 * @param string $key Art-Schlüssel.
	 * @return string
	 */
	public static function diet( $key ) {
		$profiles = self::profiles();
		return isset( $profiles[ $key ] ) ? $profiles[ $key ]['diet'] : $profiles[ self::DEFAULT_KEY ]['diet'];
	}

	/**
	 * Art-Schlüssel aus einer Liste von Begriffsnamen ermitteln.
	 *
	 * @param string[] $names Begriffsnamen.
	 * @return string
	 */
	public static function key_from_term_names( $names ) {
		$profiles = self::profiles();

		foreach ( (array) $names as $name ) {
			foreach ( $profiles as $key => $profile ) {
				if ( $name === $profile['term_name'] ) {
					return $key;
				}
				foreach ( $profile['keywords'] as $keyword ) {
					if ( false !== stripos( $name, $keyword ) ) {
						return $key;
					}
				}
			}
		}

		return self::DEFAULT_KEY;
	}

	/**
	 * Art-Schlüssel eines Tieres anhand seiner zugeordneten Taxonomie.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return string
	 */
	public static function key_for_animal( $animal_id ) {
		$names = wp_get_post_terms( $animal_id, 'rm_species', array( 'fields' => 'names' ) );
		if ( is_wp_error( $names ) || ! $names ) {
			return self::DEFAULT_KEY;
		}
		return self::key_from_term_names( $names );
	}
}
