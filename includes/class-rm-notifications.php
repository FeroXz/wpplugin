<?php
/**
 * Benachrichtigungen: tägliche Prüfung (Schlupf-Vorhersagen, Fütterungs-
 * erinnerungen), monatlicher Bericht per WP-Cron und iCal-Export.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class RM_Notifications {

	const CRON_HOOK   = 'rm_daily_check';
	const OPTION      = 'rm_notify_settings';
	const NOTIFIED    = 'rm_hatch_notified';

	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_daily' ) );
		add_action( 'admin_post_rm_save_notify', array( __CLASS__, 'handle_save_settings' ) );
		add_action( 'admin_post_rm_test_mail', array( __CLASS__, 'handle_test_mail' ) );
		add_action( 'admin_post_rm_ical', array( __CLASS__, 'handle_ical' ) );

		// Selbstheilend: Cron einplanen, falls noch nicht vorhanden.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Beim Aktivieren den Cron einplanen.
	 */
	public static function schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Beim Deaktivieren den Cron entfernen.
	 */
	public static function unschedule() {
		$ts = wp_next_scheduled( self::CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::CRON_HOOK );
		}
	}

	/* ---------------------------------------------------------------------
	 * Einstellungen
	 * ------------------------------------------------------------------ */

	/**
	 * Einstellungen mit Standardwerten.
	 *
	 * @return array
	 */
	public static function settings() {
		$defaults = array(
			'enabled'         => 0,
			'email'           => get_option( 'admin_email' ),
			'hatch_lead_days' => 5,
			'feeding_days'    => 4,
			'monthly_report'  => 1,
		);
		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	/* ---------------------------------------------------------------------
	 * Datenermittlung (rein, testbar)
	 * ------------------------------------------------------------------ */

	/**
	 * Anstehende Schlüpfe: Gelege, deren vorhergesagtes frühestes Schlupf-
	 * datum in [heute, heute+lead] liegt und die noch nicht geschlüpft sind.
	 *
	 * @param int $lead_days Vorlaufzeit in Tagen.
	 * @param int $now       Referenz-Zeitstempel (Test-Injektion).
	 * @return array[] { pairing_id, pairing_label, clutch_no, min_date, max_date, temp, signature }
	 */
	public static function upcoming_hatches( $lead_days, $now = null ) {
		$now   = $now ? $now : time();
		$today = strtotime( gmdate( 'Y-m-d', $now ) );
		$limit = $today + $lead_days * DAY_IN_SECONDS;

		$pairings = get_posts(
			array(
				'post_type'      => 'rm_pairing',
				'posts_per_page' => -1,
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'fields'         => 'ids',
			)
		);

		$out = array();
		foreach ( $pairings as $pid ) {
			foreach ( RM_Pairing::get_clutches( $pid ) as $i => $clutch ) {
				if ( ! $clutch['lay_date'] || $clutch['hatched'] > 0 ) {
					continue;
				}
				$predict = RM_Incubation::predict( $clutch['lay_date'], $clutch['temp'] );
				if ( ! $predict ) {
					continue;
				}
				$min_ts = strtotime( $predict['min_date'] );
				if ( $min_ts < $today || $min_ts > $limit ) {
					continue;
				}
				$out[] = array(
					'pairing_id'    => $pid,
					'pairing_label' => RM_Pairing::pairing_label( $pid ),
					'clutch_no'     => $i + 1,
					'min_date'      => $predict['min_date'],
					'max_date'      => $predict['max_date'],
					'temp'          => $clutch['temp'],
					'signature'     => $pid . '-' . ( $i + 1 ) . '-' . $predict['min_date'],
				);
			}
		}

		return $out;
	}

	/**
	 * Tiere, die seit mindestens $days Tagen nicht gefüttert wurden
	 * (oder noch nie).
	 *
	 * @param int $days Schwelle in Tagen.
	 * @param int $now  Referenz-Zeitstempel.
	 * @return array[] { id, title, last_date|null, days_since|null }
	 */
	public static function overdue_animals( $days, $now = null ) {
		$now   = $now ? $now : time();
		$out   = array();

		foreach ( RM_Post_Types::get_animals() as $animal ) {
			$last = RM_Feeding::last_feeding( $animal->ID );
			if ( $last && ! empty( $last['date'] ) && strtotime( $last['date'] ) ) {
				$days_since = floor( ( $now - strtotime( $last['date'] ) ) / DAY_IN_SECONDS );
				if ( $days_since < $days ) {
					continue;
				}
				$out[] = array(
					'id'         => $animal->ID,
					'title'      => $animal->post_title,
					'last_date'  => $last['date'],
					'days_since' => (int) $days_since,
				);
			} else {
				$out[] = array(
					'id'         => $animal->ID,
					'title'      => $animal->post_title,
					'last_date'  => null,
					'days_since' => null,
				);
			}
		}

		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Cron
	 * ------------------------------------------------------------------ */

	/**
	 * Täglicher Cron-Lauf: Digest versenden, ggf. Monatsbericht am 1.
	 */
	public static function run_daily() {
		$settings = self::settings();
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		self::send_daily_digest( $settings );

		if ( ! empty( $settings['monthly_report'] ) && '01' === gmdate( 'd' ) ) {
			self::send_monthly_report( $settings );
		}
	}

	/**
	 * Täglichen Digest zusammenstellen und senden (nur bei Inhalt).
	 *
	 * @param array $settings Einstellungen.
	 */
	private static function send_daily_digest( $settings ) {
		$notified = get_option( self::NOTIFIED, array() );
		if ( ! is_array( $notified ) ) {
			$notified = array();
		}

		// Schlupf-Alerts (jeweils nur einmal pro Signatur).
		$hatches   = self::upcoming_hatches( (int) $settings['hatch_lead_days'] );
		$new_hatch = array();
		foreach ( $hatches as $h ) {
			if ( ! in_array( $h['signature'], $notified, true ) ) {
				$new_hatch[]      = $h;
				$notified[]       = $h['signature'];
			}
		}

		// Fütterungserinnerungen mit Drossel pro Tier.
		$overdue      = self::overdue_animals( (int) $settings['feeding_days'] );
		$due_to_remind = array();
		foreach ( $overdue as $o ) {
			$last_reminder = (int) get_post_meta( $o['id'], '_rm_last_feed_reminder', true );
			if ( $last_reminder && ( time() - $last_reminder ) < ( (int) $settings['feeding_days'] * DAY_IN_SECONDS ) ) {
				continue;
			}
			$due_to_remind[] = $o;
			update_post_meta( $o['id'], '_rm_last_feed_reminder', time() );
		}

		if ( ! $new_hatch && ! $due_to_remind ) {
			return;
		}

		$lines = array();
		if ( $new_hatch ) {
			$lines[] = __( '=== Anstehende Schlüpfe ===', 'reptilien-manager' );
			foreach ( $new_hatch as $h ) {
				$lines[] = sprintf(
					'• %s (Gelege %d): %s – %s%s',
					$h['pairing_label'],
					$h['clutch_no'],
					date_i18n( get_option( 'date_format' ), strtotime( $h['min_date'] ) ),
					date_i18n( get_option( 'date_format' ), strtotime( $h['max_date'] ) ),
					'' !== $h['temp'] ? ' @ ' . $h['temp'] . ' °C' : ''
				);
			}
			$lines[] = '';
		}
		if ( $due_to_remind ) {
			$lines[] = __( '=== Fütterung fällig ===', 'reptilien-manager' );
			foreach ( $due_to_remind as $o ) {
				if ( null === $o['last_date'] ) {
					$lines[] = sprintf( '• %s: %s', $o['title'], __( 'noch nie gefüttert', 'reptilien-manager' ) );
				} else {
					$lines[] = sprintf(
						/* translators: 1: Tiername, 2: Anzahl Tage */
						__( '• %1$s: seit %2$d Tagen nicht gefüttert', 'reptilien-manager' ),
						$o['title'],
						$o['days_since']
					);
				}
			}
		}

		update_option( self::NOTIFIED, array_slice( array_values( array_unique( $notified ) ), -200 ) );

		self::send(
			$settings['email'],
			__( 'Reptilien Manager – Tagesübersicht', 'reptilien-manager' ),
			implode( "\n", $lines )
		);
	}

	/**
	 * Monatsbericht senden.
	 *
	 * @param array $settings Einstellungen.
	 */
	private static function send_monthly_report( $settings ) {
		$stats = RM_Stats::collect();
		$cost  = RM_Feeding::cost_report( 30 );

		$lines   = array();
		$lines[] = __( 'Monatsbericht Reptilien Manager', 'reptilien-manager' );
		$lines[] = '';
		$lines[] = sprintf( /* translators: %d: Anzahl */ __( 'Tiere im Bestand: %d', 'reptilien-manager' ), $stats['total'] );
		$lines[] = sprintf(
			/* translators: 1: männlich, 2: weiblich */
			__( 'Geschlechter: %1$d.%2$d (m.w)', 'reptilien-manager' ),
			$stats['sex']['male'],
			$stats['sex']['female']
		);
		if ( null !== $stats['avg_weight'] ) {
			$lines[] = sprintf( /* translators: %d: Gramm */ __( 'Ø Gewicht: %d g', 'reptilien-manager' ), round( $stats['avg_weight'] ) );
		}
		if ( $cost['total'] > 0 ) {
			$lines[] = sprintf(
				/* translators: %s: Betrag */
				__( 'Futterkosten (30 Tage): %s', 'reptilien-manager' ),
				number_format_i18n( $cost['total'], 2 )
			);
		}

		$hatches = self::upcoming_hatches( 30 );
		if ( $hatches ) {
			$lines[] = '';
			$lines[] = __( 'Anstehende Schlüpfe (30 Tage):', 'reptilien-manager' );
			foreach ( $hatches as $h ) {
				$lines[] = sprintf( '• %s: %s', $h['pairing_label'], date_i18n( get_option( 'date_format' ), strtotime( $h['min_date'] ) ) );
			}
		}

		self::send(
			$settings['email'],
			__( 'Reptilien Manager – Monatsbericht', 'reptilien-manager' ),
			implode( "\n", $lines )
		);
	}

	/**
	 * E-Mail versenden.
	 *
	 * @param string $to      Empfänger.
	 * @param string $subject Betreff.
	 * @param string $body    Text.
	 * @return bool
	 */
	private static function send( $to, $subject, $body ) {
		$to = sanitize_email( $to );
		if ( ! is_email( $to ) ) {
			return false;
		}
		return wp_mail( $to, $subject, $body );
	}

	/* ---------------------------------------------------------------------
	 * iCal-Export (rein testbare Formatierung)
	 * ------------------------------------------------------------------ */

	/**
	 * Erzeugt einen iCal-Kalender (VEVENTs) aus anstehenden Schlüpfen.
	 *
	 * @param array[] $events Liste von { title, date, description }.
	 * @return string
	 */
	public static function build_ics( $events ) {
		$lines   = array();
		$lines[] = 'BEGIN:VCALENDAR';
		$lines[] = 'VERSION:2.0';
		$lines[] = 'PRODID:-//Reptilien Manager//DE';
		$lines[] = 'CALSCALE:GREGORIAN';

		foreach ( $events as $i => $event ) {
			$date = str_replace( '-', '', $event['date'] ); // YYYYMMDD.
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:rm-' . $date . '-' . $i . '@reptilien-manager';
			$lines[] = 'DTSTART;VALUE=DATE:' . $date;
			$lines[] = 'SUMMARY:' . self::ics_escape( $event['title'] );
			if ( ! empty( $event['description'] ) ) {
				$lines[] = 'DESCRIPTION:' . self::ics_escape( $event['description'] );
			}
			$lines[] = 'END:VEVENT';
		}

		$lines[] = 'END:VCALENDAR';
		return implode( "\r\n", $lines ) . "\r\n";
	}

	/**
	 * Sonderzeichen für iCal maskieren.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	private static function ics_escape( $text ) {
		$text = str_replace( array( '\\', ';', ',' ), array( '\\\\', '\\;', '\\,' ), $text );
		return str_replace( array( "\r\n", "\n" ), '\\n', $text );
	}

	/* ---------------------------------------------------------------------
	 * Handler
	 * ------------------------------------------------------------------ */

	public static function handle_ical() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rm_ical' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$events = array();
		foreach ( self::upcoming_hatches( 120 ) as $h ) {
			$events[] = array(
				'title'       => sprintf( /* translators: %s: Verpaarung */ __( '🥚 Schlupf: %s', 'reptilien-manager' ), $h['pairing_label'] ),
				'date'        => $h['min_date'],
				'description' => sprintf(
					/* translators: 1: Gelege-Nr, 2: max-Datum */
					__( 'Gelege %1$d, Schlupffenster bis %2$s', 'reptilien-manager' ),
					$h['clutch_no'],
					$h['max_date']
				),
			);
		}

		nocache_headers();
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="reptilien-schlupf.ics"' );
		echo self::build_ics( $events ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- iCal-Text.
		exit;
	}

	public static function handle_save_settings() {
		if ( ! isset( $_POST['rm_notify_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['rm_notify_nonce'] ), 'rm_save_notify' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$settings = array(
			'enabled'         => isset( $_POST['rm_enabled'] ) ? 1 : 0,
			'email'           => isset( $_POST['rm_email'] ) ? sanitize_email( wp_unslash( $_POST['rm_email'] ) ) : get_option( 'admin_email' ),
			'hatch_lead_days' => isset( $_POST['rm_hatch_lead_days'] ) ? max( 1, min( 60, absint( $_POST['rm_hatch_lead_days'] ) ) ) : 5,
			'feeding_days'    => isset( $_POST['rm_feeding_days'] ) ? max( 1, min( 60, absint( $_POST['rm_feeding_days'] ) ) ) : 4,
			'monthly_report'  => isset( $_POST['rm_monthly_report'] ) ? 1 : 0,
		);
		update_option( self::OPTION, $settings );

		wp_safe_redirect( add_query_arg( 'rm_msg', 'notify_saved', admin_url( 'edit.php?post_type=rm_animal&page=rm-notifications' ) ) );
		exit;
	}

	public static function handle_test_mail() {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( $_GET['_wpnonce'] ), 'rm_test_mail' ) ) {
			wp_die( esc_html__( 'Sicherheitsprüfung fehlgeschlagen.', 'reptilien-manager' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Keine Berechtigung.', 'reptilien-manager' ) );
		}

		$settings = self::settings();
		$ok = self::send(
			$settings['email'],
			__( 'Reptilien Manager – Testnachricht', 'reptilien-manager' ),
			__( 'Dies ist eine Testnachricht. Wenn du sie erhältst, funktioniert der E-Mail-Versand.', 'reptilien-manager' )
		);

		wp_safe_redirect( add_query_arg( 'rm_msg', $ok ? 'test_sent' : 'test_failed', admin_url( 'edit.php?post_type=rm_animal&page=rm-notifications' ) ) );
		exit;
	}
}
