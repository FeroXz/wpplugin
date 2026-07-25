<?php
/**
 * Liefert die beiden Dateien aus, die zwingend von der Domain-Wurzel
 * erreichbar sein müssen, damit die PWA sich site-weit installieren kann:
 *
 * - /reptilien-manager-sw.js               (Service Worker, braucht Root-Scope)
 * - /reptilien-manager-manifest.webmanifest (Manifest, start_url hängt vom Standort
 *                                             des [reptilien-pwa]-Shortcodes ab)
 *
 * Beide werden früh auf `init` abgefangen (statt über add_rewrite_rule +
 * Rewrite-Flush), damit sie auf bereits aktiven Installationen sofort ohne
 * Reaktivierung funktionieren.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_PWA_Assets {

	const SW_SUFFIX        = '/reptilien-manager-sw.js';
	const MANIFEST_SUFFIX   = '/reptilien-manager-manifest.webmanifest';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/**
	 * Prüft den Request-Pfad und liefert bei Treffer die passende Datei aus.
	 */
	public static function maybe_serve() {
		if ( is_admin() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		if ( $path === self::target_path( self::SW_SUFFIX ) ) {
			self::serve_service_worker();
		} elseif ( $path === self::target_path( self::MANIFEST_SUFFIX ) ) {
			self::serve_manifest();
		}
	}

	/**
	 * Erwarteter Pfad relativ zur Domain, unter Berücksichtigung eines
	 * WordPress in einem Unterverzeichnis.
	 *
	 * @param string $suffix z. B. self::SW_SUFFIX.
	 * @return string
	 */
	private static function target_path( $suffix ) {
		return (string) wp_parse_url( home_url( $suffix ), PHP_URL_PATH );
	}

	/**
	 * Liefert den Service Worker mit Root-Scope-Header aus.
	 */
	private static function serve_service_worker() {
		$file = RM_PLUGIN_DIR . 'pwa/js/service-worker.js';
		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			exit;
		}

		$queue_url = RM_PLUGIN_URL . 'pwa/js/offline-queue.js';
		$precache  = array(
			RM_PLUGIN_URL . 'pwa/css/pwa.css',
			RM_PLUGIN_URL . 'pwa/js/app.js',
			RM_PLUGIN_URL . 'pwa/js/offline-queue.js',
			RM_PLUGIN_URL . 'pwa/js/barcode-scanner.js',
			RM_PLUGIN_URL . 'pwa/pages/animals.html',
			RM_PLUGIN_URL . 'pwa/pages/animal-detail.html',
			RM_PLUGIN_URL . 'pwa/pages/quick-entry.html',
			RM_PLUGIN_URL . 'pwa/icons/icon-192.png',
			RM_PLUGIN_URL . 'pwa/icons/icon-512.png',
		);

		$replacements = array(
			'__RM_PWA_QUEUE_URL__'     => $queue_url,
			'__RM_PWA_VERSION__'       => defined( 'RM_VERSION' ) ? RM_VERSION : '1.0',
			'__RM_PWA_PRECACHE__'      => wp_json_encode( array_values( $precache ) ),
			'__RM_PWA_API_MARKER__'    => '/' . rest_get_url_prefix() . '/' . RM_REST_Controller::NAMESPACE . '/',
			// Nur Assets unterhalb dieses Pfads werden Cache-First bedient.
			'__RM_PWA_ASSET_PREFIX__'  => (string) wp_parse_url( RM_PLUGIN_URL . 'pwa/', PHP_URL_PATH ),
			'__RM_PWA_ICON_192__'      => RM_PLUGIN_URL . 'pwa/icons/icon-192.png',
			'__RM_PWA_START_URL__'     => self::start_url(),
		);

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = strtr( $contents, $replacements );

		nocache_headers();
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Service-Worker-Allowed: /' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		echo $contents; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- statische JS-Datei mit reinen URL-Ersetzungen.
		exit;
	}

	/**
	 * Liefert das Manifest mit dynamischer start_url/Icon-URLs aus.
	 */
	private static function serve_manifest() {
		$file = RM_PLUGIN_DIR . 'pwa/manifest.json';
		if ( ! file_exists( $file ) ) {
			status_header( 404 );
			exit;
		}

		$replacements = array(
			'__RM_PWA_SCOPE__'      => '/',
			'__RM_PWA_START_URL__'  => self::start_url(),
			'__RM_PWA_ICON_192__'   => RM_PLUGIN_URL . 'pwa/icons/icon-192.png',
			'__RM_PWA_ICON_512__'   => RM_PLUGIN_URL . 'pwa/icons/icon-512.png',
		);

		$contents = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$contents = strtr( $contents, $replacements );

		$manifest = json_decode( $contents, true );
		if ( ! is_array( $manifest ) ) {
			status_header( 500 );
			exit;
		}

		/**
		 * Erlaubt es, das PWA-Manifest vor der Auslieferung anzupassen
		 * (z. B. eigenes Theme-Color, zusätzliche Screenshots).
		 *
		 * @param array $manifest Manifest-Daten.
		 */
		$manifest = apply_filters( 'rm_pwa_manifest', $manifest );

		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		header( 'Cache-Control: no-cache, must-revalidate' );
		echo wp_json_encode( $manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-Ausgabe.
		exit;
	}

	/**
	 * Start-URL der PWA: die erste veröffentlichte Seite/der erste Beitrag
	 * mit dem [reptilien-pwa]-Shortcode, 1 Tag zwischengespeichert; ohne
	 * Fund oder per Filter überschreibbar die Startseite.
	 *
	 * @return string
	 */
	public static function start_url() {
		$cached = get_transient( 'rm_pwa_start_url' );
		if ( false === $cached ) {
			global $wpdb;
			$post_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_content LIKE %s ORDER BY ID ASC LIMIT 1",
					'%' . $wpdb->esc_like( '[reptilien-pwa' ) . '%'
				)
			);
			$cached = $post_id ? get_permalink( (int) $post_id ) : home_url( '/' );
			set_transient( 'rm_pwa_start_url', $cached, DAY_IN_SECONDS );
		}

		/**
		 * Start-URL der PWA (Manifest start_url sowie Shortcut-Ziele).
		 *
		 * @param string $url Ermittelte oder per Filter gesetzte Start-URL.
		 */
		return apply_filters( 'rm_pwa_start_url', $cached );
	}

	/**
	 * Root-relative URLs der beiden ausgelieferten Dateien (für die
	 * <link>/<script>-Tags im Shortcode-Shell).
	 *
	 * @return array { sw, manifest }
	 */
	public static function urls() {
		return array(
			'sw'       => home_url( self::SW_SUFFIX ),
			'manifest' => home_url( self::MANIFEST_SUFFIX ),
		);
	}
}
