<?php
/**
 * Beitrags-Vorlagen für Tier-Beiträge.
 *
 * Erzeugt aus den aktuell im Formular eingetragenen Tierdaten fertige
 * Editor-Inhalte (Gutenberg-Blöcke) inkl. Profilfoto, Galerie sowie
 * Verpaarungen und Nachzuchten mit automatischer Verlinkung.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Templates {

	/**
	 * Marken-Verlauf für Banner-Karten (Wissenswerk-Design: Indigo → Cyan).
	 */
	const GRADIENT_BANNER = 'linear-gradient(135deg,#4f46e5 0%,#06b6d4 100%)';

	/**
	 * Hintergrundfarbe für Verpaarungs-Karten (Wissenswerk „surface-2“).
	 */
	const COLOR_CARD = '#eef2ff';

	/**
	 * Eckenradius für Karten (Wissenswerk „radius-lg“).
	 */
	const CARD_RADIUS = '22px';

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
				'description' => __( 'Banner, Foto neben Datentabelle, Verpaarungen & Nachzuchten und Galerie – ideal als schneller Standard.', 'reptilien-manager' ),
			),
			'portrait'   => array(
				'label'       => __( 'Ausführliches Porträt', 'reptilien-manager' ),
				'description' => __( 'Fließtext mit Abschnitten zu Herkunft, Charakter, Genetik, Ernährung und Zucht – mit Platzhaltern zum Ausformulieren.', 'reptilien-manager' ),
			),
			'zucht'      => array(
				'label'       => __( 'Zuchttier-Präsentation', 'reptilien-manager' ),
				'description' => __( 'Fokus auf Genetik, Eckdaten sowie Verpaarungen mit Gelegen und Nachzuchten – geeignet für Zuchttiere.', 'reptilien-manager' ),
			),
			'kurz'       => array(
				'label'       => __( 'Kurzprofil', 'reptilien-manager' ),
				'description' => __( 'Kompakte Vorstellung mit Foto, einem Absatz, Faktenliste und Verpaarungs-Überblick.', 'reptilien-manager' ),
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
			<?php esc_html_e( 'Erzeugt den Beitragstext automatisch aus den eingetragenen Tierdaten – inkl. Profilfoto, Galerie sowie Verpaarungen und Nachzuchten mit Verlinkung. Der Text kann danach frei angepasst werden.', 'reptilien-manager' ); ?>
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
			<?php esc_html_e( 'Tipp: Zuerst Stammdaten, Genetik, Fotos, Gewichte und ggf. die Eltern-Verpaarung eintragen – die Vorlage übernimmt automatisch die aktuellen Formularwerte. Verpaarungen und Nachzuchten werden aus den gespeicherten Verpaarungen gelesen.', 'reptilien-manager' ); ?>
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
	 * @param int $post_id Beitrags-ID (für Taxonomie, Verpaarungen und Nachzuchten).
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

		$parent_pairing = isset( $_POST['rm_parent_pairing'] ) ? absint( $_POST['rm_parent_pairing'] ) : 0;
		$clutch_no      = isset( $_POST['rm_clutch'] ) ? absint( $_POST['rm_clutch'] ) : 0;
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
			'parent'      => self::parent_info( $parent_pairing, $clutch_no ),
			'pairings'    => self::animal_pairings( $post_id ),
		);
	}

	/**
	 * Abstammungs-Informationen aus der Eltern-Verpaarung.
	 *
	 * @param int $pairing_id Beitrags-ID der Eltern-Verpaarung.
	 * @param int $clutch_no  Gelege-Nummer (1-basiert, 0 = unbekannt).
	 * @return array|null
	 */
	private static function parent_info( $pairing_id, $clutch_no ) {
		if ( ! $pairing_id || 'rm_pairing' !== get_post_type( $pairing_id ) ) {
			return null;
		}

		$sire = (int) get_post_meta( $pairing_id, '_rm_sire', true );
		$dam  = (int) get_post_meta( $pairing_id, '_rm_dam', true );

		return array(
			'sire_name' => $sire ? get_the_title( $sire ) : __( 'Unbekannt', 'reptilien-manager' ),
			'sire_url'  => $sire ? get_permalink( $sire ) : '',
			'dam_name'  => $dam ? get_the_title( $dam ) : __( 'Unbekannt', 'reptilien-manager' ),
			'dam_url'   => $dam ? get_permalink( $dam ) : '',
			'clutch'    => $clutch_no,
		);
	}

	/**
	 * Verpaarungen des Tieres mit Gelegen und verlinkten Nachzuchten.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return array[]
	 */
	private static function animal_pairings( $animal_id ) {
		$list = array();

		foreach ( RM_Pairing::pairings_for_animal( $animal_id ) as $pairing ) {
			$sire    = (int) get_post_meta( $pairing->ID, '_rm_sire', true );
			$dam     = (int) get_post_meta( $pairing->ID, '_rm_dam', true );
			$partner = ( $sire === (int) $animal_id ) ? $dam : $sire;

			$date = get_post_meta( $pairing->ID, '_rm_pairing_date', true );

			$entry = array(
				'partner_name'  => $partner ? get_the_title( $partner ) : __( 'Unbekannter Partner', 'reptilien-manager' ),
				'partner_url'   => $partner ? get_permalink( $partner ) : '',
				'partner_morph' => $partner ? RM_Genetics::animal_morph_label( $partner ) : '',
				'date'          => self::format_date( $date ),
				'clutches'      => array(),
				'offspring'     => array(),
			);

			foreach ( RM_Pairing::get_clutches( $pairing->ID ) as $i => $clutch ) {
				$entry['clutches'][] = array(
					'no'      => $i + 1,
					'lay'     => self::format_date( $clutch['lay_date'] ),
					'eggs'    => $clutch['eggs'],
					'hatched' => $clutch['hatched'],
					'est'     => self::format_date( RM_Pairing::estimated_hatch( $clutch['lay_date'] ) ),
				);
			}

			foreach ( RM_Pairing::offspring( $pairing->ID ) as $child ) {
				if ( (int) $child->ID === (int) $animal_id ) {
					continue;
				}
				$entry['offspring'][] = array(
					'name'   => $child->post_title,
					'url'    => get_permalink( $child ),
					'morph'  => RM_Genetics::animal_morph_label( $child->ID ),
					'clutch' => (int) get_post_meta( $child->ID, '_rm_clutch', true ),
				);
			}

			$list[] = $entry;
		}

		return $list;
	}

	/* ---------------------------------------------------------------------
	 * Block-Bausteine
	 * ------------------------------------------------------------------ */

	private static function heading( $text, $level = 2, $centered = false, $color = '' ) {
		$attrs = array();
		if ( $centered ) {
			$attrs['textAlign'] = 'center';
		}
		if ( 2 !== $level ) {
			$attrs['level'] = $level;
		}
		if ( $color ) {
			$attrs['style'] = array( 'color' => array( 'text' => $color ) );
		}
		$json  = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		$class = 'wp-block-heading' . ( $centered ? ' has-text-align-center' : '' ) . ( $color ? ' has-text-color' : '' );
		$style = $color ? ' style="color:' . esc_attr( $color ) . '"' : '';

		return "<!-- wp:heading{$json} -->\n<h{$level} class=\"{$class}\"{$style}>" . esc_html( $text ) . "</h{$level}>\n<!-- /wp:heading -->\n\n";
	}

	/**
	 * Absatz-Block; $html muss bereits escaped/sicher sein.
	 *
	 * @param string $html    Innerer HTML-Inhalt.
	 * @param array  $options 'center' => bool, 'large' => bool, 'color' => Hex-Textfarbe.
	 * @return string
	 */
	private static function paragraph( $html, $options = array() ) {
		$attrs   = array();
		$classes = array();
		$color   = isset( $options['color'] ) ? $options['color'] : '';

		if ( ! empty( $options['center'] ) ) {
			$attrs['align'] = 'center';
			$classes[]      = 'has-text-align-center';
		}
		if ( $color ) {
			$attrs['style'] = array( 'color' => array( 'text' => $color ) );
			$classes[]      = 'has-text-color';
		}
		if ( ! empty( $options['large'] ) ) {
			$attrs['fontSize'] = 'large';
			$classes[]         = 'has-large-font-size';
		}

		$json  = $attrs ? ' ' . wp_json_encode( $attrs ) : '';
		$class = $classes ? ' class="' . implode( ' ', $classes ) . '"' : '';
		$style = $color ? ' style="color:' . esc_attr( $color ) . '"' : '';

		return "<!-- wp:paragraph{$json} -->\n<p{$class}{$style}>" . $html . "</p>\n<!-- /wp:paragraph -->\n\n";
	}

	private static function separator() {
		return "<!-- wp:separator -->\n<hr class=\"wp-block-separator has-alpha-channel-opacity\"/>\n<!-- /wp:separator -->\n\n";
	}

	/**
	 * Gruppen-Block als Karte: Hintergrundfarbe oder Verlauf, abgerundete Ecken.
	 *
	 * @param string $inner    Innere Blöcke.
	 * @param string $bg       Hex-Hintergrundfarbe (ignoriert, wenn $gradient gesetzt).
	 * @param string $gradient CSS-Verlauf (optional).
	 * @return string
	 */
	private static function group( $inner, $bg = self::COLOR_CARD, $gradient = '' ) {
		$padding = array(
			'top'    => '1.75rem',
			'right'  => '1.75rem',
			'bottom' => '1.75rem',
			'left'   => '1.75rem',
		);

		$color_attr = $gradient ? array( 'gradient' => $gradient ) : array( 'background' => $bg );

		$attrs = wp_json_encode(
			array(
				'style'  => array(
					'border'  => array( 'radius' => self::CARD_RADIUS ),
					'color'   => $color_attr,
					'spacing' => array( 'padding' => $padding ),
				),
				'layout' => array( 'type' => 'constrained' ),
			)
		);

		$background = $gradient
			? 'background:' . esc_attr( $gradient )
			: 'background-color:' . esc_attr( $bg );

		return "<!-- wp:group {$attrs} -->\n"
			. '<div class="wp-block-group has-background" style="border-radius:' . esc_attr( self::CARD_RADIUS ) . ';' . $background . ';padding-top:1.75rem;padding-right:1.75rem;padding-bottom:1.75rem;padding-left:1.75rem">' . "\n"
			. $inner
			. "</div>\n<!-- /wp:group -->\n\n";
	}

	/**
	 * Zweispalten-Layout.
	 *
	 * @param string $left  Blöcke der linken Spalte.
	 * @param string $right Blöcke der rechten Spalte.
	 * @return string
	 */
	private static function columns( $left, $right ) {
		if ( '' === $left ) {
			return $right;
		}
		if ( '' === $right ) {
			return $left;
		}

		return "<!-- wp:columns -->\n<div class=\"wp-block-columns\"><!-- wp:column -->\n<div class=\"wp-block-column\">\n"
			. $left
			. "</div>\n<!-- /wp:column --><!-- wp:column -->\n<div class=\"wp-block-column\">\n"
			. $right
			. "</div>\n<!-- /wp:column --></div>\n<!-- /wp:columns -->\n\n";
	}

	private static function image( $attachment_id, $size = 'large' ) {
		$url = $attachment_id ? wp_get_attachment_image_url( $attachment_id, $size ) : false;
		if ( ! $url ) {
			return '';
		}
		$alt = get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
		return '<!-- wp:image {"id":' . (int) $attachment_id . ',"sizeSlug":"' . esc_attr( $size ) . '","linkDestination":"none"} -->' . "\n"
			. '<figure class="wp-block-image size-' . esc_attr( $size ) . '"><img src="' . esc_url( $url ) . '" alt="' . esc_attr( $alt ) . '" class="wp-image-' . (int) $attachment_id . '"/></figure>' . "\n"
			. "<!-- /wp:image -->\n\n";
	}

	private static function gallery( $ids, $columns = 3 ) {
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

		$columns = max( 1, min( 4, (int) $columns ) );

		return '<!-- wp:gallery {"columns":' . $columns . ',"linkTo":"none"} -->' . "\n"
			. '<figure class="wp-block-gallery has-nested-images columns-' . $columns . ' is-cropped">' . "\n"
			. $inner
			. '</figure>' . "\n"
			. "<!-- /wp:gallery -->\n\n";
	}

	/**
	 * Gestreifter Tabellen-Block aus Label/Wert-Paaren; leere Werte werden übersprungen.
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

		return "<!-- wp:table {\"className\":\"is-style-stripes\"} -->\n<figure class=\"wp-block-table is-style-stripes\"><table><tbody>\n" . $body . "</tbody></table></figure>\n<!-- /wp:table -->\n\n";
	}

	private static function bullet_list( $items ) {
		$escaped = array();
		foreach ( $items as $item ) {
			if ( '' !== (string) $item ) {
				$escaped[] = esc_html( $item );
			}
		}
		return self::bullet_list_raw( $escaped );
	}

	/**
	 * Liste aus bereits escapten/sicheren HTML-Einträgen.
	 *
	 * @param array $items HTML pro Listeneintrag.
	 * @return string
	 */
	private static function bullet_list_raw( $items ) {
		$inner = '';
		foreach ( $items as $item ) {
			if ( '' === (string) $item ) {
				continue;
			}
			$inner .= '<!-- wp:list-item --><li>' . $item . "</li><!-- /wp:list-item -->\n";
		}

		if ( '' === $inner ) {
			return '';
		}

		return "<!-- wp:list -->\n<ul class=\"wp-block-list\">\n" . $inner . "</ul>\n<!-- /wp:list -->\n\n";
	}

	/* ---------------------------------------------------------------------
	 * Hilfen
	 * ------------------------------------------------------------------ */

	private static function link( $url, $text ) {
		if ( ! $url ) {
			return esc_html( $text );
		}
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $text ) . '</a>';
	}

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

	/**
	 * Banner-Karte mit Name, Morph und Kurzinfos.
	 *
	 * @param array $data Vorlagendaten.
	 * @return string
	 */
	private static function banner( $data ) {
		$inner  = self::heading( '🦎 ' . $data['title'], 2, true, '#ffffff' );
		$inner .= self::paragraph(
			'<strong>' . esc_html( $data['morph'] ) . '</strong>',
			array(
				'center' => true,
				'large'  => true,
				'color'  => '#ffffff',
			)
		);

		$meta_parts = array_filter(
			array(
				$data['species'],
				$data['sex_label'],
				$data['age_label'],
			)
		);
		if ( $meta_parts ) {
			$inner .= self::paragraph(
				esc_html( implode( '  ·  ', $meta_parts ) ),
				array(
					'center' => true,
					'color'  => '#e0f2fe',
				)
			);
		}

		return self::group( $inner, '', self::GRADIENT_BANNER );
	}

	/**
	 * Abstammungs-Absatz (verlinkte Eltern), falls hinterlegt.
	 *
	 * @param array $data Vorlagendaten.
	 * @return string
	 */
	private static function pedigree_paragraph( $data ) {
		if ( empty( $data['parent'] ) ) {
			return '';
		}

		$parent = $data['parent'];
		$html   = '🐣 <strong>' . esc_html__( 'Abstammung:', 'reptilien-manager' ) . '</strong> ';
		$html  .= sprintf(
			/* translators: 1: Vater (verlinkt), 2: Mutter (verlinkt) */
			esc_html__( 'Eigene Nachzucht aus der Verpaarung %1$s × %2$s', 'reptilien-manager' ),
			self::link( $parent['sire_url'], $parent['sire_name'] ),
			self::link( $parent['dam_url'], $parent['dam_name'] )
		);
		if ( $parent['clutch'] ) {
			/* translators: %d: Gelege-Nummer */
			$html .= ' ' . sprintf( esc_html__( '(Gelege %d)', 'reptilien-manager' ), $parent['clutch'] );
		}
		$html .= '.';

		return self::paragraph( $html );
	}

	/**
	 * Sektion „Verpaarungen & Nachzuchten“ mit verlinkten Partnern und Jungtieren.
	 *
	 * @param array $data          Vorlagendaten.
	 * @param int   $heading_level Überschriften-Ebene.
	 * @return string
	 */
	private static function pairings_section( $data, $heading_level = 3 ) {
		if ( empty( $data['pairings'] ) ) {
			return '';
		}

		$out = self::heading( '💞 ' . __( 'Verpaarungen & Nachzuchten', 'reptilien-manager' ), $heading_level );

		foreach ( $data['pairings'] as $pairing ) {
			$card = '';

			$headline = '<strong>' . esc_html__( 'Verpaarung mit', 'reptilien-manager' ) . ' ' . self::link( $pairing['partner_url'], $pairing['partner_name'] ) . '</strong>';
			if ( $pairing['partner_morph'] ) {
				$headline .= ' <em>(' . esc_html( $pairing['partner_morph'] ) . ')</em>';
			}
			if ( $pairing['date'] ) {
				/* translators: %s: Datum */
				$headline .= '<br />' . sprintf( esc_html__( 'Verpaart am %s', 'reptilien-manager' ), esc_html( $pairing['date'] ) );
			}
			$card .= self::paragraph( $headline );

			// Gelege.
			$clutch_items = array();
			foreach ( $pairing['clutches'] as $clutch ) {
				$parts = array();
				if ( $clutch['lay'] ) {
					/* translators: %s: Datum */
					$parts[] = sprintf( __( 'Ablage am %s', 'reptilien-manager' ), $clutch['lay'] );
				}
				if ( $clutch['eggs'] ) {
					/* translators: %d: Anzahl Eier */
					$parts[] = sprintf( _n( '%d Ei', '%d Eier', $clutch['eggs'], 'reptilien-manager' ), $clutch['eggs'] );
				}
				if ( $clutch['hatched'] ) {
					/* translators: %d: Anzahl geschlüpft */
					$parts[] = sprintf( __( '%d geschlüpft', 'reptilien-manager' ), $clutch['hatched'] );
				} elseif ( $clutch['est'] ) {
					/* translators: %s: Datum */
					$parts[] = sprintf( __( 'Schlupf erwartet ≈ %s', 'reptilien-manager' ), $clutch['est'] );
				}
				if ( $parts ) {
					/* translators: %d: Gelege-Nummer */
					$clutch_items[] = '🥚 <strong>' . sprintf( esc_html__( 'Gelege %d', 'reptilien-manager' ), $clutch['no'] ) . ':</strong> ' . esc_html( implode( ' · ', $parts ) );
				}
			}
			$card .= self::bullet_list_raw( $clutch_items );

			// Nachzuchten.
			if ( $pairing['offspring'] ) {
				$card .= self::paragraph( '<strong>' . esc_html__( 'Nachzuchten:', 'reptilien-manager' ) . '</strong>' );
				$offspring_items = array();
				foreach ( $pairing['offspring'] as $child ) {
					$item = '🦎 ' . self::link( $child['url'], $child['name'] );
					$extra = array();
					if ( $child['morph'] ) {
						$extra[] = $child['morph'];
					}
					if ( $child['clutch'] ) {
						/* translators: %d: Gelege-Nummer */
						$extra[] = sprintf( __( 'Gelege %d', 'reptilien-manager' ), $child['clutch'] );
					}
					if ( $extra ) {
						$item .= ' <em>(' . esc_html( implode( ' · ', $extra ) ) . ')</em>';
					}
					$offspring_items[] = $item;
				}
				$card .= self::bullet_list_raw( $offspring_items );
			}

			$out .= self::group( $card, self::COLOR_CARD );
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Vorlagen
	 * ------------------------------------------------------------------ */

	private static function render_steckbrief( $data ) {
		$content = self::banner( $data );

		$table = self::table(
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

		$content .= self::heading( '📋 ' . __( 'Steckbrief', 'reptilien-manager' ), 3 );
		$content .= self::columns( self::image( $data['featured_id'], 'large' ), $table );
		$content .= self::pedigree_paragraph( $data );
		$content .= self::paragraph( self::placeholder( __( 'Hier ist Platz für weitere Informationen zu Haltung, Charakter und Besonderheiten.', 'reptilien-manager' ) ) );

		if ( $data['pairings'] ) {
			$content .= self::separator();
			$content .= self::pairings_section( $data, 3 );
		}

		if ( $data['gallery'] ) {
			$content .= self::separator();
			$content .= self::heading( '📸 ' . __( 'Galerie', 'reptilien-manager' ), 3 );
			$content .= self::gallery( $data['gallery'], 3 );
		}

		return $content;
	}

	private static function render_portrait( $data ) {
		$content  = self::banner( $data );
		$content .= self::paragraph( esc_html( self::intro_sentence( $data ) ), array( 'large' => true ) );
		$content .= self::image( $data['featured_id'] );
		$content .= self::pedigree_paragraph( $data );
		$content .= self::separator();

		$content .= self::heading( '📖 ' . __( 'Herkunft & Entwicklung', 'reptilien-manager' ), 3 );
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

		$content .= self::heading( '🏡 ' . __( 'Charakter & Haltung', 'reptilien-manager' ), 3 );
		$content .= self::paragraph( self::placeholder( __( 'Wie verhält sich das Tier? Terrariengröße, Beleuchtung, Temperaturen …', 'reptilien-manager' ) ) );

		$content .= self::heading( '🧬 ' . __( 'Genetik', 'reptilien-manager' ), 3 );
		/* translators: %s: Morph-Bezeichnung */
		$content .= self::paragraph( esc_html( sprintf( __( 'Morph: %s', 'reptilien-manager' ), $data['morph'] ) ) );
		$content .= self::bullet_list( self::gene_lines( $data['gene_states'] ) );

		$content .= self::heading( '🍽️ ' . __( 'Ernährung', 'reptilien-manager' ), 3 );
		$content .= self::paragraph(
			$data['food_notes']
				? esc_html( $data['food_notes'] )
				: self::placeholder( __( 'Futterplan, Lieblingsfutter und Supplemente beschreiben.', 'reptilien-manager' ) )
		);

		if ( $data['pairings'] ) {
			$content .= self::separator();
			$content .= self::pairings_section( $data, 3 );
		}

		if ( $data['gallery'] ) {
			$content .= self::separator();
			$content .= self::heading( '📸 ' . __( 'Galerie', 'reptilien-manager' ), 3 );
			$content .= self::gallery( $data['gallery'], 3 );
		}

		return $content;
	}

	private static function render_zucht( $data ) {
		$content = self::banner( $data );
		$content .= self::paragraph( esc_html( self::intro_sentence( $data ) ), array( 'large' => true ) );
		$content .= self::pedigree_paragraph( $data );

		$table = self::table(
			array(
				array( __( 'Geschlecht', 'reptilien-manager' ), $data['sex_label'] ),
				array( __( 'Schlupfdatum', 'reptilien-manager' ), self::birth_with_age( $data ) ),
				array( __( 'Herkunft / Züchter', 'reptilien-manager' ), $data['origin'] ),
				array( __( 'Kennzeichnung', 'reptilien-manager' ), $data['identifier'] ),
				array( __( 'Gesamtlänge', 'reptilien-manager' ), $data['length'] ? $data['length'] . ' cm' : '' ),
				array( __( 'Aktuelles Gewicht', 'reptilien-manager' ), self::last_weight_label( $data ) ),
			)
		);

		$content .= self::heading( '📋 ' . __( 'Eckdaten', 'reptilien-manager' ), 3 );
		$content .= self::columns( self::image( $data['featured_id'], 'large' ), $table );

		$content .= self::heading( '🧬 ' . __( 'Genetik im Detail', 'reptilien-manager' ), 3 );
		$gene_lines = self::gene_lines( $data['gene_states'] );
		if ( $gene_lines ) {
			$content .= self::bullet_list( $gene_lines );
		} else {
			$content .= self::paragraph( esc_html__( 'Für dieses Tier sind keine besonderen Genanlagen hinterlegt (klassische Wildfarbe).', 'reptilien-manager' ) );
		}

		if ( $data['pairings'] ) {
			$content .= self::separator();
			$content .= self::pairings_section( $data, 3 );
		}

		$content .= self::separator();
		$content .= self::heading( '📝 ' . __( 'Zuchtplanung', 'reptilien-manager' ), 3 );
		$content .= self::paragraph( self::placeholder( __( 'Geplante Verpaarungen, Zuchtziele und bisherige Nachzuchten beschreiben.', 'reptilien-manager' ) ) );

		if ( $data['gallery'] ) {
			$content .= self::heading( '📸 ' . __( 'Galerie', 'reptilien-manager' ), 3 );
			$content .= self::gallery( $data['gallery'], 3 );
		}

		return $content;
	}

	private static function render_kurz( $data ) {
		$content  = self::image( $data['featured_id'] );
		$content .= self::paragraph( esc_html( self::intro_sentence( $data ) ), array( 'large' => true ) );
		$content .= self::bullet_list(
			array(
				$data['sex_label'] ? __( 'Geschlecht', 'reptilien-manager' ) . ': ' . $data['sex_label'] : '',
				self::birth_with_age( $data ) ? __( 'Schlupfdatum', 'reptilien-manager' ) . ': ' . self::birth_with_age( $data ) : '',
				__( 'Morph', 'reptilien-manager' ) . ': ' . $data['morph'],
				self::last_weight_label( $data ) ? __( 'Gewicht', 'reptilien-manager' ) . ': ' . self::last_weight_label( $data ) : '',
				$data['origin'] ? __( 'Herkunft', 'reptilien-manager' ) . ': ' . $data['origin'] : '',
			)
		);
		$content .= self::pedigree_paragraph( $data );

		// Kompakter Verpaarungs-Überblick.
		if ( $data['pairings'] ) {
			$items = array();
			foreach ( $data['pairings'] as $pairing ) {
				$eggs    = 0;
				$hatched = 0;
				foreach ( $pairing['clutches'] as $clutch ) {
					$eggs    += $clutch['eggs'];
					$hatched += $clutch['hatched'];
				}
				$item = '💞 ' . esc_html__( 'Verpaart mit', 'reptilien-manager' ) . ' ' . self::link( $pairing['partner_url'], $pairing['partner_name'] );
				$stats = array();
				if ( $pairing['clutches'] ) {
					/* translators: %d: Anzahl Gelege */
					$stats[] = sprintf( _n( '%d Gelege', '%d Gelege', count( $pairing['clutches'] ), 'reptilien-manager' ), count( $pairing['clutches'] ) );
				}
				if ( $eggs ) {
					/* translators: %d: Anzahl Eier */
					$stats[] = sprintf( _n( '%d Ei', '%d Eier', $eggs, 'reptilien-manager' ), $eggs );
				}
				if ( $hatched ) {
					/* translators: %d: Anzahl geschlüpft */
					$stats[] = sprintf( __( '%d geschlüpft', 'reptilien-manager' ), $hatched );
				}
				if ( $stats ) {
					$item .= ' <em>(' . esc_html( implode( ' · ', $stats ) ) . ')</em>';
				}
				$items[] = $item;

				foreach ( $pairing['offspring'] as $child ) {
					$items[] = '🦎 ' . esc_html__( 'Nachzucht:', 'reptilien-manager' ) . ' ' . self::link( $child['url'], $child['name'] );
				}
			}
			$content .= self::bullet_list_raw( $items );
		}

		$content .= self::paragraph( self::placeholder( __( 'Optional: ein persönlicher Satz zum Tier.', 'reptilien-manager' ) ) );

		if ( $data['gallery'] ) {
			$content .= self::gallery( $data['gallery'], 3 );
		}

		return $content;
	}
}
