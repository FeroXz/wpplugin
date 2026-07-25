<?php
/**
 * Generiert die OpenAPI-3.0-Dokumentation der Reptilien-Manager-REST-API
 * unter GET /wp-json/reptilien/v1/openapi.json.
 *
 * Jeder REST-Controller liefert sein eigenes Pfad-Fragment über eine
 * statische openapi_paths()-Methode direkt neben seiner register_routes()
 * (siehe RM_REST_Animals, RM_REST_Pairings, RM_REST_Genetics,
 * RM_REST_Feedings) – dieser Generator aggregiert die Fragmente nur noch zu
 * einem vollständigen Dokument. Damit bleiben Endpunkt und Dokumentation im
 * selben Controller beisammen, ohne auf eine fragile Introspektion der
 * internen WP_REST_Server-Routentabelle angewiesen zu sein.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_OpenAPI_Generator {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			RM_REST_Controller::NAMESPACE,
			'/openapi.json',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'serve' ),
				'permission_callback' => '__return_true', // Dokumentation ist bewusst ohne API-Key erreichbar.
			)
		);
	}

	/**
	 * Liefert das vollständige OpenAPI-3.0-Dokument.
	 *
	 * @param WP_REST_Request $request Anfrage.
	 * @return WP_REST_Response
	 */
	public static function serve( $request ) {
		return new WP_REST_Response( self::generate(), 200 );
	}

	/**
	 * REST-Controller, deren openapi_paths() aggregiert werden.
	 *
	 * @return string[]
	 */
	private static function controller_classes() {
		/**
		 * Liste der Klassen, deren openapi_paths()-Fragmente in die
		 * OpenAPI-Dokumentation der Reptilien-Manager-API einfließen.
		 *
		 * @param string[] $classes Klassennamen.
		 */
		return apply_filters(
			'rm_api_openapi_controllers',
			array(
				'RM_REST_Animals',
				'RM_REST_Pairings',
				'RM_REST_Genetics',
				'RM_REST_Feedings',
			)
		);
	}

	/**
	 * Baut das vollständige OpenAPI-3.0.0-Dokument.
	 *
	 * @return array
	 */
	public static function generate() {
		$paths = array();

		foreach ( self::controller_classes() as $class ) {
			if ( ! class_exists( $class ) || ! method_exists( $class, 'openapi_paths' ) ) {
				continue;
			}

			foreach ( (array) call_user_func( array( $class, 'openapi_paths' ) ) as $path => $operations ) {
				$full_path = '/' . rest_get_url_prefix() . '/' . RM_REST_Controller::NAMESPACE . $path;
				$paths[ $full_path ] = $operations;
			}
		}

		ksort( $paths );

		return array(
			'openapi'    => '3.0.0',
			'info'       => array(
				'title'       => __( 'Reptilien Manager API', 'reptilien-manager' ),
				'version'     => defined( 'RM_VERSION' ) ? RM_VERSION : '1.0',
				'description' => __( 'REST-API für das Reptilien-Manager-Plugin: Tiere, Verpaarungen, Genetik-Rechner und Fütterungen.', 'reptilien-manager' ),
			),
			'servers'    => array(
				array( 'url' => home_url() ),
			),
			'components' => array(
				'securitySchemes' => array(
					'ApiKeyAuth' => array(
						'type' => 'apiKey',
						'in'   => 'header',
						'name' => 'X-Reptilien-API-Key',
					),
				),
			),
			'security'   => array(
				array( 'ApiKeyAuth' => array() ),
			),
			'paths'      => $paths,
		);
	}
}
