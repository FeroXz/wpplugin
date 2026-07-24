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
