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
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_styles' ) );
	}

	public static function register_styles() {
		wp_register_style( 'rm-frontend', RM_PLUGIN_URL . 'assets/css/frontend.css', array(), RM_VERSION );
	}

	/**
	 * [reptilien sex="male|female"] – Übersicht aller Tiere.
	 *
	 * @param array $atts Shortcode-Attribute.
	 * @return string
	 */
	public static function animal_list( $atts ) {
		wp_enqueue_style( 'rm-frontend' );

		$atts = shortcode_atts(
			array(
				'sex' => '',
			),
			$atts,
			'reptilien'
		);

		$sex     = in_array( $atts['sex'], array( 'male', 'female' ), true ) ? $atts['sex'] : '';
		$args    = array(
			'post_type'      => 'rm_animal',
			'posts_per_page' => -1,
			'orderby'        => 'title',
			'order'          => 'ASC',
			'post_status'    => 'publish',
		);
		if ( $sex ) {
			$args['meta_query'] = array(
				array(
					'key'   => '_rm_sex',
					'value' => $sex,
				),
			);
		}
		$animals = get_posts( $args );

		if ( ! $animals ) {
			return '<p class="rm-notice">' . esc_html__( 'Noch keine Tiere eingetragen.', 'reptilien-manager' ) . '</p>';
		}

		ob_start();
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

		if ( ! $animal || 'rm_animal' !== $animal->post_type || 'publish' !== $animal->post_status ) {
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
}
