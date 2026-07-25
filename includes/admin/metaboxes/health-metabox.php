<?php
/**
 * Admin-Metabox für Gesundheits-Einträge (reptile_health_entry):
 * Tier-Zuordnung, Datum, Symptome, Diagnose, Behandlung, Tierarzt-Kontakt
 * und Heilungs-Status.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Health_Metabox {

	public static function init() {
		add_action( 'add_meta_boxes_' . RM_Health_Entry::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . RM_Health_Entry::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . RM_Health_Entry::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . RM_Health_Entry::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'maybe_show_notice' ) );
	}

	public static function add_meta_boxes() {
		add_meta_box(
			'rm-health-details',
			__( 'Gesundheits-Eintrag', 'reptilien-manager' ),
			array( __CLASS__, 'render_details' ),
			RM_Health_Entry::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Formularfelder des Gesundheits-Eintrags.
	 *
	 * @param WP_Post $post Aktueller Beitrag.
	 */
	public static function render_details( $post ) {
		wp_nonce_field( 'rm_health_meta', 'rm_health_meta_nonce' );

		$animal_id  = (int) get_post_meta( $post->ID, '_reptile_health_animal_id', true );
		$date       = get_post_meta( $post->ID, '_reptile_health_date', true );
		$symptoms   = get_post_meta( $post->ID, '_reptile_health_symptoms', true );
		$symptoms   = is_array( $symptoms ) ? $symptoms : array();
		$diagnosis  = get_post_meta( $post->ID, '_reptile_health_diagnosis', true );
		$treatment  = get_post_meta( $post->ID, '_reptile_health_treatment', true );
		$vet        = get_post_meta( $post->ID, '_reptile_health_vet_contact', true );
		$resolved   = '1' === (string) get_post_meta( $post->ID, '_reptile_health_resolved', true );
		$resolved_d = get_post_meta( $post->ID, '_reptile_health_resolved_date', true );

		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}

		// Nur Tiere, die der aktuelle Nutzer verwalten darf (RM_Roles-Autor-Scope).
		$animals = RM_Post_Types::get_animals();

		if ( $animal_id && ! RM_Health_Entry::can_manage_for_animal( $animal_id ) ) {
			printf(
				'<p class="description">%s</p>',
				esc_html__( 'Dieser Eintrag ist einem Tier zugeordnet, das du nicht verwaltest. Er wird schreibgeschützt angezeigt.', 'reptilien-manager' )
			);
			self::render_readonly_summary( $animal_id, $date, $symptoms, $diagnosis, $treatment, $vet, $resolved, $resolved_d );
			return;
		}
		?>
		<table class="form-table rm-form-table">
			<tr>
				<th><label for="rm_health_animal"><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?> *</label></th>
				<td>
					<?php if ( ! $animals ) : ?>
						<p class="description"><?php esc_html_e( 'Noch keine Tiere eingetragen.', 'reptilien-manager' ); ?></p>
					<?php else : ?>
						<select name="rm_health_animal" id="rm_health_animal" required>
							<option value=""><?php esc_html_e( '– auswählen –', 'reptilien-manager' ); ?></option>
							<?php foreach ( $animals as $animal ) : ?>
								<option value="<?php echo esc_attr( $animal->ID ); ?>" <?php selected( $animal_id, $animal->ID ); ?>><?php echo esc_html( $animal->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_health_date"><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?></label></th>
				<td><input type="date" name="rm_health_date" id="rm_health_date" value="<?php echo esc_attr( $date ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Symptome', 'reptilien-manager' ); ?></th>
				<td>
					<div class="rm-choice-group">
						<div class="rm-choice-grid">
							<?php foreach ( RM_Health_Entry::symptoms() as $key => $label ) : ?>
								<label class="rm-choice">
									<input type="checkbox" name="rm_health_symptoms[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $symptoms, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</div>
					</div>
				</td>
			</tr>
			<tr>
				<th><label for="rm_health_diagnosis"><?php esc_html_e( 'Diagnose', 'reptilien-manager' ); ?></label></th>
				<td><textarea name="rm_health_diagnosis" id="rm_health_diagnosis" rows="2" class="large-text"><?php echo esc_textarea( $diagnosis ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="rm_health_treatment"><?php esc_html_e( 'Behandlung', 'reptilien-manager' ); ?></label></th>
				<td><textarea name="rm_health_treatment" id="rm_health_treatment" rows="2" class="large-text"><?php echo esc_textarea( $treatment ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="rm_health_vet_contact"><?php esc_html_e( 'Tierarzt-Kontakt', 'reptilien-manager' ); ?></label></th>
				<td>
					<input type="text" name="rm_health_vet_contact" id="rm_health_vet_contact" class="regular-text" list="rm-health-vet-contacts" value="<?php echo esc_attr( $vet ); ?>" />
					<?php if ( class_exists( 'RM_Health_Trends' ) ) : ?>
						<datalist id="rm-health-vet-contacts">
							<?php foreach ( RM_Health_Trends::vet_contacts() as $contact ) : ?>
								<option value="<?php echo esc_attr( $contact['name'] . ( $contact['phone'] ? ' – ' . $contact['phone'] : '' ) ); ?>"></option>
							<?php endforeach; ?>
						</datalist>
						<p class="description"><?php esc_html_e( 'Vorschläge aus dem Tierarzt-Kontakt-Verzeichnis (Reptilien → Gesundheit: Trends).', 'reptilien-manager' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_health_resolved"><?php esc_html_e( 'Status', 'reptilien-manager' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="rm_health_resolved" id="rm_health_resolved" value="1" <?php checked( $resolved ); ?> />
						<?php esc_html_e( 'Ausgeheilt / abgeschlossen', 'reptilien-manager' ); ?>
					</label>
					<p class="rm-health-resolved-date" style="<?php echo $resolved ? '' : 'display:none;'; ?>">
						<label for="rm_health_resolved_date"><?php esc_html_e( 'Datum der Ausheilung', 'reptilien-manager' ); ?></label>
						<input type="date" name="rm_health_resolved_date" id="rm_health_resolved_date" value="<?php echo esc_attr( $resolved_d ? $resolved_d : current_time( 'Y-m-d' ) ); ?>" />
					</p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Schreibgeschützte Ansicht für Einträge, die einem fremden Tier gehören.
	 *
	 * @param int      $animal_id  Beitrags-ID des Tieres.
	 * @param string   $date       Datum.
	 * @param string[] $symptoms   Symptom-Schlüssel.
	 * @param string   $diagnosis  Diagnose.
	 * @param string   $treatment  Behandlung.
	 * @param string   $vet        Tierarzt-Kontakt.
	 * @param bool     $resolved   Ausgeheilt.
	 * @param string   $resolved_d Datum der Ausheilung.
	 */
	private static function render_readonly_summary( $animal_id, $date, $symptoms, $diagnosis, $treatment, $vet, $resolved, $resolved_d ) {
		$labels = array_map( array( 'RM_Health_Entry', 'symptom_label' ), $symptoms );
		?>
		<ul>
			<li><strong><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?>:</strong> <?php echo esc_html( get_the_title( $animal_id ) ); ?></li>
			<li><strong><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?>:</strong> <?php echo esc_html( $date ); ?></li>
			<li><strong><?php esc_html_e( 'Symptome', 'reptilien-manager' ); ?>:</strong> <?php echo esc_html( implode( ', ', $labels ) ); ?></li>
			<li><strong><?php esc_html_e( 'Diagnose', 'reptilien-manager' ); ?>:</strong> <?php echo esc_html( $diagnosis ); ?></li>
			<li><strong><?php esc_html_e( 'Behandlung', 'reptilien-manager' ); ?>:</strong> <?php echo esc_html( $treatment ); ?></li>
			<li><strong><?php esc_html_e( 'Tierarzt-Kontakt', 'reptilien-manager' ); ?>:</strong> <?php echo esc_html( $vet ); ?></li>
			<li><strong><?php esc_html_e( 'Status', 'reptilien-manager' ); ?>:</strong> <?php echo $resolved ? esc_html__( 'Ausgeheilt', 'reptilien-manager' ) . ' (' . esc_html( $resolved_d ) . ')' : esc_html__( 'Aktiv', 'reptilien-manager' ); ?></li>
		</ul>
		<?php
	}

	/**
	 * Speichert die Meta-Felder eines Gesundheits-Eintrags.
	 *
	 * @param int     $post_id Beitrags-ID.
	 * @param WP_Post $post    Beitrag.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rm_health_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_health_meta_nonce'] ), 'rm_health_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$existing_animal = (int) get_post_meta( $post_id, '_reptile_health_animal_id', true );

		// Der Tier-Eintrag darf nur einem Tier zugeordnet werden, das der
		// Nutzer auch verwalten darf – sonst bleibt die bestehende Zuordnung
		// (bzw. bei Neuanlage keine) erhalten.
		$submitted_animal = isset( $_POST['rm_health_animal'] ) ? absint( $_POST['rm_health_animal'] ) : 0;
		if ( $submitted_animal && RM_Health_Entry::can_manage_for_animal( $submitted_animal ) ) {
			$animal_id = $submitted_animal;
		} else {
			$animal_id = $existing_animal;
			if ( $submitted_animal && $submitted_animal !== $existing_animal ) {
				set_transient( 'rm_health_notice_' . get_current_user_id(), __( 'Der Eintrag konnte nicht dem gewählten Tier zugeordnet werden – keine Berechtigung.', 'reptilien-manager' ), 60 );
			}
		}
		update_post_meta( $post_id, '_reptile_health_animal_id', $animal_id );

		$date = isset( $_POST['rm_health_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_health_date'] ) ) : current_time( 'Y-m-d' );
		if ( ! $date || ! strtotime( $date ) ) {
			$date = current_time( 'Y-m-d' );
		}
		update_post_meta( $post_id, '_reptile_health_date', $date );

		$raw_symptoms = isset( $_POST['rm_health_symptoms'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['rm_health_symptoms'] ) ) : array();
		$symptoms     = array_values( array_intersect( $raw_symptoms, array_keys( RM_Health_Entry::symptoms() ) ) );
		update_post_meta( $post_id, '_reptile_health_symptoms', $symptoms );

		update_post_meta( $post_id, '_reptile_health_diagnosis', isset( $_POST['rm_health_diagnosis'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_health_diagnosis'] ) ) : '' );
		update_post_meta( $post_id, '_reptile_health_treatment', isset( $_POST['rm_health_treatment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_health_treatment'] ) ) : '' );
		update_post_meta( $post_id, '_reptile_health_vet_contact', isset( $_POST['rm_health_vet_contact'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_health_vet_contact'] ) ) : '' );

		$resolved = isset( $_POST['rm_health_resolved'] ) ? '1' : '0';
		update_post_meta( $post_id, '_reptile_health_resolved', $resolved );

		if ( '1' === $resolved ) {
			$resolved_date = isset( $_POST['rm_health_resolved_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_health_resolved_date'] ) ) : current_time( 'Y-m-d' );
			if ( ! $resolved_date || ! strtotime( $resolved_date ) ) {
				$resolved_date = current_time( 'Y-m-d' );
			}
			update_post_meta( $post_id, '_reptile_health_resolved_date', $resolved_date );
		} else {
			delete_post_meta( $post_id, '_reptile_health_resolved_date' );
		}

		self::maybe_autotitle( $post_id, $post->post_title, $animal_id, $date );
	}

	/**
	 * Vergibt automatisch den sprechenden Titel „Gesundheit [Tier] [Datum]“,
	 * solange kein eigener Titel gesetzt wurde.
	 *
	 * @param int    $post_id Beitrags-ID.
	 * @param string $title   Aktueller Titel.
	 * @param int    $animal_id Beitrags-ID des Tieres.
	 * @param string $date    Datum.
	 */
	private static function maybe_autotitle( $post_id, $title, $animal_id, $date ) {
		static $updating = false;

		if ( $updating || ! $animal_id || '' !== trim( (string) $title ) ) {
			return;
		}

		$updating = true;
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => RM_Health_Entry::build_title( $animal_id, $date ),
			)
		);
		$updating = false;
	}

	/**
	 * Admin-Hinweis, falls eine Tier-Zuordnung abgelehnt wurde.
	 */
	public static function maybe_show_notice() {
		$key = 'rm_health_notice_' . get_current_user_id();
		$msg = get_transient( $key );
		if ( ! $msg ) {
			return;
		}
		delete_transient( $key );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $msg ) );
	}

	/* ---------------------------------------------------------------------
	 * Admin-Spalten
	 * ------------------------------------------------------------------ */

	/**
	 * Spalten der Beitragsliste.
	 *
	 * @param array $columns Standard-Spalten.
	 * @return array
	 */
	public static function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['rm_health_animal']   = __( 'Tier', 'reptilien-manager' );
				$new['rm_health_date']     = __( 'Datum', 'reptilien-manager' );
				$new['rm_health_symptoms'] = __( 'Symptome', 'reptilien-manager' );
				$new['rm_health_status']   = __( 'Status', 'reptilien-manager' );
			}
		}
		return $new;
	}

	/**
	 * Inhalt der Zusatzspalten.
	 *
	 * @param string $column  Spaltenname.
	 * @param int    $post_id Beitrags-ID.
	 */
	public static function admin_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'rm_health_animal':
				$animal_id = (int) get_post_meta( $post_id, '_reptile_health_animal_id', true );
				echo $animal_id ? esc_html( get_the_title( $animal_id ) ) : '–'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits escapt.
				break;

			case 'rm_health_date':
				$date = get_post_meta( $post_id, '_reptile_health_date', true );
				echo esc_html( $date && strtotime( $date ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '–' );
				break;

			case 'rm_health_symptoms':
				$symptoms = get_post_meta( $post_id, '_reptile_health_symptoms', true );
				$symptoms = is_array( $symptoms ) ? $symptoms : array();
				$labels   = array_map( array( 'RM_Health_Entry', 'symptom_label' ), $symptoms );
				echo esc_html( $labels ? implode( ', ', $labels ) : '–' );
				break;

			case 'rm_health_status':
				$resolved = '1' === (string) get_post_meta( $post_id, '_reptile_health_resolved', true );
				printf(
					'<span class="rm-status rm-status--%s">%s</span>',
					$resolved ? 'ok' : 'high',
					$resolved ? esc_html__( 'Gelöst', 'reptilien-manager' ) : esc_html__( 'Aktiv', 'reptilien-manager' )
				);
				break;
		}
	}
}
