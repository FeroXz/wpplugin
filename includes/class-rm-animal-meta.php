<?php
/**
 * Meta-Boxen und Speichern der Tierdaten.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Animal_Meta {

	public static function init() {
		add_action( 'add_meta_boxes_rm_animal', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_rm_animal', array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'manage_rm_animal_posts_columns', array( __CLASS__, 'admin_columns' ) );
		add_action( 'manage_rm_animal_posts_custom_column', array( __CLASS__, 'admin_column_content' ), 10, 2 );
		add_action( 'wp_ajax_rm_upload_photo', array( __CLASS__, 'ajax_upload_photo' ) );
	}

	public static function add_meta_boxes() {
		add_meta_box( 'rm-animal-details', __( 'Stammdaten', 'reptilien-manager' ), array( __CLASS__, 'render_details' ), 'rm_animal', 'normal', 'high' );
		add_meta_box( 'rm-animal-genetics', __( 'Genetik / Morph', 'reptilien-manager' ), array( __CLASS__, 'render_genetics' ), 'rm_animal', 'normal', 'default' );
		add_meta_box( 'rm-animal-weights', __( 'Gewichtsverlauf', 'reptilien-manager' ), array( __CLASS__, 'render_weights' ), 'rm_animal', 'normal', 'default' );
		add_meta_box( 'rm-animal-gallery', __( 'Fotogalerie', 'reptilien-manager' ), array( __CLASS__, 'render_gallery' ), 'rm_animal', 'side', 'default' );
	}

	/**
	 * Geschlechts-Optionen.
	 *
	 * @return array
	 */
	public static function sexes() {
		return array(
			'unknown' => __( 'Unbekannt', 'reptilien-manager' ),
			'male'    => __( 'Männlich (1.0)', 'reptilien-manager' ),
			'female'  => __( 'Weiblich (0.1)', 'reptilien-manager' ),
		);
	}

	public static function render_details( $post ) {
		wp_nonce_field( 'rm_animal_meta', 'rm_animal_meta_nonce' );

		$sex        = get_post_meta( $post->ID, '_rm_sex', true );
		$birth      = get_post_meta( $post->ID, '_rm_birth', true );
		$origin     = get_post_meta( $post->ID, '_rm_origin', true );
		$acquired   = get_post_meta( $post->ID, '_rm_acquired', true );
		$identifier = get_post_meta( $post->ID, '_rm_identifier', true );
		$length     = get_post_meta( $post->ID, '_rm_length', true );
		$food_notes = get_post_meta( $post->ID, '_rm_food_notes', true );
		?>
		<table class="form-table rm-form-table">
			<tr>
				<th><label for="rm_sex"><?php esc_html_e( 'Geschlecht', 'reptilien-manager' ); ?></label></th>
				<td>
					<select name="rm_sex" id="rm_sex">
						<?php foreach ( self::sexes() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $sex, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_birth"><?php esc_html_e( 'Schlupfdatum', 'reptilien-manager' ); ?></label></th>
				<td>
					<input type="date" name="rm_birth" id="rm_birth" value="<?php echo esc_attr( $birth ); ?>" />
					<?php if ( $birth ) : ?>
						<span class="description"><?php echo esc_html( self::age_label( $birth ) ); ?></span>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="rm_origin"><?php esc_html_e( 'Herkunft / Züchter', 'reptilien-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" name="rm_origin" id="rm_origin" value="<?php echo esc_attr( $origin ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="rm_acquired"><?php esc_html_e( 'Erwerbsdatum', 'reptilien-manager' ); ?></label></th>
				<td><input type="date" name="rm_acquired" id="rm_acquired" value="<?php echo esc_attr( $acquired ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="rm_identifier"><?php esc_html_e( 'Kennzeichnung (Chip-/Buchnummer)', 'reptilien-manager' ); ?></label></th>
				<td><input type="text" class="regular-text" name="rm_identifier" id="rm_identifier" value="<?php echo esc_attr( $identifier ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="rm_length"><?php esc_html_e( 'Gesamtlänge (cm)', 'reptilien-manager' ); ?></label></th>
				<td><input type="number" step="0.1" min="0" name="rm_length" id="rm_length" value="<?php echo esc_attr( $length ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="rm_parent_pairing"><?php esc_html_e( 'Eltern-Verpaarung (eigene Nachzucht)', 'reptilien-manager' ); ?></label></th>
				<td>
					<?php
					$parent_pairing = (int) get_post_meta( $post->ID, '_rm_parent_pairing', true );
					$clutch_no      = (int) get_post_meta( $post->ID, '_rm_clutch', true );
					$pairings       = get_posts(
						array(
							'post_type'      => 'rm_pairing',
							'posts_per_page' => -1,
							'post_status'    => array( 'publish', 'draft', 'private' ),
							'orderby'        => 'date',
							'order'          => 'DESC',
						)
					);
					?>
					<select name="rm_parent_pairing" id="rm_parent_pairing">
						<option value=""><?php esc_html_e( '– keine (kein eigener Nachwuchs) –', 'reptilien-manager' ); ?></option>
						<?php foreach ( $pairings as $pairing ) : ?>
							<option value="<?php echo esc_attr( $pairing->ID ); ?>" <?php selected( $parent_pairing, $pairing->ID ); ?>>
								<?php echo esc_html( RM_Pairing::pairing_label( $pairing->ID ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<label for="rm_clutch" style="margin-left:8px">
						<?php esc_html_e( 'Gelege Nr.', 'reptilien-manager' ); ?>
						<input type="number" min="0" style="width:70px" name="rm_clutch" id="rm_clutch" value="<?php echo esc_attr( $clutch_no ? $clutch_no : '' ); ?>" />
					</label>
					<p class="description"><?php esc_html_e( 'Bei eigener Nachzucht: die Verpaarung der Elterntiere auswählen. Das Tier erscheint dann automatisch als Nachzucht bei der Verpaarung und in den Beitrags-Vorlagen der Eltern.', 'reptilien-manager' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="rm_food_notes"><?php esc_html_e( 'Futter-Besonderheiten', 'reptilien-manager' ); ?></label></th>
				<td>
					<textarea name="rm_food_notes" id="rm_food_notes" rows="3" class="large-text"><?php echo esc_textarea( $food_notes ); ?></textarea>
					<p class="description"><?php esc_html_e( 'z. B. Lieblingsfutter, Unverträglichkeiten, Diät.', 'reptilien-manager' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'Weitere Informationen (Haltung, Gesundheit, Charakter) können im Textfeld oben beschrieben werden. Das Profilfoto wird als Beitragsbild gesetzt.', 'reptilien-manager' ); ?></p>
		<?php
	}

	public static function render_genetics( $post ) {
		$states = RM_Genetics::get_animal_genes( $post->ID );
		?>
		<p class="description"><?php esc_html_e( 'Genanlagen dieses Tieres – Grundlage für die Genetik-Vorschau bei Verpaarungen.', 'reptilien-manager' ); ?></p>
		<table class="form-table rm-form-table">
			<?php foreach ( RM_Genetics::genes() as $key => $gene ) : ?>
				<tr>
					<th><label for="rm_gene_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $gene['label'] ); ?></label></th>
					<td>
						<select name="rm_genes[<?php echo esc_attr( $key ); ?>]" id="rm_gene_<?php echo esc_attr( $key ); ?>">
							<?php foreach ( RM_Genetics::states_for_gene( $gene ) as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $states[ $key ], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
			<?php endforeach; ?>
		</table>
		<p>
			<strong><?php esc_html_e( 'Aktueller Morph:', 'reptilien-manager' ); ?></strong>
			<?php echo esc_html( RM_Genetics::animal_morph_label( $post->ID ) ); ?>
		</p>
		<?php
	}

	public static function render_weights( $post ) {
		$weights = get_post_meta( $post->ID, '_rm_weights', true );
		if ( ! is_array( $weights ) ) {
			$weights = array();
		}
		?>
		<table class="widefat rm-weight-table" id="rm-weight-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?></th>
					<th><?php esc_html_e( 'Gewicht (g)', 'reptilien-manager' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $weights as $i => $entry ) : ?>
					<tr>
						<td><input type="date" name="rm_weight_date[]" value="<?php echo esc_attr( $entry['date'] ); ?>" /></td>
						<td><input type="number" step="1" min="0" name="rm_weight_grams[]" value="<?php echo esc_attr( $entry['grams'] ); ?>" /></td>
						<td><button type="button" class="button rm-weight-remove"><?php esc_html_e( 'Entfernen', 'reptilien-manager' ); ?></button></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<button type="button" class="button" id="rm-weight-add"><?php esc_html_e( 'Wiegung hinzufügen', 'reptilien-manager' ); ?></button>
		</p>
		<?php
	}

	public static function render_gallery( $post ) {
		$gallery = get_post_meta( $post->ID, '_rm_gallery', true );
		if ( ! is_array( $gallery ) ) {
			$gallery = array();
		}
		?>
		<div id="rm-gallery" class="rm-gallery">
			<ul class="rm-gallery-list">
				<?php foreach ( $gallery as $attachment_id ) : ?>
					<?php if ( wp_get_attachment_image_url( $attachment_id, 'thumbnail' ) ) : ?>
						<li>
							<input type="hidden" name="rm_gallery_ids[]" value="<?php echo esc_attr( $attachment_id ); ?>" />
							<?php echo wp_get_attachment_image( $attachment_id, 'thumbnail' ); ?>
							<button type="button" class="button-link rm-gallery-remove" aria-label="<?php esc_attr_e( 'Foto entfernen', 'reptilien-manager' ); ?>">&times;</button>
						</li>
					<?php endif; ?>
				<?php endforeach; ?>
			</ul>
			<input type="hidden" id="rm_upload_nonce" value="<?php echo esc_attr( wp_create_nonce( 'rm_upload_photo' ) ); ?>" />
			<input type="file" id="rm-upload-input" accept="image/*" multiple style="display:none" />
			<p class="rm-gallery-buttons">
				<button type="button" class="button rm-upload-add"><?php esc_html_e( 'Bilder hochladen', 'reptilien-manager' ); ?></button>
				<button type="button" class="button rm-gallery-add"><?php esc_html_e( 'Aus Mediathek wählen', 'reptilien-manager' ); ?></button>
				<span class="spinner rm-upload-spinner"></span>
			</p>
			<p class="description"><?php esc_html_e( '„Bilder hochladen“ lädt Fotos direkt vom Gerät hoch und fügt sie der Galerie hinzu. Anschließend den Beitrag speichern.', 'reptilien-manager' ); ?></p>
		</div>
		<?php
	}

	/**
	 * AJAX: Foto direkt hochladen und der Galerie zuordnen.
	 */
	public static function ajax_upload_photo() {
		check_ajax_referer( 'rm_upload_photo', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) || ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'reptilien-manager' ) ), 403 );
		}

		if ( empty( $_FILES['rm_photo'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Datei übermittelt.', 'reptilien-manager' ) ), 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$attachment_id = media_handle_upload( 'rm_photo', $post_id );

		if ( is_wp_error( $attachment_id ) ) {
			wp_send_json_error( array( 'message' => $attachment_id->get_error_message() ), 400 );
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			wp_delete_attachment( $attachment_id, true );
			wp_send_json_error( array( 'message' => __( 'Nur Bilddateien sind erlaubt.', 'reptilien-manager' ) ), 400 );
		}

		wp_send_json_success(
			array(
				'id'    => $attachment_id,
				'thumb' => wp_get_attachment_image_url( $attachment_id, 'thumbnail' ),
			)
		);
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['rm_animal_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_animal_meta_nonce'] ), 'rm_animal_meta' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$text_fields = array(
			'rm_sex'        => '_rm_sex',
			'rm_birth'      => '_rm_birth',
			'rm_origin'     => '_rm_origin',
			'rm_acquired'   => '_rm_acquired',
			'rm_identifier' => '_rm_identifier',
			'rm_length'     => '_rm_length',
		);

		foreach ( $text_fields as $field => $meta_key ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			update_post_meta( $post_id, $meta_key, $value );
		}

		$food_notes = isset( $_POST['rm_food_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_food_notes'] ) ) : '';
		update_post_meta( $post_id, '_rm_food_notes', $food_notes );

		// Abstammung (eigene Nachzucht).
		$parent_pairing = isset( $_POST['rm_parent_pairing'] ) ? absint( $_POST['rm_parent_pairing'] ) : 0;
		update_post_meta( $post_id, '_rm_parent_pairing', $parent_pairing ? $parent_pairing : '' );
		$clutch_no = isset( $_POST['rm_clutch'] ) ? absint( $_POST['rm_clutch'] ) : 0;
		update_post_meta( $post_id, '_rm_clutch', $clutch_no ? $clutch_no : '' );

		// Genanlagen.
		$genes = array();
		$raw   = isset( $_POST['rm_genes'] ) && is_array( $_POST['rm_genes'] ) ? wp_unslash( $_POST['rm_genes'] ) : array();
		foreach ( array_keys( RM_Genetics::genes() ) as $key ) {
			$state = isset( $raw[ $key ] ) ? sanitize_key( $raw[ $key ] ) : '';
			if ( in_array( $state, array( 'het', 'homo' ), true ) ) {
				$genes[ $key ] = $state;
			}
		}
		update_post_meta( $post_id, '_rm_genes', $genes );

		// Gewichtsverlauf.
		$weights = array();
		$dates   = isset( $_POST['rm_weight_date'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['rm_weight_date'] ) ) : array();
		$grams   = isset( $_POST['rm_weight_grams'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['rm_weight_grams'] ) ) : array();

		foreach ( $dates as $i => $date ) {
			$g = isset( $grams[ $i ] ) ? absint( $grams[ $i ] ) : 0;
			if ( '' === $date || ! $g ) {
				continue;
			}
			$weights[] = array(
				'date'  => $date,
				'grams' => $g,
			);
		}
		usort(
			$weights,
			static function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);
		update_post_meta( $post_id, '_rm_weights', $weights );

		// Galerie.
		$gallery = isset( $_POST['rm_gallery_ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_gallery_ids'] ) ) : array();
		update_post_meta( $post_id, '_rm_gallery', array_values( array_filter( array_unique( $gallery ) ) ) );

		// Standard-Art setzen, wenn keine gewählt wurde (weniger Pflichtangaben).
		$terms = wp_get_post_terms( $post_id, 'rm_species', array( 'fields' => 'ids' ) );
		if ( ! is_wp_error( $terms ) && empty( $terms ) ) {
			$default = term_exists( 'Bartagame (Pogona vitticeps)', 'rm_species' );
			if ( $default ) {
				wp_set_object_terms( $post_id, (int) $default['term_id'], 'rm_species' );
			}
		}
	}

	public static function admin_columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			if ( 'title' === $key ) {
				$new['rm_photo'] = __( 'Foto', 'reptilien-manager' );
				$new['title']    = $label;
				$new['rm_sex']   = __( 'Geschlecht', 'reptilien-manager' );
				$new['rm_age']   = __( 'Alter', 'reptilien-manager' );
				$new['rm_morph'] = __( 'Morph / Genetik', 'reptilien-manager' );
			} else {
				$new[ $key ] = $label;
			}
		}
		return $new;
	}

	public static function admin_column_content( $column, $post_id ) {
		switch ( $column ) {
			case 'rm_photo':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 50, 50 ) );
				} else {
					echo '&mdash;';
				}
				break;
			case 'rm_sex':
				$sexes = self::sexes();
				$sex   = get_post_meta( $post_id, '_rm_sex', true );
				echo esc_html( isset( $sexes[ $sex ] ) ? $sexes[ $sex ] : $sexes['unknown'] );
				break;
			case 'rm_age':
				$birth = get_post_meta( $post_id, '_rm_birth', true );
				echo esc_html( $birth ? self::age_label( $birth ) : '—' );
				break;
			case 'rm_morph':
				echo esc_html( RM_Genetics::animal_morph_label( $post_id ) );
				break;
		}
	}

	/**
	 * Alter in Monaten seit einem Datum.
	 *
	 * @param string $birth Datum (Y-m-d).
	 * @return int|null Monate oder null bei ungültigem Datum.
	 */
	public static function age_in_months( $birth ) {
		$birth_ts = strtotime( $birth );
		if ( ! $birth_ts || $birth_ts > time() ) {
			return null;
		}
		$diff = ( new DateTime( '@' . $birth_ts ) )->diff( new DateTime( 'now' ) );
		return $diff->y * 12 + $diff->m;
	}

	/**
	 * Lesbare Altersangabe.
	 *
	 * @param string $birth Datum (Y-m-d).
	 * @return string
	 */
	public static function age_label( $birth ) {
		$months = self::age_in_months( $birth );
		if ( null === $months ) {
			return '';
		}
		if ( $months < 12 ) {
			/* translators: %d: Anzahl Monate */
			return sprintf( _n( '%d Monat', '%d Monate', $months, 'reptilien-manager' ), $months );
		}
		$years          = intdiv( $months, 12 );
		$rest_months    = $months % 12;
		/* translators: %d: Anzahl Jahre */
		$label = sprintf( _n( '%d Jahr', '%d Jahre', $years, 'reptilien-manager' ), $years );
		if ( $rest_months ) {
			/* translators: %d: Anzahl Monate */
			$label .= ', ' . sprintf( _n( '%d Monat', '%d Monate', $rest_months, 'reptilien-manager' ), $rest_months );
		}
		return $label;
	}
}
