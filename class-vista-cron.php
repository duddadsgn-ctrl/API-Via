<?php
/**
 * Cron wrapper around Vista_Importer.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Vista_Cron {

	const HOOK = 'vista_api_cron_import';

	/** @var Vista_Importer */
	protected $importer;

	public function __construct( Vista_Importer $importer ) {
		$this->importer = $importer;

		add_filter( 'cron_schedules', array( $this, 'filter_schedules' ) );
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'update_option_vista_api_settings', array( $this, 'reschedule_on_settings_change' ), 10, 2 );
	}

	public function filter_schedules( $schedules ) {
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array( 'interval' => WEEK_IN_SECONDS, 'display' => __( 'Semanalmente', 'vista-api' ) );
		}
		if ( ! isset( $schedules['monthly'] ) ) {
			$schedules['monthly'] = array( 'interval' => 30 * DAY_IN_SECONDS, 'display' => __( 'Mensalmente', 'vista-api' ) );
		}
		return $schedules;
	}

	public function schedule() {
		$settings = wp_parse_args( get_option( 'vista_api_settings', array() ), array(
			'auto_import' => 0,
			'interval'    => 'hourly',
		) );
		$this->unschedule();
		if ( empty( $settings['auto_import'] ) ) {
			return;
		}
		$interval = in_array( $settings['interval'], array( 'hourly', 'daily', 'weekly', 'monthly' ), true )
			? $settings['interval']
			: 'hourly';
		wp_schedule_event( time() + 60, $interval, self::HOOK );
	}

	public function unschedule() {
		$ts = wp_next_scheduled( self::HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
		}
		wp_clear_scheduled_hook( self::HOOK );
	}

	public function reschedule_on_settings_change( $old, $new ) {
		$this->schedule();
	}

	public function run() {
		$this->importer->run_full();
	}
}
