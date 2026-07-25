<?php
/**
 * Admin-Übersichtsseite „Gesundheits-Logbuch“: alle Einträge (auf eigene
 * Tiere beschränkt), filterbar nach Tier, Symptom und Status.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Health_Tracking_Page {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Gesundheits-Logbuch', 'reptilien-manager' ),
			__( 'Gesundheits-Logbuch', 'reptilien-manager' ),
			'edit_posts',
			'rm-health-tracking',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Übersichtsseite mit Filterleiste und Tabelle (neueste zuerst).
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Lese-/Filterparameter.
		$filter_animal  = isset( $_GET['rm_health_f_animal'] ) ? absint( $_GET['rm_health_f_animal'] ) : 0;
		$filter_symptom = isset( $_GET['rm_health_f_symptom'] ) ? sanitize_key( $_GET['rm_health_f_symptom'] ) : '';
		$filter_status  = isset( $_GET['rm_health_f_status'] ) ? sanitize_key( $_GET['rm_health_f_status'] ) : '';
		// phpcs:enable

		$entries = RM_Health_Entry::all_entries(
			array(
				'animal'  => $filter_animal,
				'symptom' => $filter_symptom,
				'status'  => $filter_status,
			)
		);

		$animals = RM_Post_Types::get_animals();
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Gesundheits-Logbuch', 'reptilien-manager' ); ?></h1>

			<form method="get">
				<input type="hidden" name="post_type" value="rm_animal" />
				<input type="hidden" name="page" value="rm-health-tracking" />
				<table class="form-table rm-form-table">
					<tr>
						<th><label for="rm_health_f_animal"><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></label></th>
						<td>
							<select name="rm_health_f_animal" id="rm_health_f_animal">
								<option value=""><?php esc_html_e( 'Alle', 'reptilien-manager' ); ?></option>
								<?php foreach ( $animals as $animal ) : ?>
									<option value="<?php echo esc_attr( $animal->ID ); ?>" <?php selected( $filter_animal, $animal->ID ); ?>><?php echo esc_html( $animal->post_title ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="rm_health_f_symptom"><?php esc_html_e( 'Symptom', 'reptilien-manager' ); ?></label></th>
						<td>
							<select name="rm_health_f_symptom" id="rm_health_f_symptom">
								<option value=""><?php esc_html_e( 'Alle', 'reptilien-manager' ); ?></option>
								<?php foreach ( RM_Health_Entry::symptoms() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $filter_symptom, $key ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="rm_health_f_status"><?php esc_html_e( 'Status', 'reptilien-manager' ); ?></label></th>
						<td>
							<select name="rm_health_f_status" id="rm_health_f_status">
								<option value=""><?php esc_html_e( 'Alle', 'reptilien-manager' ); ?></option>
								<option value="active" <?php selected( $filter_status, 'active' ); ?>><?php esc_html_e( 'Aktiv', 'reptilien-manager' ); ?></option>
								<option value="resolved" <?php selected( $filter_status, 'resolved' ); ?>><?php esc_html_e( 'Gelöst', 'reptilien-manager' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Filtern', 'reptilien-manager' ), 'primary', 'submit', false ); ?>
				<?php if ( $filter_animal || $filter_symptom || $filter_status ) : ?>
					<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=rm_animal&page=rm-health-tracking' ) ); ?>"><?php esc_html_e( 'Zurücksetzen', 'reptilien-manager' ); ?></a>
				<?php endif; ?>
			</form>

			<?php if ( ! $entries ) : ?>
				<p><?php esc_html_e( 'Keine Gesundheits-Einträge gefunden.', 'reptilien-manager' ); ?></p>
			<?php else : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Symptome', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Diagnose', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Status', 'reptilien-manager' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $entries as $entry ) : ?>
							<?php self::render_row( $entry ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Eine Tabellenzeile der Übersicht.
	 *
	 * @param WP_Post $entry Gesundheits-Eintrag.
	 */
	private static function render_row( $entry ) {
		$animal_id = (int) get_post_meta( $entry->ID, '_reptile_health_animal_id', true );
		$date      = get_post_meta( $entry->ID, '_reptile_health_date', true );
		$symptoms  = get_post_meta( $entry->ID, '_reptile_health_symptoms', true );
		$symptoms  = is_array( $symptoms ) ? $symptoms : array();
		$diagnosis = get_post_meta( $entry->ID, '_reptile_health_diagnosis', true );
		$resolved  = '1' === (string) get_post_meta( $entry->ID, '_reptile_health_resolved', true );

		$labels = array_map( array( 'RM_Health_Entry', 'symptom_label' ), $symptoms );
		?>
		<tr>
			<td><?php echo $animal_id ? esc_html( get_the_title( $animal_id ) ) : '–'; ?></td>
			<td><?php echo esc_html( $date && strtotime( $date ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '–' ); ?></td>
			<td><?php echo esc_html( $labels ? implode( ', ', $labels ) : '–' ); ?></td>
			<td><?php echo esc_html( $diagnosis ? $diagnosis : '–' ); ?></td>
			<td>
				<span class="rm-status rm-status--<?php echo $resolved ? 'ok' : 'high'; ?>">
					<?php echo $resolved ? esc_html__( 'Gelöst', 'reptilien-manager' ) : esc_html__( 'Aktiv', 'reptilien-manager' ); ?>
				</span>
			</td>
			<td><a href="<?php echo esc_url( get_edit_post_link( $entry->ID ) ); ?>"><?php esc_html_e( 'Bearbeiten', 'reptilien-manager' ); ?></a></td>
		</tr>
		<?php
	}
}
