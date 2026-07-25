<?php
/**
 * Admin-Seite „Gesundheits-Trends“: Symptom-Häufigkeit, Zeitverlauf,
 * Behandlungs-Erfolgsrate, Top-betroffene Tiere/Morphe, Jahres-Alerts sowie
 * ein einfaches Tierarzt-Kontakt-Verzeichnis.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Health_Trends {

	/** Options-Schlüssel für das Tierarzt-Kontakt-Verzeichnis. */
	const VET_CONTACTS_OPTION = 'rm_vet_contacts';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_rm_health_save_vet_contact', array( __CLASS__, 'handle_save_vet_contact' ) );
		add_action( 'admin_post_rm_health_delete_vet_contact', array( __CLASS__, 'handle_delete_vet_contact' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Gesundheit: Trends', 'reptilien-manager' ),
			__( 'Gesundheit: Trends', 'reptilien-manager' ),
			'edit_posts',
			'rm-health-trends',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Trends-Übersichtsseite.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Lese-/Filterparameter.
		$from = isset( $_GET['rm_health_from'] ) ? sanitize_text_field( wp_unslash( $_GET['rm_health_from'] ) ) : '';
		$to   = isset( $_GET['rm_health_to'] ) ? sanitize_text_field( wp_unslash( $_GET['rm_health_to'] ) ) : '';
		// phpcs:enable

		$data = RM_Health_Stats::collect(
			array(
				'from' => $from ? $from : gmdate( 'Y-m-d', strtotime( '-6 months' ) ),
				'to'   => $to ? $to : current_time( 'Y-m-d' ),
			)
		);

		$chart_payload = array(
			'symptoms' => $data['top_symptoms'],
			'timeline' => $data['timeline'],
		);
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Gesundheits-Trends', 'reptilien-manager' ); ?></h1>
			<p><?php esc_html_e( 'Auswertung des Gesundheits-Logbuchs über einen wählbaren Zeitraum: häufigste Symptome, Verlauf, Behandlungs-Erfolgsrate und am häufigsten betroffene Tiere.', 'reptilien-manager' ); ?></p>

			<form method="get">
				<input type="hidden" name="post_type" value="rm_animal" />
				<input type="hidden" name="page" value="rm-health-trends" />
				<table class="form-table rm-form-table">
					<tr>
						<th><label for="rm_health_from"><?php esc_html_e( 'Von', 'reptilien-manager' ); ?></label></th>
						<td><input type="date" name="rm_health_from" id="rm_health_from" value="<?php echo esc_attr( $data['range']['from'] ); ?>" /></td>
					</tr>
					<tr>
						<th><label for="rm_health_to"><?php esc_html_e( 'Bis', 'reptilien-manager' ); ?></label></th>
						<td><input type="date" name="rm_health_to" id="rm_health_to" value="<?php echo esc_attr( $data['range']['to'] ); ?>" /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Zeitraum anwenden', 'reptilien-manager' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( ! $data['total_entries'] ) : ?>
				<p><?php esc_html_e( 'Keine Gesundheits-Einträge im gewählten Zeitraum.', 'reptilien-manager' ); ?></p>
			<?php else : ?>

				<?php self::render_alerts( $data['alerts'] ); ?>

				<div class="rm-stat-tiles">
					<div class="rm-stat-tile">
						<span class="rm-stat-tile__value"><?php echo esc_html( number_format_i18n( $data['total_entries'] ) ); ?></span>
						<span class="rm-stat-tile__label"><?php esc_html_e( 'Einträge im Zeitraum', 'reptilien-manager' ); ?></span>
					</div>
					<div class="rm-stat-tile">
						<span class="rm-stat-tile__value"><?php echo esc_html( null === $data['treatment_success']['rate'] ? '—' : $data['treatment_success']['rate'] . ' %' ); ?></span>
						<span class="rm-stat-tile__label"><?php esc_html_e( 'Behandlungs-Erfolgsrate', 'reptilien-manager' ); ?></span>
					</div>
					<div class="rm-stat-tile">
						<span class="rm-stat-tile__value"><?php echo esc_html( $data['treatment_success']['treated'] ); ?></span>
						<span class="rm-stat-tile__label"><?php esc_html_e( 'Behandelte Fälle', 'reptilien-manager' ); ?></span>
					</div>
				</div>

				<div class="rm-dashboard-charts">
					<div class="rm-chart-card">
						<h3><?php esc_html_e( 'Häufigste Symptome', 'reptilien-manager' ); ?></h3>
						<canvas id="rm-health-chart-symptoms" height="220"></canvas>
					</div>
					<div class="rm-chart-card rm-chart-card--wide">
						<h3><?php esc_html_e( 'Symptom-Häufigkeit im Zeitverlauf', 'reptilien-manager' ); ?></h3>
						<canvas id="rm-health-chart-timeline" height="220"></canvas>
					</div>
				</div>

				<h2><?php esc_html_e( 'Top betroffene Tiere', 'reptilien-manager' ); ?></h2>
				<?php self::render_top_animals( $data['top_animals'] ); ?>

				<script type="application/json" id="rm-health-trends-data"><?php echo wp_json_encode( $chart_payload ); ?></script>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Tierarzt-Kontakte', 'reptilien-manager' ); ?></h2>
			<?php self::render_vet_contacts(); ?>
		</div>
		<?php
	}

	/**
	 * Alert-Hinweise „Tier XY hatte Nx Symptom dieses Jahr“.
	 *
	 * @param array $alerts Alerts aus RM_Health_Stats::collect().
	 */
	private static function render_alerts( $alerts ) {
		if ( ! $alerts ) {
			return;
		}
		?>
		<div class="rm-health-alerts">
			<?php foreach ( $alerts as $alert ) : ?>
				<div class="notice notice-warning rm-health-alert">
					<p><?php echo esc_html( $alert['message'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Tabelle der am häufigsten betroffenen Tiere/Morphe.
	 *
	 * @param array $rows { animal_id, name, morph, total, active, resolved }[].
	 */
	private static function render_top_animals( $rows ) {
		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Keine Daten.', 'reptilien-manager' ) . '</p>';
			return;
		}
		?>
		<table class="wp-list-table widefat fixed striped rm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Morph', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Einträge', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Aktiv', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Gelöst', 'reptilien-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $row['animal_id'] ) ); ?>"><?php echo esc_html( $row['name'] ); ?></a></td>
						<td><?php echo esc_html( $row['morph'] ); ?></td>
						<td><?php echo esc_html( $row['total'] ); ?></td>
						<td><?php echo esc_html( $row['active'] ); ?></td>
						<td><?php echo esc_html( $row['resolved'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Tierarzt-Kontakt-Verzeichnis
	 * ------------------------------------------------------------------ */

	/**
	 * Gespeicherte Tierarzt-Kontakte.
	 *
	 * @return array Liste von { id, name, phone, email, notes }.
	 */
	public static function vet_contacts() {
		$contacts = get_option( self::VET_CONTACTS_OPTION, array() );
		return is_array( $contacts ) ? $contacts : array();
	}

	/**
	 * Kontakt anlegen oder aktualisieren.
	 *
	 * @param array $data { id (optional), name, phone, email, notes }.
	 */
	private static function save_vet_contact( $data ) {
		$contacts = self::vet_contacts();
		$id       = ! empty( $data['id'] ) ? sanitize_key( $data['id'] ) : 'vet_' . uniqid();

		$entry = array(
			'id'    => $id,
			'name'  => isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '',
			'phone' => isset( $data['phone'] ) ? sanitize_text_field( $data['phone'] ) : '',
			'email' => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
			'notes' => isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '',
		);

		if ( '' === $entry['name'] ) {
			return;
		}

		$found = false;
		foreach ( $contacts as $index => $contact ) {
			if ( isset( $contact['id'] ) && $contact['id'] === $id ) {
				$contacts[ $index ] = $entry;
				$found               = true;
				break;
			}
		}
		if ( ! $found ) {
			$contacts[] = $entry;
		}

		update_option( self::VET_CONTACTS_OPTION, $contacts );
	}

	/**
	 * Kontakt entfernen.
	 *
	 * @param string $id Kontakt-ID.
	 */
	private static function delete_vet_contact( $id ) {
		$contacts = array_values(
			array_filter(
				self::vet_contacts(),
				function ( $contact ) use ( $id ) {
					return ! isset( $contact['id'] ) || $contact['id'] !== $id;
				}
			)
		);
		update_option( self::VET_CONTACTS_OPTION, $contacts );
	}

	/**
	 * Formular + Liste des Kontakt-Verzeichnisses.
	 */
	private static function render_vet_contacts() {
		$contacts = self::vet_contacts();
		$redirect = admin_url( 'edit.php?post_type=rm_animal&page=rm-health-trends' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="rm_health_save_vet_contact" />
			<input type="hidden" name="rm_redirect" value="<?php echo esc_url( $redirect ); ?>" />
			<?php wp_nonce_field( 'rm_health_vet_contact', 'rm_health_vet_contact_nonce' ); ?>
			<table class="form-table rm-form-table">
				<tr>
					<th><label for="rm_vet_name"><?php esc_html_e( 'Name', 'reptilien-manager' ); ?></label></th>
					<td><input type="text" name="rm_vet_name" id="rm_vet_name" class="regular-text" required /></td>
				</tr>
				<tr>
					<th><label for="rm_vet_phone"><?php esc_html_e( 'Telefon', 'reptilien-manager' ); ?></label></th>
					<td><input type="text" name="rm_vet_phone" id="rm_vet_phone" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="rm_vet_email"><?php esc_html_e( 'E-Mail', 'reptilien-manager' ); ?></label></th>
					<td><input type="email" name="rm_vet_email" id="rm_vet_email" class="regular-text" /></td>
				</tr>
				<tr>
					<th><label for="rm_vet_notes"><?php esc_html_e( 'Notizen', 'reptilien-manager' ); ?></label></th>
					<td><textarea name="rm_vet_notes" id="rm_vet_notes" rows="2" class="large-text"></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Kontakt speichern', 'reptilien-manager' ), 'secondary', 'submit', false ); ?>
		</form>

		<?php if ( $contacts ) : ?>
			<table class="wp-list-table widefat fixed striped rm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'reptilien-manager' ); ?></th>
						<th><?php esc_html_e( 'Telefon', 'reptilien-manager' ); ?></th>
						<th><?php esc_html_e( 'E-Mail', 'reptilien-manager' ); ?></th>
						<th><?php esc_html_e( 'Notizen', 'reptilien-manager' ); ?></th>
						<th></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $contacts as $contact ) : ?>
						<tr>
							<td><?php echo esc_html( $contact['name'] ); ?></td>
							<td><?php echo esc_html( $contact['phone'] ); ?></td>
							<td><?php echo esc_html( $contact['email'] ); ?></td>
							<td><?php echo esc_html( $contact['notes'] ); ?></td>
							<td>
								<a
									class="submitdelete"
									href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=rm_health_delete_vet_contact&id=' . rawurlencode( $contact['id'] ) ), 'rm_health_delete_vet_contact' ) ); ?>"
									onclick="return confirm('<?php echo esc_js( __( 'Kontakt wirklich löschen?', 'reptilien-manager' ) ); ?>');"
								><?php esc_html_e( 'Löschen', 'reptilien-manager' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php else : ?>
			<p><?php esc_html_e( 'Noch keine Tierarzt-Kontakte gespeichert.', 'reptilien-manager' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Kontakt-Formular verarbeiten.
	 */
	public static function handle_save_vet_contact() {
		if ( ! isset( $_POST['rm_health_vet_contact_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_health_vet_contact_nonce'] ), 'rm_health_vet_contact' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$redirect = isset( $_POST['rm_redirect'] ) ? esc_url_raw( wp_unslash( $_POST['rm_redirect'] ) ) : admin_url( 'edit.php?post_type=rm_animal&page=rm-health-trends' );

		self::save_vet_contact(
			array(
				'name'  => isset( $_POST['rm_vet_name'] ) ? wp_unslash( $_POST['rm_vet_name'] ) : '',
				'phone' => isset( $_POST['rm_vet_phone'] ) ? wp_unslash( $_POST['rm_vet_phone'] ) : '',
				'email' => isset( $_POST['rm_vet_email'] ) ? wp_unslash( $_POST['rm_vet_email'] ) : '',
				'notes' => isset( $_POST['rm_vet_notes'] ) ? wp_unslash( $_POST['rm_vet_notes'] ) : '',
			)
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Kontakt-Löschung verarbeiten.
	 */
	public static function handle_delete_vet_contact() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rm_health_delete_vet_contact' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		if ( $id ) {
			self::delete_vet_contact( $id );
		}

		wp_safe_redirect( admin_url( 'edit.php?post_type=rm_animal&page=rm-health-trends' ) );
		exit;
	}
}
