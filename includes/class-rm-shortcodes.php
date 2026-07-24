<?php
/**
 * Frontend-Shortcodes: Tierliste und Tierprofil.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Shortcodes {

	public static function init() {
		add_shortcode( 'reptilien', array( __CLASS__, 'animal_list' ) );
		add_shortcode( 'reptil', array( __CLASS__, 'animal_profile' ) );
		add_shortcode( 'reptilien-dashboard', array( __CLASS__, 'dashboard' ) );
		add_shortcode( 'reptilien-stammbaum', array( __CLASS__, 'pedigree' ) );
		add_shortcode( 'reptilien-genetik', array( __CLASS__, 'genetics_calculator' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_styles' ) );
	}

	public static function register_styles() {
		wp_register_style( 'rm-frontend', RM_PLUGIN_URL . 'assets/css/frontend.css', array(), RM_VERSION );
	}

	/**
	 * Chart.js für Frontend-Visualisierungen einbinden (analog zum Admin,
	 * lokal bundelbar oder via CDN, Filter `rm_chartjs_src`).
	 */
	private static function enqueue_charts() {
		$local_path = RM_PLUGIN_DIR . 'assets/js/vendor/chart.min.js';
		$local_url  = RM_PLUGIN_URL . 'assets/js/vendor/chart.min.js';
		$cdn        = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js';

		$src = file_exists( $local_path ) ? $local_url : $cdn;
		$src = apply_filters( 'rm_chartjs_src', $src );

		wp_enqueue_script( 'rm-chartjs', $src, array(), '4.4.1', true );
		wp_enqueue_script( 'rm-dashboard', RM_PLUGIN_URL . 'assets/js/dashboard.js', array( 'rm-chartjs' ), RM_VERSION, true );
	}

	/**
	 * [reptilien sex="male|female"] – filterbare Übersicht aller Tiere.
	 *
	 * Attribute setzen Standardwerte, GET-Parameter der Filterleiste
	 * (rm_f_*) überschreiben sie.
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string
	 */
	public static function animal_list( $atts ) {
		wp_enqueue_style( 'rm-frontend' );

		$atts = shortcode_atts(
			array(
				'sex'      => '',
				'species'  => '',
				'morph'    => '',
				'sort'     => 'title',
				'filter'   => 'yes',
				'per_page' => 24,
			),
			$atts,
			'reptilien'
		);

		$filters = self::resolve_filters( $atts );
		$animals = self::query_animals( $filters );

		ob_start();

		if ( 'no' !== $atts['filter'] ) {
			self::render_filter_bar( $filters );
		}

		if ( ! $animals ) {
			echo '<p class="rm-notice">' . esc_html__( 'Keine Tiere gefunden, die den Filtern entsprechen.', 'reptilien-manager' ) . '</p>';
			return ob_get_clean();
		}

		// Pagination (per_page="0" zeigt alle).
		$per_page = max( 0, (int) $atts['per_page'] );
		$total    = count( $animals );
		$page     = 1;
		$pages    = 1;
		if ( $per_page > 0 && $total > $per_page ) {
			$pages = (int) ceil( $total / $per_page );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Navigation.
			$page  = isset( $_GET['rm_page'] ) ? max( 1, min( $pages, absint( $_GET['rm_page'] ) ) ) : 1;
			$animals = array_slice( $animals, ( $page - 1 ) * $per_page, $per_page );
		}

		echo '<div class="rm-animal-grid">';
		foreach ( $animals as $animal ) {
			$sexes     = RM_Animal_Meta::sexes();
			$sex_value = get_post_meta( $animal->ID, '_rm_sex', true );
			$sex_label = isset( $sexes[ $sex_value ] ) ? $sexes[ $sex_value ] : $sexes['unknown'];
			$birth     = get_post_meta( $animal->ID, '_rm_birth', true );
			$species   = wp_get_post_terms( $animal->ID, 'rm_species', array( 'fields' => 'names' ) );

			$meta_parts = array();
			if ( $birth && RM_Animal_Meta::age_label( $birth ) ) {
				$meta_parts[] = RM_Animal_Meta::age_label( $birth );
			}
			if ( is_array( $species ) && $species ) {
				$meta_parts[] = implode( ', ', $species );
			}
			?>
			<article class="rm-animal-card">
				<a class="rm-card__media" href="<?php echo esc_url( get_permalink( $animal ) ); ?>">
					<?php if ( has_post_thumbnail( $animal ) ) : ?>
						<?php echo get_the_post_thumbnail( $animal, 'medium_large' ); ?>
					<?php else : ?>
						<span class="rm-card__placeholder" aria-hidden="true">🦎</span>
					<?php endif; ?>
					<span class="rm-card__badge"><?php echo esc_html( $sex_label ); ?></span>
				</a>
				<div class="rm-card__body">
					<h3 class="rm-card__title">
						<a href="<?php echo esc_url( get_permalink( $animal ) ); ?>"><?php echo esc_html( $animal->post_title ); ?></a>
					</h3>
					<p class="rm-card__morph"><?php echo esc_html( RM_Genetics::animal_morph_label( $animal->ID ) ); ?></p>
					<?php if ( $meta_parts ) : ?>
						<p class="rm-card__meta"><?php echo esc_html( implode( ' · ', $meta_parts ) ); ?></p>
					<?php endif; ?>
				</div>
			</article>
			<?php
		}
		echo '</div>';

		self::render_pagination( $page, $pages );

		return ob_get_clean();
	}

	/**
	 * Seitennummerierung rendern (behält bestehende Query-Parameter bei).
	 *
	 * @param int $page  Aktuelle Seite.
	 * @param int $pages Gesamtzahl Seiten.
	 */
	private static function render_pagination( $page, $pages ) {
		if ( $pages < 2 ) {
			return;
		}

		$link = static function ( $p ) {
			return esc_url( add_query_arg( 'rm_page', $p ) );
		};
		?>
		<nav class="rm-pagination" aria-label="<?php esc_attr_e( 'Seitennummerierung', 'reptilien-manager' ); ?>">
			<?php if ( $page > 1 ) : ?>
				<a class="rm-page-link" href="<?php echo $link( $page - 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits via esc_url. ?>">&larr;</a>
			<?php endif; ?>
			<?php for ( $i = 1; $i <= $pages; $i++ ) : ?>
				<?php if ( $i === $page ) : ?>
					<span class="rm-page-link rm-page-link--current" aria-current="page"><?php echo esc_html( $i ); ?></span>
				<?php else : ?>
					<a class="rm-page-link" href="<?php echo $link( $i ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits via esc_url. ?>"><?php echo esc_html( $i ); ?></a>
				<?php endif; ?>
			<?php endfor; ?>
			<?php if ( $page < $pages ) : ?>
				<a class="rm-page-link" href="<?php echo $link( $page + 1 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- bereits via esc_url. ?>">&rarr;</a>
			<?php endif; ?>
		</nav>
		<?php
	}

	/**
	 * Filterwerte aus Shortcode-Attributen und GET-Parametern auflösen.
	 *
	 * @param array $atts Shortcode-Attribute (Standardwerte).
	 * @return array
	 */
	private static function resolve_filters( $atts ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reine Lese-Filter über GET.
		$get = static function ( $key, $default = '' ) {
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : $default;
		};

		$submitted = isset( $_GET['rm_filter'] );
		// phpcs:enable

		$sort = $submitted ? $get( 'rm_f_sort', $atts['sort'] ) : $atts['sort'];
		if ( ! in_array( $sort, array( 'title', 'weight', 'age' ), true ) ) {
			$sort = 'title';
		}

		return array(
			'sex'     => $submitted ? $get( 'rm_f_sex', '' ) : $atts['sex'],
			'species' => $submitted ? $get( 'rm_f_species', '' ) : $atts['species'],
			'morph'   => $submitted ? $get( 'rm_f_morph', '' ) : $atts['morph'],
			'age_min' => $submitted ? $get( 'rm_f_age_min', '' ) : '',
			'age_max' => $submitted ? $get( 'rm_f_age_max', '' ) : '',
			'sort'    => $sort,
		);
	}

	/**
	 * Tiere anhand der Filter abfragen und sortieren.
	 *
	 * Sex/Art laufen über die DB; Alter und Morph werden nachgelagert in PHP
	 * gefiltert (berechnete Werte).
	 *
	 * @param array $filters Filterwerte.
	 * @return WP_Post[]
	 */
	private static function query_animals( $filters ) {
		$args = array(
			'post_type'      => 'rm_animal',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Als privat markierte Tiere im Frontend ausschließen.
		$meta_query = array( RM_Roles::public_meta_query() );

		if ( in_array( $filters['sex'], array( 'male', 'female' ), true ) ) {
			$meta_query[] = array(
				'key'   => '_rm_sex',
				'value' => $filters['sex'],
			);
		}

		if ( count( $meta_query ) > 1 ) {
			$meta_query['relation'] = 'AND';
		}
		$args['meta_query'] = $meta_query;

		if ( $filters['species'] ) {
			$args['tax_query'] = array(
				array(
					'taxonomy' => 'rm_species',
					'field'    => 'slug',
					'terms'    => $filters['species'],
				),
			);
		}

		$animals = get_posts( $args );

		// Nachgelagerte Filter (Alter, Morph) + Sortierung.
		$age_min = '' !== $filters['age_min'] ? (float) $filters['age_min'] : null;
		$age_max = '' !== $filters['age_max'] ? (float) $filters['age_max'] : null;
		$morph_q = $filters['morph'] ? mb_strtolower( $filters['morph'] ) : '';

		$filtered = array();
		foreach ( $animals as $animal ) {
			$birth  = get_post_meta( $animal->ID, '_rm_birth', true );
			$months = $birth ? RM_Animal_Meta::age_in_months( $birth ) : null;

			if ( null !== $age_min && ( null === $months || $months < $age_min ) ) {
				continue;
			}
			if ( null !== $age_max && ( null === $months || $months > $age_max ) ) {
				continue;
			}
			if ( $morph_q ) {
				$morph = mb_strtolower( RM_Genetics::animal_morph_label( $animal->ID ) );
				if ( false === mb_strpos( $morph, $morph_q ) ) {
					continue;
				}
			}

			$animal->rm_months = $months;
			$w                 = get_post_meta( $animal->ID, '_rm_weights', true );
			$animal->rm_weight = ( is_array( $w ) && $w ) ? (int) end( $w )['grams'] : 0;
			$filtered[]        = $animal;
		}

		self::sort_animals( $filtered, $filters['sort'] );

		return $filtered;
	}

	/**
	 * Tierliste in-place sortieren.
	 *
	 * @param WP_Post[] $animals Referenz auf die Liste.
	 * @param string    $sort    title|weight|age.
	 */
	private static function sort_animals( &$animals, $sort ) {
		if ( 'weight' === $sort ) {
			usort(
				$animals,
				static function ( $a, $b ) {
					return $b->rm_weight <=> $a->rm_weight;
				}
			);
		} elseif ( 'age' === $sort ) {
			usort(
				$animals,
				static function ( $a, $b ) {
					// Ältere zuerst; unbekanntes Alter ans Ende.
					$am = null === $a->rm_months ? -1 : $a->rm_months;
					$bm = null === $b->rm_months ? -1 : $b->rm_months;
					return $bm <=> $am;
				}
			);
		}
		// 'title' ist bereits durch die Query sortiert.
	}

	/**
	 * Filterleiste (GET-Formular) rendern.
	 *
	 * @param array $filters Aktuelle Filterwerte.
	 */
	private static function render_filter_bar( $filters ) {
		$species_terms = get_terms(
			array(
				'taxonomy'   => 'rm_species',
				'hide_empty' => true,
			)
		);
		?>
		<form class="rm-filter-bar" method="get">
			<input type="hidden" name="rm_filter" value="1" />
			<label>
				<span><?php esc_html_e( 'Geschlecht', 'reptilien-manager' ); ?></span>
				<select name="rm_f_sex">
					<option value=""><?php esc_html_e( 'Alle', 'reptilien-manager' ); ?></option>
					<option value="male" <?php selected( $filters['sex'], 'male' ); ?>><?php esc_html_e( 'Männlich', 'reptilien-manager' ); ?></option>
					<option value="female" <?php selected( $filters['sex'], 'female' ); ?>><?php esc_html_e( 'Weiblich', 'reptilien-manager' ); ?></option>
				</select>
			</label>
			<?php if ( ! is_wp_error( $species_terms ) && count( $species_terms ) > 1 ) : ?>
				<label>
					<span><?php esc_html_e( 'Art', 'reptilien-manager' ); ?></span>
					<select name="rm_f_species">
						<option value=""><?php esc_html_e( 'Alle', 'reptilien-manager' ); ?></option>
						<?php foreach ( $species_terms as $term ) : ?>
							<option value="<?php echo esc_attr( $term->slug ); ?>" <?php selected( $filters['species'], $term->slug ); ?>><?php echo esc_html( $term->name ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
			<?php endif; ?>
			<label>
				<span><?php esc_html_e( 'Morph', 'reptilien-manager' ); ?></span>
				<input type="text" name="rm_f_morph" value="<?php echo esc_attr( $filters['morph'] ); ?>" placeholder="<?php esc_attr_e( 'z. B. Hypo', 'reptilien-manager' ); ?>" />
			</label>
			<label class="rm-filter-age">
				<span><?php esc_html_e( 'Alter (Monate)', 'reptilien-manager' ); ?></span>
				<span class="rm-filter-age__inputs">
					<input type="number" min="0" name="rm_f_age_min" value="<?php echo esc_attr( $filters['age_min'] ); ?>" placeholder="min" />
					<input type="number" min="0" name="rm_f_age_max" value="<?php echo esc_attr( $filters['age_max'] ); ?>" placeholder="max" />
				</span>
			</label>
			<label>
				<span><?php esc_html_e( 'Sortierung', 'reptilien-manager' ); ?></span>
				<select name="rm_f_sort">
					<option value="title" <?php selected( $filters['sort'], 'title' ); ?>><?php esc_html_e( 'Name', 'reptilien-manager' ); ?></option>
					<option value="age" <?php selected( $filters['sort'], 'age' ); ?>><?php esc_html_e( 'Alter', 'reptilien-manager' ); ?></option>
					<option value="weight" <?php selected( $filters['sort'], 'weight' ); ?>><?php esc_html_e( 'Gewicht', 'reptilien-manager' ); ?></option>
				</select>
			</label>
			<button type="submit" class="rm-filter-submit"><?php esc_html_e( 'Filtern', 'reptilien-manager' ); ?></button>
		</form>
		<?php
	}

	/**
	 * [reptilien-dashboard] – Bestands-Übersicht mit Kennzahlen und Diagrammen.
	 *
	 * @return string
	 */
	public static function dashboard() {
		wp_enqueue_style( 'rm-frontend' );
		self::enqueue_charts();

		$stats = RM_Stats::collect();

		if ( ! $stats['total'] ) {
			return '<p class="rm-notice">' . esc_html__( 'Noch keine Tiere im Bestand.', 'reptilien-manager' ) . '</p>';
		}

		$sexes     = RM_Animal_Meta::sexes();
		$age_labels = RM_Stats::age_group_labels();

		// Daten für Chart.js aufbereiten.
		$sex_data = array();
		foreach ( $stats['sex'] as $key => $count ) {
			if ( $count > 0 ) {
				$sex_data[] = array( 'label' => $sexes[ $key ], 'value' => $count );
			}
		}
		$age_data = array();
		foreach ( $stats['age_groups'] as $key => $count ) {
			if ( $count > 0 ) {
				$age_data[] = array( 'label' => $age_labels[ $key ], 'value' => $count );
			}
		}
		$morph_data = array();
		foreach ( array_slice( $stats['morphs'], 0, 8, true ) as $label => $count ) {
			$morph_data[] = array( 'label' => $label, 'value' => $count );
		}

		$chart_payload = array(
			'sex'   => $sex_data,
			'age'   => $age_data,
			'morph' => $morph_data,
		);

		ob_start();
		?>
		<div class="rm-dashboard">
			<div class="rm-stat-tiles">
				<div class="rm-stat-tile">
					<span class="rm-stat-tile__value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
					<span class="rm-stat-tile__label"><?php esc_html_e( 'Tiere gesamt', 'reptilien-manager' ); ?></span>
				</div>
				<div class="rm-stat-tile">
					<span class="rm-stat-tile__value"><?php echo esc_html( $stats['sex']['male'] . '.' . $stats['sex']['female'] ); ?></span>
					<span class="rm-stat-tile__label"><?php esc_html_e( 'Männlich.Weiblich', 'reptilien-manager' ); ?></span>
				</div>
				<div class="rm-stat-tile">
					<span class="rm-stat-tile__value"><?php echo esc_html( null === $stats['avg_weight'] ? '—' : round( $stats['avg_weight'] ) . ' g' ); ?></span>
					<span class="rm-stat-tile__label"><?php esc_html_e( 'Ø Gewicht', 'reptilien-manager' ); ?></span>
				</div>
				<div class="rm-stat-tile">
					<span class="rm-stat-tile__value"><?php echo esc_html( null === $stats['hatch_rate'] ? '—' : round( $stats['hatch_rate'] * 100 ) . ' %' ); ?></span>
					<span class="rm-stat-tile__label"><?php esc_html_e( 'Ø Schlupfquote', 'reptilien-manager' ); ?></span>
				</div>
			</div>

			<div class="rm-dashboard-charts">
				<div class="rm-chart-card">
					<h3><?php esc_html_e( 'Geschlechterverteilung', 'reptilien-manager' ); ?></h3>
					<canvas id="rm-chart-sex" height="220"></canvas>
				</div>
				<div class="rm-chart-card">
					<h3><?php esc_html_e( 'Altersverteilung', 'reptilien-manager' ); ?></h3>
					<canvas id="rm-chart-age" height="220"></canvas>
				</div>
				<div class="rm-chart-card rm-chart-card--wide">
					<h3><?php esc_html_e( 'Morph-Verteilung (Top 8)', 'reptilien-manager' ); ?></h3>
					<canvas id="rm-chart-morph" height="220"></canvas>
				</div>
			</div>

			<script type="application/json" id="rm-dashboard-data"><?php echo wp_json_encode( $chart_payload ); ?></script>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [reptil id="123"] – Detailprofil eines Tieres inkl. Galerie.
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string
	 */
	public static function animal_profile( $atts ) {
		wp_enqueue_style( 'rm-frontend' );

		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'reptil' );
		$animal = get_post( absint( $atts['id'] ) );

		if ( ! $animal || 'rm_animal' !== $animal->post_type || 'publish' !== $animal->post_status || ! RM_Roles::is_public( $animal->ID ) ) {
			return '<p class="rm-notice">' . esc_html__( 'Tier nicht gefunden.', 'reptilien-manager' ) . '</p>';
		}

		$sexes   = RM_Animal_Meta::sexes();
		$sex     = get_post_meta( $animal->ID, '_rm_sex', true );
		$birth   = get_post_meta( $animal->ID, '_rm_birth', true );
		$origin  = get_post_meta( $animal->ID, '_rm_origin', true );
		$length  = get_post_meta( $animal->ID, '_rm_length', true );
		$weights = get_post_meta( $animal->ID, '_rm_weights', true );
		$gallery = get_post_meta( $animal->ID, '_rm_gallery', true );
		$species = wp_get_post_terms( $animal->ID, 'rm_species', array( 'fields' => 'names' ) );

		$last_weight = ( is_array( $weights ) && $weights ) ? end( $weights ) : null;

		$facts = array();
		if ( is_array( $species ) && $species ) {
			$facts[ __( 'Art', 'reptilien-manager' ) ] = implode( ', ', $species );
		}
		$facts[ __( 'Geschlecht', 'reptilien-manager' ) ] = isset( $sexes[ $sex ] ) ? $sexes[ $sex ] : $sexes['unknown'];
		if ( $birth && strtotime( $birth ) ) {
			$facts[ __( 'Schlupf', 'reptilien-manager' ) ] = date_i18n( get_option( 'date_format' ), strtotime( $birth ) );
			if ( RM_Animal_Meta::age_label( $birth ) ) {
				$facts[ __( 'Alter', 'reptilien-manager' ) ] = RM_Animal_Meta::age_label( $birth );
			}
		}
		if ( $origin ) {
			$facts[ __( 'Herkunft', 'reptilien-manager' ) ] = $origin;
		}
		if ( $length ) {
			$facts[ __( 'Länge', 'reptilien-manager' ) ] = $length . ' cm';
		}
		if ( $last_weight ) {
			$weight_value = $last_weight['grams'] . ' g';
			if ( ! empty( $last_weight['date'] ) && strtotime( $last_weight['date'] ) ) {
				$weight_value .= ' (' . date_i18n( get_option( 'date_format' ), strtotime( $last_weight['date'] ) ) . ')';
			}
			$facts[ __( 'Gewicht', 'reptilien-manager' ) ] = $weight_value;
		}

		ob_start();
		?>
		<div class="rm-animal-profile">
			<aside class="rm-profile__aside">
				<div class="rm-profile__photo">
					<?php if ( has_post_thumbnail( $animal ) ) : ?>
						<?php echo get_the_post_thumbnail( $animal, 'large' ); ?>
					<?php else : ?>
						<span class="rm-card__placeholder" aria-hidden="true">🦎</span>
					<?php endif; ?>
				</div>

				<dl class="rm-profile__facts">
					<?php foreach ( $facts as $label => $value ) : ?>
						<div class="rm-profile__fact">
							<dt><?php echo esc_html( $label ); ?></dt>
							<dd><?php echo esc_html( $value ); ?></dd>
						</div>
					<?php endforeach; ?>
				</dl>
			</aside>

			<div class="rm-profile__body">
				<h2 class="rm-profile__title"><?php echo esc_html( $animal->post_title ); ?></h2>
				<p class="rm-profile__morph"><?php echo esc_html( RM_Genetics::animal_morph_label( $animal->ID ) ); ?></p>

				<?php if ( $animal->post_content ) : ?>
					<div class="rm-profile-description"><?php echo wp_kses_post( wpautop( $animal->post_content ) ); ?></div>
				<?php endif; ?>

				<?php if ( is_array( $gallery ) && $gallery ) : ?>
					<div class="rm-profile-gallery">
						<?php foreach ( $gallery as $attachment_id ) : ?>
							<?php
							$full = wp_get_attachment_image_url( $attachment_id, 'full' );
							if ( ! $full ) {
								continue;
							}
							?>
							<a href="<?php echo esc_url( $full ); ?>">
								<?php echo wp_get_attachment_image( $attachment_id, 'medium' ); ?>
							</a>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * [reptilien-stammbaum id="123" generationen="3"] – Ahnentafel eines Tieres.
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string
	 */
	public static function pedigree( $atts ) {
		wp_enqueue_style( 'rm-frontend' );

		$atts = shortcode_atts(
			array(
				'id'           => 0,
				'generationen' => 3,
			),
			$atts,
			'reptilien-stammbaum'
		);

		$animal = get_post( absint( $atts['id'] ) );
		if ( ! $animal || 'rm_animal' !== $animal->post_type ) {
			return '<p class="rm-notice">' . esc_html__( 'Tier nicht gefunden.', 'reptilien-manager' ) . '</p>';
		}

		$tree = RM_Breeding::ancestors( $animal->ID, (int) $atts['generationen'] );

		ob_start();
		echo '<div class="rm-pedigree">';
		self::render_tree_node( $tree );
		echo '</div>';
		return ob_get_clean();
	}

	/**
	 * Rekursives Rendern eines Stammbaum-Knotens.
	 *
	 * @param array|null $node Knoten aus RM_Breeding::ancestors().
	 */
	private static function render_tree_node( $node ) {
		if ( ! $node ) {
			return;
		}
		$has_parents = $node['sire'] || $node['dam'];
		?>
		<div class="rm-tree">
			<div class="rm-tree__node">
				<a href="<?php echo esc_url( get_permalink( $node['id'] ) ); ?>"><strong><?php echo esc_html( $node['name'] ); ?></strong></a>
				<span class="rm-tree__morph"><?php echo esc_html( $node['morph'] ); ?></span>
			</div>
			<?php if ( $has_parents ) : ?>
				<div class="rm-tree__parents">
					<div class="rm-tree__branch rm-tree__branch--sire">
						<?php
						if ( $node['sire'] ) {
							self::render_tree_node( $node['sire'] );
						} else {
							echo '<div class="rm-tree__node rm-tree__node--empty">♂ ' . esc_html__( 'unbekannt', 'reptilien-manager' ) . '</div>';
						}
						?>
					</div>
					<div class="rm-tree__branch rm-tree__branch--dam">
						<?php
						if ( $node['dam'] ) {
							self::render_tree_node( $node['dam'] );
						} else {
							echo '<div class="rm-tree__node rm-tree__node--empty">♀ ' . esc_html__( 'unbekannt', 'reptilien-manager' ) . '</div>';
						}
						?>
					</div>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * [reptilien-genetik] – Genetik-Rechner im Frontend.
	 *
	 * @return string
	 */
	public static function genetics_calculator() {
		wp_enqueue_style( 'rm-frontend' );

		$args = array(
			'post_type'      => 'rm_animal',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		// Eingeloggte Züchter rechnen auch mit eigenen, nicht öffentlichen Tieren.
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			$args['post_status'] = array( 'publish', 'draft', 'private', 'pending' );
			if ( ! current_user_can( 'edit_others_posts' ) ) {
				$args['author'] = get_current_user_id();
			}
		}

		$animals = get_posts( $args );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- reines Lese-Formular.
		$sire = isset( $_GET['rm_gc_sire'] ) ? absint( $_GET['rm_gc_sire'] ) : 0;
		$dam  = isset( $_GET['rm_gc_dam'] ) ? absint( $_GET['rm_gc_dam'] ) : 0;
		$tab  = isset( $_GET['rm_tab'] ) ? sanitize_key( $_GET['rm_tab'] ) : '';
		// phpcs:enable

		ob_start();
		?>
		<div class="rm-genetics-calc">
			<form method="get" class="rm-filter-bar">
				<?php if ( $tab ) : ?>
					<input type="hidden" name="rm_tab" value="<?php echo esc_attr( $tab ); ?>" />
				<?php endif; ?>
				<?php if ( ! get_option( 'permalink_structure' ) && is_singular() ) : ?>
					<input type="hidden" name="page_id" value="<?php echo esc_attr( get_queried_object_id() ); ?>" />
				<?php endif; ?>
				<label>
					<span><?php esc_html_e( 'Vater (1.0)', 'reptilien-manager' ); ?></span>
					<select name="rm_gc_sire">
						<option value=""><?php esc_html_e( '– auswählen –', 'reptilien-manager' ); ?></option>
						<?php foreach ( $animals as $a ) : ?>
							<option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( $sire, $a->ID ); ?>><?php echo esc_html( $a->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php esc_html_e( 'Mutter (0.1)', 'reptilien-manager' ); ?></span>
					<select name="rm_gc_dam">
						<option value=""><?php esc_html_e( '– auswählen –', 'reptilien-manager' ); ?></option>
						<?php foreach ( $animals as $a ) : ?>
							<option value="<?php echo esc_attr( $a->ID ); ?>" <?php selected( $dam, $a->ID ); ?>><?php echo esc_html( $a->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button type="submit" class="rm-filter-submit"><?php esc_html_e( 'Berechnen', 'reptilien-manager' ); ?></button>
			</form>

			<?php
			if ( $sire && $dam ) {
				self::render_cross_frontend( $sire, $dam );
			}
			?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Frontend-Darstellung eines Kreuzungs-Ergebnisses.
	 *
	 * @param int $sire Vater-ID.
	 * @param int $dam  Mutter-ID.
	 */
	private static function render_cross_frontend( $sire, $dam ) {
		$data = RM_Genetics::cross_export_array( $sire, $dam );
		$coi  = RM_Breeding::pair_coi( $sire, $dam );
		?>
		<div class="rm-cross">
			<p class="rm-cross__parents">
				<strong><?php echo esc_html( $data['sire']['name'] ); ?></strong> <em>(<?php echo esc_html( $data['sire']['morph'] ); ?>)</em>
				×
				<strong><?php echo esc_html( $data['dam']['name'] ); ?></strong> <em>(<?php echo esc_html( $data['dam']['morph'] ); ?>)</em>
			</p>
			<p class="rm-cross__coi">
				<?php
				printf(
					/* translators: %s: COI-Prozent */
					esc_html__( 'Inzucht-Koeffizient: %s', 'reptilien-manager' ),
					esc_html( RM_Breeding::format_coi( $coi ) )
				);
				?>
			</p>

			<h3><?php esc_html_e( 'Mögliche Jungtiere', 'reptilien-manager' ); ?></h3>
			<table class="rm-cross__table">
				<thead>
					<tr><th><?php esc_html_e( 'Ergebnis', 'reptilien-manager' ); ?></th><th><?php esc_html_e( 'Wahrscheinlichkeit', 'reptilien-manager' ); ?></th></tr>
				</thead>
				<tbody>
					<?php foreach ( $data['offspring_combined'] as $row ) : ?>
						<tr><td><?php echo esc_html( $row['label'] ); ?></td><td><?php echo esc_html( $row['percent'] ); ?></td></tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
