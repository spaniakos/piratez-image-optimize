<?php
/**
 * Plugin Name: Piratez Image Optimize
 * Plugin URI: https://github.com/spaniakos/piratez-image-optimize
 * Description: Portable, non-destructive server-side image optimization: discovers install image sizes, generates missing sizes and WebP, serves WebP for featured, attachment, and content images. Full coverage; no theme changes; reversible.
 * Version: 1.0.0
 * Author: Spaniakos
 * Author URI: https://github.com/spaniakos
 * Text Domain: piratez-image-optimize
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 *
 * @package Piratez_Image_Optimize
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PIRATEZ_IO_VERSION', '1.0.0' );
define( 'PIRATEZ_IO_PLUGIN_FILE', __FILE__ );
define( 'PIRATEZ_IO_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PIRATEZ_IO_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Activation: always succeed. Run environment check and set ready flag.
 */
function piratez_io_activate() {
	require_once PIRATEZ_IO_PLUGIN_DIR . 'includes/class-environment.php';
	Piratez_IO_Environment::run_detection();
}

register_activation_hook( __FILE__, 'piratez_io_activate' );

/**
 * Bootstrap plugin.
 */
function piratez_io_init() {
	load_plugin_textdomain( 'piratez-image-optimize', false, dirname( PIRATEZ_IO_PLUGIN_BASENAME ) . '/languages' );
	require_once PIRATEZ_IO_PLUGIN_DIR . 'includes/class-environment.php';
	require_once PIRATEZ_IO_PLUGIN_DIR . 'includes/class-discovery.php';
	require_once PIRATEZ_IO_PLUGIN_DIR . 'includes/class-generation.php';
	require_once PIRATEZ_IO_PLUGIN_DIR . 'includes/class-batch.php';
	require_once PIRATEZ_IO_PLUGIN_DIR . 'includes/class-substitution.php';
	require_once PIRATEZ_IO_PLUGIN_DIR . 'includes/class-content-substitution.php';

	if ( is_admin() ) {
		Piratez_IO_Environment::run_detection();
	}

	if ( is_admin() ) {
		require_once PIRATEZ_IO_PLUGIN_DIR . 'admin/class-admin.php';
		Piratez_IO_Admin::init();
	}

	Piratez_IO_Substitution::init();
	Piratez_IO_Content_Substitution::init();
	Piratez_IO_Batch::init();

	add_action( 'wp_generate_attachment_metadata', 'piratez_io_on_upload', 20, 2 );
	add_action( 'after_switch_theme', array( 'Piratez_IO_Discovery', 'invalidate_size_cache' ) );
}

add_action( 'plugins_loaded', 'piratez_io_init' );

/**
 * On upload: generate WebP for newly created sizes only.
 *
 * @param array $metadata       Attachment metadata.
 * @param int   $attachment_id  Attachment ID.
 * @return array
 */
function piratez_io_on_upload( $metadata, $attachment_id ) {
	if ( ! Piratez_IO_Environment::is_ready() || ! Piratez_IO_Environment::is_processing_enabled() ) {
		return $metadata;
	}
	Piratez_IO_Generation::generate_webp_for_attachment( $attachment_id, $metadata );
	return $metadata;
}
