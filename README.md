# Piratez Image Optimize

[![License: GPL v3](https://img.shields.io/badge/License-GPLv3-blue.svg)](https://www.gnu.org/licenses/gpl-3.0)

Portable, non-destructive server-side image optimization for WordPress. Discovers install image sizes, generates missing sizes and WebP, serves WebP for featured, attachment, and content images. Full coverage; no theme changes; reversible.

## Features

- **Discover** all image sizes required by the current WordPress install (theme + core + plugins)
- **Generate** missing image sizes for existing media (WordPress does resizing)
- **Generate WebP** versions only where missing (does not overwrite existing WebP)
- **Serve WebP** via `<picture>` element for:
  - Featured images
  - Attachment images
  - Images inside post content
- **Retroactive** — one bulk run processes the full media library
- **Graceful activation** — plugin always activates; processing is gated by an internal "ready" flag when requirements are not met

## Requirements

- WordPress 5.8+
- PHP 7.4+
- Writable uploads directory
- Imagick with WebP or GD with WebP

## Installation

1. Upload the plugin folder to `wp-content/plugins/`
2. Activate the plugin from the **Plugins** screen
3. Go to **PirateZ → Image Optimize** to check status and run regeneration

## Usage

After activation, the plugin:

1. Runs environment detection on activation
2. Processes new uploads automatically (generates WebP)
3. Serves WebP images to browsers that support them

To process existing media:

1. Navigate to **PirateZ → Image Optimize**
2. Review the status dashboard
3. Run the batch regeneration to generate missing sizes and WebP for the full library

## License

This project is licensed under the **GNU General Public License v3.0**. See the [LICENSE](LICENSE) file for details.

## Author

**Spaniakos** — [https://github.com/spaniakos](https://github.com/spaniakos)

## Links

- [Plugin URI](https://github.com/spaniakos/piratez-image-optimize)
- [GNU GPL v3.0](https://www.gnu.org/licenses/gpl-3.0.html)

## Alt text (1.2.0)

Open **PirateZ → Image Optimize → Alt text**. The module is independent of the image-generation readiness/processing switch and works even when no WebP engine is available.

* Audit all images, unmarked empty alt text, existing alt text and explicitly marked decorative images; search and sort the paginated Media Library view.
* Select images, edit their descriptions or choose filename/image-title/attached-post-title suggestions, then **Preview selected changes** and **Confirm changes**. Nothing is written by a preview. Work is bounded to 50 images per request (20 images per screen).
* Existing text and decorative markers are protected unless **Allow replacing existing text / decorative markers** is selected. Suggestions are text derived from filenames/titles, not image understanding; review them for accuracy. There is no AI service, upload autofill, keyword stuffing or page-view rewriting.
* Mark decorative only when an image conveys no information. This stores empty native attachment alt text and a PirateZ review marker. Native alt text from other plugins remains in `_wp_attachment_image_alt`; no metadata import is needed. Unmarked empty descriptions are not automatically treated as errors.
* Undo is per administrator, limited to 20 batches and approximately 1 MiB. It restores original values and missing metadata keys, skips later edits, and only reverses confirmed saves. Metadata write failures roll back completed fields where they remain unchanged. History is bounded recovery assistance, not a permanent backup.

### Saved article content

Changing attachment metadata does not change alt attributes already saved in blocks/HTML. **Usage / content review** searches candidate saved references, featured-image links and attachment parents. Dynamic templates, shortcodes, CDN-only URLs, reusable references and third-party renderers may need additional manual inspection; this is not a complete site crawler.

Choose individual image occurrences and preview their proposed descriptions separately. Core Image/Gallery blocks, Custom HTML and classic HTML are supported. Other blocks stay under the WordPress editor's control. The updater preserves the original serialized block comments and changes only selected IMG alt attributes. Empty contextual/decorative occurrences and other uses of the same image remain untouched unless selected.

Saved-content writes require WordPress 6.2+ and an administrator with `unfiltered_html` plus permission to edit the post. Post content is limited to 128 KiB per review; larger posts remain editable in WordPress. Stale previews and posts locked by another editor are refused. Changes use WordPress's post update/revision APIs; later edits are protected by optimistic checks during confirmation and undo. Third-party save filters can still alter content; unexpected results are reported for manual revision review.

### Replacing Image Alt Text 4.1.1

The free RS WebStudios plugin's native attachment descriptions are already visible here. No content or SEO metadata conversion is required. On staging, verify the audit, selected descriptions, relevant saved-image markup and frontend output, including a cache purge where the site serves cached pages. Then deactivate the old plugin and keep it installed initially for rollback. This release does not silently deactivate it. Pro AI/provider integrations and unrelated Pro features are outside this replacement's scope.

### Menu and updates

The bundled PirateZ parent works without Manager or a PirateZ theme, reuses Manager when present, and is shared with updated Cookie Consent. Old Tools bookmarks redirect. Image optimization settings, original files, processing code and the independent GitHub updater remain intact. Runtime packages exclude development files.

### Integration checks

Install Playwright 1.62.1 and @wp-playground/cli 3.1.58. Set `WP_SOURCE` to the supplied WordPress 7.1.3 source, `PIRATEZ_COOKIE_SOURCE` to Cookie Consent 1.2.0 source, `PIRATEZ_MANAGER_SOURCE` to Manager source and optionally `PIRATEZ_CHROMIUM_EXECUTABLE` / `PIRATEZ_PHP` (default 8.3; also test 7.4), then run `node tests/wordpress.cjs`. It uses a disposable local installation, synthetic media and no production credentials.
