<?php
/**
 * Plugin Name:       Reptilien Manager
 * Plugin URI:        https://github.com/FeroXz/wpplugin
 * Description:       Verwaltung von Reptilien – Bartagame (Pogona vitticeps) und Grüner Leguan (Iguana iguana). Eigene Tiere mit Fotos und allen wichtigen Daten erfassen, Verpaarungen planen inkl. artspezifischer Genetik-Vorschau der Jungtiere sowie artgerechte Futterplanung und Fütterungsprotokoll.
 * Version:           1.5.0
 * Author:            FeroXz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       reptilien-manager
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'RM_VERSION', '1.5.0' );
define( 'RM_PLUGIN_FILE', __FILE__ );
define( 'RM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'RM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once RM_PLUGIN_DIR . 'includes/class-rm-species.php';
require_once RM_PLUGIN_DIR . 'includes/class-rm-post-types.php';
require_once RM_PLUGIN_DIR . 'includes/class-rm-genetics.php';
require_once RM_PLUGIN_DIR . 'includes/class-rm-animal-meta.php';
require_once RM_PLUGIN_DIR . 'includes/class-rm-pairing.php';
require_once RM_PLUGIN_DIR . 'includes/class-rm-feeding.php';
require_once RM_PLUGIN_DIR . 'includes/class-rm-admin-pages.php';
require_once RM_PLUGIN_DIR . 'includes/class-rm-templates.php';
require_once RM_PLUGIN_DIR . 'includes/class-rm-shortcodes.php';

/**
 * Plugin bootstrap.
 */
final class Reptilien_Manager {

	/** @var Reptilien_Manager|null */
	private static $instance = null;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		add_action( 'init', array( $this, 'load_textdomain' ) );

		RM_Post_Types::init();
		RM_Animal_Meta::init();
		RM_Pairing::init();
		RM_Feeding::init();
		RM_Admin_Pages::init();
		RM_Templates::init();
		RM_Shortcodes::init();

		add_action( 'admin_enqueue_scripts', array( $this, 'admin_assets' ) );

		register_activation_hook( RM_PLUGIN_FILE, array( __CLASS__, 'activate' ) );
		register_deactivation_hook( RM_PLUGIN_FILE, array( __CLASS__, 'deactivate' ) );
	}

	public function load_textdomain() {
		load_plugin_textdomain( 'reptilien-manager', false, dirname( plugin_basename( RM_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Admin-CSS/JS nur auf den Plugin-Bildschirmen laden.
	 *
	 * @param string $hook Aktueller Admin-Hook.
	 */
	public function admin_assets( $hook ) {
		$screen = get_current_screen();
		$our_types = array( 'rm_animal', 'rm_pairing', 'rm_feeding_log' );
		$our_pages = array( 'rm_animal_page_rm-genetics', 'rm_animal_page_rm-feeding-plan' );

		$is_ours = ( $screen && in_array( $screen->post_type, $our_types, true ) )
			|| in_array( $hook, $our_pages, true );

		if ( ! $is_ours ) {
			return;
		}

		wp_enqueue_style( 'rm-admin', RM_PLUGIN_URL . 'assets/css/admin.css', array(), RM_VERSION );

		if ( $screen && 'rm_animal' === $screen->post_type ) {
			wp_enqueue_media();
		}

		wp_enqueue_script( 'rm-admin', RM_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), RM_VERSION, true );
		wp_localize_script(
			'rm-admin',
			'rmAdmin',
			array(
				'chooseImages'   => __( 'Fotos auswählen', 'reptilien-manager' ),
				'addToGallery'   => __( 'Zur Galerie hinzufügen', 'reptilien-manager' ),
				'replaceConfirm' => __( 'Der vorhandene Beitragstext wird durch die Vorlage ersetzt. Fortfahren?', 'reptilien-manager' ),
				'templateError'  => __( 'Die Vorlage konnte nicht erzeugt werden. Bitte erneut versuchen.', 'reptilien-manager' ),
				'uploadError'    => __( 'Der Upload ist fehlgeschlagen. Bitte erneut versuchen.', 'reptilien-manager' ),
			)
		);
	}

	public static function activate() {
		RM_Post_Types::register();
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
	}
}

Reptilien_Manager::instance();
