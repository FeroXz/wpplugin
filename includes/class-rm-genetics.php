<?php
/**
 * Genetik-Berechnung (Punnett) für Bartagamen-Morphe.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Genetics {

	/**
	 * Bekannte Gene der Bartagame (Pogona vitticeps).
	 *
	 * Typen:
	 * - recessive:           erst mit zwei Allelen sichtbar (het = Träger).
	 * - incomplete_dominant: ein Allel = sichtbare Form, zwei Allele = Super-Form.
	 * - dominant:            ein Allel genügt, Super-Form sieht gleich aus.
	 *
	 * @return array[]
	 */
	public static function genes() {
		return array(
			'hypo'        => array(
				'label' => __( 'Hypomelanistisch (Hypo)', 'reptilien-manager' ),
				'type'  => 'recessive',
			),
			'trans'       => array(
				'label' => __( 'Translucent (Trans)', 'reptilien-manager' ),
				'type'  => 'recessive',
			),
			'zero'        => array(
				'label' => __( 'Zero', 'reptilien-manager' ),
				'type'  => 'recessive',
			),
			'witblits'    => array(
				'label' => __( 'Witblits', 'reptilien-manager' ),
				'type'  => 'recessive',
			),
			'stripe'      => array(
				'label' => __( 'Genetic Stripe', 'reptilien-manager' ),
				'type'  => 'recessive',
			),
			'leatherback' => array(
				'label'       => __( 'Leatherback', 'reptilien-manager' ),
				'type'        => 'incomplete_dominant',
				'super_label' => __( 'Silkback', 'reptilien-manager' ),
			),
			'dunner'      => array(
				'label' => __( 'Dunner', 'reptilien-manager' ),
				'type'  => 'dominant',
			),
		);
	}

	/**
	 * Mögliche Genzustände eines Tieres für ein Gen.
	 *
	 * @param array $gene Gen-Definition aus self::genes().
	 * @return array Zustand => Beschriftung.
	 */
	public static function states_for_gene( $gene ) {
		if ( 'recessive' === $gene['type'] ) {
			return array(
				''     => __( 'Nicht vorhanden', 'reptilien-manager' ),
				'het'  => __( 'het (Träger, 1 Allel)', 'reptilien-manager' ),
				'homo' => __( 'Visuell (2 Allele)', 'reptilien-manager' ),
			);
		}

		if ( 'incomplete_dominant' === $gene['type'] ) {
			$super = isset( $gene['super_label'] ) ? $gene['super_label'] : $gene['label'];
			return array(
				''     => __( 'Nicht vorhanden', 'reptilien-manager' ),
				/* translators: %s: Genname */
				'het'  => sprintf( __( '%s (1 Allel)', 'reptilien-manager' ), $gene['label'] ),
				/* translators: %s: Name der Super-Form */
				'homo' => sprintf( __( '%s / Super-Form (2 Allele)', 'reptilien-manager' ), $super ),
			);
		}

		return array(
			''     => __( 'Nicht vorhanden', 'reptilien-manager' ),
			'het'  => __( 'Vorhanden (1 Allel)', 'reptilien-manager' ),
			'homo' => __( 'Vorhanden (2 Allele)', 'reptilien-manager' ),
		);
	}

	/**
	 * Anzahl der Allel-Kopien für einen gespeicherten Zustand.
	 *
	 * @param string $state '', 'het' oder 'homo'.
	 * @return int
	 */
	public static function copies_from_state( $state ) {
		if ( 'homo' === $state ) {
			return 2;
		}
		if ( 'het' === $state ) {
			return 1;
		}
		return 0;
	}

	/**
	 * Gespeicherte Genzustände eines Tieres.
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return array Gen-Schlüssel => Zustand.
	 */
	public static function get_animal_genes( $animal_id ) {
		$stored = get_post_meta( $animal_id, '_rm_genes', true );
		$genes  = array();

		foreach ( array_keys( self::genes() ) as $key ) {
			$state = isset( $stored[ $key ] ) ? $stored[ $key ] : '';
			$genes[ $key ] = in_array( $state, array( 'het', 'homo' ), true ) ? $state : '';
		}

		return $genes;
	}

	/**
	 * Kurzbeschreibung des Morphs eines Tieres (z. B. "Hypo Leatherback – het Zero").
	 *
	 * @param int $animal_id Beitrags-ID des Tieres.
	 * @return string
	 */
	public static function animal_morph_label( $animal_id ) {
		$genes   = self::genes();
		$states  = self::get_animal_genes( $animal_id );
		$visuals = array();
		$hets    = array();

		foreach ( $states as $key => $state ) {
			if ( '' === $state ) {
				continue;
			}
			$gene   = $genes[ $key ];
			$copies = self::copies_from_state( $state );
			$label  = self::visual_label( $gene, $copies );

			if ( null !== $label ) {
				$visuals[ $key ] = $label;
			} elseif ( 'recessive' === $gene['type'] && 1 === $copies ) {
				$hets[] = $gene['label'];
			}
		}

		return self::compose_label( $visuals, $hets );
	}

	/**
	 * Sichtbare Bezeichnung für eine Allel-Anzahl, oder null wenn nicht sichtbar.
	 *
	 * @param array $gene   Gen-Definition.
	 * @param int   $copies Allel-Kopien (0–2).
	 * @return string|null
	 */
	private static function visual_label( $gene, $copies ) {
		if ( $copies <= 0 ) {
			return null;
		}

		switch ( $gene['type'] ) {
			case 'recessive':
				return 2 === $copies ? $gene['label'] : null;
			case 'incomplete_dominant':
				if ( 2 === $copies ) {
					return isset( $gene['super_label'] ) ? $gene['super_label'] : $gene['label'];
				}
				return $gene['label'];
			case 'dominant':
			default:
				return $gene['label'];
		}
	}

	/**
	 * Setzt aus sichtbaren Merkmalen und het-Trägerschaften einen Morph-Namen zusammen.
	 *
	 * @param array $visuals Gen-Schlüssel => sichtbares Label.
	 * @param array $hets    Labels der het-Trägerschaften.
	 * @return string
	 */
	private static function compose_label( $visuals, $hets ) {
		// Kombi-Morph: Zero + Witblits visuell = Wero.
		if ( isset( $visuals['zero'], $visuals['witblits'] ) ) {
			unset( $visuals['zero'], $visuals['witblits'] );
			$visuals = array( 'wero' => __( 'Wero (Zero × Witblits)', 'reptilien-manager' ) ) + $visuals;
		}

		$parts = array();
		if ( $visuals ) {
			$parts[] = implode( ' ', $visuals );
		}
		if ( $hets ) {
			$parts[] = __( 'het', 'reptilien-manager' ) . ' ' . implode( ', ', $hets );
		}

		if ( ! $parts ) {
			return __( 'Klassisch (wildfarben)', 'reptilien-manager' );
		}

		return implode( ' – ', $parts );
	}

	/**
	 * Punnett-Kreuzung eines einzelnen Gens.
	 *
	 * @param int $sire_copies Allel-Kopien des Vaters (0–2).
	 * @param int $dam_copies  Allel-Kopien der Mutter (0–2).
	 * @return array Kopienzahl des Jungtiers => Wahrscheinlichkeit (0–1).
	 */
	public static function cross_gene( $sire_copies, $dam_copies ) {
		$sire_allele = self::allele_probability( $sire_copies );
		$dam_allele  = self::allele_probability( $dam_copies );

		$result = array( 0 => 0.0, 1 => 0.0, 2 => 0.0 );

		foreach ( $sire_allele as $s => $ps ) {
			foreach ( $dam_allele as $d => $pd ) {
				$result[ $s + $d ] += $ps * $pd;
			}
		}

		return array_filter(
			$result,
			static function ( $p ) {
				return $p > 0;
			}
		);
	}

	/**
	 * Wahrscheinlichkeit, dass ein Elternteil das Allel vererbt.
	 *
	 * @param int $copies Allel-Kopien des Elternteils.
	 * @return array 0|1 (Allel weitergegeben) => Wahrscheinlichkeit.
	 */
	private static function allele_probability( $copies ) {
		if ( $copies >= 2 ) {
			return array( 1 => 1.0 );
		}
		if ( 1 === $copies ) {
			return array( 0 => 0.5, 1 => 0.5 );
		}
		return array( 0 => 1.0 );
	}

	/**
	 * Vollständige Kreuzung zweier Tiere.
	 *
	 * @param int $sire_id Beitrags-ID des Vaters.
	 * @param int $dam_id  Beitrags-ID der Mutter.
	 * @return array {
	 *     @type array $per_gene Gen-Schlüssel => array( 'label', 'outcomes' => array( Beschreibung => Wahrscheinlichkeit ) ).
	 *     @type array $combined Liste von array( 'label', 'probability' ), absteigend sortiert.
	 * }
	 */
	public static function cross_animals( $sire_id, $dam_id ) {
		$genes       = self::genes();
		$sire_states = self::get_animal_genes( $sire_id );
		$dam_states  = self::get_animal_genes( $dam_id );

		$per_gene      = array();
		$active_genes  = array();

		foreach ( $genes as $key => $gene ) {
			$sc = self::copies_from_state( $sire_states[ $key ] );
			$dc = self::copies_from_state( $dam_states[ $key ] );

			if ( 0 === $sc && 0 === $dc ) {
				continue;
			}

			$dist = self::cross_gene( $sc, $dc );
			$active_genes[ $key ] = $dist;

			$outcomes = array();
			foreach ( $dist as $copies => $prob ) {
				$outcomes[ self::describe_outcome( $gene, $copies ) ] = $prob;
			}

			$per_gene[ $key ] = array(
				'label'    => $gene['label'],
				'outcomes' => $outcomes,
			);
		}

		return array(
			'per_gene' => $per_gene,
			'combined' => self::combine_outcomes( $genes, $active_genes ),
		);
	}

	/**
	 * Beschreibung eines Einzelgen-Ergebnisses.
	 *
	 * @param array $gene   Gen-Definition.
	 * @param int   $copies Allel-Kopien des Jungtiers.
	 * @return string
	 */
	private static function describe_outcome( $gene, $copies ) {
		if ( 0 === $copies ) {
			return __( 'Ohne Anlage (klassisch)', 'reptilien-manager' );
		}

		if ( 'recessive' === $gene['type'] ) {
			return 2 === $copies
				? sprintf( /* translators: %s: Genname */ __( '%s (visuell)', 'reptilien-manager' ), $gene['label'] )
				: sprintf( /* translators: %s: Genname */ __( '100%% het %s', 'reptilien-manager' ), $gene['label'] );
		}

		if ( 'incomplete_dominant' === $gene['type'] ) {
			$super = isset( $gene['super_label'] ) ? $gene['super_label'] : $gene['label'];
			return 2 === $copies ? $super : $gene['label'];
		}

		return 2 === $copies
			? sprintf( /* translators: %s: Genname */ __( '%s (homozygot)', 'reptilien-manager' ), $gene['label'] )
			: $gene['label'];
	}

	/**
	 * Kreuzprodukt aller aktiven Gene zu kombinierten Jungtier-Ergebnissen.
	 *
	 * @param array $genes        Alle Gen-Definitionen.
	 * @param array $active_genes Gen-Schlüssel => Verteilung (Kopien => Wahrscheinlichkeit).
	 * @return array Liste von array( 'label' => string, 'probability' => float ).
	 */
	private static function combine_outcomes( $genes, $active_genes ) {
		if ( ! $active_genes ) {
			return array(
				array(
					'label'       => __( 'Klassisch (wildfarben) – keine Genanlagen bei den Elterntieren hinterlegt', 'reptilien-manager' ),
					'probability' => 1.0,
				),
			);
		}

		// Start: eine leere Kombination mit Wahrscheinlichkeit 1.
		$combos = array(
			array(
				'probability' => 1.0,
				'copies'      => array(),
			),
		);

		foreach ( $active_genes as $key => $dist ) {
			$next = array();
			foreach ( $combos as $combo ) {
				foreach ( $dist as $copies => $prob ) {
					$new                    = $combo;
					$new['probability']    *= $prob;
					$new['copies'][ $key ]  = $copies;
					$next[]                 = $new;
				}
			}
			$combos = $next;
		}

		$results = array();
		foreach ( $combos as $combo ) {
			$visuals = array();
			$hets    = array();

			foreach ( $combo['copies'] as $key => $copies ) {
				$gene  = $genes[ $key ];
				$label = self::visual_label( $gene, $copies );

				if ( null !== $label ) {
					$visuals[ $key ] = $label;
				} elseif ( 'recessive' === $gene['type'] && 1 === $copies ) {
					$hets[] = $gene['label'];
				}
			}

			$label = self::compose_label( $visuals, $hets );

			if ( isset( $results[ $label ] ) ) {
				$results[ $label ] += $combo['probability'];
			} else {
				$results[ $label ] = $combo['probability'];
			}
		}

		arsort( $results );

		$list = array();
		foreach ( $results as $label => $prob ) {
			$list[] = array(
				'label'       => $label,
				'probability' => $prob,
			);
		}

		return $list;
	}

	/**
	 * Rendert die Ergebnis-Tabellen einer Kreuzung als HTML.
	 *
	 * @param int $sire_id Beitrags-ID des Vaters.
	 * @param int $dam_id  Beitrags-ID der Mutter.
	 * @return string HTML.
	 */
	public static function render_cross_result( $sire_id, $dam_id ) {
		$result = self::cross_animals( $sire_id, $dam_id );

		ob_start();
		?>
		<div class="rm-genetics-result">
			<h3><?php esc_html_e( 'Mögliche Jungtiere (kombiniert)', 'reptilien-manager' ); ?></h3>
			<table class="widefat striped rm-table">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Ergebnis', 'reptilien-manager' ); ?></th>
						<th><?php esc_html_e( 'Wahrscheinlichkeit', 'reptilien-manager' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $result['combined'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( $row['label'] ); ?></td>
							<td><?php echo esc_html( self::format_percent( $row['probability'] ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<?php if ( $result['per_gene'] ) : ?>
				<h3><?php esc_html_e( 'Aufschlüsselung pro Gen', 'reptilien-manager' ); ?></h3>
				<table class="widefat striped rm-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Gen', 'reptilien-manager' ); ?></th>
							<th><?php esc_html_e( 'Mögliche Ausprägungen', 'reptilien-manager' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $result['per_gene'] as $gene_row ) : ?>
							<tr>
								<td><?php echo esc_html( $gene_row['label'] ); ?></td>
								<td>
									<?php
									$parts = array();
									foreach ( $gene_row['outcomes'] as $desc => $prob ) {
										$parts[] = self::format_percent( $prob ) . ' ' . $desc;
									}
									echo esc_html( implode( ' · ', $parts ) );
									?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="description">
				<?php esc_html_e( 'Hinweis: Die Berechnung basiert auf den hinterlegten Genanlagen der Elterntiere (Mendelsche Vererbung). „het“ bedeutet Träger eines rezessiven Gens ohne sichtbare Ausprägung.', 'reptilien-manager' ); ?>
			</p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Wahrscheinlichkeit als Prozentwert formatieren.
	 *
	 * @param float $probability Wert 0–1.
	 * @return string
	 */
	public static function format_percent( $probability ) {
		$percent = $probability * 100;
		$formatted = ( floor( $percent ) === $percent ) ? number_format_i18n( $percent, 0 ) : number_format_i18n( $percent, 2 );
		return $formatted . ' %';
	}
}
