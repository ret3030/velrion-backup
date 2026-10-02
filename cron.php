<?php
/**
 * Spouštění z cronu hostingu bez webserveru (bez časových limitů a firewallu):
 *
 *     php /cesta/k/wp-content/plugins/velrion-backup/cron.php
 *
 * Volejte každou hodinu - plugin sám pozná, jestli je záloha na řadě.
 */

if ( 'cli' !== PHP_SAPI ) {
	http_response_code( 403 );
	exit;
}

$velrion_dir = __DIR__;
for ( $velrion_i = 0; $velrion_i < 6 && ! file_exists( $velrion_dir . '/wp-load.php' ); $velrion_i++ ) {
	$velrion_dir = dirname( $velrion_dir );
}
if ( ! file_exists( $velrion_dir . '/wp-load.php' ) ) {
	fwrite( STDERR, "Velrion Backup: wp-load.php nenalezen.\n" );
	exit( 1 );
}

// Některé pluginy při běhu z příkazové řádky očekávají údaje o HTTP požadavku.
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI']    = '/';
$_SERVER['HTTP_HOST']      = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : 'localhost';

require $velrion_dir . '/wp-load.php';

if ( ! class_exists( 'Velrion_Backup' ) ) {
	fwrite( STDERR, "Velrion Backup: plugin není aktivní.\n" );
	exit( 1 );
}

$velrion_result = Velrion_Backup::tick( 'system' );
echo $velrion_result . "\n";
exit( 0 === strpos( $velrion_result, 'Chyba' ) ? 1 : 0 );
