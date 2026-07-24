<?php
/**
 * Aufräumen bei Deinstallation.
 *
 * Tiere, Verpaarungen und Fütterungsprotokolle bleiben bewusst erhalten,
 * damit beim versehentlichen Löschen des Plugins keine Bestandsdaten
 * verloren gehen. Sie können über das WordPress-Backend entfernt werden.
 *
 * @package Reptilien_Manager
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Die Züchter-Rolle entfernen (Bestandsdaten bleiben erhalten).
remove_role( 'rm_breeder' );

// Geplanten Cron und plugin-eigene Optionen aufräumen.
$timestamp = wp_next_scheduled( 'rm_daily_check' );
if ( $timestamp ) {
	wp_unschedule_event( $timestamp, 'rm_daily_check' );
}
delete_option( 'rm_notify_settings' );
delete_option( 'rm_hatch_notified' );
