<?php
/**
 * Frontend-Verwaltung: eingeloggte Nutzer können Tiere anlegen und
 * bearbeiten (inkl. artspezifischer Genetik), Fütterungen eintragen und
 * den Genetik-Rechner nutzen – direkt auf einer beliebigen Seite.
 *
 * Shortcode [reptilien-verwaltung].
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Frontend {

	/** Verfügbare Reiter der Verwaltungsoberfläche. */
	const TABS = array( 'tiere', 'fuetterung', 'genetik' );

	public static function init() {
		add_shortcode( 'reptilien-verwaltung', array( __CLASS__, 'render_manager' ) );
		add_action( 'admin_post_rm_fe_save_animal', array( __CLASS__, 'handle_save' ) );
		add_action( 'admin_post_rm_fe_save_feeding', array( __CLASS__, 'handle_save_feeding' ) );
		add_action( 'wp_ajax_rm_fe_genes', array( __CLASS__, 'ajax_genes' ) );
	}

	/**
	 * Mindest-Fähigkeit, um im Frontend Tiere verwalten zu dürfen.
	 *
	 * @return string
	 */
	private static function required_cap() {
		/**
		 * Fähigkeit, die für die Frontend-Verwaltung nötig ist.
		 *
		 * @param string $cap Standard 'edit_posts'.
		 */
		return apply_filters( 'rm_frontend_manage_cap', 'edit_posts' );
	}

	/**
	 * Darf der aktuelle Nutzer die Frontend-Verwaltung überhaupt öffnen?
	 *
	 * @return bool
	 */
	private static function user_may_manage() {
		return is_user_logged_in() && current_user_can( self::required_cap() );
	}

	/**
	 * Kann der aktuelle Nutzer ein bestimmtes Tier bearbeiten?
	 *
	 * @param int $animal_id Beitrags-ID (0 = neues Tier).
	 * @return bool
	 */
	private static function can_manage( $animal_id = 0 ) {
		if ( ! self::user_may_manage() ) {
			return false;
		}
		if ( $animal_id ) {
			$post = get_post( $animal_id );
			if ( ! $post || 'rm_animal' !== $post->post_type ) {
				return false;
			}
			// Eigene Tiere immer; fremde nur mit edit_others_posts.
			if ( (int) $post->post_author !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Tiere, die der aktuelle Nutzer verwalten darf.
	 *
	 * @return WP_Post[]
	 */
	private static function manageable_animals() {
		$args = array(
			'post_type'      => 'rm_animal',
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			$args['author'] = get_current_user_id();
		}
		return get_posts( $args );
	}

	/* ---------------------------------------------------------------------
	 * Shortcode
	 * ------------------------------------------------------------------ */

	/**
	 * [reptilien-verwaltung] – Verwaltungsoberfläche für eingeloggte Nutzer.
	 *
	 * Attribute:
	 *   tabs="tiere,fuetterung,genetik" – welche Bereiche angezeigt werden.
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string
	 */
	public static function render_manager( $atts = array() ) {
		wp_enqueue_style( 'rm-frontend' );

		$atts = shortcode_atts( array( 'tabs' => implode( ',', self::TABS ) ), $atts, 'reptilien-verwaltung' );

		$tabs = array_values(
			array_intersect(
				array_map( 'sanitize_key', array_map( 'trim', explode( ',', $atts['tabs'] ) ) ),
				self::TABS
			)
		);
		if ( ! $tabs ) {
			$tabs = self::TABS;
		}

		if ( ! is_user_logged_in() ) {
			return '<div class="rm-notice">' . sprintf(
				/* translators: %s: Login-Link */
				wp_kses_post( __( 'Bitte <a href="%s">einloggen</a>, um Tiere zu verwalten.', 'reptilien-manager' ) ),
				esc_url( wp_login_url( self::current_url() ) )
			) . '</div>';
		}

		if ( ! current_user_can( self::required_cap() ) ) {
			return '<div class="rm-notice">' . esc_html__( 'Dein Benutzerkonto hat keine Berechtigung, Tiere zu verwalten.', 'reptilien-manager' ) . '</div>';
		}

		self::enqueue_assets();

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Navigation/Statusanzeige.
		$edit_id = isset( $_GET['rm_edit'] ) ? absint( $_GET['rm_edit'] ) : 0;
		$action  = isset( $_GET['rm_action'] ) ? sanitize_key( $_GET['rm_action'] ) : '';
		$msg     = isset( $_GET['rm_msg'] ) ? sanitize_key( $_GET['rm_msg'] ) : '';
		$tab     = isset( $_GET['rm_tab'] ) ? sanitize_key( $_GET['rm_tab'] ) : '';
		// phpcs:enable

		if ( ! in_array( $tab, $tabs, true ) ) {
			$tab = $tabs[0];
		}

		ob_start();
		echo '<div class="rm-manage">';

		self::render_tabs( $tabs, $tab );
		self::render_notice( $msg );

		if ( 'fuetterung' === $tab ) {
			self::render_feeding_form();
		} elseif ( 'genetik' === $tab ) {
			echo RM_Shortcodes::genetics_calculator(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits escapte Ausgabe.
		} elseif ( 'new' === $action || ( $edit_id && self::can_manage( $edit_id ) ) ) {
			self::render_form( $edit_id );
		} else {
			self::render_list();
		}

		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Reiter-Navigation.
	 *
	 * @param string[] $tabs    Anzuzeigende Reiter.
	 * @param string   $current Aktiver Reiter.
	 */
	private static function render_tabs( $tabs, $current ) {
		if ( count( $tabs ) < 2 ) {
			return;
		}

		$labels = array(
			'tiere'      => __( 'Tiere', 'reptilien-manager' ),
			'fuetterung' => __( 'Fütterung', 'reptilien-manager' ),
			'genetik'    => __( 'Genetik-Rechner', 'reptilien-manager' ),
		);

		echo '<nav class="rm-fe-tabs">';
		foreach ( $tabs as $tab ) {
			$url = add_query_arg( 'rm_tab', $tab, remove_query_arg( array( 'rm_action', 'rm_edit', 'rm_msg' ), self::current_url() ) );
			printf(
				'<a class="rm-fe-tab%s" href="%s">%s</a>',
				$tab === $current ? ' rm-fe-tab--active' : '',
				esc_url( $url ),
				esc_html( $labels[ $tab ] )
			);
		}
		echo '</nav>';
	}

	/**
	 * Statusmeldung nach dem Speichern.
	 *
	 * @param string $msg Nachrichtenschlüssel.
	 */
	private static function render_notice( $msg ) {
		$map = array(
			'saved'   => array( 'ok', __( 'Tier gespeichert.', 'reptilien-manager' ) ),
			'created' => array( 'ok', __( 'Tier angelegt.', 'reptilien-manager' ) ),
			'fed'     => array( 'ok', __( 'Fütterung eingetragen.', 'reptilien-manager' ) ),
			'denied'  => array( 'err', __( 'Keine Berechtigung.', 'reptilien-manager' ) ),
			'missing' => array( 'err', __( 'Bitte mindestens ein Tier und eine Futterart wählen.', 'reptilien-manager' ) ),
			'range_error' => array( 'err', __( 'Der gewählte Zeitraum ist ungültig (max. 90 Tage, und die gewählten Wochentage müssen darin vorkommen).', 'reptilien-manager' ) ),
			'error'   => array( 'err', __( 'Speichern fehlgeschlagen.', 'reptilien-manager' ) ),
		);
		if ( isset( $map[ $msg ] ) ) {
			printf(
				'<div class="rm-notice rm-notice--%s">%s</div>',
				'ok' === $map[ $msg ][0] ? 'success' : 'error',
				esc_html( $map[ $msg ][1] )
			);
		}
	}

	/**
	 * Liste der eigenen Tiere mit Aktionen.
	 */
	private static function render_list() {
		$animals = self::manageable_animals();

		$base    = self::current_url();
		$new_url = add_query_arg( 'rm_action', 'new', $base );
		?>
		<div class="rm-manage__head">
			<h2><?php esc_html_e( 'Meine Tiere', 'reptilien-manager' ); ?></h2>
			<a class="rm-filter-submit" href="<?php echo esc_url( $new_url ); ?>"><?php esc_html_e( '+ Neues Tier', 'reptilien-manager' ); ?></a>
		</div>

		<?php if ( ! $animals ) : ?>
			<p class="rm-notice"><?php esc_html_e( 'Noch keine Tiere angelegt. Lege dein erstes Tier an.', 'reptilien-manager' ); ?></p>
		<?php else : ?>
			<div class="rm-manage__scroll">
			<table class="rm-manage__table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'reptilien-manager' ); ?></th>
						<th><?php esc_html_e( 'Art', 'reptilien-manager' ); ?></th>
						<th><?php esc_html_e( 'Geschlecht', 'reptilien-manager' ); ?></th>
						<th><?php esc_html_e( 'Morph', 'reptilien-manager' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php
					$sexes = RM_Animal_Meta::sexes();
					foreach ( $animals as $animal ) :
						$sex      = get_post_meta( $animal->ID, '_rm_sex', true );
						$edit_url = add_query_arg( 'rm_edit', $animal->ID, $base );
						?>
						<tr>
							<td><strong><?php echo esc_html( $animal->post_title ); ?></strong></td>
							<td><?php echo esc_html( RM_Species::label( RM_Species::key_for_animal( $animal->ID ) ) ); ?></td>
							<td><?php echo esc_html( isset( $sexes[ $sex ] ) ? $sexes[ $sex ] : $sexes['unknown'] ); ?></td>
							<td><?php echo esc_html( RM_Genetics::animal_morph_label( $animal->ID ) ); ?></td>
							<td><a class="rm-page-link" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Bearbeiten', 'reptilien-manager' ); ?></a></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Formular zum Anlegen/Bearbeiten eines Tieres.
	 *
	 * @param int $animal_id Beitrags-ID (0 = neu).
	 */
	private static function render_form( $animal_id ) {
		$is_edit = (bool) $animal_id;
		$title   = $is_edit ? get_the_title( $animal_id ) : '';
		$sex     = $is_edit ? get_post_meta( $animal_id, '_rm_sex', true ) : '';
		$birth   = $is_edit ? get_post_meta( $animal_id, '_rm_birth', true ) : '';
		$origin  = $is_edit ? get_post_meta( $animal_id, '_rm_origin', true ) : '';
		$length  = $is_edit ? get_post_meta( $animal_id, '_rm_length', true ) : '';
		$public  = $is_edit ? ( '0' !== (string) get_post_meta( $animal_id, '_rm_public', true ) ) : true;

		$terms        = RM_Species::ensure_terms();
		$current_key  = $is_edit ? RM_Species::key_for_animal( $animal_id ) : RM_Species::DEFAULT_KEY;
		$current_term = 0;
		if ( $is_edit ) {
			$ct           = wp_get_post_terms( $animal_id, 'rm_species', array( 'fields' => 'ids' ) );
			$current_term = ( is_array( $ct ) && $ct ) ? (int) $ct[0] : 0;
		}

		$sexes = RM_Animal_Meta::sexes();
		$base  = self::current_url();
		?>
		<div class="rm-manage__head">
			<h2><?php echo $is_edit ? esc_html__( 'Tier bearbeiten', 'reptilien-manager' ) : esc_html__( 'Neues Tier', 'reptilien-manager' ); ?></h2>
			<a class="rm-page-link" href="<?php echo esc_url( $base ); ?>"><?php esc_html_e( '← Zurück zur Liste', 'reptilien-manager' ); ?></a>
		</div>

		<form class="rm-fe-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
			<input type="hidden" name="action" value="rm_fe_save_animal" />
			<input type="hidden" name="rm_animal_id" value="<?php echo esc_attr( $animal_id ); ?>" />
			<input type="hidden" name="rm_redirect" value="<?php echo esc_url( $base ); ?>" />
			<input type="hidden" id="rm_fe_genes_nonce" value="<?php echo esc_attr( wp_create_nonce( 'rm_fe_genes' ) ); ?>" />
			<input type="hidden" id="rm_fe_ajaxurl" value="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" />
			<?php wp_nonce_field( 'rm_fe_save_animal', 'rm_fe_nonce' ); ?>

			<div class="rm-fe-grid">
				<label class="rm-fe-field rm-fe-field--full">
					<span><?php esc_html_e( 'Name', 'reptilien-manager' ); ?> *</span>
					<input type="text" name="rm_name" required value="<?php echo esc_attr( $title ); ?>" />
				</label>

				<label class="rm-fe-field">
					<span><?php esc_html_e( 'Tierart', 'reptilien-manager' ); ?></span>
					<select name="rm_species" id="rm-fe-species">
						<?php foreach ( $terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $current_term, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="rm-fe-field">
					<span><?php esc_html_e( 'Geschlecht', 'reptilien-manager' ); ?></span>
					<select name="rm_sex">
						<?php foreach ( $sexes as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sex, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>

				<label class="rm-fe-field">
					<span><?php esc_html_e( 'Schlupfdatum', 'reptilien-manager' ); ?></span>
					<input type="date" name="rm_birth" value="<?php echo esc_attr( $birth ); ?>" />
				</label>

				<label class="rm-fe-field">
					<span><?php esc_html_e( 'Gesamtlänge (cm)', 'reptilien-manager' ); ?></span>
					<input type="number" step="0.1" min="0" name="rm_length" value="<?php echo esc_attr( $length ); ?>" />
				</label>

				<?php if ( ! $is_edit ) : ?>
					<label class="rm-fe-field">
						<span><?php esc_html_e( 'Aktuelles Gewicht (g)', 'reptilien-manager' ); ?></span>
						<input type="number" step="1" min="0" name="rm_weight" value="" />
					</label>
				<?php endif; ?>

				<label class="rm-fe-field rm-fe-field--full">
					<span><?php esc_html_e( 'Herkunft / Züchter', 'reptilien-manager' ); ?></span>
					<input type="text" name="rm_origin" value="<?php echo esc_attr( $origin ); ?>" />
				</label>

				<?php if ( current_user_can( 'upload_files' ) ) : ?>
					<label class="rm-fe-field rm-fe-field--full">
						<span><?php esc_html_e( 'Foto (optional)', 'reptilien-manager' ); ?></span>
						<input type="file" name="rm_photo" accept="image/*" />
					</label>
				<?php endif; ?>

				<label class="rm-fe-field rm-fe-field--full rm-fe-check">
					<input type="checkbox" name="rm_public" value="1" <?php checked( $public ); ?> />
					<span><?php esc_html_e( 'Öffentlich im Frontend anzeigen', 'reptilien-manager' ); ?></span>
				</label>
			</div>

			<h3><?php esc_html_e( 'Genetik', 'reptilien-manager' ); ?></h3>
			<div id="rm-fe-genes">
				<?php self::render_gene_fields( $animal_id, $current_key ); ?>
			</div>

			<p>
				<button type="submit" class="rm-filter-submit"><?php echo $is_edit ? esc_html__( 'Änderungen speichern', 'reptilien-manager' ) : esc_html__( 'Tier anlegen', 'reptilien-manager' ); ?></button>
			</p>
		</form>
		<?php
	}

	/**
	 * Genetik-Auswahlfelder (frontend) für eine Art.
	 *
	 * @param int    $animal_id Beitrags-ID (0 = neu).
	 * @param string $species   Art-Schlüssel.
	 */
	public static function render_gene_fields( $animal_id, $species ) {
		$states = $animal_id ? RM_Genetics::get_animal_genes_for_species( $animal_id, $species ) : array();
		?>
		<div class="rm-fe-grid">
			<?php foreach ( RM_Genetics::genes( $species ) as $key => $gene ) : ?>
				<label class="rm-fe-field">
					<span><?php echo esc_html( $gene['label'] ); ?></span>
					<select name="rm_genes[<?php echo esc_attr( $key ); ?>]">
						<?php
						$current = isset( $states[ $key ] ) ? $states[ $key ] : '';
						foreach ( RM_Genetics::states_for_gene( $gene ) as $value => $label ) :
							?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $current, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endforeach; ?>
		</div>
		<p class="rm-fe-morph-note"><?php esc_html_e( 'Der Morph wird beim Speichern automatisch aus den Genanlagen bestimmt.', 'reptilien-manager' ); ?></p>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Fütterung
	 * ------------------------------------------------------------------ */

	/**
	 * Formular für den Fütterungs-Schnelleintrag im Frontend.
	 */
	private static function render_feeding_form() {
		$animals = self::manageable_animals();
		$base    = self::current_url();
		?>
		<div class="rm-manage__head">
			<h2><?php esc_html_e( 'Fütterung eintragen', 'reptilien-manager' ); ?></h2>
		</div>

		<?php if ( ! $animals ) : ?>
			<p class="rm-notice"><?php esc_html_e( 'Lege zuerst ein Tier an, um Fütterungen zu protokollieren.', 'reptilien-manager' ); ?></p>
			<?php return; ?>
		<?php endif; ?>

		<form class="rm-fe-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rm_fe_save_feeding" />
			<input type="hidden" name="rm_redirect" value="<?php echo esc_url( add_query_arg( 'rm_tab', 'fuetterung', $base ) ); ?>" />
			<?php wp_nonce_field( 'rm_fe_save_feeding', 'rm_fe_feed_nonce' ); ?>

			<h3><?php esc_html_e( 'Tiere', 'reptilien-manager' ); ?></h3>
			<div class="rm-choice-group">
				<label class="rm-choice rm-choice--all">
					<input type="checkbox" class="rm-check-all" />
					<strong><?php esc_html_e( 'Alle Tiere', 'reptilien-manager' ); ?></strong>
				</label>
				<div class="rm-choice-grid">
					<?php foreach ( $animals as $animal ) : ?>
						<label class="rm-choice">
							<input type="checkbox" class="rm-choice-cb" name="rm_feed_animals[]" value="<?php echo esc_attr( $animal->ID ); ?>" />
							<?php echo esc_html( $animal->post_title ); ?>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<h3><?php esc_html_e( 'Futterarten', 'reptilien-manager' ); ?></h3>
			<?php RM_Feeding::render_food_choices(); ?>

			<h3><?php esc_html_e( 'Supplemente', 'reptilien-manager' ); ?></h3>
			<?php RM_Feeding::render_supplement_choices(); ?>

			<h3><?php esc_html_e( 'Zeitraum', 'reptilien-manager' ); ?></h3>
			<div class="rm-fe-mode">
				<label>
					<input type="radio" name="rm_feed_mode" value="single" checked="checked" />
					<span><?php esc_html_e( 'Einzelner Tag', 'reptilien-manager' ); ?></span>
				</label>
				<label>
					<input type="radio" name="rm_feed_mode" value="range" />
					<span><?php esc_html_e( 'Mehrere Tage / Woche', 'reptilien-manager' ); ?></span>
				</label>
			</div>

			<div class="rm-fe-grid" data-feed-mode="single">
				<label class="rm-fe-field">
					<span><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?></span>
					<input type="date" name="rm_feed_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
				</label>
			</div>

			<div class="rm-fe-grid" data-feed-mode="range" hidden>
				<label class="rm-fe-field">
					<span><?php esc_html_e( 'Von', 'reptilien-manager' ); ?></span>
					<input type="date" name="rm_feed_from" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
				</label>
				<label class="rm-fe-field">
					<span><?php esc_html_e( 'Bis', 'reptilien-manager' ); ?></span>
					<input type="date" name="rm_feed_to" value="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) ) + 6 * DAY_IN_SECONDS ) ); ?>" />
				</label>
				<div class="rm-fe-field rm-fe-field--full">
					<span><?php esc_html_e( 'Nur an diesen Wochentagen', 'reptilien-manager' ); ?></span>
					<div class="rm-fe-weekdays">
						<?php foreach ( RM_Feeding::weekdays() as $num => $label ) : ?>
							<label class="rm-fe-weekday">
								<input type="checkbox" name="rm_feed_weekdays[]" value="<?php echo esc_attr( $num ); ?>" />
								<?php echo esc_html( $label ); ?>
							</label>
						<?php endforeach; ?>
					</div>
					<p class="rm-fe-morph-note"><?php esc_html_e( 'Nichts angehakt = jeder Tag im Zeitraum. Es entsteht ein Eintrag pro Tag.', 'reptilien-manager' ); ?></p>
				</div>
			</div>

			<div class="rm-fe-grid">
				<label class="rm-fe-field">
					<span><?php esc_html_e( 'Menge', 'reptilien-manager' ); ?></span>
					<input type="text" name="rm_feed_amount" placeholder="<?php esc_attr_e( 'z. B. 10 Heimchen', 'reptilien-manager' ); ?>" />
				</label>
				<label class="rm-fe-field rm-fe-field--full">
					<span><?php esc_html_e( 'Notizen', 'reptilien-manager' ); ?></span>
					<textarea name="rm_feed_notes" rows="3"></textarea>
				</label>
			</div>

			<p>
				<button type="submit" class="rm-filter-submit"><?php esc_html_e( 'Fütterung speichern', 'reptilien-manager' ); ?></button>
			</p>
		</form>

		<h3><?php esc_html_e( 'Letzte Fütterungen', 'reptilien-manager' ); ?></h3>
		<div class="rm-manage__scroll">
		<table class="rm-manage__table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Letzte Fütterung', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Bewertung', 'reptilien-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $animals as $animal ) :
					$last = RM_Feeding::last_feeding( $animal->ID );
					?>
					<tr>
						<td><strong><?php echo esc_html( $animal->post_title ); ?></strong></td>
						<td>
							<?php
							echo esc_html(
								( $last && ! empty( $last['date'] ) && strtotime( $last['date'] ) )
									? date_i18n( get_option( 'date_format' ), strtotime( $last['date'] ) )
									: __( 'noch keine', 'reptilien-manager' )
							);
							?>
						</td>
						<td><?php echo esc_html( self::feeding_summary( $animal->ID ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>
		<?php
	}

	/**
	 * Kurzbewertung der letzten Fütterungen eines Tieres.
	 *
	 * @param int $animal_id Beitrags-ID.
	 * @return string
	 */
	private static function feeding_summary( $animal_id ) {
		$analysis = RM_Feeding::analyze_animal( $animal_id );

		if ( empty( $analysis['has_targets'] ) ) {
			return __( 'Schlupfdatum fehlt', 'reptilien-manager' );
		}
		if ( empty( $analysis['has_data'] ) ) {
			return __( 'keine Daten', 'reptilien-manager' );
		}

		$off = array();
		foreach ( $analysis['categories'] as $cat ) {
			if ( isset( $cat['status'] ) && 'ok' !== $cat['status'] ) {
				$off[] = sprintf(
					/* translators: 1: Futterkategorie, 2: zu wenig/zu viel */
					__( '%1$s %2$s', 'reptilien-manager' ),
					$cat['label'],
					'low' === $cat['status'] ? __( 'zu wenig', 'reptilien-manager' ) : __( 'zu viel', 'reptilien-manager' )
				);
			}
		}

		return $off ? implode( ', ', $off ) : __( 'im Optimum', 'reptilien-manager' );
	}

	/**
	 * Fütterungs-Eintrag aus dem Frontend speichern.
	 */
	public static function handle_save_feeding() {
		$redirect = self::sanitize_redirect( isset( $_POST['rm_redirect'] ) ? wp_unslash( $_POST['rm_redirect'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- in sanitize_redirect() behandelt.

		if ( ! isset( $_POST['rm_fe_feed_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_fe_feed_nonce'] ), 'rm_fe_save_feeding' ) ) {
			self::redirect( $redirect, 'denied' );
		}
		if ( ! self::user_may_manage() ) {
			self::redirect( $redirect, 'denied' );
		}

		$raw_animals = isset( $_POST['rm_feed_animals'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rm_feed_animals'] ) ) : array();
		$animals     = array();
		foreach ( $raw_animals as $candidate ) {
			if ( $candidate && self::can_manage( $candidate ) ) {
				$animals[] = $candidate;
			}
		}

		$foods  = isset( $_POST['rm_feed_foods'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rm_feed_foods'] ) ) : array();
		$supps  = isset( $_POST['rm_feed_supplements'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rm_feed_supplements'] ) ) : array();
		$date   = isset( $_POST['rm_feed_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_date'] ) ) : '';
		$amount = isset( $_POST['rm_feed_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_amount'] ) ) : '';
		$notes  = isset( $_POST['rm_feed_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_feed_notes'] ) ) : '';

		if ( ! $animals || ! $foods ) {
			self::redirect( $redirect, 'missing' );
		}

		// Zeitraum-Modus: einen Eintrag je Tag anlegen (siehe
		// RM_Feeding::expand_dates() für die Wochentags-Logik).
		$mode = isset( $_POST['rm_feed_mode'] ) ? sanitize_key( $_POST['rm_feed_mode'] ) : 'single';

		if ( 'range' === $mode ) {
			$from     = isset( $_POST['rm_feed_from'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_from'] ) ) : '';
			$to       = isset( $_POST['rm_feed_to'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_to'] ) ) : '';
			$weekdays = isset( $_POST['rm_feed_weekdays'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['rm_feed_weekdays'] ) ) : array();

			$dates = RM_Feeding::expand_dates( $from, $to, $weekdays );
			if ( is_wp_error( $dates ) ) {
				self::redirect( $redirect, 'range_error' );
			}
		} else {
			$dates = array( $date ? $date : current_time( 'Y-m-d' ) );
		}

		$result = RM_Feeding::create_logs( $animals, $dates, $foods, $amount, $supps, $notes );

		self::redirect( $redirect, is_wp_error( $result ) ? 'error' : 'fed' );
	}

	/* ---------------------------------------------------------------------
	 * AJAX: Genetik-Felder bei Artwechsel
	 * ------------------------------------------------------------------ */

	public static function ajax_genes() {
		check_ajax_referer( 'rm_fe_genes', 'nonce' );

		if ( ! self::user_may_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'reptilien-manager' ) ), 403 );
		}

		$animal_id = isset( $_POST['animal_id'] ) ? absint( $_POST['animal_id'] ) : 0;
		if ( $animal_id && ! self::can_manage( $animal_id ) ) {
			$animal_id = 0;
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$species = RM_Species::DEFAULT_KEY;
		if ( $term_id ) {
			$term = get_term( $term_id, 'rm_species' );
			if ( $term && ! is_wp_error( $term ) ) {
				$species = RM_Species::key_from_term_names( array( $term->name ) );
			}
		}

		ob_start();
		self::render_gene_fields( $animal_id, $species );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	/* ---------------------------------------------------------------------
	 * Speichern
	 * ------------------------------------------------------------------ */

	public static function handle_save() {
		$redirect = self::sanitize_redirect( isset( $_POST['rm_redirect'] ) ? wp_unslash( $_POST['rm_redirect'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- in sanitize_redirect() behandelt.

		if ( ! isset( $_POST['rm_fe_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_fe_nonce'] ), 'rm_fe_save_animal' ) ) {
			self::redirect( $redirect, 'denied' );
		}

		$animal_id = isset( $_POST['rm_animal_id'] ) ? absint( $_POST['rm_animal_id'] ) : 0;

		if ( ! self::can_manage( $animal_id ) ) {
			self::redirect( $redirect, 'denied' );
		}

		$name = isset( $_POST['rm_name'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_name'] ) ) : '';
		if ( '' === $name ) {
			self::redirect( $redirect, 'error' );
		}

		$is_new = ! $animal_id;

		if ( $is_new ) {
			// Ohne Veröffentlichungsrecht landet der Eintrag zur Prüfung im Entwurf.
			$animal_id = wp_insert_post(
				array(
					'post_type'   => 'rm_animal',
					'post_status' => current_user_can( 'publish_posts' ) ? 'publish' : 'pending',
					'post_title'  => $name,
					'post_author' => get_current_user_id(),
				),
				true
			);
			if ( is_wp_error( $animal_id ) || ! $animal_id ) {
				self::redirect( $redirect, 'error' );
			}
		} else {
			wp_update_post(
				array(
					'ID'         => $animal_id,
					'post_title' => $name,
				)
			);
		}

		// Stammdaten.
		$fields = array(
			'rm_sex'    => '_rm_sex',
			'rm_birth'  => '_rm_birth',
			'rm_origin' => '_rm_origin',
			'rm_length' => '_rm_length',
		);
		foreach ( $fields as $field => $meta_key ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			update_post_meta( $animal_id, $meta_key, $value );
		}

		update_post_meta( $animal_id, '_rm_public', isset( $_POST['rm_public'] ) ? '1' : '0' );

		// Genanlagen (artübergreifende Validierung wie im Backend).
		$genes = array();
		$raw   = isset( $_POST['rm_genes'] ) && is_array( $_POST['rm_genes'] ) ? wp_unslash( $_POST['rm_genes'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- je Feld unten sanitisiert.
		foreach ( RM_Genetics::all_gene_keys() as $key ) {
			$state = isset( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : '';
			if ( in_array( $state, array( 'het', 'homo' ), true ) ) {
				$genes[ $key ] = $state;
			}
		}
		update_post_meta( $animal_id, '_rm_genes', $genes );

		// Tierart.
		if ( isset( $_POST['rm_species'] ) ) {
			$term_id = absint( $_POST['rm_species'] );
			if ( $term_id && term_exists( $term_id, 'rm_species' ) ) {
				wp_set_object_terms( $animal_id, array( $term_id ), 'rm_species' );
				RM_Species::forget_animal( $animal_id );
			}
		}

		// Optionale erste Wiegung.
		$weight = isset( $_POST['rm_weight'] ) ? absint( $_POST['rm_weight'] ) : 0;
		if ( $weight && $is_new ) {
			update_post_meta(
				$animal_id,
				'_rm_weights',
				array(
					array(
						'date'  => current_time( 'Y-m-d' ),
						'grams' => $weight,
					),
				)
			);
		}

		// Foto (optional) als Beitragsbild.
		self::maybe_handle_photo( $animal_id );

		self::redirect( $redirect, $is_new ? 'created' : 'saved' );
	}

	/**
	 * Foto-Upload verarbeiten und als Beitragsbild setzen.
	 *
	 * @param int $animal_id Beitrags-ID.
	 */
	private static function maybe_handle_photo( $animal_id ) {
		if ( empty( $_FILES['rm_photo'] ) || empty( $_FILES['rm_photo']['tmp_name'] ) ) {
			return;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return;
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'rm_photo', $animal_id );
		if ( is_wp_error( $attachment_id ) ) {
			return;
		}
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			return;
		}
		set_post_thumbnail( $animal_id, $attachment_id );
	}

	/* ---------------------------------------------------------------------
	 * Hilfsfunktionen
	 * ------------------------------------------------------------------ */

	/**
	 * URL der aktuellen Seite (inkl. Query-String) für Links und Redirects.
	 *
	 * @return string
	 */
	private static function current_url() {
		$permalink = get_permalink();
		return $permalink ? $permalink : home_url( '/' );
	}

	/**
	 * Redirect-Ziel auf die eigene Website begrenzen.
	 *
	 * @param string $url Rohwert aus dem Formular.
	 * @return string
	 */
	private static function sanitize_redirect( $url ) {
		$url = esc_url_raw( (string) $url );
		if ( ! $url ) {
			return home_url( '/' );
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host && $host !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return home_url( '/' );
		}
		return $url;
	}

	/**
	 * Sicherer Redirect mit Statusmeldung.
	 *
	 * @param string $url URL.
	 * @param string $msg Nachrichtenschlüssel.
	 */
	private static function redirect( $url, $msg ) {
		$url = $url ? $url : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'rm_msg', $msg, remove_query_arg( array( 'rm_action', 'rm_edit', 'rm_msg' ), $url ) ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------ */

	private static function enqueue_assets() {
		wp_enqueue_script( 'rm-frontend', RM_PLUGIN_URL . 'assets/js/frontend.js', array(), RM_VERSION, true );
	}
}
