<?php
/**
 * Plugin Name: Velrion Backup
 * Description: Kompletní záloha webu (databáze + všechny soubory) s ověřením integrity, stažením a obnovou - i ze ZIPu z jiného webu. Automatické zálohování přes cron, uchovává 3 poslední zálohy.
 * Version: 2.0.2
 * Author: Velrion Solutions
 * Author URI: https://velrionsolutions.com
 * Plugin URI: https://velrionsolutions.com
 * Requires PHP: 7.4
 * Requires at least: 5.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VELRION_BACKUP_VERSION', '2.0.2' );
define( 'VELRION_BACKUP_PATH', plugin_dir_path( __FILE__ ) );

require_once VELRION_BACKUP_PATH . 'includes/class-velrion-backup-db.php';
require_once VELRION_BACKUP_PATH . 'includes/class-velrion-backup.php';

register_activation_hook( __FILE__, array( 'Velrion_Backup', 'schedule_wp_cron' ) );
register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( Velrion_Backup::CRON_HOOK );
	}
);

// Pojistka přes WP-Cron (když na webu běží) - hlavní cesta je systémový cron, viz admin.
add_action(
	Velrion_Backup::CRON_HOOK,
	function () {
		Velrion_Backup::tick( 'wp-cron' );
	}
);

// Systémový cron přes URL: admin-ajax.php?action=velrion_backup_cron&key=… (admin-ajax
// necachují žádné cache pluginy ani CDN, na rozdíl od běžných URL webu).
function velrion_backup_cron_endpoint() {
	nocache_headers();
	header( 'Content-Type: text/plain; charset=utf-8' );

	$key = isset( $_GET['key'] ) ? (string) wp_unslash( $_GET['key'] ) : '';
	if ( ! hash_equals( Velrion_Backup::cron_key(), $key ) ) {
		Velrion_Backup::record_rejected();
		status_header( 403 );
		exit( 'Neplatný klíč.' );
	}

	if ( isset( $_GET['test'] ) ) {
		exit( 'OK - adresa pro cron funguje. (Testovací volání, záloha se nespouštěla.)' );
	}

	ignore_user_abort( true );
	exit( Velrion_Backup::tick( 'system' ) ); // phpcs:ignore WordPress.Security.EscapeOutput
}
add_action( 'wp_ajax_velrion_backup_cron', 'velrion_backup_cron_endpoint' );
add_action( 'wp_ajax_nopriv_velrion_backup_cron', 'velrion_backup_cron_endpoint' );

// Plugin nepracuje s objednávkami - je kompatibilní s HPOS (jinak WooCommerce hlásí nekompatibilitu).
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		}
	}
);

if ( is_admin() ) {
	require_once VELRION_BACKUP_PATH . 'includes/class-velrion-backup-admin.php';
	Velrion_Backup_Admin::init();
}
