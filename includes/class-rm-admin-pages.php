<?php
/**
 * Admin-Seiten: Genetik-Rechner und Futterplan.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Admin_Pages {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_pages' ) );
	}

	public static function register_pages() {
		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Genetik-Rechner', 'reptilien-manager' ),
			__( 'Genetik-Rechner', 'reptilien-manager' ),
			'edit_posts',
			'rm-genetics',
			array( __CLASS__, 'render_genetics_page' )
		);

		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Futterplan', 'reptilien-manager' ),
			__( 'Futterplan', 'reptilien-manager' ),
			'edit_posts',
			'rm-feeding-plan',
			array( __CLASS__, 'render_feeding_plan_page' )
		);
	}

	public static function render_genetics_page() {
		$males   = RM_Post_Types::get_animals( 'male' );
		$females = RM_Post_Types::get_animals( 'female' );

		$sire = isset( $_GET['rm_sire'] ) ? absint( $_GET['rm_sire'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur-Lese-Formular.
		$dam  = isset( $_GET['rm_dam'] ) ? absint( $_GET['rm_dam'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Genetik-Rechner (Bartagamen)', 'reptilien-manager' ); ?></h1>
			<p><?php esc_html_e( 'Wähle zwei Tiere aus, um die möglichen Genkombinationen der Jungtiere zu berechnen. Die Genanlagen werden am jeweiligen Tier unter „Genetik / Morph“ gepflegt.', 'reptilien-manager' ); ?></p>

			<form method="get">
				<input type="hidden" name="post_type" value="rm_animal" />
				<input type="hidden" name="page" value="rm-genetics" />
				<table class="form-table rm-form-table">
					<tr>
						<th><label for="rm_sire"><?php esc_html_e( 'Vater (1.0)', 'reptilien-manager' ); ?></label></th>
						<td>
							<select name="rm_sire" id="rm_sire">
								<option value=""><?php esc_html_e( '– auswählen –', 'reptilien-manager' ); ?></option>
								<?php foreach ( $males as $animal ) : ?>
									<option value="<?php echo esc_attr( $animal->ID ); ?>" <?php selected( $sire, $animal->ID ); ?>>
										<?php echo esc_html( $animal->post_title . ' (' . RM_Genetics::animal_morph_label( $animal->ID ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><label for="rm_dam"><?php esc_html_e( 'Mutter (0.1)', 'reptilien-manager' ); ?></label></th>
						<td>
							<select name="rm_dam" id="rm_dam">
								<option value=""><?php esc_html_e( '– auswählen –', 'reptilien-manager' ); ?></option>
								<?php foreach ( $females as $animal ) : ?>
									<option value="<?php echo esc_attr( $animal->ID ); ?>" <?php selected( $dam, $animal->ID ); ?>>
										<?php echo esc_html( $animal->post_title . ' (' . RM_Genetics::animal_morph_label( $animal->ID ) . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Genetik berechnen', 'reptilien-manager' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( $sire && $dam ) : ?>
				<hr />
				<?php echo RM_Genetics::render_cross_result( $sire, $dam ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML wird intern escaped. ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_feeding_plan_page() {
		$animals = RM_Post_Types::get_animals();
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Futterplan (Bartagamen)', 'reptilien-manager' ); ?></h1>
			<p><?php esc_html_e( 'Altersgerechte Fütterungsempfehlung pro Tier – berechnet aus dem hinterlegten Schlupfdatum – sowie die zuletzt protokollierte Fütterung.', 'reptilien-manager' ); ?></p>

			<?php if ( ! $animals ) : ?>
				<p><?php esc_html_e( 'Noch keine Tiere eingetragen.', 'reptilien-manager' ); ?></p>
			<?php else : ?>
				<table class="widefat striped rm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Alter / Gruppe', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Insekten', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Grünfutter', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Supplemente', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Letzte Fütterung', 'reptilien-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $animals as $animal ) : ?>
							<?php
							$birth  = get_post_meta( $animal->ID, '_rm_birth', true );
							$months = $birth ? RM_Animal_Meta::age_in_months( $birth ) : null;
							$plan   = RM_Feeding::plan_for_age( $months );
							$last   = RM_Feeding::last_feeding( $animal->ID );
							$notes  = get_post_meta( $animal->ID, '_rm_food_notes', true );
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $animal->ID ) ); ?>"><strong><?php echo esc_html( $animal->post_title ); ?></strong></a>
									<?php if ( $notes ) : ?>
										<br /><em><?php echo esc_html( $notes ); ?></em>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $birth ? RM_Animal_Meta::age_label( $birth ) : '—' ); ?><br />
									<span class="description"><?php echo esc_html( $plan['group'] ); ?></span>
								</td>
								<td><?php echo esc_html( $plan['insects'] ); ?></td>
								<td><?php echo esc_html( $plan['greens'] ); ?></td>
								<td><?php echo esc_html( $plan['supplements'] ); ?></td>
								<td>
									<?php
									if ( $last && $last['date'] && strtotime( $last['date'] ) ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $last['date'] ) ) . ' – ' . $last['food'] );
									} else {
										echo esc_html__( 'Noch keine Fütterung protokolliert', 'reptilien-manager' );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<a class="button button-primary" href="<?php echo esc_url( admin_url( 'post-new.php?post_type=rm_feeding_log' ) ); ?>">
						<?php esc_html_e( 'Fütterung eintragen', 'reptilien-manager' ); ?>
					</a>
				</p>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Richtwerte für Pogona vitticeps. Individuelle Bedürfnisse (Gesundheit, Winterruhe, Trächtigkeit) immer berücksichtigen und im Zweifel reptilienkundige Tierärzte hinzuziehen.', 'reptilien-manager' ); ?>
			</p>
		</div>
		<?php
	}
}
