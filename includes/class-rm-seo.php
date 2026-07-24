<?php
/**
 * SEO für öffentliche Tierprofile: Open-Graph-/Twitter-Meta, JSON-LD und
 * 404 für als privat markierte Tiere im Frontend.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Seo {

	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'render_head' ), 5 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_block_private' ) );
	}

	/**
	 * Blockiert die Einzelansicht privat markierter Tiere für Besucher
	 * (Eigentümer/Redakteure dürfen weiterhin eine Vorschau sehen).
	 */
	public static function maybe_block_private() {
		if ( ! is_singular( 'rm_animal' ) ) {
			return;
		}

		$id = get_queried_object_id();
		if ( RM_Roles::is_public( $id ) || current_user_can( 'edit_post', $id ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Gibt Meta-Tags und strukturierte Daten für öffentliche Tierprofile aus.
	 */
	public static function render_head() {
		if ( ! is_singular( 'rm_animal' ) ) {
			return;
		}

		$id = get_queried_object_id();
		if ( ! $id || ! RM_Roles::is_public( $id ) ) {
			return;
		}

		$title       = get_the_title( $id );
		$description  = self::build_description( $id );
		$url          = get_permalink( $id );
		$image        = has_post_thumbnail( $id ) ? get_the_post_thumbnail_url( $id, 'large' ) : '';

		// Open Graph.
		echo "\n" . '<meta property="og:type" content="article" />' . "\n";
		printf( '<meta property="og:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s" />' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:url" content="%s" />' . "\n", esc_url( $url ) );
		if ( $image ) {
			printf( '<meta property="og:image" content="%s" />' . "\n", esc_url( $image ) );
		}

		// Twitter Card.
		printf( '<meta name="twitter:card" content="%s" />' . "\n", $image ? 'summary_large_image' : 'summary' );
		printf( '<meta name="twitter:title" content="%s" />' . "\n", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s" />' . "\n", esc_attr( $description ) );
		if ( $image ) {
			printf( '<meta name="twitter:image" content="%s" />' . "\n", esc_url( $image ) );
		}

		// JSON-LD (schema.org).
		echo '<script type="application/ld+json">' . wp_json_encode( self::schema( $id, $title, $description, $url, $image ) ) . '</script>' . "\n";
	}

	/**
	 * Beschreibungstext aus den Kerndaten des Tieres.
	 *
	 * @param int $id Beitrags-ID.
	 * @return string
	 */
	public static function build_description( $id ) {
		$parts = array();

		$species = RM_Species::label( RM_Species::key_for_animal( $id ) );
		if ( $species ) {
			$parts[] = $species;
		}

		$sexes = RM_Animal_Meta::sexes();
		$sex   = get_post_meta( $id, '_rm_sex', true );
		if ( isset( $sexes[ $sex ] ) && 'unknown' !== $sex ) {
			$parts[] = $sexes[ $sex ];
		}

		$morph = RM_Genetics::animal_morph_label( $id );
		if ( $morph ) {
			$parts[] = $morph;
		}

		$birth = get_post_meta( $id, '_rm_birth', true );
		if ( $birth && RM_Animal_Meta::age_label( $birth ) ) {
			$parts[] = RM_Animal_Meta::age_label( $birth );
		}

		return implode( ' · ', $parts );
	}

	/**
	 * JSON-LD-Struktur (schema.org/Thing mit Zusatz-Eigenschaften).
	 *
	 * @param int    $id          Beitrags-ID.
	 * @param string $title       Titel.
	 * @param string $description Beschreibung.
	 * @param string $url         Permalink.
	 * @param string $image       Bild-URL.
	 * @return array
	 */
	public static function schema( $id, $title, $description, $url, $image ) {
		$props = array();

		$add = static function ( $name, $value ) use ( &$props ) {
			if ( '' !== (string) $value ) {
				$props[] = array(
					'@type' => 'PropertyValue',
					'name'  => $name,
					'value' => (string) $value,
				);
			}
		};

		$add( __( 'Art', 'reptilien-manager' ), RM_Species::label( RM_Species::key_for_animal( $id ) ) );
		$add( __( 'Morph', 'reptilien-manager' ), RM_Genetics::animal_morph_label( $id ) );

		$sexes = RM_Animal_Meta::sexes();
		$sex   = get_post_meta( $id, '_rm_sex', true );
		if ( isset( $sexes[ $sex ] ) && 'unknown' !== $sex ) {
			$add( __( 'Geschlecht', 'reptilien-manager' ), $sexes[ $sex ] );
		}

		$birth = get_post_meta( $id, '_rm_birth', true );
		if ( $birth ) {
			$add( __( 'Schlupfdatum', 'reptilien-manager' ), $birth );
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'Thing',
			'name'     => $title,
			'url'      => $url,
		);
		if ( $description ) {
			$schema['description'] = $description;
		}
		if ( $image ) {
			$schema['image'] = $image;
		}
		if ( $props ) {
			$schema['additionalProperty'] = $props;
		}

		return $schema;
	}
}
