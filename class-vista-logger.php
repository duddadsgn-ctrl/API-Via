<?php
/**
 * Simple rolling logger stored in wp-content/uploads.
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class Vista_Logger {

	const OPTION = 'vista_api_log_tail';
	const MAX_LINES = 500;

	public function log( $message, $level = 'info' ) {
		if ( is_array( $message ) || is_object( $message ) ) {
			$message = wp_json_encode( $message );
		}
		$line = sprintf( '[%s] [%s] %s', current_time( 'mysql' ), strtoupper( $level ), $message );

		$tail   = (array) get_option( self::OPTION, array() );
		$tail[] = $line;
		if ( count( $tail ) > self::MAX_LINES ) {
			$tail = array_slice( $tail, -self::MAX_LINES );
		}
		update_option( self::OPTION, $tail, false );
	}

	public function get_lines() {
		return (array) get_option( self::OPTION, array() );
	}

	public function clear() {
		delete_option( self::OPTION );
	}
}
