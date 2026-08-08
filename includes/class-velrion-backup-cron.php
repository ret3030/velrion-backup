<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Správa WP-Cron naplánování týdenní zálohy.
 */
class Velrion_Backup_Cron {

	public static function register_schedule( $schedules ) {
		$schedules['velrion_weekly'] = array(
			'interval' => WEEK_IN_SECONDS,
			'display'  => 'Jednou týdně (Velrion Backup)',
		);

		return $schedules;
	}

	public static function activate() {
		$settings = Velrion_Backup_Core::get_settings();

		if ( empty( get_option( VELRION_BACKUP_OPTION ) ) ) {
			update_option( VELRION_BACKUP_OPTION, $settings );
		}

		if ( ! empty( $settings['enabled'] ) ) {
			self::schedule( (int) $settings['weekday'], (int) $settings['hour'] );
		}
	}

	public static function deactivate() {
		self::unschedule();
	}

	public static function unschedule() {
		wp_clear_scheduled_hook( VELRION_BACKUP_CRON_HOOK );
	}

	/**
	 * Přeplánuje týdenní úlohu na nejbližší výskyt zvoleného dne a hodiny.
	 *
	 * @param int $weekday 0 (neděle) - 6 (sobota), stejně jako date('w').
	 * @param int $hour    0 - 23, dle časové zóny webu.
	 */
	public static function schedule( $weekday, $hour ) {
		self::unschedule();

		$timestamp = self::next_occurrence( $weekday, $hour );

		wp_schedule_event( $timestamp, 'velrion_weekly', VELRION_BACKUP_CRON_HOOK );
	}

	private static function next_occurrence( $weekday, $hour ) {
		$tz  = wp_timezone();
		$now = new DateTime( 'now', $tz );

		$target = clone $now;
		$target->setTime( $hour, 0, 0 );

		$diff = ( $weekday - (int) $target->format( 'w' ) + 7 ) % 7;

		if ( $diff === 0 && $target <= $now ) {
			$diff = 7;
		}

		if ( $diff > 0 ) {
			$target->modify( "+{$diff} days" );
		}

		return $target->getTimestamp();
	}
}
