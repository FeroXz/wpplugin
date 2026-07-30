<?php
/**
 * Admin-Seite „Terrarien & Strom“: Übersicht aller Terrarien mit Besatz,
 * Maßen und Stromkosten sowie ein freier Strom-Kalkulator zum Durchrechnen
 * einzelner Geräte.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Terrarium_Page {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ) );
		add_action( 'admin_post_rm_save_power_price', array( __CLASS__, 'handle_save_price' ) );
	}

	public static function register_page() {
		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Terrarien & Strom', 'reptilien-manager' ),
			__( 'Terrarien & Strom', 'reptilien-manager' ),
			'edit_posts',
			'rm-terrariums',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Alle Terrarien des aktuellen Nutzers.
	 *
	 * @return WP_Post[]
	 */
	private static function terrariums() {
		$args = array(
			'post_type'      => RM_Terrarium::POST_TYPE,
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		if ( class_exists( 'RM_Roles' ) ) {
			$args = array_merge( $args, RM_Roles::author_query_args() );
		}

		return get_posts( $args );
	}

	/**
	 * Der Strompreis ist eine seitenweite Einstellung, keine eigene Angabe je
	 * Halter – deshalb darf ihn nur ändern, wer auch fremde Tiere verwaltet.
	 *
	 * @return bool
	 */
	public static function can_edit_price() {
		return current_user_can( 'manage_options' ) || current_user_can( 'edit_others_posts' );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$terrariums = self::terrariums();
		$price      = RM_Terrarium::price_per_kwh();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Statusanzeige.
		$msg = isset( $_GET['rm_msg'] ) ? sanitize_key( $_GET['rm_msg'] ) : '';
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Terrarien & Strom', 'reptilien-manager' ); ?></h1>

			<?php if ( 'price_saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Strompreis gespeichert.', 'reptilien-manager' ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Strompreis', 'reptilien-manager' ); ?></h2>
			<?php if ( self::can_edit_price() ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="rm_save_power_price" />
				<?php wp_nonce_field( 'rm_save_power_price', 'rm_power_price_nonce' ); ?>
				<table class="form-table rm-form-table">
					<tr>
						<th><label for="rm_power_price"><?php esc_html_e( 'Preis je kWh (€)', 'reptilien-manager' ); ?></label></th>
						<td>
							<input type="number" step="0.01" min="0" name="rm_power_price" id="rm_power_price" value="<?php echo esc_attr( number_format( $price, 2, '.', '' ) ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'Gilt für alle Berechnungen auf dieser Seite und in den Terrarien.', 'reptilien-manager' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Strompreis speichern', 'reptilien-manager' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php else : ?>
				<p class="description">
					<?php
					printf(
						/* translators: %s: Preis je kWh, bereits formatiert. */
						esc_html__( 'Es wird mit %s € je kWh gerechnet. Diese Einstellung kann nur die Verwaltung ändern.', 'reptilien-manager' ),
						esc_html( number_format_i18n( $price, 2 ) )
					);
					?>
				</p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Strom-Kalkulator', 'reptilien-manager' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Einzelnes Gerät durchrechnen, ohne es einem Terrarium zuzuordnen – z. B. um zwei Lampen zu vergleichen.', 'reptilien-manager' ); ?></p>
			<table class="form-table rm-form-table rm-power-calc">
				<tr>
					<th><label for="rm_calc_watt"><?php esc_html_e( 'Leistung (W)', 'reptilien-manager' ); ?></label></th>
					<td><input type="number" step="1" min="0" id="rm_calc_watt" value="75" class="small-text" /></td>
				</tr>
				<tr>
					<th><label for="rm_calc_hours"><?php esc_html_e( 'Stunden pro Tag', 'reptilien-manager' ); ?></label></th>
					<td><input type="number" step="0.5" min="0" max="24" id="rm_calc_hours" value="10" class="small-text" /></td>
				</tr>
				<tr>
					<th><label for="rm_calc_duty"><?php esc_html_e( 'Einschaltdauer (%)', 'reptilien-manager' ); ?></label></th>
					<td>
						<input type="number" step="1" min="0" max="100" id="rm_calc_duty" value="100" class="small-text" />
						<p class="description"><?php esc_html_e( 'Bei Thermostat-Betrieb: Anteil der Zeit, in der das Gerät tatsächlich heizt.', 'reptilien-manager' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="rm_calc_price"><?php esc_html_e( 'Preis je kWh (€)', 'reptilien-manager' ); ?></label></th>
					<td><input type="number" step="0.01" min="0" id="rm_calc_price" value="<?php echo esc_attr( number_format( $price, 2, '.', '' ) ); ?>" class="small-text" /></td>
				</tr>
			</table>
			<div class="rm-power-summary" id="rm-calc-result" data-empty="<?php esc_attr_e( 'Bitte Werte eingeben.', 'reptilien-manager' ); ?>"></div>

			<h2><?php esc_html_e( 'Terrarien-Übersicht', 'reptilien-manager' ); ?></h2>

			<?php if ( ! $terrariums ) : ?>
				<p>
					<?php esc_html_e( 'Noch keine Terrarien angelegt.', 'reptilien-manager' ); ?>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=' . RM_Terrarium::POST_TYPE ) ); ?>"><?php esc_html_e( 'Erstes Terrarium anlegen', 'reptilien-manager' ); ?></a>
				</p>
			<?php else : ?>
				<?php self::render_overview( $terrariums, $price ); ?>
			<?php endif; ?>

			<?php self::render_unassigned(); ?>
		</div>
		<?php
	}

	/**
	 * Tabelle aller Terrarien inkl. Summenzeile.
	 *
	 * @param WP_Post[] $terrariums Terrarien.
	 * @param float     $price      Preis je kWh.
	 */
	private static function render_overview( $terrariums, $price ) {
		$sum_watt  = 0.0;
		$sum_kwh   = 0.0;
		$sum_month = 0.0;
		$sum_year  = 0.0;
		$warnings  = array();
		?>
		<table class="wp-list-table widefat fixed striped rm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Terrarium', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Maße / Volumen', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Besatz', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Technik', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'kWh/Monat', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( '€/Monat', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( '€/Jahr', 'reptilien-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $terrariums as $terrarium ) :
					$dims      = RM_Terrarium::dimensions( $terrarium->ID );
					$totals    = RM_Terrarium::terrarium_consumption( $terrarium->ID, $price );
					$occupants = RM_Terrarium::occupants( $terrarium->ID );

					$sum_watt  += $totals['watt_installed'];
					$sum_kwh   += $totals['kwh_month'];
					$sum_month += $totals['cost_month'];
					$sum_year  += $totals['cost_year'];

					foreach ( RM_Terrarium::occupancy_warnings( $terrarium->ID ) as $warning ) {
						$warnings[] = array(
							'terrarium' => $terrarium->post_title,
							'warning'   => $warning,
						);
					}

					$names = array();
					foreach ( $occupants as $animal_id ) {
						$names[] = get_the_title( $animal_id );
					}
					?>
					<tr>
						<td>
							<a href="<?php echo esc_url( get_edit_post_link( $terrarium->ID ) ); ?>"><strong><?php echo esc_html( $terrarium->post_title ); ?></strong></a>
							<?php
							$room = get_post_meta( $terrarium->ID, '_rm_terr_room', true );
							if ( $room ) :
								?>
								<br /><span class="description"><?php echo esc_html( $room ); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<?php if ( $dims['length'] && $dims['width'] && $dims['height'] ) : ?>
								<?php echo esc_html( sprintf( '%d × %d × %d cm', $dims['length'], $dims['width'], $dims['height'] ) ); ?>
								<br /><span class="description"><?php echo esc_html( number_format_i18n( $dims['volume_liters'], 0 ) ); ?> L</span>
							<?php else : ?>
								–
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $names ? implode( ', ', $names ) : __( 'leer', 'reptilien-manager' ) ); ?></td>
						<td>
							<?php
							printf(
								/* translators: 1: Anzahl Geräte, 2: installierte Leistung */
								esc_html__( '%1$d Geräte · %2$s W', 'reptilien-manager' ),
								count( $totals['devices'] ),
								esc_html( number_format_i18n( $totals['watt_installed'], 0 ) )
							);
							?>
						</td>
						<td><?php echo esc_html( number_format_i18n( $totals['kwh_month'], 1 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $totals['cost_month'], 2 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $totals['cost_year'], 2 ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<th colspan="3"><?php esc_html_e( 'Gesamt', 'reptilien-manager' ); ?></th>
					<th><?php echo esc_html( number_format_i18n( $sum_watt, 0 ) ); ?> W</th>
					<th><?php echo esc_html( number_format_i18n( $sum_kwh, 1 ) ); ?></th>
					<th><?php echo esc_html( number_format_i18n( $sum_month, 2 ) ); ?> €</th>
					<th><?php echo esc_html( number_format_i18n( $sum_year, 2 ) ); ?> €</th>
				</tr>
			</tfoot>
		</table>

		<?php if ( $warnings ) : ?>
			<h3><?php esc_html_e( 'Hinweise zur Haltung', 'reptilien-manager' ); ?></h3>
			<?php foreach ( $warnings as $entry ) : ?>
				<div class="notice notice-<?php echo 'warning' === $entry['warning']['level'] ? 'warning' : 'info'; ?> inline">
					<p><strong><?php echo esc_html( $entry['terrarium'] ); ?>:</strong> <?php echo esc_html( $entry['warning']['text'] ); ?></p>
				</div>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Tiere ohne Terrarium-Zuordnung auflisten.
	 */
	private static function render_unassigned() {
		$animals    = RM_Post_Types::get_animals();
		$unassigned = array();

		foreach ( $animals as $animal ) {
			if ( ! RM_Terrarium::for_animal( $animal->ID ) ) {
				$unassigned[] = $animal;
			}
		}

		if ( ! $unassigned ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Tiere ohne Terrarium', 'reptilien-manager' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Diese Tiere sind noch keinem Terrarium zugeordnet.', 'reptilien-manager' ); ?></p>
		<ul class="rm-unassigned-list">
			<?php foreach ( $unassigned as $animal ) : ?>
				<li><a href="<?php echo esc_url( get_edit_post_link( $animal->ID ) ); ?>"><?php echo esc_html( $animal->post_title ); ?></a></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	/**
	 * Strompreis speichern.
	 */
	public static function handle_save_price() {
		if ( ! isset( $_POST['rm_power_price_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_power_price_nonce'] ), 'rm_save_power_price' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! self::can_edit_price() ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$raw   = isset( $_POST['rm_power_price'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_power_price'] ) ) : '';
		$price = max( 0, (float) str_replace( ',', '.', $raw ) );

		update_option( RM_Terrarium::PRICE_OPTION, $price );

		wp_safe_redirect( add_query_arg( 'rm_msg', 'price_saved', admin_url( 'edit.php?post_type=rm_animal&page=rm-terrariums' ) ) );
		exit;
	}
}
