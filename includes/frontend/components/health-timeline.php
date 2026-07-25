<?php
/**
 * Frontend-Baustein: Gesundheits-Timeline eines Tieres, eingebunden in
 * [reptil id="123"]. Nur für den Tier-Besitzer sichtbar.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Health_Timeline {

	/** Anzahl der in der Timeline angezeigten Einträge. */
	const TIMELINE_LIMIT = 5;

	/**
	 * Rendert die Gesundheits-Timeline eines Tieres, sofern der aktuelle
	 * Nutzer dazu berechtigt ist. Ohne Berechtigung wird nichts ausgegeben.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return string
	 */
	public static function render( $animal_id ) {
		if ( ! RM_Health_Entry::can_manage_for_animal( $animal_id ) ) {
			return '';
		}

		$entries = RM_Health_Entry::entries_for_animal( $animal_id, array( 'limit' => self::TIMELINE_LIMIT ) );
		$summary = RM_Health_Entry::last_symptoms_summary( $animal_id );

		ob_start();
		?>
		<div class="rm-health-timeline">
			<h3 class="rm-health-timeline__title"><?php esc_html_e( 'Gesundheits-Logbuch', 'reptilien-manager' ); ?></h3>

			<?php if ( $summary ) : ?>
				<p class="rm-health-timeline__summary">
					<?php
					printf(
						/* translators: %s: Symptome inkl. „vor N Tagen“ */
						esc_html__( 'Letzte Symptome: %s', 'reptilien-manager' ),
						esc_html( $summary )
					);
					?>
				</p>
			<?php endif; ?>

			<?php if ( ! $entries ) : ?>
				<p class="rm-notice"><?php esc_html_e( 'Noch keine Gesundheits-Einträge vorhanden.', 'reptilien-manager' ); ?></p>
			<?php else : ?>
				<ol class="rm-health-timeline__list">
					<?php foreach ( $entries as $entry ) : ?>
						<?php self::render_item( $entry ); ?>
					<?php endforeach; ?>
				</ol>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Ein einzelner Timeline-Eintrag.
	 *
	 * @param WP_Post $entry Gesundheits-Eintrag.
	 */
	private static function render_item( $entry ) {
		$date      = get_post_meta( $entry->ID, '_reptile_health_date', true );
		$symptoms  = get_post_meta( $entry->ID, '_reptile_health_symptoms', true );
		$symptoms  = is_array( $symptoms ) ? $symptoms : array();
		$diagnosis = get_post_meta( $entry->ID, '_reptile_health_diagnosis', true );
		$treatment = get_post_meta( $entry->ID, '_reptile_health_treatment', true );
		$vet       = get_post_meta( $entry->ID, '_reptile_health_vet_contact', true );
		$resolved  = '1' === (string) get_post_meta( $entry->ID, '_reptile_health_resolved', true );

		$labels     = array_map( array( 'RM_Health_Entry', 'symptom_label' ), $symptoms );
		$date_label = ( $date && strtotime( $date ) ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '';
		?>
		<li class="rm-health-timeline__item">
			<div class="rm-health-timeline__head">
				<span class="rm-health-timeline__date"><?php echo esc_html( $date_label ); ?></span>
				<span class="rm-status rm-status--<?php echo $resolved ? 'ok' : 'high'; ?>">
					<?php echo $resolved ? esc_html__( 'Gelöst', 'reptilien-manager' ) : esc_html__( 'Aktiv', 'reptilien-manager' ); ?>
				</span>
			</div>

			<?php if ( $labels ) : ?>
				<p class="rm-health-timeline__symptoms"><?php echo esc_html( implode( ', ', $labels ) ); ?></p>
			<?php endif; ?>

			<?php if ( $diagnosis ) : ?>
				<p class="rm-health-timeline__row"><strong><?php esc_html_e( 'Diagnose:', 'reptilien-manager' ); ?></strong> <?php echo esc_html( $diagnosis ); ?></p>
			<?php endif; ?>

			<?php if ( $treatment ) : ?>
				<p class="rm-health-timeline__row"><strong><?php esc_html_e( 'Behandlung:', 'reptilien-manager' ); ?></strong> <?php echo esc_html( $treatment ); ?></p>
			<?php endif; ?>

			<?php if ( $vet ) : ?>
				<p class="rm-health-timeline__row"><strong><?php esc_html_e( 'Tierarzt:', 'reptilien-manager' ); ?></strong> <?php echo esc_html( $vet ); ?></p>
			<?php endif; ?>
		</li>
		<?php
	}
}
