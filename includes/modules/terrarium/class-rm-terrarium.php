<?php
/**
 * Terrarien-Verwaltung mit Besatz und Technik/Stromkalkulation.
 *
 * Datenmodell:
 * - CPT `rm_terrarium` mit Maßen, Standort, Bauart und Technik-Liste.
 * - Die Besatz-Zuordnung liegt bewusst am Tier (`_rm_terrarium`, eine ID):
 *   so kann ein Tier systembedingt nie in zwei Terrarien gleichzeitig
 *   stehen. Die Terrarium-Maske schreibt diese Zuordnung, liest sie aber
 *   auch über eine Meta-Query zurück.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Terrarium {

	const POST_TYPE = 'rm_terrarium';

	/** Meta am Tier: zugeordnetes Terrarium. */
	const ANIMAL_META = '_rm_terrarium';

	/** Options-Schlüssel für den Strompreis (€/kWh). */
	const PRICE_OPTION = 'rm_power_price';

	/** Standard-Strompreis in €/kWh, falls nichts hinterlegt ist. */
	const DEFAULT_PRICE = 0.35;

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'add_meta_boxes_' . self::POST_TYPE, array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
	}

	public static function register() {
		$labels = array(
			'name'               => __( 'Terrarien', 'reptilien-manager' ),
			'singular_name'      => __( 'Terrarium', 'reptilien-manager' ),
			'add_new'            => __( 'Terrarium hinzufügen', 'reptilien-manager' ),
			'add_new_item'       => __( 'Neues Terrarium anlegen', 'reptilien-manager' ),
			'edit_item'          => __( 'Terrarium bearbeiten', 'reptilien-manager' ),
			'search_items'       => __( 'Terrarien durchsuchen', 'reptilien-manager' ),
			'not_found'          => __( 'Keine Terrarien gefunden.', 'reptilien-manager' ),
			'all_items'          => __( 'Terrarien', 'reptilien-manager' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=rm_animal',
				'supports'        => array( 'title', 'author' ),
				'capability_type' => 'post',
				'map_meta_cap'    => true,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Stammdaten
	 * ------------------------------------------------------------------ */

	/**
	 * Bauarten.
	 *
	 * @return array<string,string>
	 */
	public static function build_types() {
		return array(
			'glas'      => __( 'Glas', 'reptilien-manager' ),
			'holz'      => __( 'Holz / OSB', 'reptilien-manager' ),
			'kunststoff' => __( 'Kunststoff / PVC', 'reptilien-manager' ),
			'sonstiges' => __( 'Sonstiges', 'reptilien-manager' ),
		);
	}

	/**
	 * Typische Technik-Vorlagen für neue Zeilen (Watt-Richtwerte).
	 *
	 * @return array[] Liste von { label, watt, hours }.
	 */
	public static function device_presets() {
		return array(
			array(
				'label' => __( 'Wärmespot / Halogen', 'reptilien-manager' ),
				'watt'  => 75,
				'hours' => 10,
			),
			array(
				'label' => __( 'UV-Metalldampflampe', 'reptilien-manager' ),
				'watt'  => 70,
				'hours' => 8,
			),
			array(
				'label' => __( 'T5-Leuchtstoffröhre', 'reptilien-manager' ),
				'watt'  => 39,
				'hours' => 12,
			),
			array(
				'label' => __( 'LED-Beleuchtung', 'reptilien-manager' ),
				'watt'  => 20,
				'hours' => 12,
			),
			array(
				'label' => __( 'Heizmatte / Bodenheizung', 'reptilien-manager' ),
				'watt'  => 25,
				'hours' => 24,
			),
		);
	}

	/**
	 * Technik-Liste eines Terrariums (bereinigt).
	 *
	 * @param int $terrarium_id Beitrags-ID.
	 * @return array[] Liste von { label, watt, hours, duty }.
	 */
	public static function devices( $terrarium_id ) {
		$raw = get_post_meta( $terrarium_id, '_rm_terr_devices', true );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$devices = array();
		foreach ( $raw as $device ) {
			$label = isset( $device['label'] ) ? (string) $device['label'] : '';
			$watt  = isset( $device['watt'] ) ? (float) $device['watt'] : 0.0;
			if ( '' === trim( $label ) && $watt <= 0 ) {
				continue;
			}
			$devices[] = array(
				'label' => $label,
				'watt'  => max( 0.0, $watt ),
				'hours' => min( 24.0, max( 0.0, isset( $device['hours'] ) ? (float) $device['hours'] : 0.0 ) ),
				// Einschaltdauer in Prozent: bildet Thermostat-/Dimmer-Betrieb ab,
				// bei dem das Gerät nur einen Teil der Zeit tatsächlich zieht.
				'duty'  => min( 100.0, max( 0.0, isset( $device['duty'] ) ? (float) $device['duty'] : 100.0 ) ),
			);
		}
		return $devices;
	}

	/* ---------------------------------------------------------------------
	 * Stromkalkulation
	 * ------------------------------------------------------------------ */

	/**
	 * Aktueller Strompreis in €/kWh.
	 *
	 * @return float
	 */
	public static function price_per_kwh() {
		$price = get_option( self::PRICE_OPTION, null );
		if ( null === $price || '' === $price ) {
			return self::DEFAULT_PRICE;
		}
		return max( 0.0, (float) $price );
	}

	/**
	 * Verbrauch und Kosten eines einzelnen Geräts.
	 *
	 * kWh/Tag = Watt × Stunden × Einschaltdauer / 1000
	 *
	 * @param array      $device Gerät { watt, hours, duty }.
	 * @param float|null $price  Preis je kWh; null = hinterlegter Preis.
	 * @return array { kwh_day, kwh_month, kwh_year, cost_month, cost_year }
	 */
	public static function device_consumption( $device, $price = null ) {
		$price = null === $price ? self::price_per_kwh() : max( 0.0, (float) $price );

		$watt  = isset( $device['watt'] ) ? max( 0.0, (float) $device['watt'] ) : 0.0;
		$hours = isset( $device['hours'] ) ? max( 0.0, (float) $device['hours'] ) : 0.0;
		$duty  = isset( $device['duty'] ) ? max( 0.0, (float) $device['duty'] ) : 100.0;

		$kwh_day = $watt * $hours * ( $duty / 100 ) / 1000;

		// 365,25/12 ≈ 30,44 Tage – über das Jahr genauer als pauschal 30.
		$kwh_month = $kwh_day * 30.44;
		$kwh_year  = $kwh_day * 365.25;

		return array(
			'kwh_day'    => $kwh_day,
			'kwh_month'  => $kwh_month,
			'kwh_year'   => $kwh_year,
			'cost_month' => $kwh_month * $price,
			'cost_year'  => $kwh_year * $price,
		);
	}

	/**
	 * Gesamtverbrauch eines Terrariums über alle Geräte.
	 *
	 * @param int        $terrarium_id Beitrags-ID.
	 * @param float|null $price        Preis je kWh; null = hinterlegter Preis.
	 * @return array { watt_installed, kwh_day, kwh_month, kwh_year, cost_month, cost_year, devices }
	 */
	public static function terrarium_consumption( $terrarium_id, $price = null ) {
		$devices = self::devices( $terrarium_id );

		$totals = array(
			'watt_installed' => 0.0,
			'kwh_day'        => 0.0,
			'kwh_month'      => 0.0,
			'kwh_year'       => 0.0,
			'cost_month'     => 0.0,
			'cost_year'      => 0.0,
			'devices'        => array(),
		);

		foreach ( $devices as $device ) {
			$consumption = self::device_consumption( $device, $price );

			$totals['watt_installed'] += $device['watt'];
			$totals['kwh_day']        += $consumption['kwh_day'];
			$totals['kwh_month']      += $consumption['kwh_month'];
			$totals['kwh_year']       += $consumption['kwh_year'];
			$totals['cost_month']     += $consumption['cost_month'];
			$totals['cost_year']      += $consumption['cost_year'];

			$totals['devices'][] = array_merge( $device, $consumption );
		}

		return $totals;
	}

	/* ---------------------------------------------------------------------
	 * Maße & Besatz
	 * ------------------------------------------------------------------ */

	/**
	 * Maße eines Terrariums.
	 *
	 * @param int $terrarium_id Beitrags-ID.
	 * @return array { length, width, height, volume_liters, floor_cm2 }
	 */
	public static function dimensions( $terrarium_id ) {
		$length = (float) get_post_meta( $terrarium_id, '_rm_terr_length', true );
		$width  = (float) get_post_meta( $terrarium_id, '_rm_terr_width', true );
		$height = (float) get_post_meta( $terrarium_id, '_rm_terr_height', true );

		return array(
			'length'        => $length,
			'width'         => $width,
			'height'        => $height,
			// cm³ -> Liter.
			'volume_liters' => ( $length * $width * $height ) / 1000,
			'floor_cm2'     => $length * $width,
		);
	}

	/**
	 * Besatz eines Terrariums (Tier-IDs).
	 *
	 * @param int $terrarium_id Beitrags-ID.
	 * @return int[]
	 */
	public static function occupants( $terrarium_id ) {
		$args = array(
			'post_type'      => 'rm_animal',
			'posts_per_page' => -1,
			'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
			'fields'         => 'ids',
			'orderby'        => 'title',
			'order'          => 'ASC',
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'   => self::ANIMAL_META,
					'value' => (int) $terrarium_id,
				),
			),
		);

		if ( class_exists( 'RM_Roles' ) ) {
			$args = array_merge( $args, RM_Roles::author_query_args() );
		}

		return array_map( 'absint', get_posts( $args ) );
	}

	/**
	 * Terrarium eines Tieres.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return int 0, wenn keines zugeordnet ist.
	 */
	public static function for_animal( $animal_id ) {
		return (int) get_post_meta( $animal_id, self::ANIMAL_META, true );
	}

	/**
	 * Empfohlene Mindestmaße (L × B × H in cm) je Art – gängige Richtwerte
	 * aus den deutschen Haltungsempfehlungen für adulte Tiere.
	 *
	 * @param string $species_key Art-Schlüssel.
	 * @return array{0:int,1:int,2:int}
	 */
	public static function minimum_size( $species_key ) {
		if ( 'iguana' === $species_key ) {
			// Grüne Leguane werden sehr groß; Richtwert für ein adultes Tier.
			return array( 300, 150, 200 );
		}
		// Bartagame (Pogona vitticeps).
		return array( 150, 80, 80 );
	}

	/**
	 * Prüft Besatz und Maße auf typische Haltungsprobleme.
	 *
	 * @param int $terrarium_id Beitrags-ID.
	 * @return array[] Liste von { level: warning|info, text }.
	 */
	public static function occupancy_warnings( $terrarium_id ) {
		$warnings  = array();
		$occupants = self::occupants( $terrarium_id );
		$dims      = self::dimensions( $terrarium_id );

		if ( ! $occupants ) {
			return $warnings;
		}

		// Bartagamen und Grüne Leguane sind Einzelgänger – Vergesellschaftung
		// führt regelmäßig zu Stress, Beißereien und Futterneid.
		if ( count( $occupants ) > 1 ) {
			$warnings[] = array(
				'level' => 'warning',
				'text'  => sprintf(
					/* translators: %d: Anzahl Tiere */
					__( '%d Tiere in einem Terrarium: Bartagamen und Grüne Leguane sind Einzelgänger. Dauerhafte Vergesellschaftung führt zu Stress und Verletzungen.', 'reptilien-manager' ),
					count( $occupants )
				),
			);
		}

		if ( $dims['length'] <= 0 || $dims['width'] <= 0 || $dims['height'] <= 0 ) {
			$warnings[] = array(
				'level' => 'info',
				'text'  => __( 'Maße unvollständig – ohne Länge, Breite und Höhe lässt sich die Mindestgröße nicht prüfen.', 'reptilien-manager' ),
			);
			return $warnings;
		}

		foreach ( $occupants as $animal_id ) {
			$species = class_exists( 'RM_Species' ) ? RM_Species::key_for_animal( $animal_id ) : 'pogona';
			list( $min_l, $min_w, $min_h ) = self::minimum_size( $species );

			if ( $dims['length'] < $min_l || $dims['width'] < $min_w || $dims['height'] < $min_h ) {
				$warnings[] = array(
					'level' => 'warning',
					'text'  => sprintf(
						/* translators: 1: Tiername, 2: Mindestmaße */
						__( '%1$s: Das Terrarium unterschreitet die empfohlenen Mindestmaße von %2$s (L × B × H) für ein adultes Tier dieser Art.', 'reptilien-manager' ),
						get_the_title( $animal_id ),
						$min_l . ' × ' . $min_w . ' × ' . $min_h . ' cm'
					),
				);
				// Nur einmal je Terrarium melden, sonst wiederholt sich der
				// Hinweis bei mehreren Tieren derselben Art.
				break;
			}
		}

		return $warnings;
	}

	/* ---------------------------------------------------------------------
	 * Meta-Box
	 * ------------------------------------------------------------------ */

	public static function add_meta_boxes() {
		add_meta_box( 'rm-terr-details', __( 'Terrarium-Daten', 'reptilien-manager' ), array( __CLASS__, 'render_details' ), self::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'rm-terr-devices', __( 'Technik & Stromverbrauch', 'reptilien-manager' ), array( __CLASS__, 'render_devices' ), self::POST_TYPE, 'normal', 'default' );
		add_meta_box( 'rm-terr-occupants', __( 'Besatz', 'reptilien-manager' ), array( __CLASS__, 'render_occupants' ), self::POST_TYPE, 'side', 'default' );
	}

	/**
	 * Stammdaten-Felder.
	 *
	 * @param WP_Post $post Beitrag.
	 */
	public static function render_details( $post ) {
		wp_nonce_field( 'rm_terrarium_meta', 'rm_terrarium_nonce' );

		$length = get_post_meta( $post->ID, '_rm_terr_length', true );
		$width  = get_post_meta( $post->ID, '_rm_terr_width', true );
		$height = get_post_meta( $post->ID, '_rm_terr_height', true );
		$type   = get_post_meta( $post->ID, '_rm_terr_type', true );
		$room   = get_post_meta( $post->ID, '_rm_terr_room', true );
		$notes  = get_post_meta( $post->ID, '_rm_terr_notes', true );

		$dims = self::dimensions( $post->ID );
		?>
		<table class="form-table rm-form-table">
			<tr>
				<th><?php esc_html_e( 'Maße (cm)', 'reptilien-manager' ); ?></th>
				<td>
					<label>
						<?php esc_html_e( 'Länge', 'reptilien-manager' ); ?>
						<input type="number" step="1" min="0" name="rm_terr_length" value="<?php echo esc_attr( $length ); ?>" class="small-text" />
					</label>
					&times;
					<label>
						<?php esc_html_e( 'Breite', 'reptilien-manager' ); ?>
						<input type="number" step="1" min="0" name="rm_terr_width" value="<?php echo esc_attr( $width ); ?>" class="small-text" />
					</label>
					&times;
					<label>
						<?php esc_html_e( 'Höhe', 'reptilien-manager' ); ?>
						<input type="number" step="1" min="0" name="rm_terr_height" value="<?php echo esc_attr( $height ); ?>" class="small-text" />
					</label>
					<?php if ( $dims['volume_liters'] > 0 ) : ?>
						<p class="description">
							<?php
							printf(
								/* translators: 1: Volumen in Litern, 2: Grundfläche in cm² */
								esc_html__( 'Volumen: %1$s Liter · Grundfläche: %2$s cm²', 'reptilien-manager' ),
								esc_html( number_format_i18n( $dims['volume_liters'], 0 ) ),
								esc_html( number_format_i18n( $dims['floor_cm2'], 0 ) )
							);
							?>
						</p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_terr_type"><?php esc_html_e( 'Bauart', 'reptilien-manager' ); ?></label></th>
				<td>
					<select name="rm_terr_type" id="rm_terr_type">
						<option value=""><?php esc_html_e( '– keine Angabe –', 'reptilien-manager' ); ?></option>
						<?php foreach ( self::build_types() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_terr_room"><?php esc_html_e( 'Standort / Raum', 'reptilien-manager' ); ?></label></th>
				<td><input type="text" name="rm_terr_room" id="rm_terr_room" class="regular-text" value="<?php echo esc_attr( $room ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="rm_terr_notes"><?php esc_html_e( 'Notizen', 'reptilien-manager' ); ?></label></th>
				<td><textarea name="rm_terr_notes" id="rm_terr_notes" rows="3" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Technik-Liste mit Verbrauchsvorschau.
	 *
	 * @param WP_Post $post Beitrag.
	 */
	public static function render_devices( $post ) {
		$devices = self::devices( $post->ID );
		$totals  = self::terrarium_consumption( $post->ID );
		$price   = self::price_per_kwh();
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Strompreis */
				esc_html__( 'Gerechnet wird mit %s €/kWh (änderbar unter „Terrarien & Strom“). Die Einschaltdauer bildet Thermostat- oder Dimmer-Betrieb ab: 50 %% bedeutet, das Gerät zieht nur die halbe Zeit Strom.', 'reptilien-manager' ),
				esc_html( number_format_i18n( $price, 2 ) )
			);
			?>
		</p>

		<table class="widefat rm-table rm-device-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Gerät', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Watt', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Std./Tag', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Einschaltdauer %', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'kWh/Monat', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( '€/Monat', 'reptilien-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody id="rm-device-rows">
				<?php
				$rows = $devices ? $totals['devices'] : array();
				foreach ( $rows as $device ) :
					?>
					<tr class="rm-device-row">
						<td><input type="text" name="rm_device_label[]" value="<?php echo esc_attr( $device['label'] ); ?>" class="regular-text" /></td>
						<td><input type="number" step="0.1" min="0" name="rm_device_watt[]" value="<?php echo esc_attr( $device['watt'] ); ?>" class="small-text" /></td>
						<td><input type="number" step="0.5" min="0" max="24" name="rm_device_hours[]" value="<?php echo esc_attr( $device['hours'] ); ?>" class="small-text" /></td>
						<td><input type="number" step="1" min="0" max="100" name="rm_device_duty[]" value="<?php echo esc_attr( $device['duty'] ); ?>" class="small-text" /></td>
						<td><?php echo esc_html( number_format_i18n( $device['kwh_month'], 1 ) ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $device['cost_month'], 2 ) ); ?></td>
						<td><button type="button" class="button-link rm-device-remove" aria-label="<?php esc_attr_e( 'Zeile entfernen', 'reptilien-manager' ); ?>">&times;</button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<p>
			<button type="button" class="button" id="rm-device-add"><?php esc_html_e( '+ Gerät hinzufügen', 'reptilien-manager' ); ?></button>
			<select id="rm-device-preset">
				<option value=""><?php esc_html_e( '– Vorlage wählen –', 'reptilien-manager' ); ?></option>
				<?php foreach ( self::device_presets() as $i => $preset ) : ?>
					<option
						value="<?php echo esc_attr( $i ); ?>"
						data-label="<?php echo esc_attr( $preset['label'] ); ?>"
						data-watt="<?php echo esc_attr( $preset['watt'] ); ?>"
						data-hours="<?php echo esc_attr( $preset['hours'] ); ?>"
					><?php echo esc_html( $preset['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>

		<?php if ( $devices ) : ?>
			<div class="rm-power-summary">
				<span>
					<strong><?php echo esc_html( number_format_i18n( $totals['watt_installed'], 0 ) ); ?> W</strong>
					<?php esc_html_e( 'installiert', 'reptilien-manager' ); ?>
				</span>
				<span>
					<strong><?php echo esc_html( number_format_i18n( $totals['kwh_month'], 1 ) ); ?> kWh</strong>
					<?php esc_html_e( 'pro Monat', 'reptilien-manager' ); ?>
				</span>
				<span>
					<strong><?php echo esc_html( number_format_i18n( $totals['cost_month'], 2 ) ); ?> €</strong>
					<?php esc_html_e( 'pro Monat', 'reptilien-manager' ); ?>
				</span>
				<span>
					<strong><?php echo esc_html( number_format_i18n( $totals['cost_year'], 2 ) ); ?> €</strong>
					<?php esc_html_e( 'pro Jahr', 'reptilien-manager' ); ?>
				</span>
			</div>
			<p class="description"><?php esc_html_e( 'Die Werte aktualisieren sich nach dem Speichern.', 'reptilien-manager' ); ?></p>
		<?php endif; ?>
		<?php
	}

	/**
	 * Besatz-Auswahl.
	 *
	 * @param WP_Post $post Beitrag.
	 */
	public static function render_occupants( $post ) {
		$animals = RM_Post_Types::get_animals();
		$current = self::occupants( $post->ID );

		if ( ! $animals ) {
			echo '<p class="description">' . esc_html__( 'Noch keine Tiere eingetragen.', 'reptilien-manager' ) . '</p>';
			return;
		}
		?>
		<p class="description"><?php esc_html_e( 'Ein Tier kann immer nur in einem Terrarium stehen – die Auswahl hier zieht es automatisch aus einem anderen Terrarium ab.', 'reptilien-manager' ); ?></p>
		<?php // Marker, damit das Abwählen *aller* Tiere beim Speichern ankommt. ?>
		<input type="hidden" name="rm_terr_occupants_present" value="1" />
		<div class="rm-choice-group">
			<div class="rm-choice-grid">
				<?php
				foreach ( $animals as $animal ) :
					$other = self::for_animal( $animal->ID );
					$busy  = $other && $other !== (int) $post->ID;
					?>
					<label class="rm-choice">
						<input type="checkbox" name="rm_terr_animals[]" value="<?php echo esc_attr( $animal->ID ); ?>" <?php checked( in_array( (int) $animal->ID, $current, true ) ); ?> />
						<?php echo esc_html( $animal->post_title ); ?>
						<?php if ( $busy ) : ?>
							<em class="rm-occupant-hint">(<?php echo esc_html( get_the_title( $other ) ); ?>)</em>
						<?php endif; ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>

		<?php
		$warnings = self::occupancy_warnings( $post->ID );
		if ( $warnings ) :
			?>
			<div class="rm-occupancy-warnings">
				<?php foreach ( $warnings as $warning ) : ?>
					<p class="rm-status rm-status--<?php echo 'warning' === $warning['level'] ? 'high' : 'none'; ?>">
						<?php echo esc_html( $warning['text'] ); ?>
					</p>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Speichern
	 * ------------------------------------------------------------------ */

	/**
	 * Speichert Terrarium-Daten, Technik und Besatz.
	 *
	 * @param int     $post_id Beitrags-ID.
	 * @param WP_Post $post    Beitrag.
	 */
	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rm_terrarium_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_terrarium_nonce'] ), 'rm_terrarium_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		foreach ( array( 'length', 'width', 'height' ) as $dim ) {
			$value = isset( $_POST[ 'rm_terr_' . $dim ] ) ? (float) $_POST[ 'rm_terr_' . $dim ] : 0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash
			update_post_meta( $post_id, '_rm_terr_' . $dim, max( 0, $value ) );
		}

		$type = isset( $_POST['rm_terr_type'] ) ? sanitize_key( $_POST['rm_terr_type'] ) : '';
		update_post_meta( $post_id, '_rm_terr_type', array_key_exists( $type, self::build_types() ) ? $type : '' );

		update_post_meta( $post_id, '_rm_terr_room', isset( $_POST['rm_terr_room'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_terr_room'] ) ) : '' );
		update_post_meta( $post_id, '_rm_terr_notes', isset( $_POST['rm_terr_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_terr_notes'] ) ) : '' );

		self::save_devices( $post_id );
		self::save_occupants( $post_id );
	}

	/**
	 * Technik-Zeilen übernehmen.
	 *
	 * @param int $post_id Beitrags-ID.
	 */
	private static function save_devices( $post_id ) {
		$labels = isset( $_POST['rm_device_label'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['rm_device_label'] ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in save() geprüft.
		$watts  = isset( $_POST['rm_device_watt'] ) ? (array) $_POST['rm_device_watt'] : array(); // phpcs:ignore
		$hours  = isset( $_POST['rm_device_hours'] ) ? (array) $_POST['rm_device_hours'] : array(); // phpcs:ignore
		$duties = isset( $_POST['rm_device_duty'] ) ? (array) $_POST['rm_device_duty'] : array(); // phpcs:ignore

		$devices = array();
		foreach ( $labels as $i => $label ) {
			$watt = isset( $watts[ $i ] ) ? (float) $watts[ $i ] : 0.0;

			// Leere Zeilen (weder Bezeichnung noch Leistung) verwerfen.
			if ( '' === trim( $label ) && $watt <= 0 ) {
				continue;
			}

			$devices[] = array(
				'label' => $label,
				'watt'  => max( 0.0, $watt ),
				'hours' => min( 24.0, max( 0.0, isset( $hours[ $i ] ) ? (float) $hours[ $i ] : 0.0 ) ),
				'duty'  => min( 100.0, max( 0.0, isset( $duties[ $i ] ) ? (float) $duties[ $i ] : 100.0 ) ),
			);
		}

		update_post_meta( $post_id, '_rm_terr_devices', $devices );
	}

	/**
	 * Besatz übernehmen: gewählte Tiere diesem Terrarium zuordnen, zuvor
	 * zugeordnete und nun abgewählte Tiere freigeben.
	 *
	 * @param int $post_id Beitrags-ID.
	 */
	private static function save_occupants( $post_id ) {
		// Ohne das Feld (z. B. wenn keine Tiere existieren) nichts ändern.
		if ( ! isset( $_POST['rm_terr_animals'] ) && ! isset( $_POST['rm_terr_occupants_present'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- in save() geprüft.
			return;
		}

		$selected = isset( $_POST['rm_terr_animals'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_terr_animals'] ) ) : array(); // phpcs:ignore
		$selected = array_values( array_unique( array_filter( $selected ) ) );

		$previous = self::occupants( $post_id );

		// Abgewählte Tiere freigeben.
		foreach ( array_diff( $previous, $selected ) as $animal_id ) {
			delete_post_meta( $animal_id, self::ANIMAL_META );
		}

		// Neu gewählte Tiere zuordnen – nur eigene Tiere, damit über die
		// Terrarium-Maske keine fremden Tiere umgehängt werden können.
		foreach ( $selected as $animal_id ) {
			$animal = get_post( $animal_id );
			if ( ! $animal || 'rm_animal' !== $animal->post_type ) {
				continue;
			}
			if ( (int) $animal->post_author !== get_current_user_id() && ! current_user_can( 'edit_others_posts' ) ) {
				continue;
			}
			update_post_meta( $animal_id, self::ANIMAL_META, (int) $post_id );
		}
	}

	/* ---------------------------------------------------------------------
	 * Admin-Spalten
	 * ------------------------------------------------------------------ */

	/**
	 * Spalten der Terrarien-Liste.
	 *
	 * @param array $columns Standard-Spalten.
	 * @return array
	 */
	public static function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['rm_terr_size']  = __( 'Maße', 'reptilien-manager' );
				$new['rm_terr_besatz'] = __( 'Besatz', 'reptilien-manager' );
				$new['rm_terr_power'] = __( 'Strom/Monat', 'reptilien-manager' );
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
			case 'rm_terr_size':
				$d = self::dimensions( $post_id );
				echo $d['length'] && $d['width'] && $d['height']
					? esc_html( sprintf( '%d × %d × %d cm', $d['length'], $d['width'], $d['height'] ) )
					: '–';
				break;

			case 'rm_terr_besatz':
				$names = array();
				foreach ( self::occupants( $post_id ) as $animal_id ) {
					$names[] = get_the_title( $animal_id );
				}
				echo esc_html( $names ? implode( ', ', $names ) : '–' );
				break;

			case 'rm_terr_power':
				$totals = self::terrarium_consumption( $post_id );
				echo $totals['cost_month'] > 0
					? esc_html( number_format_i18n( $totals['cost_month'], 2 ) . ' € (' . number_format_i18n( $totals['kwh_month'], 1 ) . ' kWh)' )
					: '–';
				break;
		}
	}
}
