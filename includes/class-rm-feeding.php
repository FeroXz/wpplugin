<?php
/**
 * Futterplanung und Fütterungsprotokoll (speziell Bartagamen).
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Feeding {

	public static function init() {
		add_action( 'add_meta_boxes_rm_feeding_log', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_rm_feeding_log', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_rm_feeding_log_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_rm_feeding_log_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
	}

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
	 * Altersgerechter Bartagamen-Futterplan.
	 *
	 * @param int|null $months Alter in Monaten oder null.
	 * @return array { group, insects, greens, supplements }
	 */
	public static function plan_for_age( $months ) {
		if ( null === $months ) {
			return array(
				'group'       => __( 'Unbekanntes Alter', 'reptilien-manager' ),
				'insects'     => __( 'Bitte Schlupfdatum beim Tier hinterlegen, um eine Empfehlung zu erhalten.', 'reptilien-manager' ),
				'greens'      => __( 'Grünfutter täglich anbieten.', 'reptilien-manager' ),
				'supplements' => __( 'Calcium regelmäßig, D3 nach UV-Versorgung dosieren.', 'reptilien-manager' ),
			);
		}

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

		$foods = self::food_types();
		$food  = get_post_meta( $logs[0]->ID, '_rm_feed_food', true );

		return array(
			'date' => get_post_meta( $logs[0]->ID, '_rm_feed_date', true ),
			'food' => isset( $foods[ $food ] ) ? $foods[ $food ] : $food,
		);
	}

	public static function add_meta_boxes() {
		add_meta_box( 'rm-feeding-details', __( 'Fütterungsdaten', 'reptilien-manager' ), array( __CLASS__, 'render_details' ), 'rm_feeding_log', 'normal', 'high' );
	}

	public static function render_details( $post ) {
		wp_nonce_field( 'rm_feeding_meta', 'rm_feeding_meta_nonce' );

		$animal      = (int) get_post_meta( $post->ID, '_rm_feed_animal', true );
		$date        = get_post_meta( $post->ID, '_rm_feed_date', true );
		$food        = get_post_meta( $post->ID, '_rm_feed_food', true );
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
		<table class="form-table rm-form-table">
			<tr>
				<th><label for="rm_feed_animal"><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></label></th>
				<td>
					<select name="rm_feed_animal" id="rm_feed_animal">
						<option value=""><?php esc_html_e( '– auswählen –', 'reptilien-manager' ); ?></option>
						<?php foreach ( RM_Post_Types::get_animals() as $a ) : ?>
							<option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( $animal, $a->ID ); ?>><?php echo esc_html( $a->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_feed_date"><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?></label></th>
				<td><input type="date" name="rm_feed_date" id="rm_feed_date" value="<?php echo esc_attr( $date ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="rm_feed_food"><?php esc_html_e( 'Futter', 'reptilien-manager' ); ?></label></th>
				<td>
					<select name="rm_feed_food" id="rm_feed_food">
						<?php foreach ( self::food_types() as $key => $label ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $food, $key ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_feed_amount"><?php esc_html_e( 'Menge', 'reptilien-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" name="rm_feed_amount" id="rm_feed_amount" value="<?php echo esc_attr( $amount ); ?>" placeholder="<?php esc_attr_e( 'z. B. 5 Stück, 1 Handvoll', 'reptilien-manager' ); ?>" /></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Supplemente', 'reptilien-manager' ); ?></th>
				<td>
					<?php foreach ( self::supplements() as $key => $label ) : ?>
						<label class="rm-checkbox">
							<input type="checkbox" name="rm_feed_supplements[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $supplements, true ) ); ?> />
							<?php echo esc_html( $label ); ?>
						</label><br />
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_feed_notes"><?php esc_html_e( 'Notizen', 'reptilien-manager' ); ?></label></th>
				<td><textarea name="rm_feed_notes" id="rm_feed_notes" rows="3" class="large-text"><?php echo esc_textarea( $notes ); ?></textarea></td>
			</tr>
		</table>
		<?php
	}

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

		update_post_meta( $post_id, '_rm_feed_animal', isset( $_POST['rm_feed_animal'] ) ? absint( $_POST['rm_feed_animal'] ) : 0 );

		$date = isset( $_POST['rm_feed_date'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_date'] ) ) : '';
		update_post_meta( $post_id, '_rm_feed_date', $date );

		$food = isset( $_POST['rm_feed_food'] ) ? sanitize_key( $_POST['rm_feed_food'] ) : '';
		if ( ! array_key_exists( $food, self::food_types() ) ) {
			$food = 'sonstiges';
		}
		update_post_meta( $post_id, '_rm_feed_food', $food );

		$amount = isset( $_POST['rm_feed_amount'] ) ? sanitize_text_field( wp_unslash( $_POST['rm_feed_amount'] ) ) : '';
		update_post_meta( $post_id, '_rm_feed_amount', $amount );

		$valid_supplements = array_keys( self::supplements() );
		$supplements       = isset( $_POST['rm_feed_supplements'] ) ? array_map( 'sanitize_key', wp_unslash( (array) $_POST['rm_feed_supplements'] ) ) : array();
		update_post_meta( $post_id, '_rm_feed_supplements', array_values( array_intersect( $supplements, $valid_supplements ) ) );

		$notes = isset( $_POST['rm_feed_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_feed_notes'] ) ) : '';
		update_post_meta( $post_id, '_rm_feed_notes', $notes );
	}

	public static function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['rm_feed_animal'] = __( 'Tier', 'reptilien-manager' );
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
				$animal = (int) get_post_meta( $post_id, '_rm_feed_animal', true );
				echo esc_html( $animal ? get_the_title( $animal ) : '—' );
				break;
			case 'rm_feed_date':
				$date = get_post_meta( $post_id, '_rm_feed_date', true );
				echo esc_html( $date && strtotime( $date ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '—' );
				break;
			case 'rm_feed_food':
				$foods = self::food_types();
				$food  = get_post_meta( $post_id, '_rm_feed_food', true );
				echo esc_html( isset( $foods[ $food ] ) ? $foods[ $food ] : '—' );
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
