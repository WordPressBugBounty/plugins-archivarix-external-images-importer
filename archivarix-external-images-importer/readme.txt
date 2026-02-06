=== Archivarix External Images Importer ===
Contributors: archivarix
Tags: images, import, wayback, archive, media
Requires at least: 6.0
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 2.0.1
License: GPLv3 or later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Import external images in posts and pages from external sources or Web Archive if original sources are not available anymore.

== Description ==

Archivarix External Images Importer scans your posts and pages for external URLs in src/srcset attributes of all img tags. Based on configured settings, the plugin can:

* Download images from their original external sources
* Download images from Internet Archive (Wayback Machine)
* Try to download from original source first, then from Internet Archive if failed
* Try to download from Internet Archive first, then from original source if failed
* Restore missing local images (404) from Web Archive

You can choose what to do with images that could not be downloaded:

* Keep image unchanged
* Remove image from post completely
* Replace with 1x1 pixel placeholder

= Key Features in Version 2.0 =

* Full compatibility with WordPress 6.9
* Modern AJAX interface with real-time progress
* Background processing - you can close the browser while processing continues
* Detailed import statistics and logs with CSV export
* Custom filename patterns support
* Automatic restoration of missing local images
* srcset attribute support
* Custom post types support
* Invalid URL detection and handling
* Improved error handling and logging
* Responsive design for all devices

Available in English, Russian (Русский), and Spanish (Español).

For more information, visit the [plugin documentation](https://archivarix.com/en/wordpress/).

== Installation ==

= Automatic Installation =

1. Log in to your WordPress admin panel
2. Go to Plugins → Add New
3. Search for "Archivarix External Images Importer"
4. Click Install and then Activate

= Manual Installation =

1. Upload the `archivarix-external-images-importer` folder to `/wp-content/plugins/`
2. Activate the plugin through the Plugins menu in WordPress
3. Go to Settings → Archivarix Images Importer to configure

== Frequently Asked Questions ==

= What WordPress versions are supported? =

The plugin requires WordPress 6.0 or higher. Tested up to version 6.9.

= What PHP version is required? =

PHP 7.4 or higher is required.

= Can I restore images from Internet Archive? =

Yes! The plugin can automatically search and download images from Wayback Machine if original sources are unavailable.

= What happens with invalid image URLs? =

Invalid URLs (like `p://...` instead of `http://...`) are detected during scan and handled according to your "If Download Fails" setting - they can be kept, removed, or replaced with a placeholder.

= Is it safe to use in production? =

Yes! Version 2.0 includes improved error handling, permission checks, and safe file processing.

= Can I close the browser during processing? =

Yes! Processing runs in the background on the server. You can close the browser and check results later in the Statistics tab.

== Screenshots ==

1. Settings page with full control over plugin behavior
2. Process interface with real-time progress bar
3. Statistics panel with detailed import information and logs

== Changelog ==

= 2.0.1 (2026-02-05) =
* Fixed various issues for WordPress.org Plugin Check compliance
* Code refactoring and minor bug fixes

= 2.0.0 (2025-01-21) =
* Complete rewrite with WP Background Processing for reliable server-side processing
* File-based logging system (wp-content/uploads/archivarix-logs/) instead of database storage
* Database-level URL locking to prevent duplicate downloads
* Log rotation: max 10000 entries and 100 days retention
* Enhanced security: proper escaping, nonce verification, capability checks
* Time-based processing (25s limit) to avoid server timeouts
* Added uninstall.php for complete cleanup on plugin deletion
* Full i18n support with Russian and Spanish translations
* Keep original filename option now enabled by default
* All public post types now selected by default
* Full srcset attribute support for both scanning and image replacement
* Download logs button for exporting logs as CSV
* Improved responsive design for statistics display

= 1.0.3 =
* WordPress 6.0 support

= 1.0.2 =
* WordPress 6.0 support

= 1.0.1 =
* Restore local missing images enabled by default
* Image date path matches post creation path
* Fixed alt attribute addition

= 1.0.0 =
* Initial release

== Upgrade Notice ==

= 2.0.0 =
Major update with new interface, background processing, and many improvements. Please backup your database before upgrading.
