<?php
/**
 * Verpaarungen: Elterntier-Auswahl, Gelege, Nachzuchten und Genetik-Vorschau.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Pairing {

	/**
	 * Typische Inkubationsdauer bei Bartagamen in Tagen (bei ca. 28–29 °C).
	 */
	const INCUBATION_DAYS = 60;

	public static function init() {
		add_action( 'add_meta_boxes_rm_pairing', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_rm_pairing', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_rm_pairing_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_rm_pairing_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
	}

	public static function add_meta_boxes() {
		add_meta_box( 'rm-pairing-details', __( 'Verpaarungsdaten', 'reptilien-manager' ), array( __CLASS__, 'render_details' ), 'rm_pairing', 'normal', 'high' );
		add_meta_box( 'rm-pairing-clutches', __( 'Gelege & Nachzuchten', 'reptilien-manager' ), array( __CLASS__, 'render_clutches' ), 'rm_pairing', 'normal', 'default' );
		add_meta_box( 'rm-pairing-genetics', __( 'Genetik-Vorschau der Jungtiere', 'reptilien-manager' ), array( __CLASS__, 'render_genetics' ), 'rm_pairing', 'normal', 'default' );
	}

	/* ---------------------------------------------------------------------
	 * Daten-Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Ungefähres Schlupfdatum eines Geleges (Ablage + Inkubationsdauer).
	 *
	 * @param string $lay_date Ablagedatum (Y-m-d).
	 * @return string Y-m-d oder ''.
	 */
	public static function estimated_hatch( $lay_date ) {
		$ts = $lay_date ? strtotime( $lay_date ) : false;
		return $ts ? gmdate( 'Y-m-d', $ts + self::INCUBATION_DAYS * DAY_IN_SECONDS ) : '';
	}

	/**
	 * Gelege einer Verpaarung.
	 *
	 * @param int $pairing_id Beitrags-ID der Verpaarung.
	 * @return array[] Liste von { lay_date, eggs, hatched }.
	 */
	public static function get_clutches( $pairing_id ) {
		$clutches = get_post_meta( $pairing_id, '_rm_clutches', true );

		if ( ! is_array( $clutches ) ) {
			$clutches = array();

			// Migration: alte Einzelfelder (Version 1.0/1.1) als erstes Gelege übernehmen.
			$legacy_lay  = get_post_meta( $pairing_id, '_rm_lay_date', true );
			$legacy_size = get_post_meta( $pairing_id, '_rm_clutch_size', true );
			if ( $legacy_lay || $legacy_size ) {
				$clutches[] = array(
					'lay_date' => $legacy_lay,
					'eggs'     => absint( $legacy_size ),
					'hatched'  => 0,
				);
			}
		}

		// Verpaarungs-weite Temperatur als Vorbelegung, falls pro Gelege keine gesetzt.
		$default_temp = get_post_meta( $pairing_id, '_rm_incubation_temp', true );

		$clean = array();
		foreach ( $clutches as $clutch ) {
			$temp = isset( $clutch['temp'] ) && '' !== $clutch['temp'] ? $clutch['temp'] : $default_temp;
			$clean[] = array(
				'lay_date'  => isset( $clutch['lay_date'] ) ? $clutch['lay_date'] : '',
				'eggs'      => isset( $clutch['eggs'] ) ? absint( $clutch['eggs'] ) : 0,
				'hatched'   => isset( $clutch['hatched'] ) ? absint( $clutch['hatched'] ) : 0,
				'temp'      => $temp,
				'infertile' => isset( $clutch['infertile'] ) ? absint( $clutch['infertile'] ) : 0,
				'died'      => isset( $clutch['died'] ) ? absint( $clutch['died'] ) : 0,
				'died_day'  => isset( $clutch['died_day'] ) ? absint( $clutch['died_day'] ) : 0,
			);
		}

		return $clean;
	}

	/**
	 * Schlupfquote eines Geleges (geschlüpft / Eier).
	 *
	 * @param array $clutch Gelege-Daten.
	 * @return float|null Anteil 0–1 oder null (keine Eier hinterlegt).
	 */
	public static function clutch_hatch_rate( $clutch ) {
		if ( empty( $clutch['eggs'] ) ) {
			return null;
		}
		return min( 1.0, $clutch['hatched'] / $clutch['eggs'] );
	}

	/**
	 * Durchschnittliche Schlupfquote über alle Gelege aller Verpaarungen
	 * (nur Gelege mit Eiern und mindestens einem Schlupf-Eintrag).
	 *
	 * @return float|null
	 */
	public static function average_hatch_rate() {
		$pairings = get_posts(
			array(
				'post_type'      => 'rm_pairing',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'fields'         => 'ids',
			)
		);

		$sum   = 0.0;
		$count = 0;
		foreach ( $pairings as $pid ) {
			foreach ( self::get_clutches( $pid ) as $clutch ) {
				if ( empty( $clutch['eggs'] ) || ( 0 === $clutch['hatched'] && 0 === $clutch['infertile'] && 0 === $clutch['died'] ) ) {
					continue; // Noch nicht ausgewertete Gelege überspringen.
				}
				$rate = self::clutch_hatch_rate( $clutch );
				if ( null !== $rate ) {
					$sum += $rate;
					$count++;
				}
			}
		}

		return $count ? $sum / $count : null;
	}

	/**
	 * Alle Verpaarungen, an denen ein Tier beteiligt ist.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return WP_Post[]
	 */
	public static function pairings_for_animal( $animal_id ) {
		return get_posts(
			array(
				'post_type'      => 'rm_pairing',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_rm_sire',
						'value' => $animal_id,
					),
					array(
						'key'   => '_rm_dam',
						'value' => $animal_id,
					),
				),
			)
		);
	}

	/**
	 * Nachzuchten einer Verpaarung (Tiere mit dieser Eltern-Verpaarung).
	 *
	 * @param int $pairing_id   Beitrags-ID der Verpaarung.
	 * @param int $clutch_index Optional: nur ein bestimmtes Gelege (1-basiert).
	 * @return WP_Post[]
	 */
	public static function offspring( $pairing_id, $clutch_index = 0 ) {
		$meta_query = array(
			array(
				'key'   => '_rm_parent_pairing',
				'value' => $pairing_id,
			),
		);

		if ( $clutch_index ) {
			$meta_query[] = array(
				'key'   => '_rm_clutch',
				'value' => $clutch_index,
			);
		}

		return get_posts(
			array(
				'post_type'      => 'rm_animal',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'orderby'        => 'title',
				'order'          => 'ASC',
				'meta_query'     => $meta_query,
			)
		);
	}

	/**
	 * Lesbare Bezeichnung einer Verpaarung ("Vater × Mutter").
	 *
	 * @param int $pairing_id Beitrags-ID der Verpaarung.
	 * @return string
	 */
	public static function pairing_label( $pairing_id ) {
		$sire = (int) get_post_meta( $pairing_id, '_rm_sire', true );
		$dam  = (int) get_post_meta( $pairing_id, '_rm_dam', true );

		$parts = array();
		if ( $sire ) {
			$parts[] = get_the_title( $sire );
		}
		if ( $dam ) {
			$parts[] = get_the_title( $dam );
		}

		if ( ! $parts ) {
			return get_the_title( $pairing_id );
		}

		$label = implode( ' × ', $parts );
		$date  = get_post_meta( $pairing_id, '_rm_pairing_date', true );
		if ( $date && strtotime( $date ) ) {
			$label .= ' (' . date_i18n( get_option( 'date_format' ), strtotime( $date ) ) . ')';
		}

		return $label;
	}

	/* ---------------------------------------------------------------------
	 * Meta-Boxen
	 * ------------------------------------------------------------------ */

	public static function render_details( $post ) {
		wp_nonce_field( 'rm_pairing_meta', 'rm_pairing_meta_nonce' );

		$sire       = (int) get_post_meta( $post->ID, '_rm_sire', true );
		$dam        = (int) get_post_meta( $post->ID, '_rm_dam', true );
		$date       = get_post_meta( $post->ID, '_rm_pairing_date', true );
		$incubation = get_post_meta( $post->ID, '_rm_incubation_temp', true );

		$males   = RM_Post_Types::get_animals( 'male' );
		$females = RM_Post_Types::get_animals( 'female' );
		?>
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
					<?php if ( ! $males ) : ?>
						<p class="description"><?php esc_html_e( 'Noch keine männlichen Tiere eingetragen.', 'reptilien-manager' ); ?></p>
					<?php endif; ?>
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
					<?php if ( ! $females ) : ?>
						<p class="description"><?php esc_html_e( 'Noch keine weiblichen Tiere eingetragen.', 'reptilien-manager' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_pairing_date"><?php esc_html_e( 'Verpaarungsdatum', 'reptilien-manager' ); ?></label></th>
				<td><input type="date" name="rm_pairing_date" id="rm_pairing_date" value="<?php echo esc_attr( $date ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="rm_incubation_temp"><?php esc_html_e( 'Inkubationstemperatur (°C)', 'reptilien-manager' ); ?></label></th>
				<td>
					<input type="number" step="0.1" min="0" name="rm_incubation_temp" id="rm_incubation_temp" value="<?php echo esc_attr( $incubation ); ?>" />
					<p class="description"><?php esc_html_e( 'Empfehlung für Bartagamen: 27–29 °C, Schlupf nach ca. 55–75 Tagen.', 'reptilien-manager' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Notizen zur Verpaarung (Verhalten, Trächtigkeit, Inkubator) können im Textfeld oben festgehalten werden.', 'reptilien-manager' ); ?></p>
		<?php
	}

	public static function render_clutches( $post ) {
		$clutches  = self::get_clutches( $post->ID );
		$offspring = self::offspring( $post->ID );
		$avg_rate  = self::average_hatch_rate();
		?>
		<p class="description">
			<?php esc_html_e( 'Pro Gelege: Ablagedatum, Eizahl, Inkubationstemperatur und – sobald es soweit ist – geschlüpfte, unbefruchtete und gestorbene Eier. Schlupf-Vorhersage und Schlupfquote werden nach dem Speichern angezeigt.', 'reptilien-manager' ); ?>
		</p>
		<table class="widefat rm-clutch-table" id="rm-clutch-table">
			<thead>
				<tr>
					<th style="width:34px"><?php esc_html_e( 'Nr.', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Ablage', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Eier', 'reptilien-manager' ); ?></th>
					<th title="<?php esc_attr_e( 'Inkubationstemperatur in °C', 'reptilien-manager' ); ?>">°C</th>
					<th><?php esc_html_e( 'Geschlüpft', 'reptilien-manager' ); ?></th>
					<th title="<?php esc_attr_e( 'unbefruchtete Eier', 'reptilien-manager' ); ?>"><?php esc_html_e( 'Unbefr.', 'reptilien-manager' ); ?></th>
					<th title="<?php esc_attr_e( 'abgestorbene Embryonen', 'reptilien-manager' ); ?>"><?php esc_html_e( 'Gestorben', 'reptilien-manager' ); ?></th>
					<th title="<?php esc_attr_e( 'Inkubationstag, an dem der Embryo abstarb', 'reptilien-manager' ); ?>"><?php esc_html_e( 'Tag †', 'reptilien-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $clutches as $i => $clutch ) : ?>
					<tr>
						<td class="rm-clutch-no"><?php echo esc_html( $i + 1 ); ?></td>
						<td><input type="date" name="rm_clutch_lay[]" value="<?php echo esc_attr( $clutch['lay_date'] ); ?>" /></td>
						<td><input type="number" min="0" class="rm-narrow" name="rm_clutch_eggs[]" value="<?php echo esc_attr( $clutch['eggs'] ); ?>" /></td>
						<td><input type="number" step="0.1" min="0" class="rm-narrow" name="rm_clutch_temp[]" value="<?php echo esc_attr( $clutch['temp'] ); ?>" /></td>
						<td><input type="number" min="0" class="rm-narrow" name="rm_clutch_hatched[]" value="<?php echo esc_attr( $clutch['hatched'] ); ?>" /></td>
						<td><input type="number" min="0" class="rm-narrow" name="rm_clutch_infertile[]" value="<?php echo esc_attr( $clutch['infertile'] ? $clutch['infertile'] : '' ); ?>" /></td>
						<td><input type="number" min="0" class="rm-narrow" name="rm_clutch_died[]" value="<?php echo esc_attr( $clutch['died'] ? $clutch['died'] : '' ); ?>" /></td>
						<td><input type="number" min="0" class="rm-narrow" name="rm_clutch_died_day[]" value="<?php echo esc_attr( $clutch['died_day'] ? $clutch['died_day'] : '' ); ?>" /></td>
						<td><button type="button" class="button rm-clutch-remove"><?php esc_html_e( 'Entf.', 'reptilien-manager' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="rm-clutch-add"><?php esc_html_e( 'Gelege hinzufügen', 'reptilien-manager' ); ?></button>
		</p>
		<p class="description">
			<?php esc_html_e( 'Beim Speichern werden für jedes Gelege automatisch so viele Nachzucht-Tiere (als Entwurf) angelegt, wie geschlüpft eingetragen ist – inklusive Verknüpfung zu dieser Verpaarung und Schlupfdatum.', 'reptilien-manager' ); ?>
		</p>

		<?php self::render_clutch_analytics( $clutches, $avg_rate ); ?>

		<?php if ( $offspring ) : ?>
			<h4><?php esc_html_e( 'Verknüpfte Nachzuchten', 'reptilien-manager' ); ?></h4>
			<ul class="rm-offspring-list">
				<?php foreach ( $offspring as $child ) : ?>
					<?php
					$clutch_no  = (int) get_post_meta( $child->ID, '_rm_clutch', true );
					$chip_label = $child->post_title;
					if ( $clutch_no ) {
						/* translators: %d: Gelege-Nummer */
						$chip_label .= ' · ' . sprintf( __( 'Gelege %d', 'reptilien-manager' ), $clutch_no );
					}
					?>
					<li>
						<a href="<?php echo esc_url( get_edit_post_link( $child->ID ) ); ?>"><?php echo esc_html( $chip_label ); ?></a>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php
	}

	/**
	 * Auswertung & Timeline pro Gelege (Schlupf-Vorhersage, Schlupfquote, Verluste).
	 *
	 * @param array[]    $clutches Gelege-Daten.
	 * @param float|null $avg_rate Durchschnittliche Schlupfquote im Bestand.
	 */
	private static function render_clutch_analytics( $clutches, $avg_rate ) {
		$has_any = false;
		foreach ( $clutches as $clutch ) {
			if ( $clutch['lay_date'] || $clutch['eggs'] ) {
				$has_any = true;
				break;
			}
		}
		if ( ! $has_any ) {
			return;
		}
		?>
		<h4><?php esc_html_e( 'Auswertung & Timeline', 'reptilien-manager' ); ?></h4>
		<div class="rm-clutch-analytics">
			<?php
			foreach ( $clutches as $i => $clutch ) :
				if ( ! $clutch['lay_date'] && ! $clutch['eggs'] ) {
					continue;
				}
				$predict = RM_Incubation::predict( $clutch['lay_date'], $clutch['temp'] );
				$rate    = self::clutch_hatch_rate( $clutch );
				?>
				<div class="rm-clutch-card">
					<div class="rm-clutch-card__head">
						<?php /* translators: %d: Gelege-Nummer */ ?>
						<strong><?php echo esc_html( sprintf( __( 'Gelege %d', 'reptilien-manager' ), $i + 1 ) ); ?></strong>
						<?php if ( $clutch['eggs'] ) : ?>
							<?php /* translators: %d: Anzahl Eier */ ?>
							<span class="rm-chip"><?php echo esc_html( sprintf( _n( '%d Ei', '%d Eier', $clutch['eggs'], 'reptilien-manager' ), $clutch['eggs'] ) ); ?></span>
						<?php endif; ?>
						<?php if ( '' !== $clutch['temp'] ) : ?>
							<span class="rm-chip"><?php echo esc_html( $clutch['temp'] . ' °C' ); ?></span>
						<?php endif; ?>
					</div>

					<?php if ( $predict ) : ?>
						<p class="rm-clutch-predict">🌡️ <?php echo esc_html( RM_Incubation::prediction_label( $clutch['lay_date'], $clutch['temp'] ) ); ?></p>
						<?php self::render_clutch_timeline( $clutch['lay_date'], $predict ); ?>
					<?php endif; ?>

					<?php if ( null !== $rate && ( $clutch['hatched'] || $clutch['infertile'] || $clutch['died'] ) ) : ?>
						<?php
						$pct       = round( $rate * 100 );
						$loss_bits = array();
						if ( $clutch['infertile'] ) {
							/* translators: %d: Anzahl unbefruchtet */
							$loss_bits[] = sprintf( __( '%d unbefruchtet', 'reptilien-manager' ), $clutch['infertile'] );
						}
						if ( $clutch['died'] ) {
							if ( $clutch['died_day'] ) {
								/* translators: 1: Anzahl gestorben, 2: Inkubationstag */
								$loss_bits[] = sprintf( __( '%1$d gestorben (Tag %2$d)', 'reptilien-manager' ), $clutch['died'], $clutch['died_day'] );
							} else {
								/* translators: %d: Anzahl gestorben */
								$loss_bits[] = sprintf( __( '%d gestorben', 'reptilien-manager' ), $clutch['died'] );
							}
						}

						$vs_class = '';
						$vs_text  = '';
						if ( null !== $avg_rate ) {
							$diff = $rate - $avg_rate;
							if ( $diff > 0.05 ) {
								$vs_class = 'rm-status--ok';
								/* translators: %d: Prozent */
								$vs_text = sprintf( __( '%d %% über Ø', 'reptilien-manager' ), round( $diff * 100 ) );
							} elseif ( $diff < -0.05 ) {
								$vs_class = 'rm-status--low';
								/* translators: %d: Prozent */
								$vs_text = sprintf( __( '%d %% unter Ø', 'reptilien-manager' ), round( abs( $diff ) * 100 ) );
							} else {
								$vs_class = 'rm-status--none';
								$vs_text  = __( 'im Ø', 'reptilien-manager' );
							}
						}
						?>
						<p class="rm-clutch-rate">
							<?php /* translators: 1: Schlupfquote Prozent, 2: geschlüpft, 3: Eier */ ?>
							🐣 <strong><?php echo esc_html( sprintf( __( 'Schlupfquote %1$d %% (%2$d/%3$d)', 'reptilien-manager' ), $pct, $clutch['hatched'], $clutch['eggs'] ) ); ?></strong>
							<?php if ( $vs_text ) : ?>
								<span class="rm-status <?php echo esc_attr( $vs_class ); ?>"><?php echo esc_html( $vs_text ); ?></span>
							<?php endif; ?>
							<?php if ( $loss_bits ) : ?>
								<br /><span class="description"><?php echo esc_html( implode( ' · ', $loss_bits ) ); ?></span>
							<?php endif; ?>
						</p>
					<?php endif; ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * CSS-Timeline von der Eiablage bis zum vorhergesagten Schlupffenster.
	 *
	 * @param string $lay_date Ablagedatum.
	 * @param array  $predict  Ergebnis von RM_Incubation::predict().
	 */
	private static function render_clutch_timeline( $lay_date, $predict ) {
		$lay_ts = strtotime( $lay_date );
		$min_ts = strtotime( $predict['min_date'] );
		$max_ts = strtotime( $predict['max_date'] );
		$now_ts = time();

		$span = max( 1, $max_ts - $lay_ts );

		// Positionen in Prozent.
		$window_start = ( $min_ts - $lay_ts ) / $span * 100;
		$window_start = max( 0, min( 100, $window_start ) );
		$progress     = ( $now_ts - $lay_ts ) / $span * 100;
		$progress     = max( 0, min( 100, $progress ) );

		$days_in = floor( ( $now_ts - $lay_ts ) / DAY_IN_SECONDS );
		$hatched_window = $now_ts >= $min_ts;
		?>
		<div class="rm-timeline" role="img" aria-label="<?php esc_attr_e( 'Inkubations-Timeline', 'reptilien-manager' ); ?>">
			<div class="rm-timeline__track">
				<div class="rm-timeline__window" style="left:<?php echo esc_attr( round( $window_start, 1 ) ); ?>%;right:0"></div>
				<div class="rm-timeline__progress" style="width:<?php echo esc_attr( round( $progress, 1 ) ); ?>%"></div>
				<div class="rm-timeline__today" style="left:<?php echo esc_attr( round( $progress, 1 ) ); ?>%"></div>
			</div>
			<div class="rm-timeline__labels">
				<span><?php echo esc_html( date_i18n( 'd.m.', $lay_ts ) ); ?></span>
				<span class="rm-timeline__mid">
					<?php
					if ( $days_in >= 0 && ! $hatched_window ) {
						/* translators: %d: Inkubationstage */
						echo esc_html( sprintf( __( 'Tag %d', 'reptilien-manager' ), $days_in ) );
					} elseif ( $hatched_window ) {
						esc_html_e( 'Schlupffenster', 'reptilien-manager' );
					}
					?>
				</span>
				<span><?php echo esc_html( date_i18n( 'd.m.', $max_ts ) ); ?></span>
			</div>
		</div>
		<?php
	}

	public static function render_genetics( $post ) {
		$sire = (int) get_post_meta( $post->ID, '_rm_sire', true );
		$dam  = (int) get_post_meta( $post->ID, '_rm_dam', true );

		if ( ! $sire || ! $dam ) {
			echo '<p>' . esc_html__( 'Bitte Vater und Mutter auswählen und die Verpaarung speichern – danach erscheint hier die Genetik-Vorschau der möglichen Jungtiere.', 'reptilien-manager' ) . '</p>';
			return;
		}

		printf(
			'<p><strong>%s</strong> %s × <strong>%s</strong> %s</p>',
			esc_html( get_the_title( $sire ) ),
			esc_html( '(' . RM_Genetics::animal_morph_label( $sire ) . ')' ),
			esc_html( get_the_title( $dam ) ),
			esc_html( '(' . RM_Genetics::animal_morph_label( $dam ) . ')' )
		);

		// Inzucht-Koeffizient des geplanten Nachwuchses.
		$coi     = RM_Breeding::pair_coi( $sire, $dam );
		$warning = RM_Breeding::coi_warning( $coi );
		printf(
			'<p class="rm-coi-line">🧬 <strong>%s %s</strong> <span class="rm-status rm-status--%s">%s</span></p>',
			esc_html__( 'Inzucht-Koeffizient (COI):', 'reptilien-manager' ),
			esc_html( RM_Breeding::format_coi( $coi ) ),
			esc_attr( $warning['status'] ),
			esc_html( $warning['label'] )
		);

		echo RM_Genetics::render_cross_result( $sire, $dam ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML wird intern escaped.

		self::render_expected_vs_actual( $post->ID );
	}

	/**
	 * Vergleicht die erwartete Morph-Verteilung mit den tatsächlich
	 * eingetragenen Nachzuchten (Lernfeedback).
	 *
	 * @param int $pairing_id Beitrags-ID der Verpaarung.
	 * @return array|null { rows, total } oder null.
	 */
	public static function expected_vs_actual( $pairing_id ) {
		$sire = (int) get_post_meta( $pairing_id, '_rm_sire', true );
		$dam  = (int) get_post_meta( $pairing_id, '_rm_dam', true );
		if ( ! $sire || ! $dam ) {
			return null;
		}

		$result   = RM_Genetics::cross_animals( $sire, $dam );
		$expected = array();
		foreach ( $result['combined'] as $row ) {
			$expected[ $row['label'] ] = $row['probability'];
		}

		$actual = array();
		$total  = 0;
		foreach ( self::offspring( $pairing_id ) as $child ) {
			$morph = RM_Genetics::animal_morph_label( $child->ID );
			$actual[ $morph ] = isset( $actual[ $morph ] ) ? $actual[ $morph ] + 1 : 1;
			$total++;
		}

		if ( ! $total ) {
			return null;
		}

		$labels = array_unique( array_merge( array_keys( $expected ), array_keys( $actual ) ) );
		$rows   = array();
		foreach ( $labels as $label ) {
			$exp = isset( $expected[ $label ] ) ? $expected[ $label ] : 0.0;
			$act = isset( $actual[ $label ] ) ? $actual[ $label ] : 0;
			$rows[] = array(
				'label'      => $label,
				'expected'   => $exp,
				'actual'     => $act,
				'actual_pct' => $total ? $act / $total : 0.0,
				'unexpected' => ! isset( $expected[ $label ] ),
			);
		}

		usort(
			$rows,
			static function ( $a, $b ) {
				return $b['expected'] <=> $a['expected'];
			}
		);

		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/**
	 * Rendert den Erwartet-vs-Tatsächlich-Vergleich in der Meta-Box.
	 *
	 * @param int $pairing_id Beitrags-ID der Verpaarung.
	 */
	private static function render_expected_vs_actual( $pairing_id ) {
		$data = self::expected_vs_actual( $pairing_id );
		if ( ! $data ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Erwartet vs. Tatsächlich', 'reptilien-manager' ); ?></h3>
		<p class="description">
			<?php
			printf(
				/* translators: %d: Anzahl Nachzuchten */
				esc_html__( 'Vergleich der genetischen Erwartung mit den %d eingetragenen Nachzuchten. Abweichungen sind bei kleinen Wurfgrößen normal.', 'reptilien-manager' ),
				(int) $data['total']
			);
			?>
		</p>
		<table class="widefat striped rm-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Morph', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Erwartet', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Tatsächlich', 'reptilien-manager' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $data['rows'] as $row ) : ?>
					<tr<?php echo $row['unexpected'] ? ' class="rm-row-unexpected"' : ''; ?>>
						<td>
							<?php echo esc_html( $row['label'] ); ?>
							<?php if ( $row['unexpected'] ) : ?>
								<span class="rm-status rm-status--low"><?php esc_html_e( 'unerwartet', 'reptilien-manager' ); ?></span>
							<?php endif; ?>
						</td>
						<td><?php echo esc_html( $row['expected'] > 0 ? RM_Genetics::format_percent( $row['expected'] ) : '—' ); ?></td>
						<td>
							<?php
							echo esc_html( $row['actual'] );
							if ( $row['actual'] > 0 ) {
								echo ' (' . esc_html( RM_Genetics::format_percent( $row['actual_pct'] ) ) . ')';
							}
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Speichern
	 * ------------------------------------------------------------------ */

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rm_pairing_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_pairing_meta_nonce'] ), 'rm_pairing_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( array( 'rm_sire' => '_rm_sire', 'rm_dam' => '_rm_dam' ) as $field => $meta_key ) {
			$value = isset( $_POST[ $field ] ) ? absint( $_POST[ $field ] ) : 0;
			update_post_meta( $post_id, $meta_key, $value ? $value : '' );
		}

		foreach ( array( 'rm_pairing_date' => '_rm_pairing_date', 'rm_incubation_temp' => '_rm_incubation_temp' ) as $field => $meta_key ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			update_post_meta( $post_id, $meta_key, $value );
		}

		// Gelege.
		$lays      = isset( $_POST['rm_clutch_lay'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['rm_clutch_lay'] ) ) : array();
		$eggs      = isset( $_POST['rm_clutch_eggs'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_clutch_eggs'] ) ) : array();
		$temps     = isset( $_POST['rm_clutch_temp'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['rm_clutch_temp'] ) ) : array();
		$hatched   = isset( $_POST['rm_clutch_hatched'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_clutch_hatched'] ) ) : array();
		$infertile = isset( $_POST['rm_clutch_infertile'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_clutch_infertile'] ) ) : array();
		$died      = isset( $_POST['rm_clutch_died'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_clutch_died'] ) ) : array();
		$died_day  = isset( $_POST['rm_clutch_died_day'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_clutch_died_day'] ) ) : array();

		$clutches = array();
		foreach ( $lays as $i => $lay ) {
			$e = isset( $eggs[ $i ] ) ? $eggs[ $i ] : 0;
			$h = isset( $hatched[ $i ] ) ? $hatched[ $i ] : 0;
			$t = isset( $temps[ $i ] ) ? $temps[ $i ] : '';
			$u = isset( $infertile[ $i ] ) ? $infertile[ $i ] : 0;
			$d = isset( $died[ $i ] ) ? $died[ $i ] : 0;
			if ( '' === $lay && ! $e && ! $h && ! $u && ! $d ) {
				continue;
			}
			// Temperatur auf einen plausiblen Bereich begrenzen.
			$temp_val = '' === $t ? '' : (string) max( 0, min( 45, (float) str_replace( ',', '.', $t ) ) );
			$clutches[] = array(
				'lay_date'  => $lay,
				'eggs'      => $e,
				'hatched'   => $h,
				'temp'      => $temp_val,
				'infertile' => $u,
				'died'      => $d,
				'died_day'  => isset( $died_day[ $i ] ) ? $died_day[ $i ] : 0,
			);
		}
		update_post_meta( $post_id, '_rm_clutches', $clutches );

		self::auto_create_offspring( $post_id, $clutches );
		self::maybe_autotitle( $post_id, $post->post_title );
	}

	/**
	 * Erzeugt einen sprechenden Titel ("Vater × Mutter"), falls keiner vergeben wurde.
	 *
	 * @param int    $post_id Beitrags-ID.
	 * @param string $title   Aktueller Titel.
	 */
	private static function maybe_autotitle( $post_id, $title ) {
		static $updating = false;

		if ( $updating || '' !== trim( $title ) ) {
			return;
		}

		$label = self::pairing_label( $post_id );
		if ( '' === $label ) {
			return;
		}

		$updating = true;
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $label,
			)
		);
		$updating = false;
	}

	/**
	 * Legt fehlende Nachzucht-Tiere für die Gelege automatisch als Entwurf an.
	 *
	 * @param int   $pairing_id Beitrags-ID der Verpaarung.
	 * @param array $clutches   Gespeicherte Gelege.
	 */
	private static function auto_create_offspring( $pairing_id, $clutches ) {
		$sire = (int) get_post_meta( $pairing_id, '_rm_sire', true );
		$dam  = (int) get_post_meta( $pairing_id, '_rm_dam', true );

		$sire_name = $sire ? get_the_title( $sire ) : __( 'Unbekannt', 'reptilien-manager' );
		$dam_name  = $dam ? get_the_title( $dam ) : __( 'Unbekannt', 'reptilien-manager' );

		foreach ( $clutches as $i => $clutch ) {
			$clutch_no = $i + 1;
			if ( $clutch['hatched'] < 1 ) {
				continue;
			}

			$existing = count( self::offspring( $pairing_id, $clutch_no ) );
			$missing  = $clutch['hatched'] - $existing;
			if ( $missing < 1 ) {
				continue;
			}

			$hatch_date = self::estimated_hatch( $clutch['lay_date'] );
			$year       = $clutch['lay_date'] && strtotime( $clutch['lay_date'] ) ? gmdate( 'Y', strtotime( $clutch['lay_date'] ) ) : gmdate( 'Y' );

			for ( $n = $existing + 1; $n <= $clutch['hatched']; $n++ ) {
				$title = sprintf(
					/* translators: 1: Jahr, 2: Gelege-Nummer, 3: laufende Nummer, 4: Vater, 5: Mutter */
					__( 'NZ %1$s G%2$d-%3$02d (%4$s × %5$s)', 'reptilien-manager' ),
					$year,
					$clutch_no,
					$n,
					$sire_name,
					$dam_name
				);

				$child_id = wp_insert_post(
					array(
						'post_type'   => 'rm_animal',
						'post_status' => 'draft',
						'post_title'  => $title,
					)
				);

				if ( is_wp_error( $child_id ) || ! $child_id ) {
					continue;
				}

				update_post_meta( $child_id, '_rm_parent_pairing', $pairing_id );
				update_post_meta( $child_id, '_rm_clutch', $clutch_no );
				update_post_meta( $child_id, '_rm_sex', 'unknown' );
				if ( $hatch_date ) {
					update_post_meta( $child_id, '_rm_birth', $hatch_date );
				}

				// Art vom Muttertier übernehmen.
				if ( $dam ) {
					$terms = wp_get_post_terms( $dam, 'rm_species', array( 'fields' => 'ids' ) );
					if ( ! is_wp_error( $terms ) && $terms ) {
						wp_set_object_terms( $child_id, $terms, 'rm_species' );
						RM_Species::forget_animal( $child_id );
					}
				}
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin-Spalten
	 * ------------------------------------------------------------------ */

	public static function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['rm_parents']   = __( 'Elterntiere', 'reptilien-manager' );
				$new['rm_date']      = __( 'Verpaarung', 'reptilien-manager' );
				$new['rm_clutches']  = __( 'Gelege', 'reptilien-manager' );
				$new['rm_offspring'] = __( 'Nachzuchten', 'reptilien-manager' );
			}
		}
		return $new;
	}

	public static function admin_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'rm_parents':
				$sire  = (int) get_post_meta( $post_id, '_rm_sire', true );
				$dam   = (int) get_post_meta( $post_id, '_rm_dam', true );
				$parts = array();
				if ( $sire ) {
					$parts[] = get_the_title( $sire );
				}
				if ( $dam ) {
					$parts[] = get_the_title( $dam );
				}
				echo esc_html( $parts ? implode( ' × ', $parts ) : '—' );
				break;
			case 'rm_date':
				$date = get_post_meta( $post_id, '_rm_pairing_date', true );
				echo esc_html( $date && strtotime( $date ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '—' );
				break;
			case 'rm_clutches':
				$clutches = self::get_clutches( $post_id );
				if ( ! $clutches ) {
					echo '—';
					break;
				}
				$eggs    = 0;
				$hatched = 0;
				foreach ( $clutches as $clutch ) {
					$eggs    += $clutch['eggs'];
					$hatched += $clutch['hatched'];
				}
				printf(
					/* translators: 1: Anzahl Gelege, 2: Eier gesamt, 3: geschlüpft gesamt */
					esc_html__( '%1$d Gelege · %2$d Eier · %3$d geschlüpft', 'reptilien-manager' ),
					count( $clutches ),
					(int) $eggs,
					(int) $hatched
				);
				break;
			case 'rm_offspring':
				echo esc_html( count( self::offspring( $post_id ) ) );
				break;
		}
	}
}
