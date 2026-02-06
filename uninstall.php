<?php
/**
 * Uninstall: remove options. Optional: remove generated .webp (if opt-in set and implemented).
 *
 * @package Piratez_IO
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$options = array(
	'piratez_io_ready',
	'piratez_io_processing_enabled',
	'piratez_io_env_last_check',
	'piratez_io_env_issues',
	'piratez_io_batch_state',
	'piratez_io_registered_sizes',
	'piratez_io_images_optimized',
	'piratez_io_mb_saved',
	'piratez_io_remove_webp_on_uninstall',
);

foreach ( $options as $option ) {
	delete_option( $option );
}

// Optional: if in future we implement "remove WebP on uninstall", check:
// if ( get_option( 'piratez_io_remove_webp_on_uninstall' ) === '1' ) { ... scan and delete .webp ... }
