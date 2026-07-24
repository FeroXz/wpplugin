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
		add_action( 'admin_post_rm_export_weights', array( __CLASS__, 'export_weights_csv' ) );
		add_action( 'wp_ajax_rm_species_genes', array( __CLASS__, 'ajax_species_genes' ) );
		// Standard-Taxonomie-Box entfernen – die Art wird im Stammdaten-Feld gewählt.
		add_action( 'add_meta_boxes', array( __CLASS__, 'remove_species_metabox' ), 11 );
	}

	/**
	 * Entfernt die Standard-Auswahlbox der Arten-Taxonomie (eigene Auswahl
	 * erfolgt im Stammdaten-Bereich).
	 */
	public static function remove_species_metabox() {
		remove_meta_box( 'rm_speciesdiv', 'rm_animal', 'side' );
	}

	/**
	 * Exportiert den Gewichtsverlauf eines Tieres als CSV
	 * (Datum, Alter in Monaten, Gewicht, Erwartet, Status).
	 */
	public static function export_weights_csv() {
		$animal_id = isset( $_GET['animal'] ) ? absint( $_GET['animal'] ) : 0;

		if ( ! $animal_id || ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rm_export_weights_' . $animal_id ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'edit_post', $animal_id ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$chart = RM_Growth::chart_data( $animal_id );
		$title = sanitize_file_name( get_the_title( $animal_id ) );

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="gewicht-' . ( $title ? $title : $animal_id ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		// UTF-8 BOM, damit Excel Umlaute korrekt anzeigt.
		fwrite( $out, "\xEF\xBB\xBF" );
		fputcsv(
			$out,
			array(
				__( 'Datum', 'reptilien-manager' ),
				__( 'Alter (Monate)', 'reptilien-manager' ),
				__( 'Gewicht (g)', 'reptilien-manager' ),
				__( 'Erwartet (g)', 'reptilien-manager' ),
				__( 'Status', 'reptilien-manager' ),
			),
			';'
		);

		$status_labels = array(
			'low'     => __( 'untergewichtig', 'reptilien-manager' ),
			'normal'  => __( 'normal', 'reptilien-manager' ),
			'high'    => __( 'übergewichtig', 'reptilien-manager' ),
			'unknown' => __( 'unbekannt', 'reptilien-manager' ),
		);

		foreach ( $chart['points'] as $point ) {
			fputcsv(
				$out,
				array(
					$point['date'],
					null === $point['x'] ? '' : $point['x'],
					$point['y'],
					null === $point['expected'] ? '' : $point['expected'],
					isset( $status_labels[ $point['status'] ] ) ? $status_labels[ $point['status'] ] : $point['status'],
				),
				';'
			);
		}

		fclose( $out );
		exit;
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

	/**
	 * Körperkonditions-Score (BCS) 1–5.
	 *
	 * @return array
	 */
	public static function bcs_options() {
		return array(
			''  => __( '– keine Angabe –', 'reptilien-manager' ),
			'1' => __( '1 – stark abgemagert', 'reptilien-manager' ),
			'2' => __( '2 – untergewichtig', 'reptilien-manager' ),
			'3' => __( '3 – ideal', 'reptilien-manager' ),
			'4' => __( '4 – kräftig', 'reptilien-manager' ),
			'5' => __( '5 – übergewichtig', 'reptilien-manager' ),
		);
	}

	/**
	 * Temperament-Skala 1–5.
	 *
	 * @return array
	 */
	public static function temperament_options() {
		return array(
			''  => __( '– keine Angabe –', 'reptilien-manager' ),
			'1' => __( '1 – sehr scheu', 'reptilien-manager' ),
			'2' => __( '2 – zurückhaltend', 'reptilien-manager' ),
			'3' => __( '3 – ausgeglichen', 'reptilien-manager' ),
			'4' => __( '4 – forsch', 'reptilien-manager' ),
			'5' => __( '5 – aggressiv', 'reptilien-manager' ),
		);
	}

	/**
	 * Farbintensität-Skala.
	 *
	 * @return array
	 */
	public static function color_options() {
		return array(
			''       => __( '– keine Angabe –', 'reptilien-manager' ),
			'hell'   => __( 'Hell', 'reptilien-manager' ),
			'mittel' => __( 'Mittel', 'reptilien-manager' ),
			'dunkel' => __( 'Dunkel', 'reptilien-manager' ),
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

		// Erweiterte Stammdaten (Morphologie & Kondition).
		$bcs         = get_post_meta( $post->ID, '_rm_bcs', true );
		$temperament = get_post_meta( $post->ID, '_rm_temperament', true );
		$color       = get_post_meta( $post->ID, '_rm_color', true );
		$shed        = get_post_meta( $post->ID, '_rm_shed_interval', true );
		$svl         = get_post_meta( $post->ID, '_rm_svl', true );
		$tail        = get_post_meta( $post->ID, '_rm_tail', true );
		$girth       = get_post_meta( $post->ID, '_rm_girth', true );
		$is_public   = '0' !== (string) get_post_meta( $post->ID, '_rm_public', true );

		// Sicherstellen, dass die Standard-Arten existieren (z. B. nach einem
		// Update ohne Reaktivierung), damit die Auswahl nie leer ist.
		RM_Species::register_terms();

		// Arten aus der Taxonomie (angelegte Arten) für die Auswahl.
		$species_terms = get_terms(
			array(
				'taxonomy'   => 'rm_species',
				'hide_empty' => false,
			)
		);
		if ( is_wp_error( $species_terms ) ) {
			$species_terms = array();
		}
		$current_terms = wp_get_post_terms( $post->ID, 'rm_species', array( 'fields' => 'ids' ) );
		$current_term  = ( is_array( $current_terms ) && $current_terms ) ? (int) $current_terms[0] : 0;
		?>
		<table class="form-table rm-form-table">
			<tr>
				<th><label for="rm_species"><?php esc_html_e( 'Tierart', 'reptilien-manager' ); ?></label></th>
				<td>
					<?php if ( is_wp_error( $species_terms ) || ! $species_terms ) : ?>
						<p class="description"><?php esc_html_e( 'Noch keine Arten angelegt.', 'reptilien-manager' ); ?></p>
					<?php else : ?>
						<select name="rm_species" id="rm_species">
							<?php foreach ( $species_terms as $term ) : ?>
								<option value="<?php echo esc_attr( $term->term_id ); ?>" <?php selected( $current_term, $term->term_id ); ?>><?php echo esc_html( $term->name ); ?></option>
							<?php endforeach; ?>
						</select>
						<span class="spinner rm-species-spinner"></span>
						<p class="description"><?php esc_html_e( 'Genetik-Felder und Futterplan richten sich automatisch nach der gewählten Art. Weitere Arten lassen sich unter „Reptilien → Arten“ anlegen.', 'reptilien-manager' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
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
				<th><?php esc_html_e( 'Sichtbarkeit', 'reptilien-manager' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="rm_public" value="1" <?php checked( $is_public ); ?> />
						<?php esc_html_e( 'Öffentlich im Frontend anzeigen', 'reptilien-manager' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Nur öffentliche Tiere erscheinen in den Shortcodes (Liste, Profil, Dashboard). Der Beitrag selbst bleibt davon unberührt.', 'reptilien-manager' ); ?></p>
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
				<th><?php esc_html_e( 'Morphologie (cm)', 'reptilien-manager' ); ?></th>
				<td class="rm-inline-fields">
					<label><?php esc_html_e( 'KRL', 'reptilien-manager' ); ?>
						<input type="number" step="0.1" min="0" name="rm_svl" value="<?php echo esc_attr( $svl ); ?>" title="<?php esc_attr_e( 'Kopf-Rumpf-Länge', 'reptilien-manager' ); ?>" />
					</label>
					<label><?php esc_html_e( 'Schwanz', 'reptilien-manager' ); ?>
						<input type="number" step="0.1" min="0" name="rm_tail" value="<?php echo esc_attr( $tail ); ?>" />
					</label>
					<label><?php esc_html_e( 'Umfang', 'reptilien-manager' ); ?>
						<input type="number" step="0.1" min="0" name="rm_girth" value="<?php echo esc_attr( $girth ); ?>" />
					</label>
				</td>
			</tr>
			<tr>
				<th><label for="rm_bcs"><?php esc_html_e( 'Körperkondition (BCS 1–5)', 'reptilien-manager' ); ?></label></th>
				<td>
					<select name="rm_bcs" id="rm_bcs">
						<?php foreach ( self::bcs_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $bcs, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_temperament"><?php esc_html_e( 'Temperament (1–5)', 'reptilien-manager' ); ?></label></th>
				<td>
					<select name="rm_temperament" id="rm_temperament">
						<?php foreach ( self::temperament_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( (string) $temperament, (string) $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_color"><?php esc_html_e( 'Farbintensität', 'reptilien-manager' ); ?></label></th>
				<td>
					<select name="rm_color" id="rm_color">
						<?php foreach ( self::color_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $color, $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="rm_shed_interval"><?php esc_html_e( 'Häutungs-Intervall (Tage)', 'reptilien-manager' ); ?></label></th>
				<td>
					<input type="number" min="0" name="rm_shed_interval" id="rm_shed_interval" value="<?php echo esc_attr( $shed ); ?>" />
					<p class="description"><?php esc_html_e( 'Durchschnittlicher Abstand zwischen den Häutungen (Ecdysis).', 'reptilien-manager' ); ?></p>
				</td>
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
		$species = RM_Species::key_for_animal( $post->ID );
		?>
		<input type="hidden" id="rm_species_genes_nonce" value="<?php echo esc_attr( wp_create_nonce( 'rm_species_genes' ) ); ?>" />
		<div id="rm-genetics-inner">
			<?php self::render_genetics_inner( $post->ID, $species ); ?>
		</div>
		<?php
	}

	/**
	 * Rendert die Genetik-Felder für eine Art (wiederverwendbar für AJAX bei
	 * Artwechsel). Die gespeicherten Genwerte werden auf das Gen-Set der Art
	 * gefiltert.
	 *
	 * @param int    $post_id Beitrags-ID.
	 * @param string $species Art-Schlüssel.
	 */
	public static function render_genetics_inner( $post_id, $species ) {
		$states = RM_Genetics::get_animal_genes_for_species( $post_id, $species );
		?>
		<p class="description">
			<?php
			printf(
				/* translators: %s: Artname */
				esc_html__( 'Genanlagen dieses Tieres (Art: %s). Bei Artwechsel oben werden diese Felder automatisch aktualisiert.', 'reptilien-manager' ),
				'<strong>' . esc_html( RM_Species::label( $species ) ) . '</strong>'
			);
			?>
		</p>
		<table class="form-table rm-form-table">
			<?php foreach ( RM_Genetics::genes( $species ) as $key => $gene ) : ?>
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
			<?php echo esc_html( RM_Genetics::morph_label_from_states( $states, $species ) ); ?>
		</p>
		<?php
	}

	/**
	 * AJAX: liefert die Genetik-Felder für die gewählte Art (Live-Umschaltung).
	 */
	public static function ajax_species_genes() {
		check_ajax_referer( 'rm_species_genes', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$allowed = $post_id ? current_user_can( 'edit_post', $post_id ) : current_user_can( 'edit_posts' );
		if ( ! $allowed ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'reptilien-manager' ) ), 403 );
		}

		$term_id = isset( $_POST['term_id'] ) ? absint( $_POST['term_id'] ) : 0;
		$species = RM_Species::DEFAULT_KEY;
		if ( $term_id ) {
			$term = get_term( $term_id, 'rm_species' );
			if ( $term && ! is_wp_error( $term ) ) {
				$species = RM_Species::key_from_term_names( array( $term->name ) );
			}
		}

		ob_start();
		self::render_genetics_inner( $post_id, $species );
		wp_send_json_success( array( 'html' => ob_get_clean() ) );
	}

	public static function render_weights( $post ) {
		$weights = get_post_meta( $post->ID, '_rm_weights', true );
		if ( ! is_array( $weights ) ) {
			$weights = array();
		}

		$chart = RM_Growth::chart_data( $post->ID );
		$export_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=rm_export_weights&animal=' . $post->ID ),
			'rm_export_weights_' . $post->ID
		);
		?>
		<?php if ( $chart['points'] ) : ?>
			<div class="rm-chart-wrap">
				<canvas id="rm-weight-chart" height="220"></canvas>
			</div>
			<script type="application/json" id="rm-weight-chart-data"><?php echo wp_json_encode( $chart ); ?></script>
			<?php if ( ! $chart['has_age'] ) : ?>
				<p class="description"><?php esc_html_e( 'Für die Referenz-Wachstumskurve und die X-Achse „Alter“ bitte ein Schlupfdatum in den Stammdaten hinterlegen.', 'reptilien-manager' ); ?></p>
			<?php endif; ?>
			<p>
				<a class="button" href="<?php echo esc_url( $export_url ); ?>"><?php esc_html_e( 'Als CSV exportieren', 'reptilien-manager' ); ?></a>
			</p>
		<?php endif; ?>
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
			'rm_sex'           => '_rm_sex',
			'rm_birth'         => '_rm_birth',
			'rm_origin'        => '_rm_origin',
			'rm_acquired'      => '_rm_acquired',
			'rm_identifier'    => '_rm_identifier',
			'rm_length'        => '_rm_length',
			// Erweiterte Stammdaten.
			'rm_bcs'           => '_rm_bcs',
			'rm_temperament'   => '_rm_temperament',
			'rm_color'         => '_rm_color',
			'rm_shed_interval' => '_rm_shed_interval',
			'rm_svl'           => '_rm_svl',
			'rm_tail'          => '_rm_tail',
			'rm_girth'         => '_rm_girth',
		);

		foreach ( $text_fields as $field => $meta_key ) {
			$value = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
			update_post_meta( $post_id, $meta_key, $value );
		}

		$food_notes = isset( $_POST['rm_food_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_food_notes'] ) ) : '';
		update_post_meta( $post_id, '_rm_food_notes', $food_notes );

		// Sichtbarkeit (Checkbox nicht gesetzt = privat).
		update_post_meta( $post_id, '_rm_public', isset( $_POST['rm_public'] ) ? '1' : '0' );

		// Abstammung (eigene Nachzucht).
		$parent_pairing = isset( $_POST['rm_parent_pairing'] ) ? absint( $_POST['rm_parent_pairing'] ) : 0;
		update_post_meta( $post_id, '_rm_parent_pairing', $parent_pairing ? $parent_pairing : '' );
		$clutch_no = isset( $_POST['rm_clutch'] ) ? absint( $_POST['rm_clutch'] ) : 0;
		update_post_meta( $post_id, '_rm_clutch', $clutch_no ? $clutch_no : '' );

		// Genanlagen (artübergreifende Schlüssel-Validierung, damit die Reihenfolge
		// von Taxonomie- und Meta-Speicherung keine Rolle spielt).
		$genes = array();
		$raw   = isset( $_POST['rm_genes'] ) && is_array( $_POST['rm_genes'] ) ? wp_unslash( $_POST['rm_genes'] ) : array();
		foreach ( RM_Genetics::all_gene_keys() as $key ) {
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

		// Tierart aus dem Auswahlfeld setzen (maßgeblich für Genetik und Futterplan).
		if ( isset( $_POST['rm_species'] ) ) {
			$term_id = absint( $_POST['rm_species'] );
			if ( $term_id && term_exists( $term_id, 'rm_species' ) ) {
				wp_set_object_terms( $post_id, array( $term_id ), 'rm_species' );
			}
		}

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
