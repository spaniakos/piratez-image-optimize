<?php
/**
 * Front-end substitution: wrap featured and attachment image output in <picture> with WebP.
 *
 * @package Piratez_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Piratez_IO_Substitution
 */
class Piratez_IO_Substitution {

	/**
	 * Init: register filters.
	 */
	public static function init() {
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'filter_post_thumbnail_html' ), 10, 5 );
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'filter_attachment_image' ), 10, 5 );
	}

	/**
	 * Filter post_thumbnail_html: wrap in picture with WebP source if available.
	 *
	 * @param string   $html              Existing HTML.
	 * @param int      $post_id           Post ID.
	 * @param int      $post_thumbnail_id Attachment ID.
	 * @param string   $size              Size name.
	 * @param string[] $attr              Attributes.
	 * @return string
	 */
	public static function filter_post_thumbnail_html( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
		if ( empty( $html ) || ! $post_thumbnail_id ) {
			return $html;
		}
		return self::wrap_img_with_picture( $html, $post_thumbnail_id, $size );
	}

	/**
	 * Filter wp_get_attachment_image: wrap in picture with WebP source if available.
	 *
	 * @param string   $html          Existing img HTML.
	 * @param int      $attachment_id Attachment ID.
	 * @param string   $size          Size name.
	 * @param bool     $icon          Icon.
	 * @param string[] $attr          Attributes.
	 * @return string
	 */
	public static function filter_attachment_image( $html, $attachment_id, $size, $icon, $attr ) {
		if ( empty( $html ) || $icon ) {
			return $html;
		}
		return self::wrap_img_with_picture( $html, $attachment_id, $size );
	}

	/**
	 * Wrap img HTML in picture with WebP source(s) when .webp files exist.
	 *
	 * @param string $html          Full img tag (may include srcset/sizes).
	 * @param int    $attachment_id Attachment ID.
	 * @param string $size          Requested size.
	 * @return string
	 */
	private static function wrap_img_with_picture( $html, $attachment_id, $size ) {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) || empty( $meta['file'] ) ) {
			return $html;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return $html;
		}

		$base_url = $upload_dir['baseurl'];
		$base_dir = $upload_dir['basedir'];
		$rel_file = $meta['file'];
		$file_dir = trailingslashit( dirname( $base_dir . '/' . $rel_file ) );

		// Get src and srcset from img.
		if ( ! preg_match( '/<img\s[^>]*>/i', $html, $m ) ) {
			return $html;
		}
		$img = $m[0];
		$src = self::get_attr( $img, 'src' );
		if ( ! $src ) {
			return $html;
		}

		// Resolve which file this src points to (could be full or a size).
		$src_path = self::url_to_path( $src );
		if ( ! $src_path || ! file_exists( $src_path ) ) {
			return $html;
		}
		$webp_path = Piratez_IO_Discovery::path_to_webp( $src_path );
		if ( ! Piratez_IO_Discovery::webp_worth_serving( $src_path, $webp_path ) ) {
			return $html;
		}

		$webp_src = self::path_to_url( $webp_path );
		$webp_srcset = '';
		$srcset = self::get_attr( $img, 'srcset' );
		$sizes_attr = self::get_attr( $img, 'sizes' );
		if ( $srcset ) {
			$webp_srcset = self::srcset_to_webp( $srcset );
			if ( empty( $webp_srcset ) ) {
				$webp_srcset = $webp_src;
			}
		} else {
			$webp_srcset = $webp_src;
		}

		$source = sprintf(
			'<source type="image/webp" srcset="%s"%s>',
			esc_attr( $webp_srcset ),
			$sizes_attr ? ' sizes="' . esc_attr( $sizes_attr ) . '"' : ''
		);
		return '<picture>' . $source . ' ' . $html . '</picture>';
	}

	/**
	 * Get attribute value from img tag.
	 *
	 * @param string $html Tag HTML.
	 * @param string $name Attribute name.
	 * @return string|null
	 */
	private static function get_attr( $html, $name ) {
		if ( preg_match( '/\s' . preg_quote( $name, '/' ) . '\s*=\s*["\']([^"\']*)["\']/i', $html, $m ) ) {
			return $m[1];
		}
		return null;
	}

	/**
	 * Convert srcset string to WebP URLs where files exist.
	 *
	 * @param string $srcset Original srcset.
	 * @return string
	 */
	private static function srcset_to_webp( $srcset ) {
		$parts = array_map( 'trim', explode( ',', $srcset ) );
		$out   = array();
		foreach ( $parts as $part ) {
			$chunks = preg_split( '/\s+/', $part, 2 );
			$url    = $chunks[0];
			$descriptor = isset( $chunks[1] ) ? ' ' . $chunks[1] : '';
			$path = self::url_to_path( $url );
			if ( $path && file_exists( $path ) ) {
				$webp_path = Piratez_IO_Discovery::path_to_webp( $path );
				if ( Piratez_IO_Discovery::webp_worth_serving( $path, $webp_path ) ) {
					$out[] = self::path_to_url( $webp_path ) . $descriptor;
				} else {
					$out[] = $part;
				}
			} else {
				$out[] = $part;
			}
		}
		return implode( ', ', $out );
	}

	/**
	 * Convert URL (uploads) to filesystem path.
	 *
	 * @param string $url URL.
	 * @return string|null
	 */
	private static function url_to_path( $url ) {
		$upload_dir = wp_upload_dir();
		$base_url = $upload_dir['baseurl'];
		if ( strpos( $url, $base_url ) !== 0 ) {
			return null;
		}
		$rel = substr( $url, strlen( $base_url ) );
		$rel = ltrim( $rel, '/' );
		return $upload_dir['basedir'] . '/' . $rel;
	}

	/**
	 * Convert path to URL (uploads).
	 *
	 * @param string $path Path.
	 * @return string
	 */
	private static function path_to_url( $path ) {
		$upload_dir = wp_upload_dir();
		$base = trailingslashit( $upload_dir['basedir'] );
		$path = str_replace( '\\', '/', $path );
		if ( strpos( $path, $base ) !== 0 ) {
			return '';
		}
		$rel = substr( $path, strlen( $base ) );
		$rel = ltrim( $rel, '/' );
		return $upload_dir['baseurl'] . '/' . $rel;
	}
}
