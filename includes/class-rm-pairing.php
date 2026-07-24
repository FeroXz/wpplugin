<?php
/**
 * Verpaarungen: Elterntier-Auswahl, Zuchtdaten und Genetik-Vorschau.
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
		add_meta_box( 'rm-pairing-genetics', __( 'Genetik-Vorschau der Jungtiere', 'reptilien-manager' ), array( __CLASS__, 'render_genetics' ), 'rm_pairing', 'normal', 'default' );
	}

	public static function render_details( $post ) {
		wp_nonce_field( 'rm_pairing_meta', 'rm_pairing_meta_nonce' );

		$sire        = (int) get_post_meta( $post->ID, '_rm_sire', true );
		$dam         = (int) get_post_meta( $post->ID, '_rm_dam', true );
		$date        = get_post_meta( $post->ID, '_rm_pairing_date', true );
		$lay_date    = get_post_meta( $post->ID, '_rm_lay_date', true );
		$clutch_size = get_post_meta( $post->ID, '_rm_clutch_size', true );
		$incubation  = get_post_meta( $post->ID, '_rm_incubation_temp', true );

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
				<th><label for="rm_lay_date"><?php esc_html_e( 'Eiablage', 'reptilien-manager' ); ?></label></th>
				<td>
					<input type="date" name="rm_lay_date" id="rm_lay_date" value="<?php echo esc_attr( $lay_date ); ?>" />
					<?php if ( $lay_date && strtotime( $lay_date ) ) : ?>
						<span class="description">
							<?php
							printf(
								/* translators: %s: Datum */
								esc_html__( 'Erwarteter Schlupf (≈ 60 Tage bei 28–29 °C): %s', 'reptilien-manager' ),
								esc_html( date_i18n( get_option( 'date_format' ), strtotime( $lay_date . ' +' . self::INCUBATION_DAYS . ' days' ) ) )
							);
							?>
						</span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_clutch_size"><?php esc_html_e( 'Gelegegröße (Eier)', 'reptilien-manager' ); ?></label></th>
				<td><input type="number" min="0" name="rm_clutch_size" id="rm_clutch_size" value="<?php echo esc_attr( $clutch_size ); ?>" /></td>
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

		echo RM_Genetics::render_cross_result( $sire, $dam ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML wird intern escaped.
	}

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

		$fields = array(
			'rm_sire'            => '_rm_sire',
			'rm_dam'             => '_rm_dam',
			'rm_clutch_size'     => '_rm_clutch_size',
		);
		foreach ( $fields as $field => $meta_key ) {
			$value = isset( $_POST[ $field ] ) ? absint( $_POST[ $field ] ) : 0;
			update_post_meta( $post_id, $meta_key, $value ? $value : '' );
		}

		$text_fields = array(
			'rm_pairing_date'    => '_rm_pairing_date',
			'rm_lay_date'        => '_rm_lay_date',
			'rm_incubation_temp' => '_rm_incubation_temp',
		);
		foreach ( $text_fields as $field => $meta_key ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			update_post_meta( $post_id, $meta_key, $value );
		}
	}

	public static function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['rm_parents'] = __( 'Elterntiere', 'reptilien-manager' );
				$new['rm_date']    = __( 'Verpaarung', 'reptilien-manager' );
				$new['rm_lay']     = __( 'Eiablage', 'reptilien-manager' );
				$new['rm_clutch']  = __( 'Gelege', 'reptilien-manager' );
			}
		}
		return $new;
	}

	public static function admin_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'rm_parents':
				$sire = (int) get_post_meta( $post_id, '_rm_sire', true );
				$dam  = (int) get_post_meta( $post_id, '_rm_dam', true );
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
			case 'rm_lay':
				$date = get_post_meta( $post_id, '_rm_lay_date', true );
				echo esc_html( $date && strtotime( $date ) ? date_i18n( get_option( 'date_format' ), strtotime( $date ) ) : '—' );
				break;
			case 'rm_clutch':
				$size = get_post_meta( $post_id, '_rm_clutch_size', true );
				echo esc_html( $size ? $size : '—' );
				break;
		}
	}
}
