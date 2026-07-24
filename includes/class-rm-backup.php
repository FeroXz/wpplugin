<?php
/**
 * Import / Export / Backup:
 * - JSON-Backup des gesamten Bestands (Export und Wiederherstellung/Transfer)
 * - CSV-Import für Altdaten
 * - druckbares PDF-Zuchtbuch (über den Browser)
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Backup {

	/**
	 * Format-Version des Backups (für spätere Migrationen).
	 */
	const FORMAT = 1;

	public static function init() {
		add_action( 'admin_post_rm_backup_export', array( __CLASS__, 'handle_export_json' ) );
		add_action( 'admin_post_rm_backup_import', array( __CLASS__, 'handle_import_json' ) );
		add_action( 'admin_post_rm_backup_csv', array( __CLASS__, 'handle_import_csv' ) );
		add_action( 'admin_post_rm_studbook', array( __CLASS__, 'handle_studbook' ) );
	}

	/* ---------------------------------------------------------------------
	 * Export-Daten (rein, testbar)
	 * ------------------------------------------------------------------ */

	/**
	 * Sammelt alle Bestandsdaten für das Backup.
	 *
	 * Medien-Verweise (Beitragsbild, Galerie) werden mitgesichert, lassen
	 * sich beim Transfer auf eine andere Seite aber nicht auflösen.
	 *
	 * @return array
	 */
	public static function export_data() {
		$animal_meta_keys = array(
			'_rm_sex', '_rm_birth', '_rm_origin', '_rm_acquired', '_rm_identifier',
			'_rm_length', '_rm_food_notes', '_rm_genes', '_rm_weights', '_rm_gallery',
			'_rm_parent_pairing', '_rm_clutch', '_rm_bcs', '_rm_temperament',
			'_rm_color', '_rm_shed_interval', '_rm_svl', '_rm_tail', '_rm_girth',
			'_rm_public',
		);
		$pairing_meta_keys = array(
			'_rm_sire', '_rm_dam', '_rm_pairing_date', '_rm_incubation_temp', '_rm_clutches',
		);
		$feeding_meta_keys = array(
			'_rm_feed_date', '_rm_feed_foods', '_rm_feed_amount', '_rm_feed_supplements', '_rm_feed_notes',
		);

		$animals = array();
		foreach ( self::all_posts( 'rm_animal' ) as $post ) {
			$species = wp_get_post_terms( $post->ID, 'rm_species', array( 'fields' => 'names' ) );
			$animals[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'status'  => $post->post_status,
				'species' => is_wp_error( $species ) ? array() : $species,
				'meta'    => self::collect_meta( $post->ID, $animal_meta_keys ),
			);
		}

		$pairings = array();
		foreach ( self::all_posts( 'rm_pairing' ) as $post ) {
			$pairings[] = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'status'  => $post->post_status,
				'meta'    => self::collect_meta( $post->ID, $pairing_meta_keys ),
			);
		}

		$feedings = array();
		foreach ( self::all_posts( 'rm_feeding_log' ) as $post ) {
			$entry = array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'status'  => $post->post_status,
				'animals' => array_map( 'intval', (array) get_post_meta( $post->ID, '_rm_feed_animal' ) ),
				'meta'    => self::collect_meta( $post->ID, $feeding_meta_keys ),
			);
			$feedings[] = $entry;
		}

		return array(
			'format'      => self::FORMAT,
			'generated'   => current_time( 'c' ),
			'site'        => home_url(),
			'animals'     => $animals,
			'pairings'    => $pairings,
			'feedings'    => $feedings,
			'food_prices' => get_option( 'rm_food_prices', array() ),
		);
	}

	/**
	 * Alle Beiträge eines Typs (alle Status außer Papierkorb/Auto-Draft).
	 *
	 * @param string $type Post-Type.
	 * @return WP_Post[]
	 */
	private static function all_posts( $type ) {
		return get_posts(
			array(
				'post_type'      => $type,
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private', 'pending' ),
				'orderby'        => 'ID',
				'order'          => 'ASC',
			)
		);
	}

	/**
	 * Ausgewählte Meta-Werte eines Beitrags einsammeln.
	 *
	 * @param int      $post_id Beitrags-ID.
	 * @param string[] $keys    Meta-Schlüssel.
	 * @return array
	 */
	private static function collect_meta( $post_id, $keys ) {
		$out = array();
		foreach ( $keys as $key ) {
			$value = get_post_meta( $post_id, $key, true );
			if ( '' !== $value && array() !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * CSV-Parsing (rein, testbar)
	 * ------------------------------------------------------------------ */

	/**
	 * Unterstützte CSV-Felder mit ihren Spalten-Aliassen (klein geschrieben).
	 *
	 * @return array<string,string[]>
	 */
	public static function csv_fields() {
		return array(
			'name'       => array( 'name', 'tier', 'titel', 'title' ),
			'sex'        => array( 'geschlecht', 'sex', 'sexe' ),
			'birth'      => array( 'schlupfdatum', 'schlupf', 'birth', 'geburtsdatum', 'hatch' ),
			'species'    => array( 'art', 'species', 'spezies' ),
			'origin'     => array( 'herkunft', 'origin', 'zuechter', 'züchter', 'breeder' ),
			'length'     => array( 'laenge', 'länge', 'length', 'gesamtlaenge' ),
			'weight'     => array( 'gewicht', 'weight', 'gramm' ),
			'identifier' => array( 'kennzeichnung', 'chip', 'identifier' ),
		);
	}

	/**
	 * Ordnet die Kopfzeile den bekannten Feldern zu.
	 *
	 * @param array $header Kopfzeilen-Zellen.
	 * @return array<string,int> Feld => Spaltenindex.
	 */
	public static function csv_column_map( $header ) {
		$fields = self::csv_fields();
		$map    = array();

		foreach ( $header as $index => $cell ) {
			$norm = self::mb_lower( trim( (string) $cell ) );
			foreach ( $fields as $field => $aliases ) {
				if ( isset( $map[ $field ] ) ) {
					continue;
				}
				if ( in_array( $norm, $aliases, true ) ) {
					$map[ $field ] = $index;
				}
			}
		}

		return $map;
	}

	/**
	 * Wandelt eine CSV-Zeile in einen Tier-Datensatz um.
	 *
	 * @param array $map Feld => Spaltenindex.
	 * @param array $row Zeilenzellen.
	 * @return array|null { title, species, weight, meta } oder null (ohne Name).
	 */
	public static function csv_row_to_animal( $map, $row ) {
		$get = static function ( $field ) use ( $map, $row ) {
			if ( ! isset( $map[ $field ] ) ) {
				return '';
			}
			$idx = $map[ $field ];
			return isset( $row[ $idx ] ) ? trim( (string) $row[ $idx ] ) : '';
		};

		$title = $get( 'name' );
		if ( '' === $title ) {
			return null;
		}

		$meta = array();
		$sex  = self::normalize_sex( $get( 'sex' ) );
		if ( $sex ) {
			$meta['_rm_sex'] = $sex;
		}
		$birth = self::normalize_date( $get( 'birth' ) );
		if ( $birth ) {
			$meta['_rm_birth'] = $birth;
		}
		if ( '' !== $get( 'origin' ) ) {
			$meta['_rm_origin'] = $get( 'origin' );
		}
		if ( '' !== $get( 'length' ) ) {
			$meta['_rm_length'] = (string) (float) str_replace( ',', '.', $get( 'length' ) );
		}
		if ( '' !== $get( 'identifier' ) ) {
			$meta['_rm_identifier'] = $get( 'identifier' );
		}

		$weight = '' !== $get( 'weight' ) ? (int) round( (float) str_replace( ',', '.', $get( 'weight' ) ) ) : 0;

		return array(
			'title'   => $title,
			'species' => $get( 'species' ),
			'weight'  => $weight,
			'meta'    => $meta,
		);
	}

	/**
	 * Geschlechts-Wert normalisieren.
	 *
	 * @param string $value Rohwert.
	 * @return string 'male'|'female'|'' (unbekannt).
	 */
	public static function normalize_sex( $value ) {
		$v = self::mb_lower( trim( $value ) );
		if ( in_array( $v, array( 'm', 'male', 'männlich', 'maennlich', '1.0', '1,0' ), true ) ) {
			return 'male';
		}
		if ( in_array( $v, array( 'w', 'f', 'female', 'weiblich', '0.1', '0,1' ), true ) ) {
			return 'female';
		}
		return '';
	}

	/**
	 * Datum in Y-m-d normalisieren (akzeptiert d.m.Y, d.m.y, Y-m-d).
	 *
	 * @param string $value Rohwert.
	 * @return string Y-m-d oder ''.
	 */
	public static function normalize_date( $value ) {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		// ISO bereits korrekt?
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}

		// d.m.Y oder d.m.y (auch mit / oder -).
		if ( preg_match( '#^(\d{1,2})[.\-/](\d{1,2})[.\-/](\d{2,4})$#', $value, $m ) ) {
			$day   = (int) $m[1];
			$month = (int) $m[2];
			$year  = (int) $m[3];
			if ( $year < 100 ) {
				$year += 2000;
			}
			if ( checkdate( $month, $day, $year ) ) {
				return sprintf( '%04d-%02d-%02d', $year, $month, $day );
			}
		}

		$ts = strtotime( $value );
		return $ts ? gmdate( 'Y-m-d', $ts ) : '';
	}

	/**
	 * mb_strtolower mit Fallback.
	 *
	 * @param string $s Eingabe.
	 * @return string
	 */
	private static function mb_lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	/* ---------------------------------------------------------------------
	 * Handler: JSON-Export
	 * ------------------------------------------------------------------ */

	public static function handle_export_json() {
		self::verify( 'rm_backup_export', 'edit_posts' );

		$data = self::export_data();

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reptilien-backup-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Handler: JSON-Import (Wiederherstellung / Transfer)
	 * ------------------------------------------------------------------ */

	public static function handle_import_json() {
		self::verify( 'rm_backup_import', 'manage_options' );

		$file = self::uploaded_file( 'rm_backup_file' );
		if ( ! $file ) {
			self::redirect( 'import_error' );
		}

		$raw  = file_get_contents( $file );
		$data = json_decode( $raw, true );

		if ( ! is_array( $data ) || empty( $data['animals'] ) ) {
			self::redirect( 'import_invalid' );
		}

		$counts = self::import_data( $data );

		self::redirect( 'imported', $counts );
	}

	/**
	 * Importiert einen Backup-Datensatz und legt neue Beiträge an.
	 * IDs werden neu vergeben und Verweise (Eltern-Verpaarung, Vater/Mutter,
	 * Fütterungs-Tiere) über ID-Maps umgeschrieben.
	 *
	 * @param array $data Backup-Struktur.
	 * @return array { animals, pairings, feedings }
	 */
	public static function import_data( $data ) {
		$animal_map  = array();
		$pairing_map = array();

		// 1. Tiere anlegen (ohne Eltern-Verpaarung, die erst nach den Verpaarungen bekannt ist).
		foreach ( $data['animals'] as $animal ) {
			$new_id = wp_insert_post(
				array(
					'post_type'   => 'rm_animal',
					'post_status' => self::safe_status( isset( $animal['status'] ) ? $animal['status'] : 'draft' ),
					'post_title'  => isset( $animal['title'] ) ? wp_strip_all_tags( $animal['title'] ) : __( 'Importiertes Tier', 'reptilien-manager' ),
					'post_content' => isset( $animal['content'] ) ? wp_kses_post( $animal['content'] ) : '',
				)
			);
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				continue;
			}
			$animal_map[ (int) $animal['id'] ] = $new_id;

			self::apply_meta( $new_id, isset( $animal['meta'] ) ? $animal['meta'] : array(), array( '_rm_parent_pairing', '_rm_gallery' ) );

			if ( ! empty( $animal['species'] ) ) {
				wp_set_object_terms( $new_id, array_map( 'sanitize_text_field', (array) $animal['species'] ), 'rm_species' );
			}
		}

		// 2. Verpaarungen anlegen und Vater/Mutter neu verknüpfen.
		if ( ! empty( $data['pairings'] ) ) {
			foreach ( $data['pairings'] as $pairing ) {
				$new_id = wp_insert_post(
					array(
						'post_type'    => 'rm_pairing',
						'post_status'  => self::safe_status( isset( $pairing['status'] ) ? $pairing['status'] : 'publish' ),
						'post_title'   => isset( $pairing['title'] ) ? wp_strip_all_tags( $pairing['title'] ) : __( 'Verpaarung', 'reptilien-manager' ),
						'post_content' => isset( $pairing['content'] ) ? wp_kses_post( $pairing['content'] ) : '',
					)
				);
				if ( is_wp_error( $new_id ) || ! $new_id ) {
					continue;
				}
				$pairing_map[ (int) $pairing['id'] ] = $new_id;

				$meta = isset( $pairing['meta'] ) ? $pairing['meta'] : array();
				if ( isset( $meta['_rm_sire'] ) ) {
					$meta['_rm_sire'] = isset( $animal_map[ (int) $meta['_rm_sire'] ] ) ? $animal_map[ (int) $meta['_rm_sire'] ] : '';
				}
				if ( isset( $meta['_rm_dam'] ) ) {
					$meta['_rm_dam'] = isset( $animal_map[ (int) $meta['_rm_dam'] ] ) ? $animal_map[ (int) $meta['_rm_dam'] ] : '';
				}
				self::apply_meta( $new_id, $meta, array() );
			}
		}

		// 3. Eltern-Verpaarung der Tiere nachtragen (jetzt sind die Verpaarungs-IDs bekannt).
		foreach ( $data['animals'] as $animal ) {
			if ( empty( $animal['meta']['_rm_parent_pairing'] ) || ! isset( $animal_map[ (int) $animal['id'] ] ) ) {
				continue;
			}
			$old_pairing = (int) $animal['meta']['_rm_parent_pairing'];
			if ( isset( $pairing_map[ $old_pairing ] ) ) {
				update_post_meta( $animal_map[ (int) $animal['id'] ], '_rm_parent_pairing', $pairing_map[ $old_pairing ] );
			}
		}

		// 4. Fütterungen anlegen und Tiere neu verknüpfen.
		$feed_count = 0;
		if ( ! empty( $data['feedings'] ) ) {
			foreach ( $data['feedings'] as $feeding ) {
				$new_id = wp_insert_post(
					array(
						'post_type'   => 'rm_feeding_log',
						'post_status' => self::safe_status( isset( $feeding['status'] ) ? $feeding['status'] : 'publish' ),
						'post_title'  => isset( $feeding['title'] ) ? wp_strip_all_tags( $feeding['title'] ) : __( 'Fütterung', 'reptilien-manager' ),
					)
				);
				if ( is_wp_error( $new_id ) || ! $new_id ) {
					continue;
				}
				$feed_count++;

				self::apply_meta( $new_id, isset( $feeding['meta'] ) ? $feeding['meta'] : array(), array() );

				if ( ! empty( $feeding['animals'] ) ) {
					foreach ( (array) $feeding['animals'] as $old_animal ) {
						if ( isset( $animal_map[ (int) $old_animal ] ) ) {
							add_post_meta( $new_id, '_rm_feed_animal', $animal_map[ (int) $old_animal ] );
						}
					}
				}
			}
		}

		if ( isset( $data['food_prices'] ) && is_array( $data['food_prices'] ) ) {
			RM_Feeding::save_food_prices( $data['food_prices'] );
		}

		return array(
			'animals'  => count( $animal_map ),
			'pairings' => count( $pairing_map ),
			'feedings' => $feed_count,
		);
	}

	/**
	 * Meta-Werte setzen (mit Ausschluss bestimmter Schlüssel).
	 *
	 * @param int      $post_id Beitrags-ID.
	 * @param array    $meta    Schlüssel => Wert.
	 * @param string[] $skip    Auszulassende Schlüssel.
	 */
	private static function apply_meta( $post_id, $meta, $skip ) {
		foreach ( $meta as $key => $value ) {
			if ( in_array( $key, $skip, true ) ) {
				continue;
			}
			// Nur Plugin-eigene Schlüssel zulassen.
			if ( 0 !== strpos( $key, '_rm_' ) ) {
				continue;
			}
			update_post_meta( $post_id, $key, $value );
		}
	}

	/* ---------------------------------------------------------------------
	 * Handler: CSV-Import
	 * ------------------------------------------------------------------ */

	public static function handle_import_csv() {
		self::verify( 'rm_backup_csv', 'edit_others_posts' );

		$file = self::uploaded_file( 'rm_csv_file' );
		if ( ! $file ) {
			self::redirect( 'csv_error' );
		}

		$handle = fopen( $file, 'r' );
		if ( ! $handle ) {
			self::redirect( 'csv_error' );
		}

		$delimiter = self::detect_delimiter( $file );
		$header    = fgetcsv( $handle, 0, $delimiter );
		$map       = is_array( $header ) ? self::csv_column_map( $header ) : array();

		if ( ! isset( $map['name'] ) ) {
			fclose( $handle );
			self::redirect( 'csv_noname' );
		}

		$created = 0;
		while ( ( $row = fgetcsv( $handle, 0, $delimiter ) ) !== false ) {
			$animal = self::csv_row_to_animal( $map, $row );
			if ( ! $animal ) {
				continue;
			}

			$new_id = wp_insert_post(
				array(
					'post_type'   => 'rm_animal',
					'post_status' => 'draft',
					'post_title'  => wp_strip_all_tags( $animal['title'] ),
				)
			);
			if ( is_wp_error( $new_id ) || ! $new_id ) {
				continue;
			}
			$created++;

			foreach ( $animal['meta'] as $key => $value ) {
				update_post_meta( $new_id, $key, sanitize_text_field( $value ) );
			}
			if ( $animal['weight'] > 0 ) {
				update_post_meta(
					$new_id,
					'_rm_weights',
					array(
						array(
							'date'  => ! empty( $animal['meta']['_rm_birth'] ) ? $animal['meta']['_rm_birth'] : current_time( 'Y-m-d' ),
							'grams' => $animal['weight'],
						),
					)
				);
			}

			// Art-Zuordnung (Standard Bartagame, wenn nichts erkennbar).
			$species_key  = $animal['species'] ? RM_Species::key_from_term_names( array( $animal['species'] ) ) : RM_Species::DEFAULT_KEY;
			$species_name = RM_Species::label( $species_key );
			wp_set_object_terms( $new_id, $species_name, 'rm_species' );
		}

		fclose( $handle );
		self::redirect( 'csv_done', array( 'created' => $created ) );
	}

	/**
	 * Trennzeichen einer CSV grob erkennen (Semikolon oder Komma).
	 *
	 * @param string $file Pfad.
	 * @return string
	 */
	private static function detect_delimiter( $file ) {
		$line = '';
		$fh   = fopen( $file, 'r' );
		if ( $fh ) {
			$line = (string) fgets( $fh );
			fclose( $fh );
		}
		return ( substr_count( $line, ';' ) >= substr_count( $line, ',' ) ) ? ';' : ',';
	}

	/* ---------------------------------------------------------------------
	 * Handler: PDF-Zuchtbuch (druckbare Ansicht)
	 * ------------------------------------------------------------------ */

	public static function handle_studbook() {
		self::verify( 'rm_studbook', 'edit_posts' );

		$animals = self::all_posts( 'rm_animal' );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		$sexes = RM_Animal_Meta::sexes();
		?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8" />
	<title><?php esc_html_e( 'Zuchtbuch', 'reptilien-manager' ); ?></title>
	<style>
		body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1e293b; max-width: 1000px; margin: 1.5rem auto; padding: 0 1.5rem; }
		h1 { font-size: 1.7rem; border-bottom: 3px solid #4f46e5; padding-bottom: .4rem; }
		table { width: 100%; border-collapse: collapse; font-size: .85rem; }
		th, td { text-align: left; padding: .45rem .6rem; border-bottom: 1px solid #e2e8f0; }
		th { background: #f8fafc; }
		.muted { color: #64748b; }
		.print-btn { display: inline-block; margin: 1rem 0; padding: .6rem 1.3rem; background: #4f46e5; color: #fff; border: none; border-radius: 999px; font-size: 1rem; cursor: pointer; }
		@media print { .print-btn { display: none; } body { margin: 0; max-width: none; } }
	</style>
</head>
<body>
	<button class="print-btn" onclick="window.print()"><?php esc_html_e( 'Drucken / als PDF speichern', 'reptilien-manager' ); ?></button>
	<h1>📖 <?php esc_html_e( 'Zuchtbuch', 'reptilien-manager' ); ?></h1>
	<p class="muted"><?php echo esc_html( sprintf( /* translators: 1: Anzahl, 2: Datum */ __( '%1$d Tiere · Stand %2$s', 'reptilien-manager' ), count( $animals ), date_i18n( get_option( 'date_format' ) ) ) ); ?></p>
	<table>
		<thead>
			<tr>
				<th><?php esc_html_e( 'Name', 'reptilien-manager' ); ?></th>
				<th><?php esc_html_e( 'Art', 'reptilien-manager' ); ?></th>
				<th><?php esc_html_e( 'Geschlecht', 'reptilien-manager' ); ?></th>
				<th><?php esc_html_e( 'Schlupf', 'reptilien-manager' ); ?></th>
				<th><?php esc_html_e( 'Morph', 'reptilien-manager' ); ?></th>
				<th><?php esc_html_e( 'Herkunft', 'reptilien-manager' ); ?></th>
				<th><?php esc_html_e( 'Gewicht', 'reptilien-manager' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $animals as $animal ) : ?>
				<?php
				$sex     = get_post_meta( $animal->ID, '_rm_sex', true );
				$birth   = get_post_meta( $animal->ID, '_rm_birth', true );
				$weights = get_post_meta( $animal->ID, '_rm_weights', true );
				$last_w  = ( is_array( $weights ) && $weights ) ? end( $weights ) : null;
				?>
				<tr>
					<td><strong><?php echo esc_html( $animal->post_title ); ?></strong></td>
					<td><?php echo esc_html( RM_Species::label( RM_Species::key_for_animal( $animal->ID ) ) ); ?></td>
					<td><?php echo esc_html( isset( $sexes[ $sex ] ) ? $sexes[ $sex ] : $sexes['unknown'] ); ?></td>
					<td><?php echo esc_html( $birth && strtotime( $birth ) ? date_i18n( get_option( 'date_format' ), strtotime( $birth ) ) : '—' ); ?></td>
					<td><?php echo esc_html( RM_Genetics::animal_morph_label( $animal->ID ) ); ?></td>
					<td><?php echo esc_html( get_post_meta( $animal->ID, '_rm_origin', true ) ); ?></td>
					<td><?php echo esc_html( $last_w ? $last_w['grams'] . ' g' : '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</body>
</html>
		<?php
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Helfer
	 * ------------------------------------------------------------------ */

	/**
	 * Nonce + Capability prüfen oder abbrechen.
	 *
	 * @param string $action Nonce-Aktion.
	 * @param string $cap    Fähigkeit.
	 */
	private static function verify( $action, $cap ) {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_key( $_REQUEST['_wpnonce'] ) : '';
		if ( ! wp_verify_nonce( $nonce, $action ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( $cap ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}
	}

	/**
	 * Hochgeladene Datei validieren und temporären Pfad zurückgeben.
	 *
	 * @param string $field Formularfeld.
	 * @return string|false
	 */
	private static function uploaded_file( $field ) {
		if ( empty( $_FILES[ $field ] ) || ! isset( $_FILES[ $field ]['tmp_name'] ) ) {
			return false;
		}
		$tmp   = $_FILES[ $field ]['tmp_name']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$error = isset( $_FILES[ $field ]['error'] ) ? (int) $_FILES[ $field ]['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error || ! is_uploaded_file( $tmp ) ) {
			return false;
		}
		return $tmp;
	}

	/**
	 * Beitrags-Status auf erlaubte Werte begrenzen.
	 *
	 * @param string $status Roh-Status.
	 * @return string
	 */
	private static function safe_status( $status ) {
		$allowed = array( 'publish', 'draft', 'private', 'pending' );
		return in_array( $status, $allowed, true ) ? $status : 'draft';
	}

	/**
	 * Zurück zur Import/Export-Seite mit Statusmeldung.
	 *
	 * @param string $msg   Nachrichtenschlüssel.
	 * @param array  $extra Zusätzliche Query-Args.
	 */
	private static function redirect( $msg, $extra = array() ) {
		$args = array_merge( array( 'rm_msg' => $msg ), $extra );
		wp_safe_redirect( add_query_arg( $args, admin_url( 'edit.php?post_type=rm_animal&page=rm-backup' ) ) );
		exit;
	}
}
