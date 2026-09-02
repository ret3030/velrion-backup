<?php
/**
 * Plugin Name: Velrion Backup
 * Description: Automatická týdenní záloha databáze a wp-content (bez uploads) mimo web-root. Udržuje 2 zálohy pojmenované podle data a času, každý běh přepíše tu nejstarší, umí je i obnovit - lokálně nebo ze staženého ZIPu z jiného webu.
 * Version: 1.1.1
 * Author: Velrion Solutions
 * Author URI: https://velrionsolutions.com
 * Plugin URI: https://velrionsolutions.com
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VELRION_BACKUP_VERSION', '1.1.1' );
define( 'VELRION_BACKUP_FILE', __FILE__ );
define( 'VELRION_BACKUP_PATH', plugin_dir_path( __FILE__ ) );
define( 'VELRION_BACKUP_OPTION', 'velrion_backup_settings' );
define( 'VELRION_BACKUP_STATE_OPTION', 'velrion_backup_state' );
define( 'VELRION_BACKUP_CRON_HOOK', 'velrion_backup_run_event' );
define( 'VELRION_BACKUP_LOCK_KEY', 'velrion_backup_running' );

require_once VELRION_BACKUP_PATH . 'includes/class-velrion-backup-db.php';
require_once VELRION_BACKUP_PATH . 'includes/class-velrion-backup-core.php';
require_once VELRION_BACKUP_PATH . 'includes/class-velrion-backup-cron.php';
require_once VELRION_BACKUP_PATH . 'includes/class-velrion-backup-admin.php';

register_activation_hook( __FILE__, array( 'Velrion_Backup_Cron', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Velrion_Backup_Cron', 'deactivate' ) );

add_filter( 'cron_schedules', array( 'Velrion_Backup_Cron', 'register_schedule' ) );
add_action( VELRION_BACKUP_CRON_HOOK, array( 'Velrion_Backup_Core', 'run' ) );

if ( is_admin() ) {
	Velrion_Backup_Admin::init();
}
