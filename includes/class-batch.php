<?php
/**
 * Batch processing: chunked runs, progress state, admin/cron/CLI.
 *
 * @package Piratez_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Piratez_IO_Batch
 */
class Piratez_IO_Batch {

	const OPTION_STATE    = 'piratez_io_batch_state';
	const OPTION_STATS    = 'piratez_io_stats'; // MB saved, images optimized.
	const BATCH_SIZE      = 15;
	const CRON_ACTION     = 'piratez_io_batch_cron';

	/**
	 * Init: register cron and AJAX handlers.
	 */
	public static function init() {
		add_action( self::CRON_ACTION, array( __CLASS__, 'run_batch_chunk' ) );
		add_action( 'wp_ajax_piratez_io_start_batch', array( __CLASS__, 'ajax_start_batch' ) );
		add_action( 'wp_ajax_piratez_io_pause_batch', array( __CLASS__, 'ajax_pause_batch' ) );
		add_action( 'wp_ajax_piratez_io_batch_status', array( __CLASS__, 'ajax_batch_status' ) );
		add_action( 'wp_ajax_piratez_io_run_chunk', array( __CLASS__, 'ajax_run_chunk' ) );
	}

	/**
	 * Get current batch state.
	 *
	 * @return array{status: string, offset: int, total: int, processed: int, last_run: int}
	 */
	public static function get_state() {
		$state = get_option( self::OPTION_STATE, array() );
		return wp_parse_args( $state, array(
			'status'   => 'idle', // idle | running | paused
			'offset'    => 0,
			'total'     => 0,
			'processed' => 0,
			'last_run'  => 0,
		) );
	}

	/**
	 * Start or resume batch.
	 */
	public static function start_batch() {
		if ( ! Piratez_IO_Environment::is_ready() || ! Piratez_IO_Environment::is_processing_enabled() ) {
			return array( 'success' => false, 'message' => __( 'Plugin is not ready or processing is disabled.', 'piratez-image-optimize' ) );
		}

		$state = self::get_state();
		if ( $state['status'] === 'running' ) {
			return array( 'success' => true, 'message' => __( 'Batch already running.', 'piratez-image-optimize' ) );
		}

		if ( $state['status'] === 'idle' ) {
			$total = Piratez_IO_Discovery::count_attachments();
			$state = array(
				'status'    => 'running',
				'offset'    => 0,
				'total'     => $total,
				'processed' => 0,
				'last_run'  => time(),
			);
		} else {
			$state['status'] = 'running';
		}
		update_option( self::OPTION_STATE, $state );
		self::schedule_cron();
		return array( 'success' => true, 'message' => __( 'Batch started.', 'piratez-image-optimize' ) );
	}

	/**
	 * Pause batch.
	 */
	public static function pause_batch() {
		$state = self::get_state();
		if ( $state['status'] === 'running' ) {
			$state['status'] = 'paused';
			update_option( self::OPTION_STATE, $state );
			wp_clear_scheduled_hook( self::CRON_ACTION );
		}
		return array( 'success' => true );
	}

