<?php
/**
 * Shortcode [reptilien-pwa]: Mobile-First-App-Shell, installierbar als
 * Progressive Web App. Baut auf der bestehenden REST-API (v1.18) auf und
 * fügt clientseitig Offline-Cache (IndexedDB), Quick-Entry für Gewicht/
 * Fütterung und einen QR-Code-Scanner hinzu.
 *
 * Nur für eingeloggte Nutzer mit Verwaltungsrecht (dieselbe Berechtigung wie
 * [reptilien-verwaltung]) – eine App zum Verwalten des eigenen Bestands
 * ergibt für anonyme Besucher keinen Sinn.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_PWA_Shortcode {

	public static function init() {
		add_shortcode( 'reptilien-pwa', array( __CLASS__, 'render' ) );
		add_action( 'wp_head', array( __CLASS__, 'maybe_output_head_tags' ) );
	}

	/**
	 * Mindest-Fähigkeit, um die PWA zu nutzen (wie RM_Frontend).
	 *
	 * @return string
	 */
	private static function required_cap() {
		return apply_filters( 'rm_frontend_manage_cap', 'edit_posts' );
	}

	/**
	 * [reptilien-pwa] – App-Shell rendern.
	 *
	 * @return string
	 */
	public static function render() {
		if ( ! is_user_logged_in() ) {
			return '<div class="rm-notice">' . sprintf(
				/* translators: %s: Login-Link */
				wp_kses_post( __( 'Bitte <a href="%s">einloggen</a>, um die App zu nutzen.', 'reptilien-manager' ) ),
				esc_url( wp_login_url( get_permalink() ) )
			) . '</div>';
		}
		if ( ! current_user_can( self::required_cap() ) ) {
			return '<div class="rm-notice">' . esc_html__( 'Dein Benutzerkonto hat keine Berechtigung, die App zu nutzen.', 'reptilien-manager' ) . '</div>';
		}

		self::enqueue_assets();

		ob_start();
		?>
		<div id="rm-pwa-app" class="rm-pwa">
			<div id="rm-pwa-offline-banner" class="rm-pwa-banner" hidden></div>

			<header class="rm-pwa-header">
				<h1 class="rm-pwa-title"><?php esc_html_e( 'Reptilien Manager', 'reptilien-manager' ); ?></h1>
				<div class="rm-pwa-sync">
					<button type="button" id="rm-pwa-notify-btn" class="rm-pwa-icon-btn" title="<?php esc_attr_e( 'Benachrichtigungen aktivieren', 'reptilien-manager' ); ?>" hidden>🔔</button>
					<button type="button" id="rm-pwa-sync-btn" title="<?php esc_attr_e( 'Jetzt synchronisieren', 'reptilien-manager' ); ?>">🔄</button>
					<span id="rm-pwa-sync-count"></span>
				</div>
			</header>

			<main id="rm-pwa-view" class="rm-pwa-view"></main>

			<nav class="rm-pwa-nav">
				<button type="button" data-route="animals">🦎 <?php esc_html_e( 'Tiere', 'reptilien-manager' ); ?></button>
				<button type="button" data-route="scan">📷 <?php esc_html_e( 'Scan', 'reptilien-manager' ); ?></button>
				<button type="button" data-modal="weight">⚖️ <?php esc_html_e( 'Wiegen', 'reptilien-manager' ); ?></button>
				<button type="button" data-modal="feeding">🍽️ <?php esc_html_e( 'Füttern', 'reptilien-manager' ); ?></button>
			</nav>

			<div id="rm-pwa-toast" class="rm-pwa-toast" hidden></div>
			<div id="rm-pwa-modal-root"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Meta-Tags/Manifest-Link in <head>, aber nur auf Seiten, die den
	 * Shortcode tatsächlich enthalten (has_shortcode() prüft den rohen
	 * Beitragsinhalt und funktioniert daher schon vor dem Rendern).
	 */
	public static function maybe_output_head_tags() {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_queried_object();
		if ( ! ( $post instanceof WP_Post ) || ! has_shortcode( $post->post_content, 'reptilien-pwa' ) ) {
			return;
		}

		$urls = RM_PWA_Assets::urls();
		?>
		<link rel="manifest" href="<?php echo esc_url( $urls['manifest'] ); ?>" />
		<meta name="theme-color" content="#4f46e5" />
		<meta name="mobile-web-app-capable" content="yes" />
		<meta name="apple-mobile-web-app-capable" content="yes" />
		<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent" />
		<meta name="apple-mobile-web-app-title" content="<?php esc_attr_e( 'RM Mobile', 'reptilien-manager' ); ?>" />
		<link rel="apple-touch-icon" href="<?php echo esc_url( RM_PLUGIN_URL . 'pwa/icons/icon-180.png' ); ?>" />
		<?php
	}

	/**
	 * Bindet CSS/JS ein und stellt window.RM_PWA_CONFIG bereit.
	 */
	private static function enqueue_assets() {
		wp_enqueue_style( 'rm-pwa', RM_PLUGIN_URL . 'pwa/css/pwa.css', array(), RM_VERSION );

		$user_id = get_current_user_id();
		$api_key = class_exists( 'RM_Api_Auth' ) ? RM_Api_Auth::key_for_user( $user_id ) : '';
		if ( class_exists( 'RM_Api_Auth' ) && ! $api_key ) {
			// Erster Aufruf: automatisch einen Key für die App bereitstellen,
			// widerrufbar/erneuerbar jederzeit unter „Reptilien → API“.
			$api_key = RM_Api_Auth::generate_key_for_user( $user_id );
		}

		wp_enqueue_script( 'rm-pwa-queue', RM_PLUGIN_URL . 'pwa/js/offline-queue.js', array(), RM_VERSION, true );
		wp_enqueue_script( 'rm-pwa-barcode', RM_PLUGIN_URL . 'pwa/js/barcode-scanner.js', array(), RM_VERSION, true );
		self::enqueue_chartjs();
		wp_enqueue_script(
			'rm-pwa-app',
			RM_PLUGIN_URL . 'pwa/js/app.js',
			array( 'rm-pwa-queue', 'rm-pwa-barcode', 'rm-chartjs' ),
			RM_VERSION,
			true
		);

		$urls = RM_PWA_Assets::urls();

		wp_localize_script(
			'rm-pwa-app',
			'RM_PWA_CONFIG',
			array(
				'apiBase'      => rest_url( RM_REST_Controller::NAMESPACE ),
				'apiKey'       => $api_key,
				'swUrl'        => $urls['sw'],
				'manifestUrl'  => $urls['manifest'],
				'pagesUrl'     => RM_PLUGIN_URL . 'pwa/pages/',
				'icon192'      => RM_PLUGIN_URL . 'pwa/icons/icon-192.png',
				/**
				 * jsQR-Quelle (QR-Scanner), per CDN oder selbst gehostet.
				 *
				 * @param string $src Standard: jsDelivr-CDN.
				 */
				'jsQrSrc'      => apply_filters( 'rm_pwa_jsqr_src', 'https://cdn.jsdelivr.net/npm/jsqr@1.4.0/dist/jsQR.js' ),
				'fullSiteUrl'  => admin_url( 'edit.php?post_type=rm_animal' ),
				'adminPostUrl' => admin_url( 'admin-post.php' ),
				'feNonce'      => wp_create_nonce( 'rm_fe_save_animal' ),
				'foodTypes'    => class_exists( 'RM_Feeding' ) ? RM_Feeding::food_types() : array(),
				'supplements'  => class_exists( 'RM_Feeding' ) ? RM_Feeding::supplements() : array(),
			)
		);
	}

	/**
	 * Chart.js für den Gewichtsverlauf im Detail-Tab. Gleiches Muster wie
	 * im übrigen Plugin (lokal bundelbar oder CDN, Filter rm_chartjs_src).
	 */
	private static function enqueue_chartjs() {
		$local_path = RM_PLUGIN_DIR . 'assets/js/vendor/chart.min.js';
		$local_url  = RM_PLUGIN_URL . 'assets/js/vendor/chart.min.js';
		$cdn        = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';

		$src = file_exists( $local_path ) ? $local_url : $cdn;
		$src = apply_filters( 'rm_chartjs_src', $src );

		wp_enqueue_script( 'rm-chartjs', $src, array(), '4.4.1', true );
	}
}
