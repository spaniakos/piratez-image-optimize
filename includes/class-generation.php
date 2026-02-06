<?php
/**
 * Generation: WebP creation (cwebp / Imagick / GD). Optional WP regeneration for missing sizes.
 *
 * @package Piratez_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Piratez_IO_Generation
 */
class Piratez_IO_Generation {

	/**
	 * Generate missing sizes for an attachment (WordPress does the resize), then WebP for all existing sizes.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{regenerated: bool, webp_generated: int, bytes_saved: int, errors: string[]}
	 */
	public static function process_attachment( $attachment_id ) {
		set_time_limit( 120 );
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		$result = array( 'regenerated' => false, 'webp_generated' => 0, 'bytes_saved' => 0, 'errors' => array() );
		$file   = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			$result['errors'][] = __( 'File not found.', 'piratez-image-optimize' );
			return $result;
		}

		$gaps = Piratez_IO_Discovery::get_attachment_gaps( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );

		// Regenerate missing sizes via WordPress.
		if ( ! empty( $gaps['missing_sizes'] ) ) {
			$new_meta = wp_generate_attachment_metadata( $attachment_id, $file );
			if ( is_array( $new_meta ) ) {
				wp_update_attachment_metadata( $attachment_id, $new_meta );
				$result['regenerated'] = true;
				$meta = wp_get_attachment_metadata( $attachment_id );
				$gaps = Piratez_IO_Discovery::get_attachment_gaps( $attachment_id );
			} else {
				$result['errors'][] = __( 'WordPress could not regenerate metadata.', 'piratez-image-optimize' );
			}
		}

		// Generate WebP for each path in missing_webp (missing or not worth serving; overwrite bloated WebP).
		foreach ( $gaps['missing_webp'] as $size_name => $source_path ) {
			if ( ! file_exists( $source_path ) ) {
				continue;
			}
			$webp_path = Piratez_IO_Discovery::path_to_webp( $source_path );
			if ( Piratez_IO_Discovery::webp_worth_serving( $source_path, $webp_path ) ) {
				continue;
			}
			if ( self::create_webp_file( $source_path, $webp_path ) ) {
				$result['webp_generated']++;
				$src_size = @filesize( $source_path );
				$webp_size = @filesize( $webp_path );
				if ( $src_size !== false && $webp_size !== false && $src_size > $webp_size ) {
					$result['bytes_saved'] += $src_size - $webp_size;
				}
			} else {
				$result['errors'][] = sprintf(
					/* translators: 1: source file */
					__( 'WebP creation failed for %1$s', 'piratez-image-optimize' ),
					basename( $source_path )
				);
			}
		}

