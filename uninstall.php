<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'velrion_backup_settings' );
delete_option( 'velrion_backup_state' );
delete_option( 'velrion_backup_dir_suffix' );

wp_clear_scheduled_hook( 'velrion_backup_run_event' );

// Poznámka: samotný ZIP se zálohou se záměrně nemaže, aby uživatel o poslední zálohu
// nepřišel jen kvůli odinstalaci pluginu. Smazat je potřeba ručně.
