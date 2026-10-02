<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'velrion\\_backup\\_%'" );

wp_clear_scheduled_hook( 'velrion_backup_tick' );
wp_clear_scheduled_hook( 'velrion_backup_run_event' );

// Samotné ZIPy se zálohami se záměrně nemažou, aby o ně nikdo nepřišel odinstalací pluginu.
