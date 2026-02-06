<?php
/**
 * Content images substitution: filter the_content to serve WebP for img tags pointing to uploads.
 *
 * @package Piratez_IO
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Piratez_IO_Content_Substitution
 */
class Piratez_IO_Content_Substitution {

	/**
	 * Init: filter the_content on front-end only.
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'filter_the_content' ), 15 );
	}

	/**
	 * Filter the_content: wrap img tags that point to uploads in picture with WebP when available.
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public static function filter_the_content( $content ) {
		if ( ! is_singular() || is_admin() || is_feed() || empty( $content ) ) {
			return $content;
		}

		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return $content;
		}
		$base_url = $upload_dir['baseurl'];
		$base_dir = $upload_dir['basedir'];

		// Match img tags that have src in uploads.
		return preg_replace_callback(
			'/<img\s([^>]*)\s*\/?>/i',
			function ( $matches ) use ( $base_url, $base_dir ) {
				return self::replace_content_img( $matches[0], $matches[1], $base_url, $base_dir );
			},
			$content
		);
	}

	/**
	 * Replace single img tag with picture if WebP exists.
	 *
	 * @param string $img_full   Full img tag.
	 * @param string $img_attrs  Attributes string.
	 * @param string $base_url   Upload base URL.
	 * @param string $base_dir   Upload base path.
	 * @return string
	 */
	private static function replace_content_img( $img_full, $img_attrs, $base_url, $base_dir ) {
		if ( strpos( $img_attrs, 'src=' ) === false ) {
			return $img_full;
		}
		if ( ! preg_match( '/src\s*=\s*["\']([^"\']+)["\']/i', $img_attrs, $m ) ) {
			return $img_full;
		}
		$src = $m[1];
		if ( strpos( $src, $base_url ) !== 0 ) {
			return $img_full;
		}
		$rel = substr( $src, strlen( $base_url ) );
		$rel = ltrim( $rel, '/' );
		$path = $base_dir . '/' . str_replace( '\\', '/', $rel );
		if ( ! file_exists( $path ) ) {
			return $img_full;
		}
		$webp_path = Piratez_IO_Discovery::path_to_webp( $path );
		if ( ! Piratez_IO_Discovery::webp_worth_serving( $path, $webp_path ) ) {
			return $img_full;
		}
		$rel_webp = substr( $webp_path, strlen( $base_dir ) );
		$rel_webp = ltrim( str_replace( '\\', '/', $rel_webp ), '/' );
		$webp_url = $base_url . '/' . $rel_webp;

		$srcset = null;
		if ( preg_match( '/srcset\s*=\s*["\']([^"\']+)["\']/i', $img_attrs, $sm ) ) {
			$srcset = self::srcset_to_webp_content( $sm[1], $base_url, $base_dir );
		}
		$sizes_attr = null;
		if ( preg_match( '/sizes\s*=\s*["\']([^"\']+)["\']/i', $img_attrs, $sz ) ) {
			$sizes_attr = $sz[1];
		}

		$source = '<source type="image/webp" srcset="' . esc_attr( $srcset ? $srcset : $webp_url ) . '"';
		if ( $sizes_attr ) {
			$source .= ' sizes="' . esc_attr( $sizes_attr ) . '"';
		}
		$source .= '>';
		return '<picture>' . $source . ' ' . $img_full . '</picture>';
	}

	/**
	 * Convert srcset to WebP URLs where files exist.
	 *
	 * @param string $srcset   Original srcset.
	 * @param string $base_url Upload base URL.
	 * @param string $base_dir Upload base path.
	 * @return string
	 */
	private static function srcset_to_webp_content( $srcset, $base_url, $base_dir ) {
		$parts = array_map( 'trim', explode( ',', $srcset ) );
		$out   = array();
		foreach ( $parts as $part ) {
			$chunks = preg_split( '/\s+/', $part, 2 );
			$url    = $chunks[0];
			$descriptor = isset( $chunks[1] ) ? ' ' . $chunks[1] : '';
			if ( strpos( $url, $base_url ) !== 0 ) {
				$out[] = $part;
				continue;
			}
			$rel = substr( $url, strlen( $base_url ) );
			$rel = ltrim( $rel, '/' );
			$path = $base_dir . '/' . str_replace( '\\', '/', $rel );
			if ( ! file_exists( $path ) ) {
				$out[] = $part;
				continue;
			}
			$webp_path = Piratez_IO_Discovery::path_to_webp( $path );
			if ( Piratez_IO_Discovery::webp_worth_serving( $path, $webp_path ) ) {
				$rel_webp = substr( $webp_path, strlen( $base_dir ) );
				$rel_webp = ltrim( str_replace( '\\', '/', $rel_webp ), '/' );
				$out[] = $base_url . '/' . $rel_webp . $descriptor;
			} else {
				$out[] = $part;
			}
		}
		return implode( ', ', $out );
	}
}
