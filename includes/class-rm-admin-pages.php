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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Statusanzeige nach Redirect.
		$msg = isset( $_GET['rm_msg'] ) ? sanitize_key( $_GET['rm_msg'] ) : '';
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Futterplan (Bartagamen)', 'reptilien-manager' ); ?></h1>

			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Fütterung gespeichert.', 'reptilien-manager' ); ?></p></div>
			<?php elseif ( 'missing' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Bitte mindestens ein Tier und eine Futterart auswählen.', 'reptilien-manager' ); ?></p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Die Fütterung konnte nicht gespeichert werden.', 'reptilien-manager' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $animals ) : ?>
				<p><?php esc_html_e( 'Noch keine Tiere eingetragen.', 'reptilien-manager' ); ?></p>
			<?php else : ?>

				<div class="rm-quick-feeding">
					<h2><?php esc_html_e( '⚡ Schnell-Eintrag: Fütterung', 'reptilien-manager' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Mehrere Tiere und Futterarten gleichzeitig – ein Klick, fertig.', 'reptilien-manager' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rm_quick_feeding" />
						<?php wp_nonce_field( 'rm_quick_feeding', 'rm_quick_feeding_nonce' ); ?>

						<div class="rm-quick-grid">
							<div class="rm-quick-field">
								<label for="rm_feed_date"><strong><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?></strong></label><br />
								<input type="date" name="rm_feed_date" id="rm_feed_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
							</div>
							<div class="rm-quick-field">
								<label for="rm_feed_amount"><strong><?php esc_html_e( 'Menge', 'reptilien-manager' ); ?></strong></label><br />
								<input type="text" name="rm_feed_amount" id="rm_feed_amount" placeholder="<?php esc_attr_e( 'z. B. 5 Stück pro Tier', 'reptilien-manager' ); ?>" />
							</div>
						</div>

						<p><strong><?php esc_html_e( 'Tiere', 'reptilien-manager' ); ?></strong></p>
						<?php RM_Feeding::render_animal_choices(); ?>

						<p><strong><?php esc_html_e( 'Futter', 'reptilien-manager' ); ?></strong></p>
						<?php RM_Feeding::render_food_choices(); ?>

						<p><strong><?php esc_html_e( 'Supplemente', 'reptilien-manager' ); ?></strong></p>
						<?php RM_Feeding::render_supplement_choices(); ?>

						<p>
							<label for="rm_feed_notes"><strong><?php esc_html_e( 'Notizen (optional)', 'reptilien-manager' ); ?></strong></label><br />
							<textarea name="rm_feed_notes" id="rm_feed_notes" rows="2" class="large-text"></textarea>
						</p>

						<?php submit_button( __( 'Fütterung speichern', 'reptilien-manager' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<h2><?php esc_html_e( 'Empfehlung & Auswertung pro Tier', 'reptilien-manager' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %d: Anzahl Tage */
						esc_html__( 'Die Auswertung vergleicht die protokollierten Fütterungen der letzten %d Tage mit dem altersgerechten Optimum (Frequenz pro Woche).', 'reptilien-manager' ),
						(int) RM_Feeding::ANALYSIS_DAYS
					);
					?>
				</p>
				<table class="widefat striped rm-table rm-table--wide">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Alter / Gruppe', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Empfehlung', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Auswertung (Ist vs. Optimum)', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Letzte Fütterung', 'reptilien-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $animals as $animal ) : ?>
							<?php
							$birth    = get_post_meta( $animal->ID, '_rm_birth', true );
							$months   = $birth ? RM_Animal_Meta::age_in_months( $birth ) : null;
							$plan     = RM_Feeding::plan_for_age( $months );
							$last     = RM_Feeding::last_feeding( $animal->ID );
							$analysis = RM_Feeding::analyze_animal( $animal->ID );
							$notes    = get_post_meta( $animal->ID, '_rm_food_notes', true );
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
								<td class="rm-plan-cell">
									<span>🦗 <?php echo esc_html( $plan['insects'] ); ?></span>
									<span>🥬 <?php echo esc_html( $plan['greens'] ); ?></span>
									<span>🦴 <?php echo esc_html( $plan['supplements'] ); ?></span>
								</td>
								<td><?php self::render_analysis( $analysis ); ?></td>
								<td>
									<?php
									if ( $last && $last['date'] && strtotime( $last['date'] ) ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $last['date'] ) ) );
										if ( $last['food'] ) {
											echo '<br /><span class="description">' . esc_html( $last['food'] ) . '</span>';
										}
									} else {
										echo esc_html__( 'Noch keine Fütterung protokolliert', 'reptilien-manager' );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Richtwerte für Pogona vitticeps. Individuelle Bedürfnisse (Gesundheit, Winterruhe, Trächtigkeit) immer berücksichtigen und im Zweifel reptilienkundige Tierärzte hinzuziehen.', 'reptilien-manager' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Auswertungs-Chips (Ist vs. Optimum) für ein Tier rendern.
	 *
	 * @param array $analysis Ergebnis von RM_Feeding::analyze_animal().
	 */
	private static function render_analysis( $analysis ) {
		if ( ! $analysis['has_targets'] ) {
			echo '<span class="rm-status rm-status--none">' . esc_html__( 'Kein Schlupfdatum hinterlegt', 'reptilien-manager' ) . '</span>';
			return;
		}

		if ( ! $analysis['has_data'] ) {
			echo '<span class="rm-status rm-status--none">' . esc_html__( 'Keine Fütterungen im Zeitraum', 'reptilien-manager' ) . '</span>';
			return;
		}

		$status_labels = array(
			'ok'   => __( 'optimal', 'reptilien-manager' ),
			'low'  => __( 'zu wenig', 'reptilien-manager' ),
			'high' => __( 'zu viel', 'reptilien-manager' ),
		);
		$status_icons = array(
			'ok'   => '✓',
			'low'  => '↓',
			'high' => '↑',
		);

		echo '<div class="rm-analysis">';
		foreach ( $analysis['categories'] as $category ) {
			$rate_label   = number_format_i18n( $category['rate'], 1 );
			$target_label = $category['min'] === $category['max']
				? number_format_i18n( $category['min'] )
				: number_format_i18n( $category['min'] ) . '–' . number_format_i18n( $category['max'] );

			printf(
				'<span class="rm-status rm-status--%1$s" title="%2$s">%3$s %4$s: %5$s×/Wo (Ziel %6$s) – %7$s</span>',
				esc_attr( $category['status'] ),
				esc_attr( sprintf(
					/* translators: 1: Kategorie, 2: Ist-Wert, 3: Zielbereich */
					__( '%1$s: %2$s Fütterungen pro Woche, Ziel %3$s pro Woche', 'reptilien-manager' ),
					$category['label'],
					$rate_label,
					$target_label
				) ),
				esc_html( $status_icons[ $category['status'] ] ),
				esc_html( $category['label'] ),
				esc_html( $rate_label ),
				esc_html( $target_label ),
				esc_html( $status_labels[ $category['status'] ] )
			);
		}
		echo '</div>';
	}
}
