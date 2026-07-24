<?php
/**
 * Beitrags-Vorlagen für Tier-Beiträge.
 *
 * Erzeugt aus den aktuell im Formular eingetragenen Tierdaten fertige
 * Editor-Inhalte (Gutenberg-Blöcke) inkl. Profilfoto und Galerie.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Templates {

	public static function init() {
		add_action( 'add_meta_boxes_rm_animal', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'wp_ajax_rm_render_template', array( __CLASS__, 'ajax_render' ) );
	}

	/**
	 * Verfügbare Vorlagen.
	 *
	 * @return array[]
	 */
	public static function templates() {
		return array(
			'steckbrief' => array(
				'label'       => __( 'Steckbrief (Tabelle)', 'reptilien-manager' ),
				'description' => __( 'Foto plus übersichtliche Tabelle mit allen Stammdaten – ideal als schneller Standard.', 'reptilien-manager' ),
			),
			'portrait'   => array(
				'label'       => __( 'Ausführliches Porträt', 'reptilien-manager' ),
				'description' => __( 'Fließtext mit Abschnitten zu Herkunft, Charakter, Genetik und Ernährung – mit Platzhaltern zum Ausformulieren.', 'reptilien-manager' ),
			),
			'zucht'      => array(
				'label'       => __( 'Zuchttier-Präsentation', 'reptilien-manager' ),
				'description' => __( 'Fokus auf Genetik und Eckdaten – geeignet für Zucht- und Abgabetiere.', 'reptilien-manager' ),
			),
			'kurz'       => array(
				'label'       => __( 'Kurzprofil', 'reptilien-manager' ),
				'description' => __( 'Kompakte Vorstellung mit Foto, einem Absatz und den wichtigsten Fakten.', 'reptilien-manager' ),
			),
		);
	}

	public static function add_meta_box() {
		add_meta_box(
			'rm-animal-templates',
			__( 'Beitrags-Vorlagen', 'reptilien-manager' ),
			array( __CLASS__, 'render_meta_box' ),
			'rm_animal',
			'side',
			'high'
		);
	}

	public static function render_meta_box( $post ) {
		?>
		<input type="hidden" id="rm_template_nonce" value="<?php echo esc_attr( wp_create_nonce( 'rm_render_template' ) ); ?>" />
		<p class="description">
			<?php esc_html_e( 'Erzeugt den Beitragstext automatisch aus den eingetragenen Tierdaten – inkl. Profilfoto und Galerie. Der Text kann danach frei angepasst werden.', 'reptilien-manager' ); ?>
		</p>
		<p>
			<label for="rm_template_choice" class="screen-reader-text"><?php esc_html_e( 'Vorlage wählen', 'reptilien-manager' ); ?></label>
			<select id="rm_template_choice" style="width:100%">
				<?php foreach ( self::templates() as $key => $template ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $template['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<ul class="rm-template-descriptions">
			<?php foreach ( self::templates() as $key => $template ) : ?>
				<li data-template="<?php echo esc_attr( $key ); ?>">
					<strong><?php echo esc_html( $template['label'] ); ?>:</strong>
					<?php echo esc_html( $template['description'] ); ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<p>
			<button type="button" class="button button-primary" id="rm-template-insert"><?php esc_html_e( 'Vorlage in den Text übernehmen', 'reptilien-manager' ); ?></button>
			<span class="spinner rm-template-spinner"></span>
		</p>
		<p class="description">
			<?php esc_html_e( 'Tipp: Zuerst Stammdaten, Genetik, Fotos und Gewichte eintragen – die Vorlage übernimmt automatisch die aktuellen Formularwerte.', 'reptilien-manager' ); ?>
		</p>
		<?php
	}

	/**
	 * AJAX: Vorlage mit den aktuellen Formularwerten rendern.
	 */
	public static function ajax_render() {
		check_ajax_referer( 'rm_render_template', 'nonce' );

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Keine Berechtigung.', 'reptilien-manager' ) ), 403 );
		}

		$template = isset( $_POST['template'] ) ? sanitize_key( $_POST['template'] ) : '';
		if ( ! array_key_exists( $template, self::templates() ) ) {
			wp_send_json_error( array( 'message' => __( 'Unbekannte Vorlage.', 'reptilien-manager' ) ), 400 );
		}

		$data = self::collect_data( $post_id );

		switch ( $template ) {
			case 'portrait':
				$content = self::render_portrait( $data );
				break;
			case 'zucht':
				$content = self::render_zucht( $data );
				break;
			case 'kurz':
				$content = self::render_kurz( $data );
				break;
			case 'steckbrief':
			default:
				$content = self::render_steckbrief( $data );
				break;
		}

		wp_send_json_success( array( 'content' => $content ) );
	}

	/**
	 * Sammelt und bereinigt die per AJAX übermittelten Formularwerte.
	 *
	 * @param int $post_id Beitrags-ID (für Arten-Taxonomie).
	 * @return array
	 */
	private static function collect_data( $post_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Nonce wird in ajax_render() geprüft.
		$text = static function ( $field ) {
			return isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		};

		$title = $text( 'title' );
		if ( '' === $title ) {
			$title = __( 'Unser Tier', 'reptilien-manager' );
		}

		$sex_key = $text( 'rm_sex' );
		$sexes   = RM_Animal_Meta::sexes();

		$birth = $text( 'rm_birth' );

		// Genanlagen.
		$genes_raw = isset( $_POST['rm_genes'] ) && is_array( $_POST['rm_genes'] ) ? wp_unslash( $_POST['rm_genes'] ) : array();
		$states    = array();
		foreach ( array_keys( RM_Genetics::genes() ) as $key ) {
			$state = isset( $genes_raw[ $key ] ) ? sanitize_key( $genes_raw[ $key ] ) : '';
			$states[ $key ] = in_array( $state, array( 'het', 'homo' ), true ) ? $state : '';
		}

		// Gewichte.
		$dates   = isset( $_POST['rm_weight_date'] ) ? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST['rm_weight_date'] ) ) : array();
		$grams   = isset( $_POST['rm_weight_grams'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_weight_grams'] ) ) : array();
		$weights = array();
		foreach ( $dates as $i => $date ) {
			$g = isset( $grams[ $i ] ) ? $grams[ $i ] : 0;
			if ( '' === $date || ! $g || ! strtotime( $date ) ) {
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

		$gallery = isset( $_POST['rm_gallery_ids'] ) ? array_map( 'absint', wp_unslash( (array) $_POST['rm_gallery_ids'] ) ) : array();
		// phpcs:enable

		$species = wp_get_post_terms( $post_id, 'rm_species', array( 'fields' => 'names' ) );

		return array(
			'title'       => $title,
			'sex_key'     => $sex_key,
			'sex_label'   => isset( $sexes[ $sex_key ] ) ? $sexes[ $sex_key ] : '',
			'birth'       => $birth,
			'age_label'   => $birth ? RM_Animal_Meta::age_label( $birth ) : '',
			'origin'      => $text( 'rm_origin' ),
			'acquired'    => $text( 'rm_acquired' ),
			'identifier'  => $text( 'rm_identifier' ),
			'length'      => $text( 'rm_length' ),
			'food_notes'  => isset( $_POST['rm_food_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rm_food_notes'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'gene_states' => $states,
			'morph'       => RM_Genetics::morph_label_from_states( $states ),
			'weights'     => $weights,
			'featured_id' => isset( $_POST['featured_id'] ) ? absint( $_POST['featured_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing
			'gallery'     => array_values( array_filter( array_unique( $gallery ) ) ),
			'species'     => is_array( $species ) ? implode( ', ', $species ) : '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Block-Bausteine
	 * ------------------------------------------------------------------ */

	private static function heading( $text, $level = 2 ) {
		$attrs = 2 === $level ? '' : ' {"level":' . (int) $level . '}';
		return "<!-- wp:heading{$attrs} -->\n<h{$level} class=\"wp-block-heading\">" . esc_html( $text ) . "</h{$level}>\n<!-- /wp:heading -->\n\n";
	}

	private static function paragraph( $html ) {
		return "<!-- wp:paragraph -->\n<p>" . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	private static function image( $attachment_id, $size = 'large' ) {
		$url = wp_get_attachment_image_url( $attachment_id, $size );
		if ( ! $url ) {
			return '';
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		return '<!-- wp:image {"id":' . (int) $attachment_id . ',"sizeSlug":"' . esc_attr( $size ) . '","linkDestination":"none"} -->' . "\n"
			. '<figure class="wp-block-image size-' . esc_attr( $size ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $attachment_id . '"/></figure>' . "\n"
			. "<!-- /wp:image -->\n\n";
	}

	private static function gallery( $ids ) {
		$inner = '';
		foreach ( $ids as $id ) {
			$url = wp_get_attachment_image_url( $id, 'large' );
			if ( ! $url ) {
				continue;
			}
			$alt    = get_post_meta( $id, '_wp_attachment_image_alt', true );
			$inner .= '<!-- wp:image {"id":' . (int) $id . ',"sizeSlug":"large","linkDestination":"none"} -->' . "\n"
				. '<figure class="wp-block-image size-large"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $id . '"/></figure>' . "\n"
				. "<!-- /wp:image -->\n";
		}

		if ( '' === $inner ) {
			return '';
		}

		return '<!-- wp:gallery {"linkTo":"none"} -->' . "\n"
			. '<figure class="wp-block-gallery has-nested-images columns-default is-cropped">' . "\n"
			. $inner
			. '</figure>' . "\n"
			. "<!-- /wp:gallery -->\n\n";
	}

	/**
	 * Tabellen-Block aus Label/Wert-Paaren; leere Werte werden übersprungen.
	 *
	 * @param array $rows Array aus array( Label, Wert ).
	 * @return string
	 */
	private static function table( $rows ) {
		$body = '';
		foreach ( $rows as $row ) {
			if ( '' === (string) $row[1] ) {
				continue;
			}
			$body .= '<tr><td><strong>' . esc_html( $row[0] ) . '</strong></td><td>' . esc_html( $row[1] ) . "</td></tr>\n";
		}

		if ( '' === $body ) {
			return '';
		}

		return "<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody>\n" . $body . "</tbody></table></figure>\n<!-- /wp:table -->\n\n";
	}

	private static function bullet_list( $items ) {
		$inner = '';
		foreach ( $items as $item ) {
			if ( '' === (string) $item ) {
				continue;
			}
			$inner .= '<!-- wp:list-item --><li>' . esc_html( $item ) . "</li><!-- /wp:list-item -->\n";
		}

		if ( '' === $inner ) {
			return '';
		}

		return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n" . $inner . "</ul>\n<!-- /wp:list -->\n\n";
	}

	/* ---------------------------------------------------------------------
	 * Hilfen
	 * ------------------------------------------------------------------ */

	private static function format_date( $date ) {
		$ts = $date ? strtotime( $date ) : false;
		return $ts ? date_i18n( get_option( 'date_format' ), $ts ) : '';
	}

	private static function birth_with_age( $data ) {
		$formatted = self::format_date( $data['birth'] );
		if ( ! $formatted ) {
			return '';
		}
		return $data['age_label'] ? $formatted . ' (' . $data['age_label'] . ')' : $formatted;
	}

	private static function last_weight_label( $data ) {
		if ( ! $data['weights'] ) {
			return '';
		}
		$last = end( $data['weights'] );
		/* translators: 1: Gewicht in Gramm, 2: Datum */
		return sprintf( __( '%1$d g (Stand: %2$s)', 'reptilien-manager' ), $last['grams'], self::format_date( $last['date'] ) );
	}

	/**
	 * Beschreibung der einzelnen Genanlagen als Liste.
	 *
	 * @param array $states Gen-Schlüssel => Zustand.
	 * @return array
	 */
	private static function gene_lines( $states ) {
		$lines = array();
		foreach ( RM_Genetics::genes() as $key => $gene ) {
			if ( '' === $states[ $key ] ) {
				continue;
			}
			$labels  = RM_Genetics::states_for_gene( $gene );
			$lines[] = $gene['label'] . ': ' . $labels[ $states[ $key ] ];
		}
		return $lines;
	}

	private static function intro_sentence( $data ) {
		$species = $data['species'] ? $data['species'] : __( 'Bartagame', 'reptilien-manager' );

		if ( 'male' === $data['sex_key'] ) {
			/* translators: 1: Tiername, 2: Art */
			$sentence = sprintf( __( '%1$s ist ein Männchen der Art %2$s', 'reptilien-manager' ), $data['title'], $species );
		} elseif ( 'female' === $data['sex_key'] ) {
			/* translators: 1: Tiername, 2: Art */
			$sentence = sprintf( __( '%1$s ist ein Weibchen der Art %2$s', 'reptilien-manager' ), $data['title'], $species );
		} else {
			/* translators: 1: Tiername, 2: Art */
			$sentence = sprintf( __( '%1$s gehört zur Art %2$s', 'reptilien-manager' ), $data['title'], $species );
		}

		if ( $data['birth'] && self::format_date( $data['birth'] ) ) {
			/* translators: %s: Datum */
			$sentence .= sprintf( __( ' und ist am %s geschlüpft', 'reptilien-manager' ), self::format_date( $data['birth'] ) );
			if ( $data['age_label'] ) {
				/* translators: %s: Altersangabe */
				$sentence .= sprintf( __( ' (%s alt)', 'reptilien-manager' ), $data['age_label'] );
			}
		}

		$sentence .= '. ';
		/* translators: %s: Morph-Bezeichnung */
		$sentence .= sprintf( __( 'Genetisch handelt es sich um: %s.', 'reptilien-manager' ), $data['morph'] );

		return $sentence;
	}

	private static function placeholder( $text ) {
		return '<em>[' . esc_html( $text ) . ']</em>';
	}

	/* ---------------------------------------------------------------------
	 * Vorlagen
	 * ------------------------------------------------------------------ */

	private static function render_steckbrief( $data ) {
		$content  = self::image( $data['featured_id'] );
		$content .= self::heading( __( 'Steckbrief', 'reptilien-manager' ) );
		$content .= self::table(
			array(
				array( __( 'Name', 'reptilien-manager' ), $data['title'] ),
				array( __( 'Art', 'reptilien-manager' ), $data['species'] ),
				array( __( 'Geschlecht', 'reptilien-manager' ), $data['sex_label'] ),
				array( __( 'Schlupfdatum', 'reptilien-manager' ), self::birth_with_age( $data ) ),
				array( __( 'Morph / Genetik', 'reptilien-manager' ), $data['morph'] ),
				array( __( 'Herkunft / Züchter', 'reptilien-manager' ), $data['origin'] ),
				array( __( 'Bei uns seit', 'reptilien-manager' ), self::format_date( $data['acquired'] ) ),
				array( __( 'Kennzeichnung', 'reptilien-manager' ), $data['identifier'] ),
				array( __( 'Gesamtlänge', 'reptilien-manager' ), $data['length'] ? $data['length'] . ' cm' : '' ),
				array( __( 'Aktuelles Gewicht', 'reptilien-manager' ), self::last_weight_label( $data ) ),
				array( __( 'Futter-Besonderheiten', 'reptilien-manager' ), $data['food_notes'] ),
			)
		);
		$content .= self::paragraph( self::placeholder( __( 'Hier ist Platz für weitere Informationen zu Haltung, Charakter und Besonderheiten.', 'reptilien-manager' ) ) );

		if ( $data['gallery'] ) {
			$content .= self::heading( __( 'Galerie', 'reptilien-manager' ) );
			$content .= self::gallery( $data['gallery'] );
		}

		return $content;
	}

	private static function render_portrait( $data ) {
		/* translators: %s: Tiername */
		$content  = self::heading( sprintf( __( 'Über %s', 'reptilien-manager' ), $data['title'] ) );
		$content .= self::paragraph( esc_html( self::intro_sentence( $data ) ) );
		$content .= self::image( $data['featured_id'] );

		$content .= self::heading( __( 'Herkunft & Entwicklung', 'reptilien-manager' ), 3 );
		$facts = array();
		if ( $data['origin'] ) {
			/* translators: %s: Herkunft/Züchter */
			$facts[] = sprintf( __( 'Herkunft: %s.', 'reptilien-manager' ), $data['origin'] );
		}
		if ( self::format_date( $data['acquired'] ) ) {
			/* translators: %s: Datum */
			$facts[] = sprintf( __( 'Bei uns eingezogen am %s.', 'reptilien-manager' ), self::format_date( $data['acquired'] ) );
		}
		if ( self::last_weight_label( $data ) ) {
			/* translators: %s: Gewichtsangabe */
			$facts[] = sprintf( __( 'Aktuelles Gewicht: %s.', 'reptilien-manager' ), self::last_weight_label( $data ) );
		}
		if ( $data['length'] ) {
			/* translators: %s: Länge in cm */
			$facts[] = sprintf( __( 'Gesamtlänge: %s cm.', 'reptilien-manager' ), $data['length'] );
		}
		$content .= self::paragraph(
			( $facts ? esc_html( implode( ' ', $facts ) ) . ' ' : '' )
			. self::placeholder( __( 'Beschreibe hier die Entwicklung des Tieres.', 'reptilien-manager' ) )
		);

		$content .= self::heading( __( 'Charakter & Haltung', 'reptilien-manager' ), 3 );
		$content .= self::paragraph( self::placeholder( __( 'Wie verhält sich das Tier? Terrariengröße, Beleuchtung, Temperaturen …', 'reptilien-manager' ) ) );

		$content .= self::heading( __( 'Genetik', 'reptilien-manager' ), 3 );
		/* translators: %s: Morph-Bezeichnung */
		$content .= self::paragraph( esc_html( sprintf( __( 'Morph: %s', 'reptilien-manager' ), $data['morph'] ) ) );
		$content .= self::bullet_list( self::gene_lines( $data['gene_states'] ) );

		$content .= self::heading( __( 'Ernährung', 'reptilien-manager' ), 3 );
		$content .= self::paragraph(
			$data['food_notes']
				? esc_html( $data['food_notes'] )
				: self::placeholder( __( 'Futterplan, Lieblingsfutter und Supplemente beschreiben.', 'reptilien-manager' ) )
		);

		if ( $data['gallery'] ) {
			$content .= self::heading( __( 'Galerie', 'reptilien-manager' ), 3 );
			$content .= self::gallery( $data['gallery'] );
		}

		return $content;
	}

	private static function render_zucht( $data ) {
		$content  = self::heading( $data['title'] . ' – ' . $data['morph'] );
		$content .= self::image( $data['featured_id'] );
		$content .= self::paragraph( esc_html( self::intro_sentence( $data ) ) );

		$content .= self::heading( __( 'Genetik im Detail', 'reptilien-manager' ), 3 );
		$gene_lines = self::gene_lines( $data['gene_states'] );
		if ( $gene_lines ) {
			$content .= self::bullet_list( $gene_lines );
		} else {
			$content .= self::paragraph( esc_html__( 'Für dieses Tier sind keine besonderen Genanlagen hinterlegt (klassische Wildfarbe).', 'reptilien-manager' ) );
		}

		$content .= self::heading( __( 'Eckdaten', 'reptilien-manager' ), 3 );
		$content .= self::table(
			array(
				array( __( 'Geschlecht', 'reptilien-manager' ), $data['sex_label'] ),
				array( __( 'Schlupfdatum', 'reptilien-manager' ), self::birth_with_age( $data ) ),
				array( __( 'Herkunft / Züchter', 'reptilien-manager' ), $data['origin'] ),
				array( __( 'Kennzeichnung', 'reptilien-manager' ), $data['identifier'] ),
				array( __( 'Gesamtlänge', 'reptilien-manager' ), $data['length'] ? $data['length'] . ' cm' : '' ),
				array( __( 'Aktuelles Gewicht', 'reptilien-manager' ), self::last_weight_label( $data ) ),
			)
		);

		$content .= self::heading( __( 'Zuchtplanung', 'reptilien-manager' ), 3 );
		$content .= self::paragraph( self::placeholder( __( 'Geplante Verpaarungen, Zuchtziele und bisherige Nachzuchten beschreiben.', 'reptilien-manager' ) ) );

		if ( $data['gallery'] ) {
			$content .= self::heading( __( 'Galerie', 'reptilien-manager' ), 3 );
			$content .= self::gallery( $data['gallery'] );
		}

		return $content;
	}

	private static function render_kurz( $data ) {
		$content  = self::image( $data['featured_id'] );
		$content .= self::paragraph( esc_html( self::intro_sentence( $data ) ) );
		$content .= self::bullet_list(
			array(
				$data['sex_label'] ? __( 'Geschlecht', 'reptilien-manager' ) . ': ' . $data['sex_label'] : '',
				self::birth_with_age( $data ) ? __( 'Schlupfdatum', 'reptilien-manager' ) . ': ' . self::birth_with_age( $data ) : '',
				__( 'Morph', 'reptilien-manager' ) . ': ' . $data['morph'],
				self::last_weight_label( $data ) ? __( 'Gewicht', 'reptilien-manager' ) . ': ' . self::last_weight_label( $data ) : '',
				$data['origin'] ? __( 'Herkunft', 'reptilien-manager' ) . ': ' . $data['origin'] : '',
			)
		);
		$content .= self::paragraph( self::placeholder( __( 'Optional: ein persönlicher Satz zum Tier.', 'reptilien-manager' ) ) );

		if ( $data['gallery'] ) {
			$content .= self::gallery( $data['gallery'] );
		}

		return $content;
	}
}
