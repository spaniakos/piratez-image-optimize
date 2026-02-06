<?php
/**
 * Discovery: registered sizes and attachment scan (missing sizes / missing WebP).
 *
 * @package Piratez_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Piratez_IO_Discovery
 */
class Piratez_IO_Discovery {

	const CACHE_OPTION = 'piratez_io_registered_sizes';
	const CACHE_TTL    = 0; // No expiry; invalidate on theme switch.

	/**
	 * Get all registered image sizes (cached).
	 *
	 * @return array List of size names.
	 */
	public static function get_registered_sizes() {
		$cached = get_option( self::CACHE_OPTION, array() );
		if ( ! empty( $cached ) && is_array( $cached ) ) {
			return $cached;
		}
		$sizes = get_intermediate_image_sizes();
		update_option( self::CACHE_OPTION, $sizes );
		return $sizes;
	}

	/**
	 * Invalidate size cache (e.g. on after_switch_theme).
	 */
	public static function invalidate_size_cache() {
		delete_option( self::CACHE_OPTION );
	}

	/**
	 * Get all image attachment IDs (post_type=attachment, image mime).
	 *
	 * @param int $limit  Optional. Max number to return. 0 = no limit.
	 * @param int $offset Optional. Offset for pagination.
	 * @return int[] Attachment IDs.
	 */
	public static function get_attachment_ids( $limit = 0, $offset = 0 ) {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'orderby'        => 'ID',
			'order'          => 'ASC',
			'numberposts'    => -1,
		);
		if ( $limit > 0 ) {
			$args['posts_per_page'] = $limit;
			$args['offset']         = $offset;
		}
		$query = new WP_Query( $args );
		return $query->posts ? array_map( 'intval', $query->posts ) : array();
	}

	/**
	 * Count total image attachments.
	 *
	 * @return int
	 */
	public static function count_attachments() {
		$args = array(
			'post_type'      => 'attachment',
			'post_mime_type' => 'image/',
			'post_status'    => 'inherit',
			'fields'         => 'ids',
			'posts_per_page' => -1,
		);
		$query = new WP_Query( $args );
		return $query->found_posts;
	}

	/**
	 * For an attachment, get list of missing sizes and missing WebP (per size that exists).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{missing_sizes: string[], missing_webp: array} missing_webp is array of size_name => file path (source file that needs WebP).
	 */
	public static function get_attachment_gaps( $attachment_id ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			return array( 'missing_sizes' => self::get_registered_sizes(), 'missing_webp' => array() );
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return array( 'missing_sizes' => array(), 'missing_webp' => array() );
		}

		$base_dir = trailingslashit( $upload_dir['basedir'] );
		$rel_file = isset( $meta['file'] ) ? $meta['file'] : '';
		if ( ! $rel_file ) {
			return array( 'missing_sizes' => array(), 'missing_webp' => array() );
		}

		$file_dir = trailingslashit( dirname( $base_dir . $rel_file ) );
		$sizes_registered = self::get_registered_sizes();
		$sizes_in_meta    = isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array();
		$missing_sizes   = array();
		$missing_webp    = array();

		// Full-size image: generate WebP if missing or not worth serving (e.g. bloated).
		$full_path = $base_dir . $rel_file;
		if ( file_exists( $full_path ) ) {
			$webp_path = self::path_to_webp( $full_path );
			if ( ! self::webp_worth_serving( $full_path, $webp_path ) ) {
				$missing_webp['full'] = $full_path;
			}
		}

		foreach ( $sizes_registered as $size_name ) {
			$size_file = null;
			if ( isset( $sizes_in_meta[ $size_name ]['file'] ) ) {
				$size_file = $sizes_in_meta[ $size_name ]['file'];
			}
			if ( $size_file === null ) {
				$missing_sizes[] = $size_name;
				continue;
			}

			$abs_path = $file_dir . $size_file;
			if ( ! file_exists( $abs_path ) ) {
				$missing_sizes[] = $size_name;
				continue;
			}
			$webp_path = self::path_to_webp( $abs_path );
			if ( ! self::webp_worth_serving( $abs_path, $webp_path ) ) {
				$missing_webp[ $size_name ] = $abs_path;
			}
		}

		$missing_sizes = array_unique( $missing_sizes );
		return array( 'missing_sizes' => $missing_sizes, 'missing_webp' => $missing_webp );
	}

	/**
	 * Convert a file path to its WebP sibling path.
	 *
	 * @param string $path File path (e.g. /path/to/image-300x200.jpg).
	 * @return string (e.g. /path/to/image-300x200.webp).
	 */
	public static function path_to_webp( $path ) {
		$ext = strrchr( $path, '.' );
		if ( $ext === false ) {
			return $path . '.webp';
		}
		return substr_replace( $path, '.webp', -strlen( $ext ) );
	}

	/**
	 * Whether an existing WebP file is worth serving (optimized, not larger than source).
	 * Used so we do not serve bloated WebP (e.g. 5MB) when the original is small.
	 *
	 * @param string $source_path Path to source image (jpg/png).
	 * @param string $webp_path   Path to WebP file.
	 * @return bool True if WebP exists and is not larger than source (within 5% tolerance).
	 */
	public static function webp_worth_serving( $source_path, $webp_path ) {
		if ( ! $source_path || ! $webp_path ) {
			return false;
		}
		if ( ! @file_exists( $webp_path ) ) {
			return false;
		}
		$source_size = @filesize( $source_path );
		$webp_size   = @filesize( $webp_path );
		if ( $source_size === false || $webp_size === false ) {
			return true;
		}
		return $webp_size <= ( (int) $source_size * 1.05 );
	}

	/**
	 * Get total count of attachments that need work (missing sizes or missing WebP).
	 *
	 * @return array{total: int, needing_work: int}
	 */
	public static function get_counts_needing_work() {
		$total = self::count_attachments();
		$ids   = self::get_attachment_ids( 0, 0 );
		$needing = 0;
		foreach ( $ids as $id ) {
			$gaps = self::get_attachment_gaps( $id );
			if ( ! empty( $gaps['missing_sizes'] ) || ! empty( $gaps['missing_webp'] ) ) {
				$needing++;
			}
		}
		return array( 'total' => $total, 'needing_work' => $needing );
	}
}
