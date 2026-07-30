<?php
/**
 * Automatische Titel- und Text-Generierung für Tiere.
 *
 * Grundprinzip: niemals überschreiben, was jemand von Hand geändert hat.
 * Dazu merkt sich das Modul, was es zuletzt selbst erzeugt hat
 * (`_rm_autotitle_last` bzw. `_rm_autotext_hash`). Weicht der aktuelle
 * Titel/Text davon ab, wurde er manuell bearbeitet und bleibt unangetastet.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Autotext {

	/** Option: Automatik global aktiv (Standard für neue Tiere). */
	const OPTION_ENABLED = 'rm_autotext_enabled';

	/** Option: Titel-Muster. */
	const OPTION_PATTERN = 'rm_autotext_pattern';

	/** Option: Vorlage für den automatischen Text. */
	const OPTION_TEMPLATE = 'rm_autotext_template';

	/** Standard-Titelmuster. */
	const DEFAULT_PATTERN = '{name} – {morph}';

	public static function init() {
		add_action( 'add_meta_boxes_rm_animal', array( __CLASS__, 'add_meta_box' ) );
		// Priorität 20: läuft nach RM_Animal_Meta::save() (10), damit Genetik,
		// Geschlecht und Art bereits gespeichert sind.
		add_action( 'save_post_rm_animal', array( __CLASS__, 'maybe_generate' ), 20, 3 );
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_rm_save_autotext', array( __CLASS__, 'handle_save_settings' ) );
	}

	/* ---------------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------------ */

	/**
	 * Ist die Automatik global aktiv?
	 *
	 * @return bool
	 */
	public static function globally_enabled() {
		return (bool) get_option( self::OPTION_ENABLED, false );
	}

	/**
	 * Titel-Muster.
	 *
	 * @return string
	 */
	public static function pattern() {
		$pattern = (string) get_option( self::OPTION_PATTERN, '' );
		return '' !== trim( $pattern ) ? $pattern : self::DEFAULT_PATTERN;
	}

	/**
	 * Vorlage für den automatischen Text.
	 *
	 * @return string
	 */
	public static function template_key() {
		$key = (string) get_option( self::OPTION_TEMPLATE, 'steckbrief' );
		return array_key_exists( $key, RM_Templates::templates() ) ? $key : 'steckbrief';
	}

	/**
	 * Verfügbare Platzhalter mit Beschreibung.
	 *
	 * @return array<string,string>
	 */
	public static function placeholders() {
		return array(
			'{name}'       => __( 'Rufname des Tieres', 'reptilien-manager' ),
			'{morph}'      => __( 'Morph bzw. Genetik-Bezeichnung', 'reptilien-manager' ),
			'{sex}'        => __( 'Geschlecht (1.0 / 0.1 / unbekannt)', 'reptilien-manager' ),
			'{species}'    => __( 'Tierart', 'reptilien-manager' ),
			'{year}'       => __( 'Schlupfjahr', 'reptilien-manager' ),
			'{identifier}' => __( 'Kennzeichnung (Chip/Ring)', 'reptilien-manager' ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Titel-Erzeugung
	 * ------------------------------------------------------------------ */

	/**
	 * Kurzform des Geschlechts in der üblichen Zucht-Notation.
	 *
	 * @param string $sex_key Geschlecht.
	 * @return string
	 */
	private static function sex_short( $sex_key ) {
		if ( 'male' === $sex_key ) {
			return '1.0';
		}
		if ( 'female' === $sex_key ) {
			return '0.1';
		}
		return '0.0.1'; // Zuchtnotation für „Geschlecht unbekannt“.
	}

	/**
	 * Baut den Titel eines Tieres aus dem Muster.
	 *
	 * @param int         $animal_id Beitrags-ID.
	 * @param string|null $pattern   Muster; null = hinterlegtes Muster.
	 * @return string Leer, wenn kein Rufname hinterlegt ist.
	 */
	public static function build_title( $animal_id, $pattern = null ) {
		$name = (string) get_post_meta( $animal_id, '_rm_call_name', true );
		if ( '' === trim( $name ) ) {
			return '';
		}

		$pattern     = null === $pattern ? self::pattern() : (string) $pattern;
		$species_key = RM_Species::key_for_animal( $animal_id );
		$birth       = (string) get_post_meta( $animal_id, '_rm_birth', true );

		$values = array(
			'{name}'       => $name,
			'{morph}'      => RM_Genetics::animal_morph_label( $animal_id ),
			'{sex}'        => self::sex_short( (string) get_post_meta( $animal_id, '_rm_sex', true ) ),
			'{species}'    => RM_Species::label( $species_key ),
			'{year}'       => ( $birth && strtotime( $birth ) ) ? gmdate( 'Y', strtotime( $birth ) ) : '',
			'{identifier}' => (string) get_post_meta( $animal_id, '_rm_identifier', true ),
		);

		$title = strtr( $pattern, $values );

		return self::tidy( $title );
	}

	/**
	 * Räumt Reste leerer Platzhalter auf (doppelte Trenner, Klammern ohne
	 * Inhalt, überflüssige Leerzeichen).
	 *
	 * @param string $title Roh-Titel.
	 * @return string
	 */
	private static function tidy( $title ) {
		// Leere Klammern entfernen.
		$title = preg_replace( '/\(\s*\)/u', '', $title );
		// Mehrfache Leerzeichen zusammenfassen.
		$title = preg_replace( '/\s{2,}/u', ' ', $title );
		// Trennzeichen am Anfang/Ende sowie doppelte Trenner bereinigen.
		$title = preg_replace( '/\s*[–\-—,·|]\s*([–\-—,·|]\s*)+/u', ' – ', $title );
		$title = trim( $title );
		$title = trim( $title, " \t\n\r\0\x0B–-—,·|" );

		return trim( $title );
	}

	/* ---------------------------------------------------------------------
	 * Automatik beim Speichern
	 * ------------------------------------------------------------------ */

	/**
	 * Ist die Automatik für dieses Tier aktiv?
	 *
	 * @param int $animal_id Beitrags-ID.
	 * @return bool
	 */
	public static function enabled_for( $animal_id ) {
		$stored = get_post_meta( $animal_id, '_rm_autotext', true );
		if ( '' === $stored ) {
			return self::globally_enabled();
		}
		return '1' === (string) $stored;
	}

	/**
	 * Erzeugt Titel und/oder Text, sofern die Automatik aktiv ist und der
	 * bestehende Inhalt nicht von Hand bearbeitet wurde.
	 *
	 * @param int     $post_id Beitrags-ID.
	 * @param WP_Post $post    Beitrag.
	 * @param bool    $update  Ob es sich um eine Aktualisierung handelt.
	 */
	public static function maybe_generate( $post_id, $post, $update = true ) {
		static $running = false;

		if ( $running ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}

		// Rufname aus dem Formular übernehmen bzw. beim ersten Mal aus dem
		// bisherigen Titel ableiten, damit bestehende Tiere sofort greifen.
		self::sync_call_name( $post_id, $post );

		if ( ! self::enabled_for( $post_id ) ) {
			return;
		}

		$new_title   = self::build_title( $post_id );
		$last_title  = (string) get_post_meta( $post_id, '_rm_autotitle_last', true );
		$title_is_ours = ( '' === trim( $post->post_title ) ) || ( $post->post_title === $last_title );

		$new_content  = RM_Templates::render( self::template_key(), RM_Templates::collect_stored( $post_id ) );
		$last_hash    = (string) get_post_meta( $post_id, '_rm_autotext_hash', true );
		$content_is_ours = ( '' === trim( $post->post_content ) ) || ( md5( $post->post_content ) === $last_hash );

		$changes = array();

		if ( $new_title && $title_is_ours && $post->post_title !== $new_title ) {
			$changes['post_title'] = $new_title;
		}
		if ( $content_is_ours && $post->post_content !== $new_content ) {
			$changes['post_content'] = $new_content;
		}

		if ( ! $changes ) {
			return;
		}

		$running = true;
		wp_update_post( array_merge( array( 'ID' => $post_id ), $changes ) );
		$running = false;

		if ( isset( $changes['post_title'] ) ) {
			update_post_meta( $post_id, '_rm_autotitle_last', $changes['post_title'] );
		}
		if ( isset( $changes['post_content'] ) ) {
			update_post_meta( $post_id, '_rm_autotext_hash', md5( $changes['post_content'] ) );
		}
	}

	/**
	 * Hält den Rufnamen aktuell.
	 *
	 * @param int     $post_id Beitrags-ID.
	 * @param WP_Post $post    Beitrag.
	 */
	private static function sync_call_name( $post_id, $post ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird in RM_Animal_Meta::save() geprüft; hier wird nur ein bereits validiertes Formularfeld übernommen.
		if ( isset( $_POST['rm_call_name'] ) && isset( $_POST['rm_animal_meta_nonce'] ) ) {
			$name = sanitize_text_field( wp_unslash( $_POST['rm_call_name'] ) );
			if ( '' !== $name ) {
				update_post_meta( $post_id, '_rm_call_name', $name );
				return;
			}
		}
		// phpcs:enable

		// Noch kein Rufname hinterlegt: aus dem aktuellen Titel übernehmen,
		// aber nur, wenn dieser nicht selbst automatisch erzeugt wurde.
		if ( '' === (string) get_post_meta( $post_id, '_rm_call_name', true ) ) {
			$last = (string) get_post_meta( $post_id, '_rm_autotitle_last', true );
			if ( '' !== trim( $post->post_title ) && $post->post_title !== $last ) {
				update_post_meta( $post_id, '_rm_call_name', $post->post_title );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Meta-Box am Tier
	 * ------------------------------------------------------------------ */

	public static function add_meta_box() {
		add_meta_box(
			'rm-autotext',
			__( 'Titel & Text automatisch', 'reptilien-manager' ),
			array( __CLASS__, 'render_meta_box' ),
			'rm_animal',
			'side',
			'default'
		);
	}

	/**
	 * Meta-Box: Rufname und Automatik-Schalter.
	 *
	 * @param WP_Post $post Beitrag.
	 */
	public static function render_meta_box( $post ) {
		$call_name = (string) get_post_meta( $post->ID, '_rm_call_name', true );
		if ( '' === $call_name ) {
			$call_name = $post->post_title;
		}

		$stored  = get_post_meta( $post->ID, '_rm_autotext', true );
		$enabled = '' === $stored ? self::globally_enabled() : ( '1' === (string) $stored );
		$preview = self::build_title( $post->ID );
		?>
		<p>
			<label for="rm_call_name"><strong><?php esc_html_e( 'Rufname', 'reptilien-manager' ); ?></strong></label>
			<input type="text" name="rm_call_name" id="rm_call_name" value="<?php echo esc_attr( $call_name ); ?>" class="widefat" />
		</p>
		<p>
			<label>
				<input type="checkbox" name="rm_autotext" value="1" <?php checked( $enabled ); ?> />
				<?php esc_html_e( 'Titel und Beitragstext automatisch pflegen', 'reptilien-manager' ); ?>
			</label>
		</p>
		<?php if ( $preview ) : ?>
			<p class="description">
				<?php esc_html_e( 'Titel-Vorschau:', 'reptilien-manager' ); ?><br />
				<code><?php echo esc_html( $preview ); ?></code>
			</p>
		<?php endif; ?>
		<p class="description">
			<?php esc_html_e( 'Von Hand geänderte Titel und Texte werden nie überschrieben – die Automatik greift nur, solange der Inhalt unverändert von ihr stammt.', 'reptilien-manager' ); ?>
			<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=rm_animal&page=rm-autotext' ) ); ?>"><?php esc_html_e( 'Muster & Vorlage ändern', 'reptilien-manager' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Speichert den Automatik-Schalter. Wird aus RM_Animal_Meta::save()
	 * heraus mitgespeichert, damit nur eine Nonce nötig ist.
	 *
	 * @param int $post_id Beitrags-ID.
	 */
	public static function save_toggle( $post_id ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce wird in RM_Animal_Meta::save() geprüft.
		update_post_meta( $post_id, '_rm_autotext', isset( $_POST['rm_autotext'] ) ? '1' : '0' );
	}

	/* ---------------------------------------------------------------------
	 * Einstellungsseite
	 * ------------------------------------------------------------------ */

	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Titel & Text', 'reptilien-manager' ),
			__( 'Titel & Text', 'reptilien-manager' ),
			'edit_posts',
			'rm-autotext',
			array( __CLASS__, 'render_page' )
		);
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Statusanzeige.
		$msg = isset( $_GET['rm_msg'] ) ? sanitize_key( $_GET['rm_msg'] ) : '';
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Titel & Text automatisch', 'reptilien-manager' ); ?></h1>
			<p><?php esc_html_e( 'Erzeugt Beitragstitel und -text automatisch aus den Tierdaten. Sobald du einen Titel oder Text von Hand änderst, lässt die Automatik ihn in Ruhe.', 'reptilien-manager' ); ?></p>

			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Einstellungen gespeichert.', 'reptilien-manager' ); ?></p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rm_save_autotext" />
				<?php wp_nonce_field( 'rm_save_autotext', 'rm_autotext_nonce' ); ?>

				<table class="form-table rm-form-table">
					<tr>
						<th><?php esc_html_e( 'Standard', 'reptilien-manager' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="rm_autotext_enabled" value="1" <?php checked( self::globally_enabled() ); ?> />
								<?php esc_html_e( 'Automatik für neue Tiere standardmäßig aktivieren', 'reptilien-manager' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Pro Tier lässt sich die Automatik in der Seitenleiste einzeln an- und abschalten.', 'reptilien-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rm_autotext_pattern"><?php esc_html_e( 'Titel-Muster', 'reptilien-manager' ); ?></label></th>
						<td>
							<input type="text" name="rm_autotext_pattern" id="rm_autotext_pattern" class="regular-text" value="<?php echo esc_attr( self::pattern() ); ?>" />
							<p class="description"><?php esc_html_e( 'Verfügbare Platzhalter:', 'reptilien-manager' ); ?></p>
							<ul class="rm-placeholder-list">
								<?php foreach ( self::placeholders() as $token => $desc ) : ?>
									<li><code><?php echo esc_html( $token ); ?></code> – <?php echo esc_html( $desc ); ?></li>
								<?php endforeach; ?>
							</ul>
							<p class="description"><?php esc_html_e( 'Leere Platzhalter werden samt überflüssiger Trennzeichen automatisch entfernt.', 'reptilien-manager' ); ?></p>
						</td>
					</tr>
					<tr>
						<th><label for="rm_autotext_template"><?php esc_html_e( 'Vorlage für den Text', 'reptilien-manager' ); ?></label></th>
						<td>
							<select name="rm_autotext_template" id="rm_autotext_template">
								<?php foreach ( RM_Templates::templates() as $key => $template ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( self::template_key(), $key ); ?>><?php echo esc_html( $template['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Einstellungen speichern', 'reptilien-manager' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Einstellungen speichern.
	 */
	public static function handle_save_settings() {
		if ( ! isset( $_POST['rm_autotext_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_autotext_nonce'] ), 'rm_save_autotext' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		update_option( self::OPTION_ENABLED, isset( $_POST['rm_autotext_enabled'] ) ? 1 : 0 );

		$pattern = isset( $_POST['rm_autotext_pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_autotext_pattern'] ) ) : '';
		update_option( self::OPTION_PATTERN, '' !== trim( $pattern ) ? $pattern : self::DEFAULT_PATTERN );

		$template = isset( $_POST['rm_autotext_template'] ) ? sanitize_key( $_POST['rm_autotext_template'] ) : 'steckbrief';
		update_option( self::OPTION_TEMPLATE, array_key_exists( $template, RM_Templates::templates() ) ? $template : 'steckbrief' );

		wp_safe_redirect( add_query_arg( 'rm_msg', 'saved', admin_url( 'edit.php?post_type=rm_animal&page=rm-autotext' ) ) );
		exit;
	}
}