		return $result;
	}

	/**
	 * Generate WebP only for an attachment (all sizes that exist and don't have WebP yet).
	 * Used after upload: only newly created sizes need WebP.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $metadata      Attachment metadata (from wp_generate_attachment_metadata).
	 * @return int Number of WebP files created.
	 */
	public static function generate_webp_for_attachment( $attachment_id, $metadata = null ) {
		if ( $metadata === null ) {
			$metadata = wp_get_attachment_metadata( $attachment_id );
		}
		if ( ! is_array( $metadata ) ) {
			return 0;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return 0;
		}
		$base_dir = trailingslashit( $upload_dir['basedir'] );
		$rel_file = isset( $metadata['file'] ) ? $metadata['file'] : '';
		if ( ! $rel_file ) {
			return 0;
		}
		$file_dir = trailingslashit( dirname( $base_dir . $rel_file ) );
		$count    = 0;

		// Full size.
		$full_path = $base_dir . $rel_file;
		if ( file_exists( $full_path ) ) {
			$webp_path = Piratez_IO_Discovery::path_to_webp( $full_path );
			if ( ! Piratez_IO_Discovery::webp_worth_serving( $full_path, $webp_path ) && self::create_webp_file( $full_path, $webp_path ) ) {
				$count++;
			}
		}

		$sizes = isset( $metadata['sizes'] ) && is_array( $metadata['sizes'] ) ? $metadata['sizes'] : array();
		foreach ( $sizes as $size_name => $size_data ) {
			if ( empty( $size_data['file'] ) ) {
				continue;
			}
			$abs_path = $file_dir . $size_data['file'];
			if ( ! file_exists( $abs_path ) ) {
				continue;
			}
			$webp_path = Piratez_IO_Discovery::path_to_webp( $abs_path );
			if ( ! Piratez_IO_Discovery::webp_worth_serving( $abs_path, $webp_path ) && self::create_webp_file( $abs_path, $webp_path ) ) {
				$count++;
			}
		}
		return $count;
	}

	/**
	 * Create a WebP file from source. Uses cwebp > Imagick > GD.
	 *
	 * @param string $source_path Path to source image.
	 * @param string $webp_path   Path to write WebP.
	 * @return bool Success.
	 */
	public static function create_webp_file( $source_path, $webp_path ) {
		if ( Piratez_IO_Environment::has_cwebp_cli() ) {
			return self::create_webp_via_cwebp( $source_path, $webp_path );
		}
		if ( self::imagick_webp_available() ) {
			return self::create_webp_via_imagick( $source_path, $webp_path );
		}
		if ( function_exists( 'imagewebp' ) ) {
			return self::create_webp_via_gd( $source_path, $webp_path );
		}
		return false;
	}

	/**
	 * Create WebP using cwebp CLI.
	 *
	 * @param string $source_path Source image path.
	 * @param string $webp_path   WebP output path.
	 * @return bool
	 */
	private static function create_webp_via_cwebp( $source_path, $webp_path ) {
		$cmd = sprintf(
			'cwebp -q 82 -quiet %s -o %s 2>/dev/null',
			escapeshellarg( $source_path ),
			escapeshellarg( $webp_path )
		);
		exec( $cmd, $output, $code );
		return $code === 0 && file_exists( $webp_path );
	}

	/**
	 * Create WebP using Imagick.
	 *
	 * @param string $source_path Source image path.
	 * @param string $webp_path   WebP output path.
	 * @return bool
	 */
	private static function create_webp_via_imagick( $source_path, $webp_path ) {
		try {
			$im = new Imagick( $source_path );
			$im->setImageFormat( 'WEBP' );
			$im->setImageCompressionQuality( 82 );
			$im->stripImage();
			$result = $im->writeImage( $webp_path );
			$im->destroy();
			return $result && file_exists( $webp_path );
		} catch ( Exception $e ) {
			return false;
		}
	}

	/**
	 * Create WebP using GD.
	 *
	 * @param string $source_path Source image path.
	 * @param string $webp_path   WebP output path.
	 * @return bool
	 */
	private static function create_webp_via_gd( $source_path, $webp_path ) {
		$info = @getimagesize( $source_path );
		if ( ! $info ) {
			return false;
		}
		$image = null;
		switch ( $info[2] ) {
			case IMAGETYPE_JPEG:
				$image = @imagecreatefromjpeg( $source_path );
				break;
			case IMAGETYPE_PNG:
				$image = @imagecreatefrompng( $source_path );
				if ( $image ) {
					imagealphablending( $image, true );
					imagesavealpha( $image, true );
				}
				break;
			case IMAGETYPE_GIF:
				$image = @imagecreatefromgif( $source_path );
				break;
			default:
				return false;
		}
		if ( ! $image ) {
			return false;
		}
		$result = imagewebp( $image, $webp_path );
		imagedestroy( $image );
		return $result && file_exists( $webp_path );
	}

	/**
	 * Check if Imagick with WebP is available.
	 *
	 * @return bool
	 */
	private static function imagick_webp_available() {
		if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
			return false;
		}
		try {
			$formats = Imagick::queryFormats();
			return in_array( 'WEBP', $formats, true );
		} catch ( Exception $e ) {
			return false;
		}
	}
}
