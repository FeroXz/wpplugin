<?php
/**
 * Admin-Seiten: Genetik-Rechner und Futterplan.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Admin_Pages {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_pages' ) );
		add_action( 'admin_post_rm_export_cross', array( __CLASS__, 'export_cross' ) );
	}

	/**
	 * Export eines Punnett-Ergebnisses als JSON oder druckbare PDF-Ansicht.
	 */
	public static function export_cross() {
		$sire = isset( $_GET['rm_sire'] ) ? absint( $_GET['rm_sire'] ) : 0;
		$dam  = isset( $_GET['rm_dam'] ) ? absint( $_GET['rm_dam'] ) : 0;
		$fmt  = isset( $_GET['format'] ) ? sanitize_key( $_GET['format'] ) : 'json';

		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rm_export_cross' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}
		if ( ! $sire || ! $dam ) {
			wp_die( esc_html__( 'Bitte Vater und Mutter angeben.', 'reptilien-manager' ) );
		}

		$data = RM_Genetics::cross_export_array( $sire, $dam );

		if ( 'print' === $fmt ) {
			self::render_cross_printable( $data );
			exit;
		}

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="verpaarung-' . $sire . 'x' . $dam . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Druckbare (per Browser als PDF speicherbare) Ansicht eines Kreuzungs-Ergebnisses.
	 *
	 * @param array $data Ergebnis von RM_Genetics::cross_export_array().
	 */
	private static function render_cross_printable( $data ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html lang="de">
<head>
	<meta charset="utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<title><?php echo esc_html( $data['sire']['name'] . ' × ' . $data['dam']['name'] ); ?></title>
	<style>
		body { font-family: -apple-system, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; color: #1e293b; max-width: 820px; margin: 2rem auto; padding: 0 1.5rem; }
		h1 { font-size: 1.6rem; border-bottom: 3px solid #4f46e5; padding-bottom: .4rem; }
		h2 { font-size: 1.2rem; margin-top: 1.8rem; color: #3730a3; }
		.parents { display: flex; gap: 1rem; margin: 1rem 0; }
		.parent { flex: 1; background: #eef2ff; border-radius: 10px; padding: 1rem; }
		.parent strong { display: block; font-size: 1.1rem; }
		table { width: 100%; border-collapse: collapse; margin: .6rem 0 1.2rem; }
		th, td { text-align: left; padding: .5rem .7rem; border-bottom: 1px solid #e2e8f0; }
		th { background: #f8fafc; }
		.muted { color: #64748b; font-size: .85rem; }
		.print-btn { display: inline-block; margin: 1rem 0; padding: .6rem 1.3rem; background: #4f46e5; color: #fff; border: none; border-radius: 999px; font-size: 1rem; cursor: pointer; }
		@media print { .print-btn { display: none; } body { margin: 0; } }
	</style>
</head>
<body>
	<button class="print-btn" onclick="window.print()"><?php esc_html_e( 'Drucken / als PDF speichern', 'reptilien-manager' ); ?></button>
	<h1>🧬 <?php echo esc_html( $data['sire']['name'] . ' × ' . $data['dam']['name'] ); ?></h1>
	<div class="parents">
		<div class="parent">
			<strong><?php echo esc_html( $data['sire']['name'] ); ?> ♂</strong>
			<?php echo esc_html( $data['sire']['morph'] ); ?><br />
			<span class="muted"><?php echo esc_html( $data['sire']['species'] ); ?></span>
		</div>
		<div class="parent">
			<strong><?php echo esc_html( $data['dam']['name'] ); ?> ♀</strong>
			<?php echo esc_html( $data['dam']['morph'] ); ?><br />
			<span class="muted"><?php echo esc_html( $data['dam']['species'] ); ?></span>
		</div>
	</div>

	<h2><?php esc_html_e( 'Mögliche Jungtiere (kombiniert)', 'reptilien-manager' ); ?></h2>
	<table>
		<thead><tr><th><?php esc_html_e( 'Ergebnis', 'reptilien-manager' ); ?></th><th><?php esc_html_e( 'Wahrscheinlichkeit', 'reptilien-manager' ); ?></th></tr></thead>
		<tbody>
			<?php foreach ( $data['offspring_combined'] as $row ) : ?>
				<tr><td><?php echo esc_html( $row['label'] ); ?></td><td><?php echo esc_html( $row['percent'] ); ?></td></tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<?php if ( $data['per_gene'] ) : ?>
		<h2><?php esc_html_e( 'Aufschlüsselung pro Gen', 'reptilien-manager' ); ?></h2>
		<table>
			<thead><tr><th><?php esc_html_e( 'Gen', 'reptilien-manager' ); ?></th><th><?php esc_html_e( 'Mögliche Ausprägungen', 'reptilien-manager' ); ?></th></tr></thead>
			<tbody>
				<?php foreach ( $data['per_gene'] as $gene ) : ?>
					<tr>
						<td><?php echo esc_html( $gene['gene'] ); ?></td>
						<td>
							<?php
							$parts = array();
							foreach ( $gene['outcomes'] as $o ) {
								$parts[] = $o['percent'] . ' ' . $o['outcome'];
							}
							echo esc_html( implode( ' · ', $parts ) );
							?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p class="muted"><?php echo esc_html( sprintf( /* translators: %s: Datum */ __( 'Erstellt am %s mit Reptilien Manager.', 'reptilien-manager' ), date_i18n( get_option( 'date_format' ) ) ) ); ?></p>
</body>
</html>
		<?php
	}

	public static function register_pages() {
		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Genetik-Rechner', 'reptilien-manager' ),
			__( 'Genetik-Rechner', 'reptilien-manager' ),
			'edit_posts',
			'rm-genetics',
			array( __CLASS__, 'render_genetics_page' )
		);

		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Verpaarungs-Empfehlungen', 'reptilien-manager' ),
			__( 'Empfehlungen', 'reptilien-manager' ),
			'edit_posts',
			'rm-breeding',
			array( __CLASS__, 'render_breeding_page' )
		);

		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Futterplan', 'reptilien-manager' ),
			__( 'Futterplan', 'reptilien-manager' ),
			'edit_posts',
			'rm-feeding-plan',
			array( __CLASS__, 'render_feeding_plan_page' )
		);

		add_submenu_page(
			'edit.php?post_type=rm_animal',
			__( 'Import / Export', 'reptilien-manager' ),
			__( 'Import / Export', 'reptilien-manager' ),
			'edit_posts',
			'rm-backup',
			array( __CLASS__, 'render_backup_page' )
		);
	}

	/**
	 * Seite für JSON-Backup, CSV-Import und PDF-Zuchtbuch.
	 */
	public static function render_backup_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nur Statusanzeige nach Redirect.
		$msg     = isset( $_GET['rm_msg'] ) ? sanitize_key( $_GET['rm_msg'] ) : '';
		$created = isset( $_GET['created'] ) ? absint( $_GET['created'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$can_import = current_user_can( 'manage_options' );
		$base       = admin_url( 'admin-post.php' );
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Import / Export', 'reptilien-manager' ); ?></h1>

			<?php
			$notices = array(
				'imported'     => array( 'success', __( 'Backup importiert.', 'reptilien-manager' ) ),
				'import_error' => array( 'error', __( 'Keine Datei empfangen.', 'reptilien-manager' ) ),
				'import_invalid' => array( 'error', __( 'Die Datei ist kein gültiges Reptilien-Backup.', 'reptilien-manager' ) ),
				'csv_done'     => array( 'success', __( 'CSV-Import abgeschlossen.', 'reptilien-manager' ) ),
				'csv_error'    => array( 'error', __( 'CSV konnte nicht gelesen werden.', 'reptilien-manager' ) ),
				'csv_noname'   => array( 'error', __( 'In der CSV wurde keine Spalte „Name“ gefunden.', 'reptilien-manager' ) ),
			);
			if ( isset( $notices[ $msg ] ) ) {
				printf(
					'<div class="notice notice-%s is-dismissible"><p>%s%s</p></div>',
					esc_attr( $notices[ $msg ][0] ),
					esc_html( $notices[ $msg ][1] ),
					'csv_done' === $msg ? ' ' . esc_html( sprintf( /* translators: %d: Anzahl */ __( '%d Tiere angelegt (als Entwurf).', 'reptilien-manager' ), $created ) ) : ''
				);
			}
			?>

			<div class="rm-cost-grid">
				<div>
					<h2><?php esc_html_e( '⬇ Export & Backup', 'reptilien-manager' ); ?></h2>
					<p><?php esc_html_e( 'Sichert den kompletten Bestand (Tiere, Verpaarungen, Gelege, Fütterungen, Preise) als JSON – für Backups oder den Transfer zu einem anderen Züchter.', 'reptilien-manager' ); ?></p>
					<p>
						<a class="button button-primary" href="<?php echo esc_url( wp_nonce_url( $base . '?action=rm_backup_export', 'rm_backup_export' ) ); ?>"><?php esc_html_e( 'JSON-Backup herunterladen', 'reptilien-manager' ); ?></a>
					</p>
					<p>
						<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( wp_nonce_url( $base . '?action=rm_studbook', 'rm_studbook' ) ); ?>"><?php esc_html_e( 'PDF-Zuchtbuch (Druckansicht)', 'reptilien-manager' ); ?></a>
					</p>
				</div>

				<div>
					<h2><?php esc_html_e( '⬆ Import', 'reptilien-manager' ); ?></h2>

					<h3><?php esc_html_e( 'CSV-Import (Altdaten)', 'reptilien-manager' ); ?></h3>
					<p class="description"><?php esc_html_e( 'Spalten (Kopfzeile): Name, Geschlecht, Schlupfdatum, Art, Herkunft, Länge, Gewicht, Kennzeichnung. Nur „Name“ ist Pflicht. Tiere werden als Entwurf angelegt.', 'reptilien-manager' ); ?></p>
					<form method="post" action="<?php echo esc_url( $base ); ?>" enctype="multipart/form-data">
						<input type="hidden" name="action" value="rm_backup_csv" />
						<?php wp_nonce_field( 'rm_backup_csv' ); ?>
						<input type="file" name="rm_csv_file" accept=".csv,text/csv" required />
						<?php submit_button( __( 'CSV importieren', 'reptilien-manager' ), 'secondary', 'submit', false ); ?>
					</form>

					<?php if ( $can_import ) : ?>
						<h3 style="margin-top:1.5em"><?php esc_html_e( 'JSON-Backup wiederherstellen', 'reptilien-manager' ); ?></h3>
						<p class="description"><?php esc_html_e( 'Legt Tiere, Verpaarungen und Fütterungen aus einem Backup neu an (Verweise werden umgeschrieben). Medien/Fotos werden nicht übertragen.', 'reptilien-manager' ); ?></p>
						<form method="post" action="<?php echo esc_url( $base ); ?>" enctype="multipart/form-data">
							<input type="hidden" name="action" value="rm_backup_import" />
							<?php wp_nonce_field( 'rm_backup_import' ); ?>
							<input type="file" name="rm_backup_file" accept=".json,application/json" required />
							<?php submit_button( __( 'Backup importieren', 'reptilien-manager' ), 'secondary', 'submit', false ); ?>
						</form>
					<?php else : ?>
						<p class="description"><?php esc_html_e( 'Der JSON-Import (kompletter Bestand) erfordert Administrator-Rechte.', 'reptilien-manager' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Seite mit Verpaarungs-Empfehlungen (nach Inzucht-Koeffizient) und
	 * Zuchtstatistik pro Tier.
	 */
	public static function render_breeding_page() {
		$suggestions = RM_Breeding::recommend_pairings( 15 );
		$animals     = RM_Post_Types::get_animals();
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Verpaarungs-Empfehlungen', 'reptilien-manager' ); ?></h1>
			<p><?php esc_html_e( 'Vorschläge werden nach genetischer Vielfalt (niedriger Inzucht-Koeffizient), Artgleichheit und Zuchtreife sortiert. Der COI schätzt die Verwandtschaft des möglichen Nachwuchses aus der hinterlegten Abstammung.', 'reptilien-manager' ); ?></p>

			<?php if ( ! $suggestions ) : ?>
				<p><?php esc_html_e( 'Für Empfehlungen werden mindestens ein männliches und ein weibliches Tier benötigt.', 'reptilien-manager' ); ?></p>
			<?php else : ?>
				<table class="widefat striped rm-table rm-table--wide">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Vater (1.0)', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Mutter (0.1)', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'COI', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Bewertung', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Zuchtreif', 'reptilien-manager' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $suggestions as $s ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $s['sire']->ID ) ); ?>"><strong><?php echo esc_html( $s['sire']->post_title ); ?></strong></a>
									<br /><span class="description"><?php echo esc_html( RM_Genetics::animal_morph_label( $s['sire']->ID ) ); ?></span>
								</td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $s['dam']->ID ) ); ?>"><strong><?php echo esc_html( $s['dam']->post_title ); ?></strong></a>
									<br /><span class="description"><?php echo esc_html( RM_Genetics::animal_morph_label( $s['dam']->ID ) ); ?></span>
								</td>
								<td><strong><?php echo esc_html( RM_Breeding::format_coi( $s['coi'] ) ); ?></strong></td>
								<td>
									<span class="rm-status rm-status--<?php echo esc_attr( $s['warning']['status'] ); ?>"><?php echo esc_html( $s['warning']['label'] ); ?></span>
									<?php if ( ! $s['species_match'] ) : ?>
										<br /><span class="rm-status rm-status--high"><?php esc_html_e( 'Verschiedene Arten', 'reptilien-manager' ); ?></span>
									<?php endif; ?>
								</td>
								<td><?php echo $s['both_ready'] ? '✓' : '<span class="description">' . esc_html__( 'noch nicht', 'reptilien-manager' ) . '</span>'; ?></td>
								<td>
									<a class="button button-small" href="<?php echo esc_url( admin_url( 'edit.php?post_type=rm_animal&page=rm-genetics&rm_sire=' . $s['sire']->ID . '&rm_dam=' . $s['dam']->ID ) ); ?>"><?php esc_html_e( 'Genetik', 'reptilien-manager' ); ?></a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<h2><?php esc_html_e( 'Zuchtstatistik pro Tier', 'reptilien-manager' ); ?></h2>
				<table class="widefat striped rm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Verpaarungen', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Nachkommen', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Ø Schlupfquote', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Eigener COI', 'reptilien-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php
						foreach ( $animals as $animal ) :
							$stats = RM_Breeding::animal_stats( $animal->ID );
							if ( ! $stats['pairings'] && ! RM_Breeding::inbreeding_coefficient( $animal->ID ) ) {
								continue; // Nur Tiere mit Zucht-Bezug zeigen.
							}
							?>
							<tr>
								<td><a href="<?php echo esc_url( get_edit_post_link( $animal->ID ) ); ?>"><?php echo esc_html( $animal->post_title ); ?></a></td>
								<td><?php echo esc_html( $stats['pairings'] ); ?></td>
								<td><?php echo esc_html( $stats['offspring'] ); ?></td>
								<td><?php echo esc_html( null === $stats['avg_hatch_rate'] ? '—' : round( $stats['avg_hatch_rate'] * 100 ) . ' %' ); ?></td>
								<td><?php echo esc_html( RM_Breeding::format_coi( RM_Breeding::inbreeding_coefficient( $animal->ID ) ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_genetics_page() {
		$males   = RM_Post_Types::get_animals( 'male' );
		$females = RM_Post_Types::get_animals( 'female' );

		$sire = isset( $_GET['rm_sire'] ) ? absint( $_GET['rm_sire'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nur-Lese-Formular.
		$dam  = isset( $_GET['rm_dam'] ) ? absint( $_GET['rm_dam'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Genetik-Rechner (Bartagamen)', 'reptilien-manager' ); ?></h1>
			<p><?php esc_html_e( 'Wähle zwei Tiere aus, um die möglichen Genkombinationen der Jungtiere zu berechnen. Die Genanlagen werden am jeweiligen Tier unter „Genetik / Morph“ gepflegt.', 'reptilien-manager' ); ?></p>

			<form method="get">
				<input type="hidden" name="post_type" value="rm_animal" />
				<input type="hidden" name="page" value="rm-genetics" />
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
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Genetik berechnen', 'reptilien-manager' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( $sire && $dam ) : ?>
				<hr />
				<?php
				$export_base = wp_nonce_url(
					admin_url( 'admin-post.php?action=rm_export_cross&rm_sire=' . $sire . '&rm_dam=' . $dam ),
					'rm_export_cross'
				);
				?>
				<p class="rm-export-buttons">
					<a class="button" target="_blank" rel="noopener" href="<?php echo esc_url( $export_base . '&format=print' ); ?>"><?php esc_html_e( 'Druckansicht / PDF', 'reptilien-manager' ); ?></a>
					<a class="button" href="<?php echo esc_url( $export_base . '&format=json' ); ?>"><?php esc_html_e( 'JSON exportieren', 'reptilien-manager' ); ?></a>
				</p>
				<?php echo RM_Genetics::render_cross_result( $sire, $dam ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML wird intern escaped. ?>
			<?php endif; ?>
		</div>
		<?php
	}

	public static function render_feeding_plan_page() {
		$animals = RM_Post_Types::get_animals();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reine Statusanzeige nach Redirect.
		$msg = isset( $_GET['rm_msg'] ) ? sanitize_key( $_GET['rm_msg'] ) : '';
		?>
		<div class="wrap rm-wrap">
			<h1><?php esc_html_e( 'Futterplan (Bartagamen)', 'reptilien-manager' ); ?></h1>

			<?php if ( 'saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Fütterung gespeichert.', 'reptilien-manager' ); ?></p></div>
			<?php elseif ( 'missing' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Bitte mindestens ein Tier und eine Futterart auswählen.', 'reptilien-manager' ); ?></p></div>
			<?php elseif ( 'error' === $msg ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Die Fütterung konnte nicht gespeichert werden.', 'reptilien-manager' ); ?></p></div>
			<?php elseif ( 'prices_saved' === $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Futterpreise gespeichert.', 'reptilien-manager' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $animals ) : ?>
				<p><?php esc_html_e( 'Noch keine Tiere eingetragen.', 'reptilien-manager' ); ?></p>
			<?php else : ?>

				<div class="rm-quick-feeding">
					<h2><?php esc_html_e( '⚡ Schnell-Eintrag: Fütterung', 'reptilien-manager' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Mehrere Tiere und Futterarten gleichzeitig – ein Klick, fertig.', 'reptilien-manager' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="rm_quick_feeding" />
						<?php wp_nonce_field( 'rm_quick_feeding', 'rm_quick_feeding_nonce' ); ?>

						<div class="rm-quick-grid">
							<div class="rm-quick-field">
								<label for="rm_feed_date"><strong><?php esc_html_e( 'Datum', 'reptilien-manager' ); ?></strong></label><br />
								<input type="date" name="rm_feed_date" id="rm_feed_date" value="<?php echo esc_attr( current_time( 'Y-m-d' ) ); ?>" />
							</div>
							<div class="rm-quick-field">
								<label for="rm_feed_amount"><strong><?php esc_html_e( 'Menge', 'reptilien-manager' ); ?></strong></label><br />
								<input type="text" name="rm_feed_amount" id="rm_feed_amount" placeholder="<?php esc_attr_e( 'z. B. 5 Stück pro Tier', 'reptilien-manager' ); ?>" />
							</div>
						</div>

						<p><strong><?php esc_html_e( 'Tiere', 'reptilien-manager' ); ?></strong></p>
						<?php RM_Feeding::render_animal_choices(); ?>

						<p><strong><?php esc_html_e( 'Futter', 'reptilien-manager' ); ?></strong></p>
						<?php RM_Feeding::render_food_choices(); ?>

						<p><strong><?php esc_html_e( 'Supplemente', 'reptilien-manager' ); ?></strong></p>
						<?php RM_Feeding::render_supplement_choices(); ?>

						<p>
							<label for="rm_feed_notes"><strong><?php esc_html_e( 'Notizen (optional)', 'reptilien-manager' ); ?></strong></label><br />
							<textarea name="rm_feed_notes" id="rm_feed_notes" rows="2" class="large-text"></textarea>
						</p>

						<?php submit_button( __( 'Fütterung speichern', 'reptilien-manager' ), 'primary', 'submit', false ); ?>
					</form>
				</div>

				<h2><?php esc_html_e( 'Empfehlung & Auswertung pro Tier', 'reptilien-manager' ); ?></h2>
				<p class="description">
					<?php
					printf(
						/* translators: %d: Anzahl Tage */
						esc_html__( 'Die Auswertung vergleicht die protokollierten Fütterungen der letzten %d Tage mit dem altersgerechten Optimum (Frequenz pro Woche).', 'reptilien-manager' ),
						(int) RM_Feeding::ANALYSIS_DAYS
					);
					?>
				</p>
				<table class="widefat striped rm-table rm-table--wide">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Tier', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Alter / Gruppe', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Empfehlung', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Auswertung (Ist vs. Optimum)', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Nährstoff-Bilanz', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Letzte Fütterung', 'reptilien-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $animals as $animal ) : ?>
							<?php
							$birth        = get_post_meta( $animal->ID, '_rm_birth', true );
							$months       = $birth ? RM_Animal_Meta::age_in_months( $birth ) : null;
							$species_key  = RM_Species::key_for_animal( $animal->ID );
							$plan         = RM_Feeding::plan_for_age( $months, $species_key );
							$last         = RM_Feeding::last_feeding( $animal->ID );
							$analysis     = RM_Feeding::analyze_animal( $animal->ID );
							$nutrients    = RM_Feeding::nutrient_balance( $animal->ID );
							$notes        = get_post_meta( $animal->ID, '_rm_food_notes', true );
							$protein_icon = 'herbivore' === RM_Species::diet( $species_key ) ? '🌿' : '🦗';
							?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $animal->ID ) ); ?>"><strong><?php echo esc_html( $animal->post_title ); ?></strong></a>
									<br /><span class="rm-species-tag"><?php echo esc_html( RM_Species::label( $species_key ) ); ?></span>
									<?php if ( $notes ) : ?>
										<br /><em><?php echo esc_html( $notes ); ?></em>
									<?php endif; ?>
								</td>
								<td>
									<?php echo esc_html( $birth ? RM_Animal_Meta::age_label( $birth ) : '—' ); ?><br />
									<span class="description"><?php echo esc_html( $plan['group'] ); ?></span>
								</td>
								<td class="rm-plan-cell">
									<span><?php echo esc_html( $protein_icon ); ?> <?php echo esc_html( $plan['insects'] ); ?></span>
									<span>🥬 <?php echo esc_html( $plan['greens'] ); ?></span>
									<span>🦴 <?php echo esc_html( $plan['supplements'] ); ?></span>
								</td>
								<td><?php self::render_analysis( $analysis ); ?></td>
								<td><?php self::render_nutrients( $nutrients ); ?></td>
								<td>
									<?php
									if ( $last && $last['date'] && strtotime( $last['date'] ) ) {
										echo esc_html( date_i18n( get_option( 'date_format' ), strtotime( $last['date'] ) ) );
										if ( $last['food'] ) {
											echo '<br /><span class="description">' . esc_html( $last['food'] ) . '</span>';
										}
									} else {
										echo esc_html__( 'Noch keine Fütterung protokolliert', 'reptilien-manager' );
									}
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<?php self::render_cost_section(); ?>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Richtwerte für Pogona vitticeps. Individuelle Bedürfnisse (Gesundheit, Winterruhe, Trächtigkeit) immer berücksichtigen und im Zweifel reptilienkundige Tierärzte hinzuziehen.', 'reptilien-manager' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Nährstoff-Bilanz-Chips (Calcium/D3/Vitamine inkl. Toxizitäts-Warnung).
	 *
	 * @param array $nutrients Ergebnis von RM_Feeding::nutrient_balance().
	 */
	private static function render_nutrients( $nutrients ) {
		if ( ! $nutrients['has_targets'] ) {
			echo '<span class="rm-status rm-status--none">' . esc_html__( 'Kein Schlupfdatum', 'reptilien-manager' ) . '</span>';
			return;
		}
		if ( ! $nutrients['has_data'] ) {
			echo '<span class="rm-status rm-status--none">' . esc_html__( 'Keine Fütterungen im Zeitraum', 'reptilien-manager' ) . '</span>';
			return;
		}

		$status_labels = array(
			'ok'    => __( 'optimal', 'reptilien-manager' ),
			'low'   => __( 'zu wenig', 'reptilien-manager' ),
			'high'  => __( 'reichlich', 'reptilien-manager' ),
			'toxic' => __( '⚠ Überdosierung', 'reptilien-manager' ),
		);
		// Toxizität nutzt die rote „high“-Optik.
		$css_map = array(
			'ok'    => 'ok',
			'low'   => 'low',
			'high'  => 'ok',
			'toxic' => 'high',
		);

		echo '<div class="rm-analysis">';
		foreach ( $nutrients['categories'] as $category ) {
			$rate_label   = number_format_i18n( $category['rate'], 1 );
			$target_label = $category['min'] === $category['max']
				? number_format_i18n( $category['min'] )
				: number_format_i18n( $category['min'] ) . '–' . number_format_i18n( $category['max'] );

			printf(
				'<span class="rm-status rm-status--%1$s">%2$s: %3$s×/Wo (Ziel %4$s) – %5$s</span>',
				esc_attr( $css_map[ $category['status'] ] ),
				esc_html( $category['label'] ),
				esc_html( $rate_label ),
				esc_html( $target_label ),
				esc_html( $status_labels[ $category['status'] ] )
			);
		}
		echo '</div>';
	}

	/**
	 * Kosten-Tracking: Preis-Formular je Futterart plus Monatsreport.
	 */
	private static function render_cost_section() {
		$prices = RM_Feeding::food_prices();
		$report = RM_Feeding::cost_report( 30 );
		$foods  = RM_Feeding::food_types();
		?>
		<h2><?php esc_html_e( '💶 Kosten-Tracking', 'reptilien-manager' ); ?></h2>
		<div class="rm-cost-grid">
			<div class="rm-cost-prices">
				<h3><?php esc_html_e( 'Preise je Futterart (pro Portion)', 'reptilien-manager' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="rm_save_food_prices" />
					<?php wp_nonce_field( 'rm_food_prices', 'rm_food_prices_nonce' ); ?>
					<table class="widefat striped rm-table">
						<tbody>
							<?php foreach ( $foods as $key => $label ) : ?>
								<tr>
									<td><?php echo esc_html( $label ); ?></td>
									<td style="width:120px">
										<input type="number" step="0.01" min="0" name="rm_price[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $prices[ $key ] ? $prices[ $key ] : '' ); ?>" />
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p><?php submit_button( __( 'Preise speichern', 'reptilien-manager' ), 'secondary', 'submit', false ); ?></p>
				</form>
			</div>

			<div class="rm-cost-report">
				<h3><?php esc_html_e( 'Kosten (letzte 30 Tage)', 'reptilien-manager' ); ?></h3>
				<?php if ( $report['total'] <= 0 ) : ?>
					<p class="description"><?php esc_html_e( 'Noch keine Kosten berechenbar – bitte Preise hinterlegen und Fütterungen protokollieren.', 'reptilien-manager' ); ?></p>
				<?php else : ?>
					<p class="rm-cost-total">
						<strong><?php echo esc_html( self::money( $report['total'] ) ); ?></strong>
						<span class="description"><?php esc_html_e( 'in 30 Tagen', 'reptilien-manager' ); ?></span>
						<?php /* translators: %s: Betrag */ ?>
						<br /><?php echo esc_html( sprintf( __( 'Prognose ≈ %s / Monat', 'reptilien-manager' ), self::money( $report['monthly'] ) ) ); ?>
					</p>

					<?php if ( $report['per_food'] ) : ?>
						<h4><?php esc_html_e( 'Nach Futterart', 'reptilien-manager' ); ?></h4>
						<ul class="rm-cost-list">
							<?php foreach ( $report['per_food'] as $key => $cost ) : ?>
								<li><span><?php echo esc_html( isset( $foods[ $key ] ) ? $foods[ $key ] : $key ); ?></span><strong><?php echo esc_html( self::money( $cost ) ); ?></strong></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>

					<?php if ( $report['per_animal'] ) : ?>
						<h4><?php esc_html_e( 'Pro Tier', 'reptilien-manager' ); ?></h4>
						<ul class="rm-cost-list">
							<?php foreach ( $report['per_animal'] as $animal_id => $cost ) : ?>
								<li><span><?php echo esc_html( get_the_title( $animal_id ) ); ?></span><strong><?php echo esc_html( self::money( $cost ) ); ?></strong></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Betrag mit WordPress-Locale formatieren (ohne feste Währung).
	 *
	 * @param float $amount Betrag.
	 * @return string
	 */
	private static function money( $amount ) {
		return number_format_i18n( (float) $amount, 2 );
	}

	/**
	 * Auswertungs-Chips (Ist vs. Optimum) für ein Tier rendern.
	 *
	 * @param array $analysis Ergebnis von RM_Feeding::analyze_animal().
	 */
	private static function render_analysis( $analysis ) {
		if ( ! $analysis['has_targets'] ) {
			echo '<span class="rm-status rm-status--none">' . esc_html__( 'Kein Schlupfdatum hinterlegt', 'reptilien-manager' ) . '</span>';
			return;
		}

		if ( ! $analysis['has_data'] ) {
			echo '<span class="rm-status rm-status--none">' . esc_html__( 'Keine Fütterungen im Zeitraum', 'reptilien-manager' ) . '</span>';
			return;
		}

		$status_labels = array(
			'ok'   => __( 'optimal', 'reptilien-manager' ),
			'low'  => __( 'zu wenig', 'reptilien-manager' ),
			'high' => __( 'zu viel', 'reptilien-manager' ),
		);
		$status_icons = array(
			'ok'   => '✓',
			'low'  => '↓',
			'high' => '↑',
		);

		echo '<div class="rm-analysis">';
		foreach ( $analysis['categories'] as $category ) {
			$rate_label   = number_format_i18n( $category['rate'], 1 );
			$target_label = $category['min'] === $category['max']
				? number_format_i18n( $category['min'] )
				: number_format_i18n( $category['min'] ) . '–' . number_format_i18n( $category['max'] );

			printf(
				'<span class="rm-status rm-status--%1$s" title="%2$s">%3$s %4$s: %5$s×/Wo (Ziel %6$s) – %7$s</span>',
				esc_attr( $category['status'] ),
				esc_attr( sprintf(
					/* translators: 1: Kategorie, 2: Ist-Wert, 3: Zielbereich */
					__( '%1$s: %2$s Fütterungen pro Woche, Ziel %3$s pro Woche', 'reptilien-manager' ),
					$category['label'],
					$rate_label,
					$target_label
				) ),
				esc_html( $status_icons[ $category['status'] ] ),
				esc_html( $category['label'] ),
				esc_html( $rate_label ),
				esc_html( $target_label ),
				esc_html( $status_labels[ $category['status'] ] )
			);
		}
		echo '</div>';
	}
}
