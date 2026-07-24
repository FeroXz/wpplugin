<?php
/**
 * Futterplanung, Fütterungsprotokoll und Fütterungs-Auswertung –
 * artspezifisch (Bartagame: Allesfresser, Grüner Leguan: Pflanzenfresser).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Feeding {

	/**
	 * Zeitraum der Auswertung in Tagen.
	 */
	const ANALYSIS_DAYS = 14;

	public static function init() {
		add_action( 'add_meta_boxes_rm_feeding_log', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_rm_feeding_log', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_rm_feeding_log_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_rm_feeding_log_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
		add_action( 'admin_post_rm_quick_feeding', array( __CLASS__, 'handle_quick_feeding' ) );
	}

	/* ---------------------------------------------------------------------
	 * Stammdaten
	 * ------------------------------------------------------------------ */

	/**
	 * Futterarten für Bartagamen.
	 *
	 * @return array
	 */
	public static function food_types() {
		return array(
			'heimchen'      => __( 'Heimchen', 'reptilien-manager' ),
			'grillen'       => __( 'Grillen / Steppengrillen', 'reptilien-manager' ),
			'heuschrecken'  => __( 'Wüstenheuschrecken', 'reptilien-manager' ),
			'schaben'       => __( 'Schaben (z. B. Schokoschaben)', 'reptilien-manager' ),
			'zophobas'      => __( 'Zophobas (Leckerbissen)', 'reptilien-manager' ),
			'mehlwuermer'   => __( 'Mehlwürmer (Leckerbissen)', 'reptilien-manager' ),
			'gruenfutter'   => __( 'Grünfutter / Wildkräuter / Salate', 'reptilien-manager' ),
			'gemuese'       => __( 'Gemüse', 'reptilien-manager' ),
			'obst'          => __( 'Obst (selten)', 'reptilien-manager' ),
			'sonstiges'     => __( 'Sonstiges', 'reptilien-manager' ),
		);
	}

	/**
	 * Futterarten, die als Insekten/tierisch zählen.
	 *
	 * @return string[]
	 */
	public static function insect_keys() {
		return array( 'heimchen', 'grillen', 'heuschrecken', 'schaben', 'zophobas', 'mehlwuermer' );
	}

	/**
	 * Futterarten, die als pflanzlich/Grünfutter zählen.
	 *
	 * @return string[]
	 */
	public static function plant_keys() {
		return array( 'gruenfutter', 'gemuese', 'obst' );
	}

	/**
	 * Supplemente.
	 *
	 * @return array
	 */
	public static function supplements() {
		return array(
			'calcium'    => __( 'Calcium (ohne D3)', 'reptilien-manager' ),
			'calcium_d3' => __( 'Calcium + D3', 'reptilien-manager' ),
			'vitamine'   => __( 'Vitaminpräparat', 'reptilien-manager' ),
		);
	}

	/**
	 * Altersgerechter Futterplan (Beschreibungstexte) je Art.
	 *
	 * @param int|null $months  Alter in Monaten oder null.
	 * @param string   $species Art-Schlüssel.
	 * @return array { group, insects, greens, supplements }
	 */
	public static function plan_for_age( $months, $species = 'pogona' ) {
		if ( null === $months ) {
			return array(
				'group'       => __( 'Unbekanntes Alter', 'reptilien-manager' ),
				'insects'     => __( 'Bitte Schlupfdatum beim Tier hinterlegen, um eine Empfehlung zu erhalten.', 'reptilien-manager' ),
				'greens'      => __( 'Grünfutter täglich anbieten.', 'reptilien-manager' ),
				'supplements' => __( 'Calcium regelmäßig, D3 nach UV-Versorgung dosieren.', 'reptilien-manager' ),
			);
		}

		if ( 'iguana' === $species ) {
			return self::plan_iguana( $months );
		}

		return self::plan_pogona( $months );
	}

	/**
	 * Futterplan Bartagame (Pogona vitticeps) – Allesfresser.
	 *
	 * @param int $months Alter in Monaten.
	 * @return array
	 */
	private static function plan_pogona( $months ) {
		if ( $months < 6 ) {
			return array(
				'group'       => __( 'Jungtier (0–6 Monate)', 'reptilien-manager' ),
				'insects'     => __( 'Insekten 2–3× täglich, so viel wie in 10–15 Minuten gefressen wird (kleine Heimchen/Grillen).', 'reptilien-manager' ),
				'greens'      => __( 'Frisches Grünfutter täglich anbieten, auch wenn wenig angenommen wird.', 'reptilien-manager' ),
				'supplements' => __( 'Calcium an 5–6 Tagen/Woche, Calcium+D3 1×/Woche (je nach UV-Versorgung), Vitamine 1×/Woche.', 'reptilien-manager' ),
			);
		}

		if ( $months < 12 ) {
			return array(
				'group'       => __( 'Heranwachsend (6–12 Monate)', 'reptilien-manager' ),
				'insects'     => __( 'Insekten 1× täglich in angepasster Größe.', 'reptilien-manager' ),
				'greens'      => __( 'Grünfutter täglich – Anteil pflanzlicher Kost schrittweise erhöhen (Ziel ≈ 50 %).', 'reptilien-manager' ),
				'supplements' => __( 'Calcium an 4–5 Tagen/Woche, Calcium+D3 1×/Woche, Vitamine 1×/Woche.', 'reptilien-manager' ),
			);
		}

		if ( $months < 18 ) {
			return array(
				'group'       => __( 'Subadult (12–18 Monate)', 'reptilien-manager' ),
				'insects'     => __( 'Insekten jeden 2. Tag; Zophobas/Mehlwürmer nur als Leckerbissen.', 'reptilien-manager' ),
				'greens'      => __( 'Grünfutter täglich, pflanzlicher Anteil ≈ 60–70 %.', 'reptilien-manager' ),
				'supplements' => __( 'Calcium an 3–4 Tagen/Woche, Calcium+D3 alle 1–2 Wochen, Vitamine 1×/Woche.', 'reptilien-manager' ),
			);
		}

		return array(
			'group'       => __( 'Adult (ab 18 Monaten)', 'reptilien-manager' ),
			'insects'     => __( 'Insekten nur noch 2–3×/Woche – Verfettung vermeiden.', 'reptilien-manager' ),
			'greens'      => __( 'Grünfutter/Wildkräuter täglich frisch, pflanzlicher Anteil ≈ 80 %.', 'reptilien-manager' ),
			'supplements' => __( 'Calcium 2–3×/Woche, Calcium+D3 alle 2 Wochen, Vitamine 1×/Woche. Winterruhe berücksichtigen.', 'reptilien-manager' ),
		);
	}

	/**
	 * Futterplan Grüner Leguan (Iguana iguana) – strikter Pflanzenfresser.
	 *
	 * Wichtig: Tierisches Eiweiß dauerhaft meiden – zu viel Protein führt zu
	 * Nierenschäden und Gicht.
	 *
	 * @param int $months Alter in Monaten.
	 * @return array
	 */
	private static function plan_iguana( $months ) {
		if ( $months < 12 ) {
			return array(
				'group'       => __( 'Jungtier (0–12 Monate)', 'reptilien-manager' ),
				'insects'     => __( 'Reiner Pflanzenfresser – kein tierisches Eiweiß nötig. Täglich 1–2× fein gehackte Blattgrün-Mischung frisch anbieten.', 'reptilien-manager' ),
				'greens'      => __( 'Basis: kalziumreiches Blattgrün (Grünkohl, Löwenzahn, Endivie, Mangold, Brennnessel), dazu geraspeltes Gemüse; Obst nur sparsam.', 'reptilien-manager' ),
				'supplements' => __( 'Calcium an 5–6 Tagen/Woche, Calcium+D3 2×/Woche (bei UVB), Vitamine 1×/Woche – wichtig gegen Metabolische Knochenerkrankung (MBD).', 'reptilien-manager' ),
			);
		}

		if ( $months < 36 ) {
			return array(
				'group'       => __( 'Heranwachsend (12–36 Monate)', 'reptilien-manager' ),
				'insects'     => __( 'Weiterhin rein pflanzlich – kein Tierprotein.', 'reptilien-manager' ),
				'greens'      => __( 'Täglich frische Blattgrün-Mischung (≈ 80–90 %), etwas Gemüse, Obst nur als Leckerbissen.', 'reptilien-manager' ),
				'supplements' => __( 'Calcium 3–4×/Woche, Calcium+D3 1×/Woche (bei UVB), Vitamine 1×/Woche.', 'reptilien-manager' ),
			);
		}

		return array(
			'group'       => __( 'Adult (ab 36 Monaten)', 'reptilien-manager' ),
			'insects'     => __( 'Strikt pflanzlich – tierisches Eiweiß dauerhaft meiden (Gicht- und Nierenschäden).', 'reptilien-manager' ),
			'greens'      => __( 'Täglich frische Blattgrün-Mischung (≈ 80–90 %), Gemüse, wenig Obst; kalziumreiche Grünkost bevorzugen, oxalatreiche (z. B. Spinat) meiden.', 'reptilien-manager' ),
			'supplements' => __( 'Calcium 2–3×/Woche, Calcium+D3 alle 1–2 Wochen (bei UVB), Vitamine 1×/Woche.', 'reptilien-manager' ),
		);
	}

	/**
	 * Ziel-Frequenzen (Fütterungen pro Woche) je Altersgruppe und Art.
	 *
	 * @param int|null $months  Alter in Monaten oder null.
	 * @param string   $species Art-Schlüssel.
	 * @return array|null Kategorien insects/greens/calcium mit [min, max] pro Woche.
	 */
	public static function targets_for_age( $months, $species = 'pogona' ) {
		if ( null === $months ) {
			return null;
		}

		if ( 'iguana' === $species ) {
			// Pflanzenfresser: Insekten-Ziel 0 (jede Insektenfütterung = zu viel).
			if ( $months < 12 ) {
				return array(
					'insects' => array( 0, 0 ),
					'greens'  => array( 7, 14 ),
					'calcium' => array( 5, 6 ),
				);
			}
			if ( $months < 36 ) {
				return array(
					'insects' => array( 0, 0 ),
					'greens'  => array( 7, 7 ),
					'calcium' => array( 3, 4 ),
				);
			}
			return array(
				'insects' => array( 0, 0 ),
				'greens'  => array( 7, 7 ),
				'calcium' => array( 2, 3 ),
			);
		}

		// Bartagame (Allesfresser).
		if ( $months < 6 ) {
			return array(
				'insects' => array( 7, 21 ),
				'greens'  => array( 5, 7 ),
				'calcium' => array( 5, 6 ),
			);
		}

		if ( $months < 12 ) {
			return array(
				'insects' => array( 5, 7 ),
				'greens'  => array( 6, 7 ),
				'calcium' => array( 4, 5 ),
			);
		}

		if ( $months < 18 ) {
			return array(
				'insects' => array( 3, 4 ),
				'greens'  => array( 6, 7 ),
				'calcium' => array( 3, 4 ),
			);
		}

		return array(
			'insects' => array( 2, 3 ),
			'greens'  => array( 5, 7 ),
			'calcium' => array( 2, 3 ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Protokoll-Daten
	 * ------------------------------------------------------------------ */

	/**
	 * Tiere eines Fütterungs-Eintrags (Mehrfach-Meta, abwärtskompatibel).
	 *
	 * @param int $log_id Beitrags-ID des Eintrags.
	 * @return int[]
	 */
	public static function animals_for_log( $log_id ) {
		$values = get_post_meta( $log_id, '_rm_feed_animal' );
		return array_values( array_filter( array_map( 'absint', (array) $values ) ) );
	}

	/**
	 * Futterarten eines Eintrags (Array, abwärtskompatibel zum alten Einzelfeld).
	 *
	 * @param int $log_id Beitrags-ID des Eintrags.
	 * @return string[]
	 */
	public static function foods_for_log( $log_id ) {
		$foods = get_post_meta( $log_id, '_rm_feed_foods', true );
		if ( is_array( $foods ) && $foods ) {
			return array_values( array_intersect( $foods, array_keys( self::food_types() ) ) );
		}

		$legacy = get_post_meta( $log_id, '_rm_feed_food', true );
		if ( $legacy && array_key_exists( $legacy, self::food_types() ) ) {
			return array( $legacy );
		}

		return array();
	}

	/**
	 * Beschriftungen der Futterarten eines Eintrags.
	 *
	 * @param int $log_id Beitrags-ID.
	 * @return string
	 */
	public static function foods_label( $log_id ) {
		$all    = self::food_types();
		$labels = array();
		foreach ( self::foods_for_log( $log_id ) as $key ) {
			$labels[] = $all[ $key ];
		}
		return implode( ', ', $labels );
	}

	/**
	 * Letzte protokollierte Fütterung eines Tieres.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return array|null { date, food } oder null.
	 */
	public static function last_feeding( $animal_id ) {
		$logs = get_posts(
			array(
				'post_type'      => 'rm_feeding_log',
				'posts_per_page' => 1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'meta_key'       => '_rm_feed_date',
				'orderby'        => 'meta_value',
				'order'          => 'DESC',
				'meta_query'     => array(
					array(
						'key'   => '_rm_feed_animal',
						'value' => $animal_id,
					),
				),
			)
		);

		if ( ! $logs ) {
			return null;
		}

		return array(
			'date' => get_post_meta( $logs[0]->ID, '_rm_feed_date', true ),
			'food' => self::foods_label( $logs[0]->ID ),
		);
	}

	/**
	 * Auswertung: tatsächliche Fütterungs-Frequenz vs. altersgerechtes Optimum.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @param int $days      Auswertungszeitraum in Tagen.
	 * @return array {
	 *     @type bool  $has_targets Ob Zielwerte vorliegen (Schlupfdatum bekannt).
	 *     @type bool  $has_data    Ob Fütterungen im Zeitraum protokolliert sind.
	 *     @type int   $days        Zeitraum.
	 *     @type array $categories  Kategorie => { label, rate, min, max, status(ok|low|high) }.
	 * }
	 */
	public static function analyze_animal( $animal_id, $days = self::ANALYSIS_DAYS ) {
		$birth   = get_post_meta( $animal_id, '_rm_birth', true );
		$months  = $birth ? RM_Animal_Meta::age_in_months( $birth ) : null;
		$species = RM_Species::key_for_animal( $animal_id );
		$targets = self::targets_for_age( $months, $species );

		$result = array(
			'has_targets' => (bool) $targets,
			'has_data'    => false,
			'days'        => $days,
			'categories'  => array(),
		);

		if ( ! $targets ) {
			return $result;
		}

		$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );

		$logs = get_posts(
			array(
				'post_type'      => 'rm_feeding_log',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'meta_query'     => array(
					array(
						'key'   => '_rm_feed_animal',
						'value' => $animal_id,
					),
					array(
						'key'     => '_rm_feed_date',
						'value'   => $since,
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
			)
		);

		$counts = array(
			'insects' => 0,
			'greens'  => 0,
			'calcium' => 0,
		);

		foreach ( $logs as $log ) {
			$foods = self::foods_for_log( $log->ID );
			if ( array_intersect( $foods, self::insect_keys() ) ) {
				$counts['insects']++;
			}
			if ( array_intersect( $foods, self::plant_keys() ) ) {
				$counts['greens']++;
			}

			$supps = get_post_meta( $log->ID, '_rm_feed_supplements', true );
			if ( is_array( $supps ) && array_intersect( $supps, array( 'calcium', 'calcium_d3' ) ) ) {
				$counts['calcium']++;
			}
		}

		$result['has_data'] = ! empty( $logs );

		$labels = array(
			'insects' => __( 'Insekten', 'reptilien-manager' ),
			'greens'  => __( 'Grünfutter', 'reptilien-manager' ),
			'calcium' => __( 'Calcium', 'reptilien-manager' ),
		);

		foreach ( $targets as $key => $range ) {
			$rate = $counts[ $key ] * 7 / $days;

			if ( $rate < $range[0] ) {
				$status = 'low';
			} elseif ( $rate > $range[1] ) {
				$status = 'high';
			} else {
				$status = 'ok';
			}

			$result['categories'][ $key ] = array(
				'label'  => $labels[ $key ],
				'rate'   => $rate,
				'min'    => $range[0],
				'max'    => $range[1],
				'status' => $status,
			);
		}

		return $result;
	}

	/* ---------------------------------------------------------------------
	 * Auswahl-Felder (gemeinsam für Meta-Box und Schnell-Eintrag)
	 * ------------------------------------------------------------------ */

	/**
	 * Checkbox-Raster für die Tierauswahl inkl. „Alle“-Schalter.
	 *
	 * @param int[] $selected Vorausgewählte Tier-IDs.
	 */
	public static function render_animal_choices( $selected = array() ) {
		$animals = RM_Post_Types::get_animals();

		if ( ! $animals ) {
			echo '<p class="description">' . esc_html__( 'Noch keine Tiere eingetragen.', 'reptilien-manager' ) . '</p>';
			return;
		}
		?>
		<div class="rm-choice-group">
			<label class="rm-choice rm-choice--all">
				<input type="checkbox" class="rm-check-all" />
				<strong><?php esc_html_e( 'Alle Tiere', 'reptilien-manager' ); ?></strong>
			</label>
			<div class="rm-choice-grid">
				<?php foreach ( $animals as $animal ) : ?>
					<label class="rm-choice">
						<input type="checkbox" class="rm-choice-cb" name="rm_feed_animals[]" value="<?php echo esc_attr( $animal->ID ); ?>" <?php checked( in_array( (int) $animal->ID, $selected, true ) ); ?> />
						<?php echo esc_html( $animal->post_title ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Checkbox-Raster für die Futterarten.
	 *
	 * @param string[] $selected Vorausgewählte Futter-Schlüssel.
	 */
	public static function render_food_choices( $selected = array() ) {
		?>
		<div class="rm-choice-group">
			<div class="rm-choice-grid">
				<?php foreach ( self::food_types() as $key => $label ) : ?>
					<label class="rm-choice">
						<input type="checkbox" name="rm_feed_foods[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Checkboxen für Supplemente.
	 *
	 * @param string[] $selected Vorausgewählte Schlüssel.
	 */
	public static function render_supplement_choices( $selected = array() ) {
		?>
		<div class="rm-choice-group">
			<div class="rm-choice-grid rm-choice-grid--narrow">
				<?php foreach ( self::supplements() as $key => $label ) : ?>
					<label class="rm-choice">
						<input type="checkbox" name="rm_feed_supplements[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $selected, true ) ); ?> />
						<?php echo esc_html( $label ); ?>
					</label>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Meta-Box
	 * ------------------------------------------------------------------ */

	public static function add_meta_boxes() {
		add_meta_box( 'rm-feeding-details', __( 'Fütterungsdaten', 'reptilien-manager' ), array( __CLASS__, 'render_details' ), 'rm_feeding_log', 'normal', 'high' );
	}

	public static function render_details( $post ) {
		wp_nonce_field( 'rm_feeding_meta', 'rm_feeding_meta_nonce' );

		$animals     = self::animals_for_log( $post->ID );
		$date        = get_post_meta( $post->ID, '_rm_feed_date', true );
		$foods       = self::foods_for_log( $post->ID );
		$amount      = get_post_meta( $post->ID, '_rm_feed_amount', true );
		$supplements = get_post_meta( $post->ID, '_rm_feed_supplements', true );
		$notes       = get_post_meta( $post->ID, '_rm_feed_notes', true );

		if ( ! is_array( $supplements ) ) {
			$supplements = array();
		}
		if ( ! $date ) {
			$date = current_time( 'Y-m-d' );
		}
		?>
		<p class="description"><?php esc_html_e( 'Der Titel wird automatisch aus Datum und Tieren erzeugt – einfach leer lassen. Schneller geht es über den Schnell-Eintrag auf der Futterplan-Seite.', 'reptilien-manager' ); ?></p>
		<table class="form-table rm-form-table">
			<tr>
				<th><label for="rm_feed_date"><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?></label></th>
				<td><input type="date" name="rm_feed_date" id="rm_feed_date" value="<?php echo esc_attr( $date ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Tiere', 'reptilien-manager' ); ?></th>
				<td><?php self::render_animal_choices( $animals ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Futter', 'reptilien-manager' ); ?></th>
				<td><?php self::render_food_choices( $foods ); ?></td>
			</tr>
			<tr>
				<th><label for="rm_feed_amount"><?php esc_html_e( 'Menge', 'reptilien-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" name="rm_feed_amount" id="rm_feed_amount" value="<?php echo esc_attr( $amount ); ?>" placeholder="<?php esc_attr_e( 'z. B. 5 Stück pro Tier, 1 Handvoll', 'reptilien-manager' ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Supplemente', 'reptilien-manager' ); ?></th>
				<td><?php self::render_supplement_choices( $supplements ); ?></td>
			</tr>
			<tr>
				<th><label for="rm_feed_notes"><?php esc_html_e( 'Notizen', 'reptilien-manager' ); ?></label></th>
				<td><textarea name="rm_feed_notes" id="rm_feed_notes" rows="3" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

	/* ---------------------------------------------------------------------
	 * Speichern
	 * ------------------------------------------------------------------ */

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rm_feeding_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_feeding_meta_nonce'] ), 'rm_feeding_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- oben geprüft.
		$animals = isset( $_POST['rm_feed_animals'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_feed_animals'] ) ) : array();
		$date    = isset( $_POST['rm_feed_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_date'] ) ) : '';
		$foods   = isset( $_POST['rm_feed_foods'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['rm_feed_foods'] ) ) : array();
		$amount  = isset( $_POST['rm_feed_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_amount'] ) ) : '';
		$supps   = isset( $_POST['rm_feed_supplements'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['rm_feed_supplements'] ) ) : array();
		$notes   = isset( $_POST['rm_feed_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_feed_notes'] ) ) : '';
		// phpcs:enable

		self::store_log_meta( $post_id, $animals, $date, $foods, $amount, $supps, $notes );
		self::maybe_autotitle( $post_id, $post->post_title, $date, $animals );
	}

	/**
	 * Meta-Daten eines Fütterungs-Eintrags speichern.
	 *
	 * @param int      $post_id Beitrags-ID.
	 * @param int[]    $animals Tier-IDs.
	 * @param string   $date    Datum (Y-m-d).
	 * @param string[] $foods   Futter-Schlüssel.
	 * @param string   $amount  Mengenangabe.
	 * @param string[] $supps   Supplement-Schlüssel.
	 * @param string   $notes   Notizen.
	 */
	private static function store_log_meta( $post_id, $animals, $date, $foods, $amount, $supps, $notes ) {
		// Tiere als Mehrfach-Meta (eine Zeile pro Tier – ermöglicht exakte Abfragen).
		delete_post_meta( $post_id, '_rm_feed_animal' );
		foreach ( array_unique( array_filter( $animals ) ) as $animal_id ) {
			add_post_meta( $post_id, '_rm_feed_animal', $animal_id );
		}

		update_post_meta( $post_id, '_rm_feed_date', $date );

		$foods = array_values( array_intersect( $foods, array_keys( self::food_types() ) ) );
		update_post_meta( $post_id, '_rm_feed_foods', $foods );
		delete_post_meta( $post_id, '_rm_feed_food' ); // Altes Einzelfeld ablösen.

		update_post_meta( $post_id, '_rm_feed_amount', $amount );

		$supps = array_values( array_intersect( $supps, array_keys( self::supplements() ) ) );
		update_post_meta( $post_id, '_rm_feed_supplements', $supps );

		update_post_meta( $post_id, '_rm_feed_notes', $notes );
	}

	/**
	 * Erzeugt einen sprechenden Titel, falls keiner vergeben wurde.
	 *
	 * @param int    $post_id Beitrags-ID.
	 * @param string $title   Aktueller Titel.
	 * @param string $date    Datum.
	 * @param int[]  $animals Tier-IDs.
	 */
	private static function maybe_autotitle( $post_id, $title, $date, $animals ) {
		static $updating = false;

		if ( $updating || '' !== trim( $title ) ) {
			return;
		}

		$new_title = self::build_title( $date, $animals );

		$updating = true;
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => $new_title,
			)
		);
		$updating = false;
	}

	/**
	 * Sprechender Titel für einen Fütterungs-Eintrag.
	 *
	 * @param string $date    Datum (Y-m-d).
	 * @param int[]  $animals Tier-IDs.
	 * @return string
	 */
	private static function build_title( $date, $animals ) {
		$date_label = $date && strtotime( $date ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : date_i18n( get_option( 'date_format' ) );

		$animals    = array_values( array_filter( $animals ) );
		$all_count  = count( RM_Post_Types::get_animals() );
		$count      = count( $animals );

		if ( $count && $all_count && $count >= $all_count ) {
			$who = __( 'Alle Tiere', 'reptilien-manager' );
		} elseif ( $count > 0 && $count <= 2 ) {
			$names = array();
			foreach ( $animals as $id ) {
				$names[] = get_the_title( $id );
			}
			$who = implode( ', ', $names );
		} elseif ( $count > 2 ) {
			/* translators: %d: Anzahl Tiere */
			$who = sprintf( __( '%d Tiere', 'reptilien-manager' ), $count );
		} else {
			$who = '';
		}

		/* translators: %s: Datum */
		$title = sprintf( __( 'Fütterung %s', 'reptilien-manager' ), $date_label );
		if ( $who ) {
			$title .= ' – ' . $who;
		}

		return $title;
	}

	/**
	 * Schnell-Eintrag von der Futterplan-Seite verarbeiten.
	 */
	public static function handle_quick_feeding() {
		if ( ! isset( $_POST['rm_quick_feeding_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_quick_feeding_nonce'] ), 'rm_quick_feeding' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$animals = isset( $_POST['rm_feed_animals'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_feed_animals'] ) ) : array();
		$date    = isset( $_POST['rm_feed_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_date'] ) ) : current_time( 'Y-m-d' );
		$foods   = isset( $_POST['rm_feed_foods'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['rm_feed_foods'] ) ) : array();
		$amount  = isset( $_POST['rm_feed_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_amount'] ) ) : '';
		$supps   = isset( $_POST['rm_feed_supplements'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['rm_feed_supplements'] ) ) : array();
		$notes   = isset( $_POST['rm_feed_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_feed_notes'] ) ) : '';

		$redirect = admin_url( 'edit.php?post_type=rm_animal&page=rm-feeding-plan' );

		if ( ! $animals || ! $foods ) {
			wp_safe_redirect( add_query_arg( 'rm_msg', 'missing', $redirect ) );
			exit;
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'rm_feeding_log',
				'post_status' => 'publish',
				'post_title'  => self::build_title( $date, $animals ),
			)
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			wp_safe_redirect( add_query_arg( 'rm_msg', 'error', $redirect ) );
			exit;
		}

		self::store_log_meta( $post_id, $animals, $date, $foods, $amount, $supps, $notes );

		wp_safe_redirect( add_query_arg( 'rm_msg', 'saved', $redirect ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Admin-Spalten
	 * ------------------------------------------------------------------ */

	public static function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['rm_feed_animal'] = __( 'Tiere', 'reptilien-manager' );
				$new['rm_feed_date']   = __( 'Datum', 'reptilien-manager' );
				$new['rm_feed_food']   = __( 'Futter', 'reptilien-manager' );
				$new['rm_feed_supps']  = __( 'Supplemente', 'reptilien-manager' );
			}
		}
		return $new;
	}

	public static function admin_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'rm_feed_animal':
				$names = array();
				foreach ( self::animals_for_log( $post_id ) as $animal_id ) {
					$names[] = get_the_title( $animal_id );
				}
				echo esc_html( $names ? implode( ', ', $names ) : '—' );
				break;
			case 'rm_feed_date':
				$date = get_post_meta( $post_id, '_rm_feed_date', true );
				echo esc_html( $date && strtotime( $date ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '—' );
				break;
			case 'rm_feed_food':
				$label = self::foods_label( $post_id );
				echo esc_html( $label ? $label : '—' );
				break;
			case 'rm_feed_supps':
				$labels      = self::supplements();
				$supplements = get_post_meta( $post_id, '_rm_feed_supplements', true );
				if ( is_array( $supplements ) && $supplements ) {
					$out = array();
					foreach ( $supplements as $key ) {
						if ( isset( $labels[ $key ] ) ) {
							$out[] = $labels[ $key ];
						}
					}
					echo esc_html( implode( ', ', $out ) );
				} else {
					echo '—';
				}
				break;
		}
	}
}
