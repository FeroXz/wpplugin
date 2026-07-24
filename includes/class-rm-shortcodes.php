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
			return '<p>' . esc_html__( 'Noch keine Tiere eingetragen.', 'reptilien-manager' ) . '</p>';
		}

		ob_start();
		echo '<div class="rm-animal-grid">';
		foreach ( $animals as $animal ) {
			$sexes = RM_Animal_Meta::sexes();
			$sex_value = get_post_meta( $animal->ID, '_rm_sex', true );
			?>
			<div class="rm-animal-card">
				<a href="<?php echo esc_url( get_permalink( $animal ) ); ?>">
					<?php if ( has_post_thumbnail( $animal ) ) : ?>
						<?php echo get_the_post_thumbnail( $animal, 'medium' ); ?>
					<?php endif; ?>
					<h3><?php echo esc_html( $animal->post_title ); ?></h3>
				</a>
				<p class="rm-animal-morph"><?php echo esc_html( RM_Genetics::animal_morph_label( $animal->ID ) ); ?></p>
				<p class="rm-animal-sex"><?php echo esc_html( isset( $sexes[ $sex_value ] ) ? $sexes[ $sex_value ] : $sexes['unknown'] ); ?></p>
			</div>
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
			return '<p>' . esc_html__( 'Tier nicht gefunden.', 'reptilien-manager' ) . '</p>';
		}

		$sexes   = RM_Animal_Meta::sexes();
		$sex     = get_post_meta( $animal->ID, '_rm_sex', true );
		$birth   = get_post_meta( $animal->ID, '_rm_birth', true );
		$origin  = get_post_meta( $animal->ID, '_rm_origin', true );
		$length  = get_post_meta( $animal->ID, '_rm_length', true );
		$weights = get_post_meta( $animal->ID, '_rm_weights', true );
		$gallery = get_post_meta( $animal->ID, '_rm_gallery', true );

		$last_weight = ( is_array( $weights ) && $weights ) ? end( $weights ) : null;

		ob_start();
		?>
		<div class="rm-animal-profile">
			<h2><?php echo esc_html( $animal->post_title ); ?></h2>

			<?php if ( has_post_thumbnail( $animal ) ) : ?>
				<div class="rm-profile-photo"><?php echo get_the_post_thumbnail( $animal, 'large' ); ?></div>
			<?php endif; ?>

			<table class="rm-profile-table">
				<tr>
					<th><?php esc_html_e( 'Morph / Genetik', 'reptilien-manager' ); ?></th>
					<td><?php echo esc_html( RM_Genetics::animal_morph_label( $animal->ID ) ); ?></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Geschlecht', 'reptilien-manager' ); ?></th>
					<td><?php echo esc_html( isset( $sexes[ $sex ] ) ? $sexes[ $sex ] : $sexes['unknown'] ); ?></td>
				</tr>
				<?php if ( $birth ) : ?>
					<tr>
						<th><?php esc_html_e( 'Schlupfdatum', 'reptilien-manager' ); ?></th>
						<td>
							<?php
							echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $birth ) ) );
							echo esc_html( ' (' . RM_Animal_Meta::age_label( $birth ) . ')' );
							?>
						</td>
					</tr>
				<?php endif; ?>
				<?php if ( $origin ) : ?>
					<tr>
						<th><?php esc_html_e( 'Herkunft', 'reptilien-manager' ); ?></th>
						<td><?php echo esc_html( $origin ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $length ) : ?>
					<tr>
						<th><?php esc_html_e( 'Gesamtlänge', 'reptilien-manager' ); ?></th>
						<td><?php echo esc_html( $length . ' cm' ); ?></td>
					</tr>
				<?php endif; ?>
				<?php if ( $last_weight ) : ?>
					<tr>
						<th><?php esc_html_e( 'Letztes Gewicht', 'reptilien-manager' ); ?></th>
						<td>
							<?php
							echo esc_html( $last_weight['grams'] . ' g' );
							if ( ! empty( $last_weight['date'] ) && strtotime( $last_weight['date'] ) ) {
								echo esc_html( ' (' . date_i18n( get_option( 'date_format' ), strtotime( $last_weight['date'] ) ) . ')' );
							}
							?>
						</td>
					</tr>
				<?php endif; ?>
			</table>

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
		<?php
		return ob_get_clean();
	}
}
