<?php
/**
 * API-Key-Authentifizierung für die Reptilien-Manager-REST-API sowie die
 * Admin-Seite „Reptilien → API“ zum Generieren/Widerrufen von Keys.
 *
 * Ein gültiger Header X-Reptilien-API-Key setzt den aktuellen Nutzer
 * (wp_set_current_user), sodass alle bestehenden Berechtigungs- und
 * Autoren-Scopes (RM_Roles, current_user_can, get_current_user_id) unverändert
 * greifen – die REST-API nutzt exakt dieselben Regeln wie Backend/Frontend.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Api_Auth {

	/** User-Meta-Schlüssel des API-Keys. */
	const META_KEY = '_rm_api_key';

	/** User-Meta-Schlüssel des Erstellungsdatums. */
	const META_CREATED = '_rm_api_key_created';

	public static function init() {
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'authenticate' ) );
		add_filter( 'rest_pre_serve_request', array( __CLASS__, 'add_cors_headers' ), 10, 4 );

		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_rm_api_generate_key', array( __CLASS__, 'handle_generate_key' ) );
		add_action( 'admin_post_rm_api_revoke_key', array( __CLASS__, 'handle_revoke_key' ) );
	}

	/* ---------------------------------------------------------------------
	 * Authentifizierung
	 * ------------------------------------------------------------------ */

	/**
	 * `rest_authentication_errors`-Filter: prüft den API-Key-Header für
	 * Anfragen an unseren Namespace. Andere REST-Routen (z. B. wp/v2) und
	 * bereits anderweitig authentifizierte Anfragen (Cookie, Application
	 * Passwords) bleiben unberührt. Der Dokumentations-Endpunkt
	 * (openapi.json) ist bewusst ohne Key erreichbar.
	 *
	 * @param WP_Error|null|true $result Bisheriges Ergebnis.
	 * @return WP_Error|null|true
	 */
	public static function authenticate( $result ) {
		if ( ! empty( $result ) ) {
			return $result;
		}
		if ( ! self::is_our_namespace_request() || self::is_openapi_request() ) {
			return $result;
		}

		$key = self::header_key();
		if ( '' === $key ) {
			return new WP_Error(
				'rm_api_key_missing',
				__( 'API-Key fehlt. Bitte den Header X-Reptilien-API-Key setzen.', 'reptilien-manager' ),
				array( 'status' => 401 )
			);
		}

		$user = self::user_for_key( $key );
		if ( ! $user ) {
			return new WP_Error(
				'rm_api_key_invalid',
				__( 'Ungültiger API-Key.', 'reptilien-manager' ),
				array( 'status' => 401 )
			);
		}

		if ( class_exists( 'RM_Api_Rate_Limit' ) && ! RM_Api_Rate_Limit::allow( $key ) ) {
			return new WP_Error(
				'rm_api_rate_limited',
				__( 'Zu viele Anfragen – bitte in Kürze erneut versuchen (max. 60 pro Minute).', 'reptilien-manager' ),
				array( 'status' => 429 )
			);
		}

		wp_set_current_user( $user->ID );

		return true;
	}

	/**
	 * Läuft die aktuelle Anfrage gegen unseren REST-Namespace?
	 *
	 * @return bool
	 */
	private static function is_our_namespace_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		return false !== strpos( $uri, '/' . RM_REST_Controller::NAMESPACE );
	}

	/**
	 * Ist die aktuelle Anfrage der öffentliche OpenAPI-Dokumentations-Endpunkt?
	 *
	 * @return bool
	 */
	private static function is_openapi_request() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}
		$uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		return false !== strpos( $uri, '/' . RM_REST_Controller::NAMESPACE . '/openapi.json' );
	}

	/**
	 * API-Key aus dem Request-Header lesen.
	 *
	 * @return string
	 */
	private static function header_key() {
		if ( ! empty( $_SERVER['HTTP_X_REPTILIEN_API_KEY'] ) ) {
			return sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_REPTILIEN_API_KEY'] ) );
		}
		return '';
	}

	/**
	 * Nutzer zu einem API-Key.
	 *
	 * @param string $key API-Key.
	 * @return WP_User|null
	 */
	public static function user_for_key( $key ) {
		$key = trim( (string) $key );
		if ( '' === $key ) {
			return null;
		}

		$users = get_users(
			array(
				'meta_key'   => self::META_KEY,
				'meta_value' => $key,
				'number'     => 1,
				'fields'     => 'all',
			)
		);

		return $users ? $users[0] : null;
	}

	/* ---------------------------------------------------------------------
	 * CORS
	 * ------------------------------------------------------------------ */

	/**
	 * CORS-Header für unseren Namespace setzen, sofern ein erlaubter Origin
	 * per Filter hinterlegt ist (standardmäßig deaktiviert).
	 *
	 * @param bool             $served  Bisheriger Rückgabewert.
	 * @param WP_REST_Response $result  Antwort.
	 * @param WP_REST_Request  $request Anfrage.
	 * @param WP_REST_Server   $server  Server.
	 * @return bool
	 */
	public static function add_cors_headers( $served, $result, $request, $server ) {
		if ( 0 !== strpos( $request->get_route(), '/' . RM_REST_Controller::NAMESPACE ) ) {
			return $served;
		}

		/**
		 * Erlaubter CORS-Origin für die Reptilien-Manager-API.
		 * Leer (Standard) = keine CORS-Header, Browser-Zugriff von anderen
		 * Origins bleibt blockiert. '*' oder eine konkrete Origin aktivieren ihn.
		 *
		 * @param string          $origin  Standard: ''.
		 * @param WP_REST_Request $request Aktuelle Anfrage.
		 */
		$origin = apply_filters( 'rm_api_cors_allowed_origin', '', $request );
		if ( $origin ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Headers: X-Reptilien-API-Key, Content-Type' );
			header( 'Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS' );
		}

		return $served;
	}

	/* ---------------------------------------------------------------------
	 * Key-Verwaltung
	 * ------------------------------------------------------------------ */

	/**
	 * Neuen API-Key für einen Nutzer generieren (überschreibt einen
	 * bestehenden Key).
	 *
	 * @param int $user_id Nutzer-ID.
	 * @return string Neuer Key.
	 */
	public static function generate_key_for_user( $user_id ) {
		$key = 'rm_' . wp_generate_password( 40, false, false );
		update_user_meta( $user_id, self::META_KEY, $key );
		update_user_meta( $user_id, self::META_CREATED, current_time( 'mysql' ) );
		return $key;
	}

	/**
	 * API-Key eines Nutzers widerrufen.
	 *
	 * @param int $user_id Nutzer-ID.
	 */
	public static function revoke_key_for_user( $user_id ) {
		delete_user_meta( $user_id, self::META_KEY );
		delete_user_meta( $user_id, self::META_CREATED );
	}

	/**
	 * Aktueller API-Key eines Nutzers (leer, falls keiner vorhanden).
	 *
	 * @param int $user_id Nutzer-ID.
	 * @return string
	 */
	public static function key_for_user( $user_id ) {
		return (string) get_user_meta( $user_id, self::META_KEY, true );
	}

	/* ---------------------------------------------------------------------
	 * Admin-Seite „Reptilien → API“
	 * ------------------------------------------------------------------ */

	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'API-Zugang', 'reptilien-manager' ),
			__( 'API', 'reptilien-manager' ),
			'edit_posts',
			'rm-api',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Seite zur Verwaltung des eigenen API-Keys.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$user_id = get_current_user_id();
		$key     = self::key_for_user( $user_id );
		$created = get_user_meta( $user_id, self::META_CREATED, true );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Statusanzeige.
		$msg = isset( $_GET['rm_msg'] ) ? sanitize_key( $_GET['rm_msg'] ) : '';
		// phpcs:enable
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'API-Zugang', 'reptilien-manager' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: OpenAPI-Endpunkt-URL */
					esc_html__( 'Die REST-API des Reptilien Managers steht unter %s zur Verfügung. Die vollständige Dokumentation liefert der OpenAPI-Endpunkt.', 'reptilien-manager' ),
					'<code>' . esc_html( rest_url( RM_REST_Controller::NAMESPACE ) ) . '</code>'
				);
				?>
			</p>
			<p>
				<a href="<?php echo esc_url( rest_url( RM_REST_Controller::NAMESPACE . '/openapi.json' ) ); ?>" target="_blank" rel="noopener">
					<?php esc_html_e( 'OpenAPI-Dokumentation öffnen (openapi.json)', 'reptilien-manager' ); ?>
				</a>
			</p>

			<?php if ( 'revoked' === $msg ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'API-Key widerrufen.', 'reptilien-manager' ); ?></p></div>
			<?php elseif ( 'generated' === $msg ) : ?>
				<div class="notice notice-success"><p><?php esc_html_e( 'Neuer API-Key erzeugt. Bitte jetzt sicher speichern.', 'reptilien-manager' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Dein API-Key', 'reptilien-manager' ); ?></h2>
			<?php if ( $key ) : ?>
				<table class="form-table rm-form-table">
					<tr>
						<th><?php esc_html_e( 'Key', 'reptilien-manager' ); ?></th>
						<td><code><?php echo esc_html( $key ); ?></code></td>
					</tr>
					<?php if ( $created ) : ?>
						<tr>
							<th><?php esc_html_e( 'Erzeugt am', 'reptilien-manager' ); ?></th>
							<td><?php echo esc_html( $created ); ?></td>
						</tr>
					<?php endif; ?>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'Noch kein API-Key erzeugt.', 'reptilien-manager' ); ?></p>
			<?php endif; ?>

			<p>
				<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rm_api_generate_key' ), 'rm_api_generate_key' ) ); ?>">
					<?php echo $key ? esc_html__( 'Neuen Key erzeugen (ersetzt den bisherigen)', 'reptilien-manager' ) : esc_html__( 'Key erzeugen', 'reptilien-manager' ); ?>
				</a>
				<?php if ( $key ) : ?>
					<a
						class="button"
						href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rm_api_revoke_key' ), 'rm_api_revoke_key' ) ); ?>"
						onclick="return confirm('<?php echo esc_js( __( 'API-Key wirklich widerrufen?', 'reptilien-manager' ) ); ?>');"
					>
						<?php esc_html_e( 'Key widerrufen', 'reptilien-manager' ); ?>
					</a>
				<?php endif; ?>
			</p>

			<h2><?php esc_html_e( 'Beispiel', 'reptilien-manager' ); ?></h2>
			<pre>curl -H "X-Reptilien-API-Key: <?php echo esc_html( $key ? $key : '...' ); ?>" \
	"<?php echo esc_url( rest_url( RM_REST_Controller::NAMESPACE . '/animals' ) ); ?>"</pre>
		</div>
		<?php
	}

	/**
	 * Key-Generierung verarbeiten.
	 */
	public static function handle_generate_key() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rm_api_generate_key' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		self::generate_key_for_user( get_current_user_id() );

		wp_safe_redirect( add_query_arg( 'rm_msg', 'generated', admin_url( 'edit.php?post_type=rm_animal&page=rm-api' ) ) );
		exit;
	}

	/**
	 * Key-Widerruf verarbeiten.
	 */
	public static function handle_revoke_key() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rm_api_revoke_key' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		self::revoke_key_for_user( get_current_user_id() );

		wp_safe_redirect( add_query_arg( 'rm_msg', 'revoked', admin_url( 'edit.php?post_type=rm_animal&page=rm-api' ) ) );
		exit;
	}
}
