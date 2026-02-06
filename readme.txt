=== Piratez Image Optimize ===

Contributors: drvspan
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv3 or later

Portable, non-destructive server-side image optimization. Discovers install image sizes, generates missing sizes and WebP, serves WebP for featured, attachment, and content images. Full coverage; no theme changes; reversible.

== Description ==

* Discovers all image sizes required by the current WordPress install (theme + core + plugins)
* Generates missing image sizes for existing media (WordPress does resizing)
* Generates WebP versions only where missing (does not overwrite existing WebP)
* Serves WebP via picture element for featured images, attachment images, and images inside post content
* Works retroactively: one bulk run processes the full media library
* Plugin always activates; processing is gated by an internal "ready" flag when requirements are not met
* Requires: PHP 7.4+, writable uploads, Imagick with WebP or GD with WebP

== Installation ==

1. Upload the plugin folder to wp-content/plugins/
2. Activate the plugin from the Plugins screen
3. Go to Tools > Piratez Image Optimize to check status and run regeneration

== Changelog ==

= 1.0.0 =
* Initial release.
