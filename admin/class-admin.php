<?php
/**
 * Admin UI: dashboard, status, actions.
 *
 * @package ServerSideWebp
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Piratez_IO_Admin
 */
class Piratez_IO_Admin {

	const SLUG = 'piratez-image-optimize';
	const CAP  = 'manage_options';

	/**
	 * Init: add menu and enqueue scripts.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_post_piratez_io_toggle_processing', array( __CLASS__, 'handle_toggle_processing' ) );
	}

	/**
	 * Add menu page.
	 */
	public static function add_menu() {
		add_management_page(
			__( 'Piratez Image Optimize', 'piratez-image-optimize' ),
			__( 'Piratez Image Optimize', 'piratez-image-optimize' ),
			self::CAP,
			self::SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Register settings (enable processing).
	 */
	public static function register_settings() {
		register_setting( 'piratez_io_settings', 'piratez_io_processing_enabled', array(
			'type'              => 'string',
			'sanitize_callback' => function ( $v ) {
				return $v === '1' ? '1' : '0';
			},
		) );
	}

	/**
	 * Handle toggle processing (form post).
	 */
	public static function handle_toggle_processing() {
		if ( ! current_user_can( self::CAP ) || ! isset( $_POST['_wpnonce'] ) ) {
			wp_safe_redirect( self::get_settings_url() );
			exit;
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'piratez_io_toggle' ) ) {
			wp_safe_redirect( self::get_settings_url() );
			exit;
		}
		$enabled = isset( $_POST['processing_enabled'] ) && $_POST['processing_enabled'] === '1';
		Piratez_IO_Environment::set_processing_enabled( $enabled );
		wp_safe_redirect( add_query_arg( 'updated', '1', self::get_settings_url() ) );
		exit;
	}

	/**
	 * Get admin page URL.
	 *
	 * @return string
	 */
	public static function get_settings_url() {
		return admin_url( 'tools.php?page=' . self::SLUG );
	}

	/**
	 * Enqueue scripts/styles.
	 *
	 * @param string $hook Page hook.
	 */
	public static function enqueue( $hook ) {
		if ( $hook !== 'tools_page_' . self::SLUG ) {
			return;
		}
		wp_enqueue_style( 'piratez-image-optimize-admin', plugins_url( 'admin/css/admin.css', PIRATEZ_IO_PLUGIN_FILE ), array(), PIRATEZ_IO_VERSION );
		wp_enqueue_script( 'piratez-image-optimize-admin', plugins_url( 'admin/js/admin.js', PIRATEZ_IO_PLUGIN_FILE ), array( 'jquery' ), PIRATEZ_IO_VERSION, true );
		wp_localize_script( 'piratez-image-optimize-admin', 'piratezIO', array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'piratez_io_admin' ),
			'strings'  => array(
				'starting' => __( 'Starting…', 'piratez-image-optimize' ),
				'running'  => __( 'Running…', 'piratez-image-optimize' ),
				'paused'   => __( 'Paused', 'piratez-image-optimize' ),
				'done'     => __( 'Done', 'piratez-image-optimize' ),
				'error'    => __( 'Error', 'piratez-image-optimize' ),
			),
		) );
	}

	/**
	 * Render admin page.
	 */
	public static function render_page() {
		$ready = Piratez_IO_Environment::is_ready();
		$issues = Piratez_IO_Environment::get_issues();
		$processing_enabled = Piratez_IO_Environment::is_processing_enabled();
		$state = Piratez_IO_Batch::get_state();
		$counts = Piratez_IO_Discovery::get_counts_needing_work();
		$stats = Piratez_IO_Batch::get_stats();
		$stats['images_optimized'] = max( 0, $counts['total'] - $counts['needing_work'] );
		$sizes = Piratez_IO_Discovery::get_registered_sizes();
		include PIRATEZ_IO_PLUGIN_DIR . 'admin/views/dashboard.php';
	}
}