	/**
	 * Run one chunk of the batch (called by cron or AJAX).
	 *
	 * @return array{processed: int, done: bool, errors: array}
	 */
	public static function run_batch_chunk() {
		if ( ! Piratez_IO_Environment::is_ready() || ! Piratez_IO_Environment::is_processing_enabled() ) {
			return array( 'processed' => 0, 'done' => true, 'errors' => array() );
		}

		$state = self::get_state();
		if ( $state['status'] !== 'running' ) {
			return array( 'processed' => 0, 'done' => true, 'errors' => array() );
		}

		$ids = Piratez_IO_Discovery::get_attachment_ids( self::BATCH_SIZE, $state['offset'] );
		if ( empty( $ids ) ) {
			$state['status'] = 'idle';
			$state['offset'] = 0;
			update_option( self::OPTION_STATE, $state );
			wp_clear_scheduled_hook( self::CRON_ACTION );
			return array( 'processed' => 0, 'done' => true, 'errors' => array() );
		}

		$processed = 0;
		$errors   = array();
		$bytes_saved_chunk = 0;

		foreach ( $ids as $id ) {
			$gaps = Piratez_IO_Discovery::get_attachment_gaps( $id );
			if ( empty( $gaps['missing_sizes'] ) && empty( $gaps['missing_webp'] ) ) {
				$state['offset']++;
				$state['processed']++;
				continue;
			}
			$result = Piratez_IO_Generation::process_attachment( $id );
			$processed += $result['webp_generated'];
			if ( $result['regenerated'] ) {
				$processed++;
			}
			$bytes_saved_chunk += isset( $result['bytes_saved'] ) ? (int) $result['bytes_saved'] : 0;
			$errors = array_merge( $errors, $result['errors'] );
			$state['offset']++;
			$state['processed']++;
		}

		$state['last_run'] = time();
		if ( $state['offset'] >= $state['total'] || count( $ids ) < self::BATCH_SIZE ) {
			$state['status'] = 'idle';
			$state['offset'] = 0;
			wp_clear_scheduled_hook( self::CRON_ACTION );
		}
		update_option( self::OPTION_STATE, $state );

		if ( $bytes_saved_chunk > 0 ) {
			$mb = (float) get_option( 'piratez_io_mb_saved', 0 ) + ( $bytes_saved_chunk / 1024 / 1024 );
			update_option( 'piratez_io_mb_saved', round( $mb, 2 ) );
		}

		self::schedule_cron();
		return array(
			'processed' => $processed,
			'done'      => $state['status'] === 'idle',
			'errors'    => array_slice( $errors, 0, 5 ),
		);
	}

	/**
	 * Schedule next cron run.
	 */
	private static function schedule_cron() {
		if ( wp_next_scheduled( self::CRON_ACTION ) ) {
			return;
		}
		wp_schedule_single_event( time() + 5, self::CRON_ACTION );
	}

	/**
	 * Get persisted stats (MB saved, images optimized).
	 *
	 * @return array{mb_saved: float, images_optimized: int}
	 */
	public static function get_stats() {
		return array(
			'mb_saved'          => (float) get_option( 'piratez_io_mb_saved', 0 ),
			'images_optimized'  => (int) get_option( 'piratez_io_images_optimized', 0 ),
		);
	}

	/**
	 * AJAX: start batch.
	 */
	public static function ajax_start_batch() {
		check_ajax_referer( 'piratez_io_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'piratez-image-optimize' ) ) );
		}
		$result = self::start_batch();
		wp_send_json_success( $result );
	}

	/**
	 * AJAX: pause batch.
	 */
	public static function ajax_pause_batch() {
		check_ajax_referer( 'piratez_io_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'piratez-image-optimize' ) ) );
		}
		self::pause_batch();
		wp_send_json_success();
	}

	/**
	 * AJAX: run one chunk and return status (for admin-driven batch).
	 */
	public static function ajax_run_chunk() {
		check_ajax_referer( 'piratez_io_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$result = self::run_batch_chunk();
		$state = self::get_state();
		$counts = Piratez_IO_Discovery::get_counts_needing_work();
		$stats = self::get_stats();
		$stats['images_optimized'] = max( 0, $counts['total'] - $counts['needing_work'] );
		wp_send_json_success( array(
			'state'   => $state,
			'needing_work' => $counts['needing_work'],
			'total_attachments' => $counts['total'],
			'stats'   => $stats,
			'chunk_result' => $result,
		) );
	}

	/**
	 * AJAX: get batch status (for dashboard polling).
	 */
	public static function ajax_batch_status() {
		check_ajax_referer( 'piratez_io_admin', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}
		$state = self::get_state();
		$counts = Piratez_IO_Discovery::get_counts_needing_work();
		$stats = self::get_stats();
		$stats['images_optimized'] = max( 0, $counts['total'] - $counts['needing_work'] );
		wp_send_json_success( array(
			'state'   => $state,
			'needing_work' => $counts['needing_work'],
			'total_attachments' => $counts['total'],
			'stats'   => $stats,
		) );
	}
}
