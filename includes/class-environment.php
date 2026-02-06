<?php
/**
 * Environment detection and run gate (ready flag).
 *
 * @package Piratez_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Piratez_IO_Environment
 */
class Piratez_IO_Environment {

	const OPTION_READY       = 'piratez_io_ready';
	const OPTION_PROCESSING  = 'piratez_io_processing_enabled';
	const OPTION_LAST_CHECK  = 'piratez_io_env_last_check';
	const OPTION_ISSUES      = 'piratez_io_env_issues';

	/**
	 * Run environment detection and update ready flag.
	 */
	public static function run_detection() {
		$issues = self::detect();
		$ready  = empty( $issues['hard'] );
		update_option( self::OPTION_READY, $ready ? '1' : '0' );
		update_option( self::OPTION_LAST_CHECK, time() );
		update_option( self::OPTION_ISSUES, $issues );
		return $issues;
	}

	/**
	 * Detect environment; return array of issues: 'hard' and 'soft'.
	 *
	 * @return array{hard: array, soft: array}
	 */
	public static function detect() {
		$hard = array();
		$soft = array();

		// PHP >= 7.4
		$php_version = phpversion();
		if ( version_compare( $php_version, '7.4', '<' ) ) {
			$hard[] = array(
				'code'    => 'php_version',
				'message' => sprintf(
					/* translators: 1: required version, 2: current version */
					__( 'PHP %1$s or higher is required. Current: %2$s', 'piratez-image-optimize' ),
					'7.4',
					$php_version
				),
				'action'  => __( 'Upgrade PHP or contact your host.', 'piratez-image-optimize' ),
			);
		}

		// WebP engine: Imagick or GD
		$has_imagick = extension_loaded( 'imagick' );
		$has_imagick_webp = false;
		if ( $has_imagick && class_exists( 'Imagick' ) ) {
			try {
				$formats = Imagick::queryFormats();
				$has_imagick_webp = in_array( 'WEBP', $formats, true );
			} catch ( Exception $e ) {
				// ignore
			}
		}

		$has_gd_webp = extension_loaded( 'gd' ) && function_exists( 'imagewebp' );

		if ( ! $has_imagick_webp && ! $has_gd_webp ) {
			$hard[] = array(
				'code'    => 'webp_engine',
				'message' => __( 'No WebP-capable image engine found.', 'piratez-image-optimize' ),
				'action'  => __( 'Install PHP Imagick with WebP support, or ensure GD is compiled with WebP (imagewebp).', 'piratez-image-optimize' ),
			);
		}

		// Writable uploads
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) || ! is_writable( $upload_dir['basedir'] ) ) {
			$hard[] = array(
				'code'    => 'uploads_writable',
				'message' => __( 'Uploads directory is not writable.', 'piratez-image-optimize' ),
				'action'  => __( 'Fix permissions on wp-content/uploads.', 'piratez-image-optimize' ),
			);
		}

		// Soft: cwebp
		$cwebp = self::has_cwebp_cli();
		if ( ! $cwebp ) {
			$soft[] = array(
				'code'    => 'cwebp',
				'message' => __( 'cwebp CLI not found. Plugin will use PHP Imagick or GD.', 'piratez-image-optimize' ),
			);
		}

		// Soft: memory
		$memory = ini_get( 'memory_limit' );
		$memory_bytes = wp_convert_hr_to_bytes( $memory );
		if ( $memory_bytes > 0 && $memory_bytes < 128 * 1024 * 1024 ) {
			$soft[] = array(
				'code'    => 'memory',
				'message' => sprintf(
					/* translators: 1: current limit */
					__( 'PHP memory_limit is %1$s. 128MB or more is recommended for large batches.', 'piratez-image-optimize' ),
					$memory
				),
			);
		}

		return array( 'hard' => $hard, 'soft' => $soft );
	}

	/**
	 * Check if cwebp CLI is available.
	 *
	 * @return bool
	 */
	public static function has_cwebp_cli() {
		if ( ! function_exists( 'exec' ) ) {
			return false;
		}
		$output = array();
		exec( 'command -v cwebp 2>/dev/null', $output );
		return ! empty( $output );
	}

	/**
	 * Whether environment is ready (all hard requirements met).
	 *
	 * @return bool
	 */
	public static function is_ready() {
		return get_option( self::OPTION_READY, '0' ) === '1';
	}

	/**
	 * Get last detection issues.
	 *
	 * @return array{hard: array, soft: array}
	 */
	public static function get_issues() {
		$issues = get_option( self::OPTION_ISSUES, array() );
		return wp_parse_args( $issues, array( 'hard' => array(), 'soft' => array() ) );
	}

	/**
	 * Enable or disable processing (batch and on-upload).
	 *
	 * @param bool $enabled
	 */
	public static function set_processing_enabled( $enabled ) {
		update_option( self::OPTION_PROCESSING, $enabled ? '1' : '0' );
	}

	/**
	 * Whether processing (batch, on-upload) is enabled.
	 *
	 * @return bool
	 */
	public static function is_processing_enabled() {
		return get_option( self::OPTION_PROCESSING, '1' ) === '1';
	}
}
