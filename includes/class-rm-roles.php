<?php
/**
 * Rollen, Zugriffsbeschränkung und Sichtbarkeit.
 *
 * - Eigene Rolle „Reptilien-Züchter“ (verwaltet nur eigene Tiere).
 * - Beschränkt Listen im Backend auf eigene Beiträge für Nutzer ohne
 *   edit_others_posts.
 * - Öffentlich/Privat-Sichtbarkeit pro Tier im Frontend.
 * - DSGVO-Datenschutz-Textbaustein.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Roles {

	const ROLE = 'rm_breeder';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_add_role' ), 20 );
		add_action( 'pre_get_posts', array( __CLASS__, 'scope_admin_lists' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_privacy_content' ) );
	}

	/* ---------------------------------------------------------------------
	 * Rolle
	 * ------------------------------------------------------------------ */

	/**
	 * Fähigkeiten der Züchter-Rolle (Autor-Niveau für die Plugin-Inhalte,
	 * ohne Zugriff auf fremde Beiträge).
	 *
	 * @return array<string,bool>
	 */
	public static function breeder_caps() {
		return array(
			'read'                   => true,
			'upload_files'           => true,
			'edit_posts'             => true,
			'edit_published_posts'   => true,
			'publish_posts'          => true,
			'delete_posts'           => true,
			'delete_published_posts' => true,
		);
	}

	/**
	 * Rolle anlegen (idempotent).
	 */
	public static function add_role() {
		remove_role( self::ROLE );
		add_role( self::ROLE, __( 'Reptilien-Züchter', 'reptilien-manager' ), self::breeder_caps() );
	}

	/**
	 * Rolle anlegen, falls sie fehlt (z. B. nach Update ohne Reaktivierung).
	 */
	public static function maybe_add_role() {
		if ( ! get_role( self::ROLE ) ) {
			add_role( self::ROLE, __( 'Reptilien-Züchter', 'reptilien-manager' ), self::breeder_caps() );
		}
	}

	/**
	 * Rolle entfernen (bei Deinstallation).
	 */
	public static function remove_role() {
		remove_role( self::ROLE );
	}

	/* ---------------------------------------------------------------------
	 * Zugriffsbeschränkung
	 * ------------------------------------------------------------------ */

	/**
	 * Ob der aktuelle Nutzer nur eigene Tiere sehen/verwalten darf.
	 *
	 * @return bool
	 */
	public static function current_user_restricted() {
		return is_user_logged_in() && ! current_user_can( 'edit_others_posts' );
	}

	/**
	 * Autor-Filter für interne Abfragen (leeres Array = keine Einschränkung).
	 *
	 * @return array
	 */
	public static function author_query_args() {
		if ( self::current_user_restricted() ) {
			return array( 'author' => get_current_user_id() );
		}
		return array();
	}

	/**
	 * Beschränkt die Admin-Listen der Plugin-Inhaltstypen auf eigene Beiträge.
	 *
	 * @param WP_Query $query Aktuelle Abfrage.
	 */
	public static function scope_admin_lists( $query ) {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}

		$types = array( 'rm_animal', 'rm_pairing', 'rm_feeding_log' );
		$pt    = $query->get( 'post_type' );

		if ( ! in_array( $pt, $types, true ) ) {
			return;
		}

		if ( self::current_user_restricted() ) {
			$query->set( 'author', get_current_user_id() );
		}
	}

	/* ---------------------------------------------------------------------
	 * Frontend-Sichtbarkeit
	 * ------------------------------------------------------------------ */

	/**
	 * Meta-Query-Klausel, um als privat markierte Tiere im Frontend
	 * auszuschließen (fehlendes Meta = öffentlich).
	 *
	 * @return array
	 */
	public static function public_meta_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => '_rm_public',
				'value'   => '0',
				'compare' => '!=',
			),
			array(
				'key'     => '_rm_public',
				'compare' => 'NOT EXISTS',
			),
		);
	}

	/**
	 * Ist ein Tier öffentlich sichtbar? (fehlendes Meta = ja)
	 *
	 * @param int $animal_id Beitrags-ID.
	 * @return bool
	 */
	public static function is_public( $animal_id ) {
		return '0' !== (string) get_post_meta( $animal_id, '_rm_public', true );
	}

	/* ---------------------------------------------------------------------
	 * Datenschutz (DSGVO)
	 * ------------------------------------------------------------------ */

	/**
	 * Fügt einen Textbaustein zur Datenschutzerklärung hinzu.
	 */
	public static function register_privacy_content() {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = wp_kses_post(
			'<p>' . __( 'Dieses Plugin (Reptilien Manager) speichert Angaben zu den verwalteten Tieren – etwa Name, Geschlecht, Schlupfdatum, Herkunft/Züchter, Gewichtsverlauf, Genanlagen, Verpaarungen und Fütterungen. Als „Herkunft/Züchter“ eingetragene Namen können personenbezogen sein.', 'reptilien-manager' ) . '</p>'
			. '<p>' . __( 'Nur als öffentlich markierte Tiere werden im Frontend angezeigt. Registrierte Nutzer mit der Rolle „Reptilien-Züchter“ sehen und verwalten ausschließlich ihre eigenen Einträge. E-Mail-Benachrichtigungen werden nur an die in den Einstellungen hinterlegte Adresse versendet.', 'reptilien-manager' ) . '</p>'
		);

		wp_add_privacy_policy_content( 'Reptilien Manager', $content );
	}
}
