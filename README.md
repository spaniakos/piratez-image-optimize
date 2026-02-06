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
3. Go to **Tools → Piratez Image Optimize** to check status and run regeneration

## Usage

After activation, the plugin:

1. Runs environment detection on activation
2. Processes new uploads automatically (generates WebP)
3. Serves WebP images to browsers that support them

To process existing media:

1. Navigate to **Tools → Piratez Image Optimize**
2. Review the status dashboard
3. Run the batch regeneration to generate missing sizes and WebP for the full library

## License

This project is licensed under the **GNU General Public License v3.0**. See the [LICENSE](LICENSE) file for details.

## Author

**Spaniakos** — [https://github.com/spaniakos](https://github.com/spaniakos)

## Links

- [Plugin URI](https://github.com/spaniakos/piratez-image-optimize)
- [GNU GPL v3.0](https://www.gnu.org/licenses/gpl-3.0.html)
