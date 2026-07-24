<?php
/**
 * Registriert die Custom Post Types des Plugins.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Post_Types {

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		self::register_animal();
		self::register_pairing();
		self::register_feeding_log();
		self::register_species_taxonomy();
	}

	private static function register_animal() {
		$labels = array(
			'name'               => __( 'Tiere', 'reptilien-manager' ),
			'singular_name'      => __( 'Tier', 'reptilien-manager' ),
			'menu_name'          => __( 'Reptilien', 'reptilien-manager' ),
			'add_new'            => __( 'Tier hinzufügen', 'reptilien-manager' ),
			'add_new_item'       => __( 'Neues Tier eintragen', 'reptilien-manager' ),
			'edit_item'          => __( 'Tier bearbeiten', 'reptilien-manager' ),
			'new_item'           => __( 'Neues Tier', 'reptilien-manager' ),
			'view_item'          => __( 'Tier ansehen', 'reptilien-manager' ),
			'search_items'       => __( 'Tiere durchsuchen', 'reptilien-manager' ),
			'not_found'          => __( 'Keine Tiere gefunden.', 'reptilien-manager' ),
			'not_found_in_trash' => __( 'Keine Tiere im Papierkorb gefunden.', 'reptilien-manager' ),
			'all_items'          => __( 'Alle Tiere', 'reptilien-manager' ),
			'featured_image'     => __( 'Profilfoto', 'reptilien-manager' ),
			'set_featured_image' => __( 'Profilfoto festlegen', 'reptilien-manager' ),
		);

		register_post_type(
			'rm_animal',
			array(
				'labels'        => $labels,
				'public'        => true,
				'show_in_rest'  => true,
				'menu_icon'     => 'dashicons-pets',
				'menu_position' => 25,
				'supports'      => array( 'title', 'editor', 'thumbnail', 'author' ),
				'has_archive'   => true,
				'rewrite'       => array( 'slug' => 'reptilien' ),
				'capability_type' => 'post',
				'map_meta_cap'  => true,
			)
		);
	}

	private static function register_pairing() {
		$labels = array(
			'name'               => __( 'Verpaarungen', 'reptilien-manager' ),
			'singular_name'      => __( 'Verpaarung', 'reptilien-manager' ),
			'add_new'            => __( 'Verpaarung hinzufügen', 'reptilien-manager' ),
			'add_new_item'       => __( 'Neue Verpaarung planen', 'reptilien-manager' ),
			'edit_item'          => __( 'Verpaarung bearbeiten', 'reptilien-manager' ),
			'search_items'       => __( 'Verpaarungen durchsuchen', 'reptilien-manager' ),
			'not_found'          => __( 'Keine Verpaarungen gefunden.', 'reptilien-manager' ),
			'all_items'          => __( 'Verpaarungen', 'reptilien-manager' ),
		);

		register_post_type(
			'rm_pairing',
			array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=rm_animal',
				'supports'        => array( 'title', 'editor', 'author' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	private static function register_feeding_log() {
		$labels = array(
			'name'               => __( 'Fütterungen', 'reptilien-manager' ),
			'singular_name'      => __( 'Fütterung', 'reptilien-manager' ),
			'add_new'            => __( 'Fütterung eintragen', 'reptilien-manager' ),
			'add_new_item'       => __( 'Neue Fütterung eintragen', 'reptilien-manager' ),
			'edit_item'          => __( 'Fütterung bearbeiten', 'reptilien-manager' ),
			'search_items'       => __( 'Fütterungen durchsuchen', 'reptilien-manager' ),
			'not_found'          => __( 'Keine Fütterungen gefunden.', 'reptilien-manager' ),
			'all_items'          => __( 'Fütterungsprotokoll', 'reptilien-manager' ),
		);

		register_post_type(
			'rm_feeding_log',
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

	private static function register_species_taxonomy() {
		register_taxonomy(
			'rm_species',
			'rm_animal',
			array(
				'labels'       => array(
					'name'          => __( 'Arten', 'reptilien-manager' ),
					'singular_name' => __( 'Art', 'reptilien-manager' ),
					'add_new_item'  => __( 'Neue Art anlegen', 'reptilien-manager' ),
				),
				'hierarchical' => true,
				'show_in_rest' => true,
				'show_admin_column' => true,
			)
		);

		// Standard-Arten (Bartagame, Grüner Leguan) anlegen, falls noch nicht vorhanden.
		RM_Species::register_terms();
	}

	/**
	 * Liefert alle Tiere, optional nach Geschlecht gefiltert.
	 *
	 * @param string $sex 'male', 'female' oder '' für alle.
	 * @return WP_Post[]
	 */
	public static function get_animals( $sex = '' ) {
		$args = array(
			'post_type'      => 'rm_animal',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_status'    => array( 'publish', 'draft', 'private' ),
		);

		if ( $sex ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_rm_sex',
					'value' => $sex,
				),
			);
		}

		// Züchter sehen nur eigene Tiere.
		if ( class_exists( 'RM_Roles' ) ) {
			$args = array_merge( $args, RM_Roles::author_query_args() );
		}

		return get_posts( $args );
	}
}
