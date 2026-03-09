<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName
/**
 * Plugin Name: Archivarix External Images Importer
 * Plugin URI: https://archivarix.com/en/wordpress/
 * Description: Import external images in posts and pages from external sources or Web Archive if original source is unavailable.
 * Version: 2.0.3
 * Author: Archivarix
 * Author URI: https://archivarix.com
 * License: GPLv3 or later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Requires at least: 6.0
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Text Domain: archivarix-external-images-importer
 * Domain Path: /languages
 *
 * @package Archivarix External Images Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AEII_VERSION', '2.0.3' );
define( 'AEII_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AEII_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AEII_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );
define( 'AEII_MIN_ARCHIVE_DELAY', 1 ); // Minimum delay for Web Archive requests in seconds.

// Load background processing classes.
require_once AEII_PLUGIN_DIR . 'includes/class-aeii-async-request.php';
require_once AEII_PLUGIN_DIR . 'includes/class-aeii-background-process.php';
require_once AEII_PLUGIN_DIR . 'includes/class-aeii-images-process.php';

/**
 * Main plugin class for Archivarix External Images Importer.
 */
class Archivarix_External_Images_Importer {

	/**
	 * Singleton instance.
	 *
	 * @var Archivarix_External_Images_Importer|null
	 */
	private static $instance = null;

	/**
	 * Background process instance
	 *
	 * @var AEII_Images_Process
	 */
	private $background_process;

	/**
	 * Current URL locks held by this instance
	 *
	 * @var array
	 */
	private $current_locks = array();

	/**
	 * Get singleton instance.
	 *
	 * @return Archivarix_External_Images_Importer
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		// Initialize background process.
		$this->background_process = new AEII_Images_Process();

		// Load textdomain on init hook (recommended by WordPress).
		add_action( 'init', array( $this, 'load_textdomain' ) );

		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_admin_scripts' ) );

		add_action( 'wp_ajax_aeii_scan_posts', array( $this, 'ajax_scan_posts' ) );
		add_action( 'wp_ajax_aeii_process_image', array( $this, 'ajax_process_image' ) );
		add_action( 'wp_ajax_aeii_reset_statistics', array( $this, 'ajax_reset_statistics' ) );
		add_action( 'wp_ajax_aeii_get_logs', array( $this, 'ajax_get_logs' ) );
		add_action( 'wp_ajax_aeii_delete_logs', array( $this, 'ajax_delete_logs' ) );
		add_action( 'wp_ajax_aeii_download_logs', array( $this, 'ajax_download_logs' ) );
		add_action( 'wp_ajax_aeii_get_queue_status', array( $this, 'ajax_get_queue_status' ) );
		add_action( 'wp_ajax_aeii_start_background', array( $this, 'ajax_start_background' ) );
		add_action( 'wp_ajax_aeii_stop_background', array( $this, 'ajax_stop_background' ) );
	}

	/**
	 * Load plugin textdomain for translations.
	 */
	public function load_textdomain() {
		$domain = 'archivarix-external-images-importer';
		$locale = determine_locale();

		// Build path to MO file in plugin directory.
		$mofile = AEII_PLUGIN_DIR . 'languages/' . $domain . '-' . $locale . '.mo';

		// Load translation.
		if ( file_exists( $mofile ) ) {
			load_textdomain( $domain, $mofile );
		} else {
			// Try without country code (e.g., ru instead of ru_RU).
			$short_locale = substr( $locale, 0, 2 );
			$short_mofile = AEII_PLUGIN_DIR . 'languages/' . $domain . '-' . $short_locale . '.mo';
			if ( file_exists( $short_mofile ) ) {
				load_textdomain( $domain, $short_mofile );
			}
		}
	}

	/**
	 * Add admin menu page.
	 */
	public function add_admin_menu() {
		add_options_page(
			__( 'Archivarix Images Importer', 'archivarix-external-images-importer' ),
			__( 'Archivarix Images Importer', 'archivarix-external-images-importer' ),
			'manage_options',
			'archivarix-external-images',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings() {
		register_setting(
			'aeii_settings',
			'aeii_options',
			array(
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_options' ),
				'default'           => $this->get_default_options(),
			)
		);
	}

	/**
	 * Get default plugin options.
	 *
	 * @return array
	 */
	public function get_default_options() {
		// Get all public post types for default.
		$default_post_types = array_values(
			array_diff(
				get_post_types( array( 'public' => true ), 'names' ),
				array( 'attachment' ) // Exclude attachments.
			)
		);

		// Fallback if called too early.
		if ( empty( $default_post_types ) ) {
			$default_post_types = array( 'post', 'page' );
		}

		return array(
			'download_mode'      => 'original_then_archive',
			'restore_local'      => true,
			'missing_action'     => 'keep',
			'keep_original_name' => true, // Keep original filename enabled by default.
			'rename_pattern'     => '{title}-{index}',
			'download_delay'     => 0,
			'timeout'            => 20, // Default timeout in seconds.
			'user_agent'         => 'Mozilla/5.0 (compatible; ArchivarixBot/2.0)',
			'post_types'         => $default_post_types, // All public post types by default.
		);
	}

	/**
	 * Get plugin options merged with defaults.
	 *
	 * @return array
	 */
	public function get_options() {
		return wp_parse_args( get_option( 'aeii_options', array() ), $this->get_default_options() );
	}

	/**
	 * Sanitize plugin options.
	 *
	 * @param array $input Input options.
	 * @return array
	 */
	public function sanitize_options( $input ) {
		$defaults = $this->get_default_options();

		// Sanitize post_types.
		$post_types = array();
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			$allowed_types = get_post_types( array( 'public' => true ), 'names' );
			foreach ( $input['post_types'] as $type ) {
				$type = sanitize_key( $type );
				if ( in_array( $type, $allowed_types, true ) ) {
					$post_types[] = $type;
				}
			}
		}
		// If no types selected, use all public post types (except attachment).
		if ( empty( $post_types ) ) {
			$post_types = array_values(
				array_diff(
					get_post_types( array( 'public' => true ), 'names' ),
					array( 'attachment' )
				)
			);
			if ( empty( $post_types ) ) {
				$post_types = array( 'post', 'page' );
			}
		}

		return array(
			'download_mode'      => sanitize_text_field( $input['download_mode'] ?? $defaults['download_mode'] ),
			'restore_local'      => ! empty( $input['restore_local'] ),
			'missing_action'     => sanitize_text_field( $input['missing_action'] ?? $defaults['missing_action'] ),
			'keep_original_name' => ! empty( $input['keep_original_name'] ),
			'rename_pattern'     => sanitize_text_field( $input['rename_pattern'] ?? $defaults['rename_pattern'] ),
			'download_delay'     => max( 0, intval( $input['download_delay'] ?? 0 ) ),
			'timeout'            => max( 5, min( 300, intval( $input['timeout'] ?? 20 ) ) ),
			'user_agent'         => sanitize_text_field( $input['user_agent'] ?? $defaults['user_agent'] ),
			'post_types'         => $post_types,
		);
	}

	/**
	 * Enqueue admin scripts and styles.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_admin_scripts( $hook ) {
		if ( 'settings_page_archivarix-external-images' !== $hook ) {
			return;
		}

		$css = file_exists( AEII_PLUGIN_DIR . 'assets/css/admin.css' ) ? AEII_PLUGIN_URL . 'assets/css/admin.css' : AEII_PLUGIN_URL . 'admin.css';
		$js  = file_exists( AEII_PLUGIN_DIR . 'assets/js/admin.js' ) ? AEII_PLUGIN_URL . 'assets/js/admin.js' : AEII_PLUGIN_URL . 'admin.js';

		wp_enqueue_style( 'aeii-admin', $css, array(), AEII_VERSION );
		wp_enqueue_script( 'aeii-admin', $js, array( 'jquery' ), AEII_VERSION, true );

		wp_localize_script(
			'aeii-admin',
			'aeiiData',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'aeii_nonce' ),
				'strings'  => array(
					'scanning'            => __( 'Scanning...', 'archivarix-external-images-importer' ),
					'start_scan'          => __( 'Start Scan', 'archivarix-external-images-importer' ),
					'scan_complete'       => __( 'Scan Complete', 'archivarix-external-images-importer' ),
					'posts'               => __( 'Posts', 'archivarix-external-images-importer' ),
					'images_to_process'   => __( 'Images to process', 'archivarix-external-images-importer' ),
					'external'            => __( 'External', 'archivarix-external-images-importer' ),
					'local_404'           => __( 'Local 404', 'archivarix-external-images-importer' ),
					'invalid_urls'        => __( 'Invalid URLs', 'archivarix-external-images-importer' ),
					'error'               => __( 'Error', 'archivarix-external-images-importer' ),
					'error_loading_logs'  => __( 'Error loading logs', 'archivarix-external-images-importer' ),
					'confirm_process'     => __( 'Start processing?', 'archivarix-external-images-importer' ),
					'confirm_reset'       => __( 'Reset all statistics and logs?', 'archivarix-external-images-importer' ),
					'confirm_delete_logs' => __( 'Delete selected logs?', 'archivarix-external-images-importer' ),
					'confirm_delete_all'  => __( 'Delete ALL logs?', 'archivarix-external-images-importer' ),
					'no_logs'             => __( 'No logs yet', 'archivarix-external-images-importer' ),
					'loading'             => __( 'Loading...', 'archivarix-external-images-importer' ),
					'copied'              => __( 'Copied!', 'archivarix-external-images-importer' ),
					/* translators: %1$d: current page, %2$d: total pages */
					'page_of'             => __( 'Page %1$d of %2$d', 'archivarix-external-images-importer' ),
					'archive_error_500'   => __( 'Web Archive is currently unavailable. Downloads will continue from original sources only.', 'archivarix-external-images-importer' ),
					'archive_429_retry'   => __( 'Next request will be delayed by 10 seconds.', 'archivarix-external-images-importer' ),
					'archive_429_blocked' => __( 'Too many requests. Web Archive access has been disabled. Downloads will continue from original sources only.', 'archivarix-external-images-importer' ),
				),
			)
		);
	}

	/**
	 * Render admin settings page.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$options               = $this->get_options();
		$statistics            = $this->get_statistics();
		$is_background_running = get_option( 'aeii_background_running', false );

		$tpl = file_exists( AEII_PLUGIN_DIR . 'templates/admin-page.php' ) ? AEII_PLUGIN_DIR . 'templates/admin-page.php' : AEII_PLUGIN_DIR . 'admin-page.php';
		include $tpl;
	}

	/**
	 * AJAX handler for scanning posts.
	 */
	public function ajax_scan_posts() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$options       = $this->get_options();
		$restore_local = ! empty( $options['restore_local'] );
		$post_types    = ! empty( $options['post_types'] ) ? $options['post_types'] : array( 'post', 'page' );

		// Get only necessary fields to reduce memory usage.
		$posts = get_posts(
			array(
				'post_type'              => $post_types,
				'posts_per_page'         => -1,
				'post_status'            => 'any',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$all_images = array();
		$site_host  = wp_parse_url( get_site_url(), PHP_URL_HOST );

		foreach ( $posts as $post ) {
			$images = $this->extract_images( $post->post_content, $site_host, $restore_local );
			foreach ( $images as $img ) {
				$all_images[] = array(
					'post_id'    => $post->ID,
					'post_title' => $post->post_title,
					'post_date'  => $post->post_date,
					'url'        => $img['url'],
					'is_local'   => $img['is_local'],
					'is_missing' => $img['is_missing'] ?? false,
					'is_invalid' => $img['is_invalid'] ?? false,
				);
			}
		}

		// Save total posts count before freeing memory.
		$total_posts = count( $posts );

		// Free memory.
		unset( $posts );

		update_option( 'aeii_scan_results', $all_images, false ); // Don't autoload.
		update_option( 'aeii_queue_position', 0 );

		// Clear URL cache and URL locks on new scan.
		delete_option( 'aeii_url_cache' );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'aeii_lock_' ) . '%'
			)
		);

		$ext = count( array_filter( $all_images, fn( $i ) => ! $i['is_local'] && empty( $i['is_invalid'] ) ) );
		$loc = count( array_filter( $all_images, fn( $i ) => $i['is_local'] ) );
		$inv = count( array_filter( $all_images, fn( $i ) => ! empty( $i['is_invalid'] ) ) );

		// Don't include images array in response - it's already saved and can be huge.
		wp_send_json_success(
			array(
				'total_posts'          => $total_posts,
				'total_images'         => count( $all_images ),
				'external_images'      => $ext,
				'local_missing_images' => $loc,
				'invalid_urls'         => $inv,
			)
		);
	}

	/**
	 * Extract images from post content.
	 *
	 * @param string $content     Post content.
	 * @param string $site_host   Site host for local detection.
	 * @param bool   $check_local Whether to check local images.
	 * @return array
	 */
	private function extract_images( $content, $site_host, $check_local = true ) {
		$images = array();
		// Extract src attributes (use \ssrc= to avoid matching data-src, data-lazy-src, etc.).
		preg_match_all( '/<img[^>]*\ssrc=["\']([^"\']+)["\'][^>]*>/i', $content, $matches );

		foreach ( $matches[1] as $url ) {
			$this->add_image_to_list( $images, $url, $site_host, $check_local );
		}

		// Extract lazy-loading attributes (data-src, data-lazy-src, data-original).
		preg_match_all( '/<img[^>]*\sdata-(?:lazy-)?(?:src|original)=["\']([^"\']+)["\'][^>]*>/i', $content, $lazy_matches );

		foreach ( $lazy_matches[1] as $url ) {
			$this->add_image_to_list( $images, $url, $site_host, $check_local );
		}

		// Extract srcset attributes (use \ssrcset= to avoid matching data-srcset, etc.).
		preg_match_all( '/<img[^>]*\ssrcset=["\']([^"\']+)["\'][^>]*>/i', $content, $srcset_matches );

		foreach ( $srcset_matches[1] as $srcset ) {
			// Parse srcset: "image1.jpg 1x, image2.jpg 2x" or "image1.jpg 480w, image2.jpg 800w".
			$srcset_parts = preg_split( '/\s*,\s*/', $srcset );
			foreach ( $srcset_parts as $part ) {
				$part = trim( $part );
				// Extract URL (first part before space and size descriptor).
				if ( preg_match( '/^(\S+)/', $part, $url_match ) ) {
					$url = $url_match[1];
					$this->add_image_to_list( $images, $url, $site_host, $check_local );
				}
			}
		}

		return array_values( $images );
	}

	/**
	 * Helper method to add image URL to list with validation.
	 *
	 * @param array  $images      Reference to images array.
	 * @param string $url         Image URL.
	 * @param string $site_host   Site host.
	 * @param bool   $check_local Whether to check local images.
	 */
	private function add_image_to_list( &$images, $url, $site_host, $check_local ) {
		// Skip data URIs (with or without "data:" scheme prefix, with or without leading slash).
		if ( strpos( $url, 'data:' ) === 0 || preg_match( '/^\/?image\/[a-z]/i', $url ) ) {
			return;
		}
		if ( isset( $images[ $url ] ) ) {
			return; // Already added.
		}

		if ( ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
			$images[ $url ] = array(
				'url'        => $url,
				'is_local'   => false,
				'is_missing' => false,
				'is_invalid' => true,
			);
			return;
		}

		$is_local = ( wp_parse_url( $url, PHP_URL_HOST ) === $site_host );

		if ( $is_local ) {
			if ( $check_local && $this->is_local_missing( $url ) ) {
				$images[ $url ] = array(
					'url'        => $url,
					'is_local'   => true,
					'is_missing' => true,
					'is_invalid' => false,
				);
			}
		} else {
			$images[ $url ] = array(
				'url'        => $url,
				'is_local'   => false,
				'is_missing' => false,
				'is_invalid' => false,
			);
		}
	}

	/**
	 * Check if local image file is missing.
	 *
	 * @param string $url Image URL.
	 * @return bool
	 */
	private function is_local_missing( $url ) {
		// Normalize URL and base URLs to be protocol-agnostic (http vs https).
		$norm_url    = preg_replace( '/^https?:\/\//', '//', $url );
		$upload_dir  = wp_upload_dir();
		$norm_upload = preg_replace( '/^https?:\/\//', '//', $upload_dir['baseurl'] );
		if ( strpos( $norm_url, $norm_upload ) === 0 ) {
			$path = $upload_dir['basedir'] . str_replace( $norm_upload, '', $norm_url );
			return ! file_exists( $path );
		}
		$site_url      = get_site_url();
		$norm_site_url = preg_replace( '/^https?:\/\//', '//', $site_url );
		if ( strpos( $norm_url, $norm_site_url ) === 0 ) {
			$path = ABSPATH . ltrim( str_replace( $norm_site_url, '', $norm_url ), '/' );
			return ! file_exists( $path );
		}
		return false;
	}

	/**
	 * Get URL cache from options.
	 *
	 * @return array
	 */
	private function get_url_cache() {
		$cache = get_option( 'aeii_url_cache', array() );
		return is_array( $cache ) ? $cache : array();
	}

	/**
	 * Set URL cache entry.
	 *
	 * @param string $original_url Original image URL.
	 * @param string $new_url      New image URL or false.
	 * @param string $source       Download source.
	 * @param string $source_url   Source URL.
	 */
	private function set_url_cache( $original_url, $new_url, $source = '', $source_url = '' ) {
		$cache                  = $this->get_url_cache();
		$cache[ $original_url ] = array(
			'new_url'    => $new_url,
			'source'     => $source,
			'source_url' => $source_url,
			'cached_at'  => current_time( 'mysql' ),
		);
		update_option( 'aeii_url_cache', $cache, false );
	}

	/**
	 * Check URL cache for existing entry.
	 *
	 * @param string $original_url Original image URL.
	 * @return array|null
	 */
	private function check_url_cache( $original_url ) {
		$cache = $this->get_url_cache();
		return isset( $cache[ $original_url ] ) ? $cache[ $original_url ] : null;
	}

	/**
	 * Lock URL to prevent race condition during parallel processing
	 * Uses database row locking for reliability
	 *
	 * @param string $url URL to lock.
	 * @return bool True if lock acquired, false if already locked.
	 */
	private function lock_url( $url ) {
		global $wpdb;

		$lock_key   = 'aeii_lock_' . md5( $url );
		$lock_value = uniqid( '', true );

		// Try to insert lock row - will fail if already exists.
		$result = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$lock_key,
				$lock_value
			)
		);

		if ( 1 === $result ) {
			// Lock acquired - store our lock value for later verification.
			$this->current_locks[ $url ] = $lock_value;
			return true;
		}

		return false;
	}

	/**
	 * Unlock URL after processing
	 *
	 * @param string $url URL to unlock.
	 */
	private function unlock_url( $url ) {
		global $wpdb;

		$lock_key = 'aeii_lock_' . md5( $url );

		$wpdb->delete( $wpdb->options, array( 'option_name' => $lock_key ) );

		if ( isset( $this->current_locks[ $url ] ) ) {
			unset( $this->current_locks[ $url ] );
		}
	}

	/**
	 * Wait for URL lock to be released (for duplicate URLs in queue)
	 *
	 * @param string $url URL to wait for.
	 * @param int    $max_wait Maximum wait time in seconds.
	 * @return bool True if lock released, false if timeout.
	 */
	private function wait_for_url_unlock( $url, $max_wait = 30 ) {
		global $wpdb;

		$lock_key = 'aeii_lock_' . md5( $url );
		$start    = time();

		while ( true ) {
			$exists = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name = %s",
					$lock_key
				)
			);

			if ( ! $exists ) {
				return true;
			}

			if ( ( time() - $start ) > $max_wait ) {
				return false;
			}

			usleep( 500000 ); // Wait 0.5 seconds.
		}
	}

	/**
	 * Clean up stale URL locks (older than 2 minutes)
	 */
	private function cleanup_stale_locks() {
		global $wpdb;

		// Delete all locks (they should be temporary).
		// This is called at the start of processing to clean up any stale locks.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'aeii_lock_' ) . '%'
			)
		);
	}

	/**
	 * AJAX handler for processing a single image.
	 *
	 * @return void
	 */
	public function ajax_process_image() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$post_id       = intval( $_POST['post_id'] ?? 0 );
		$image_url_raw = isset( $_POST['image_url'] ) ? sanitize_text_field( wp_unslash( $_POST['image_url'] ) ) : '';
		$image_url     = trim( $image_url_raw );
		$is_local      = ! empty( $_POST['is_local'] );

		if ( ! $post_id ) {
			wp_send_json_error(
				array(
					'message'      => 'Invalid post ID',
					'action'       => 'error',
					'used_archive' => false,
				)
			);
			return;
		}

		if ( empty( $image_url ) || ! filter_var( $image_url, FILTER_VALIDATE_URL ) ) {
			$options = $this->get_options();
			$result  = $this->handle_failed_image( $post_id, $image_url, $options, 'Invalid URL format' );
			wp_send_json_error( $result );
			return;
		}

		$result = $this->process_image( $post_id, $image_url, $is_local );

		// Add archive error status to response.
		$result['archive_error'] = $this->get_archive_error_status();

		if ( $result['success'] ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Apply missing image action (remove, placeholder, or keep).
	 *
	 * @param \WP_Post|null $post      Post object.
	 * @param string        $image_url Image URL.
	 * @param array         $options   Plugin options.
	 * @return array Array with 'action' and 'message' keys.
	 */
	private function apply_missing_action( $post, $image_url, $options ) {
		$action  = 'kept';
		$message = __( 'Download failed', 'archivarix-external-images-importer' );

		if ( ! $post || ! $image_url ) {
			return compact( 'action', 'message' );
		}

		if ( 'remove' === $options['missing_action'] ) {
			$new_content = $this->remove_image_tag( $post->post_content, $image_url );
			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $new_content,
				)
			);
			$this->increment_stat( 'aeii_removed_count' );
			$action  = 'removed';
			$message = __( 'Image removed from post', 'archivarix-external-images-importer' );
		} elseif ( 'placeholder' === $options['missing_action'] ) {
			$placeholder = 'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';
			$new_content = str_replace( $image_url, $placeholder, $post->post_content );
			// Disable kses filters so WordPress doesn't strip data: from URL.
			kses_remove_filters();
			wp_update_post(
				array(
					'ID'           => $post->ID,
					'post_content' => $new_content,
				)
			);
			kses_init_filters();
			$this->increment_stat( 'aeii_placeholder_count' );
			$action  = 'placeholder';
			$message = __( 'Replaced with 1x1 placeholder', 'archivarix-external-images-importer' );
		}

		return compact( 'action', 'message' );
	}

	/**
	 * Handle a failed image download.
	 *
	 * @param int    $post_id      Post ID.
	 * @param string $image_url    Image URL.
	 * @param array  $options      Plugin options.
	 * @param string $reason       Failure reason.
	 * @param string $source_url   Source URL.
	 * @param bool   $used_archive Whether Web Archive was used.
	 * @return array Result array with success status.
	 */
	private function handle_failed_image( $post_id, $image_url, $options, $reason = '', $source_url = '', $used_archive = false ) {
		$post   = $post_id ? get_post( $post_id ) : null;
		$result = $this->apply_missing_action( $post, $image_url, $options );

		$action  = $result['action'];
		$message = $reason ? $reason : $result['message'];

		$this->increment_stat( 'aeii_failed_count' );

		if ( $image_url ) {
			$this->set_url_cache( $image_url, false, '', $source_url );
		}

		$this->add_log(
			array(
				'date'       => current_time( 'mysql' ),
				'post_id'    => $post_id ? $post_id : 0,
				'post_title' => $post ? $post->post_title : 'Unknown',
				'image_url'  => $image_url ? $image_url : 'Unknown URL',
				'success'    => false,
				'action'     => $action,
				'source'     => '',
				'source_url' => $source_url,
				'message'    => $message,
			)
		);

		return array(
			'success'      => false,
			'action'       => $action,
			'message'      => $message,
			'used_archive' => $used_archive,
		);
	}

	/**
	 * Process a single image URL.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $image_url Image URL.
	 * @param bool   $is_local  Whether the image is local (404).
	 * @return array Result array with success status.
	 */
	private function process_image( $post_id, $image_url, $is_local ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			$options = $this->get_options();
			return $this->handle_failed_image( $post_id, $image_url, $options, 'Post not found' );
		}

		$options = $this->get_options();

		// Проверяем кэш URL.
		$cached = $this->check_url_cache( $image_url );
		if ( null !== $cached ) {
			if ( false === $cached['new_url'] ) {
				return $this->handle_cached_failed( $post_id, $image_url, $options, $cached );
			} else {
				return $this->use_cached_image( $post_id, $image_url, $cached, $post );
			}
		}

		// Try to acquire lock for this URL to prevent race condition.
		$lock_acquired = $this->lock_url( $image_url );

		if ( ! $lock_acquired ) {
			// URL is being processed by another request, wait for it.
			$this->wait_for_url_unlock( $image_url, 30 );

			// Check cache again after waiting.
			$cached = $this->check_url_cache( $image_url );
			if ( null !== $cached ) {
				if ( false === $cached['new_url'] ) {
					return $this->handle_cached_failed( $post_id, $image_url, $options, $cached );
				} else {
					return $this->use_cached_image( $post_id, $image_url, $cached, $post );
				}
			}

			// If still no cache, try to lock again.
			$lock_acquired = $this->lock_url( $image_url );
			if ( ! $lock_acquired ) {
				// Still locked, skip this image for now.
				return array(
					'success'      => false,
					'action'       => 'skipped',
					'message'      => __( 'Image is being processed by another request', 'archivarix-external-images-importer' ),
					'used_archive' => false,
				);
			}
		}

		// We have the lock, proceed with download.
		// Use try-finally to ensure unlock even on errors.
		try {
			$result = $this->download_image( $image_url, $post_id, $is_local, $options );

			if ( $result['success'] ) {
				// Re-fetch post to get fresh content (in case it was modified).
				$post = get_post( $post_id );
				if ( $post ) {
					$new_content = str_replace( $image_url, $result['new_url'], $post->post_content );
					wp_update_post(
						array(
							'ID'           => $post_id,
							'post_content' => $new_content,
						)
					);
				}

				// Count existing files as cached, not downloaded.
				$action = $result['action'] ?? 'downloaded';
				if ( 'existing' === $action ) {
					$this->increment_stat( 'aeii_cached_count' );
				} else {
					$this->increment_stat( 'aeii_success_count' );
				}

				$this->set_url_cache( $image_url, $result['new_url'], $result['source'], $result['source_url'] );

				$this->add_log(
					array(
						'date'       => current_time( 'mysql' ),
						'post_id'    => $post_id,
						'post_title' => $post ? $post->post_title : 'Unknown',
						'image_url'  => $image_url,
						'new_url'    => $result['new_url'],
						'success'    => true,
						'action'     => $action,
						'source'     => $result['source'],
						'source_url' => $result['source_url'],
						/* translators: %s: download source name (e.g., "Original", "Web Archive") */
						'message'    => $result['message'] ?? sprintf( __( 'Downloaded from %s', 'archivarix-external-images-importer' ), $result['source'] ),
					)
				);

				return $result;
			} else {
				return $this->handle_failed_image( $post_id, $image_url, $options, $result['message'], $result['source_url'] ?? '', $result['used_archive'] ?? false );
			}
		} finally {
			// Always unlock URL, even if an exception occurred.
			$this->unlock_url( $image_url );
		}
	}

	/**
	 * Use a cached image URL to update post content.
	 *
	 * @param int      $post_id   Post ID.
	 * @param string   $image_url Original image URL.
	 * @param array    $cached    Cached image data.
	 * @param \WP_Post $post      Post object.
	 * @return array Result array with success status.
	 */
	private function use_cached_image( $post_id, $image_url, $cached, $post ) {
		$new_content = str_replace( $image_url, $cached['new_url'], $post->post_content );
		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new_content,
			)
		);
		$this->increment_stat( 'aeii_cached_count' );

		// Determine if Web Archive was used based on source.
		$used_archive = ( strpos( $cached['source'], 'Web Archive' ) !== false );

		$this->add_log(
			array(
				'date'       => current_time( 'mysql' ),
				'post_id'    => $post_id,
				'post_title' => $post->post_title,
				'image_url'  => $image_url,
				'new_url'    => $cached['new_url'],
				'success'    => true,
				'action'     => 'cached',
				'source'     => $cached['source'] . ' (cached)',
				'source_url' => $cached['source_url'],
				'message'    => __( 'Used previously downloaded image', 'archivarix-external-images-importer' ),
			)
		);

		return array(
			'success'      => true,
			'new_url'      => $cached['new_url'],
			'source'       => $cached['source'] . ' (cached)',
			'source_url'   => $cached['source_url'],
			'action'       => 'cached',
			'message'      => __( 'Used previously downloaded image', 'archivarix-external-images-importer' ),
			'used_archive' => $used_archive,
		);
	}

	/**
	 * Handle a previously failed image from cache.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $image_url Image URL.
	 * @param array  $options   Plugin options.
	 * @param array  $cached    Cached failure data.
	 * @return array Result array with success status.
	 */
	private function handle_cached_failed( $post_id, $image_url, $options, $cached ) {
		$post   = get_post( $post_id );
		$result = $this->apply_missing_action( $post, $image_url, $options );

		$action  = $result['action'];
		$message = __( 'Previously failed, skipped', 'archivarix-external-images-importer' );

		$this->increment_stat( 'aeii_failed_count' );

		$this->add_log(
			array(
				'date'       => current_time( 'mysql' ),
				'post_id'    => $post_id,
				'post_title' => $post ? $post->post_title : 'Unknown',
				'image_url'  => $image_url,
				'success'    => false,
				'action'     => $action . ' (cached)',
				'source'     => '',
				'source_url' => $cached['source_url'] ?? '',
				'message'    => $message,
			)
		);

		return array(
			'success'      => false,
			'action'       => $action,
			'message'      => $message,
			'used_archive' => false, // From cache - no archive delay needed.
		);
	}

	/**
	 * Try to download from original URL with placeholder validation.
	 *
	 * @param string $url     Image URL.
	 * @param array  $options Plugin options.
	 * @return array|false Downloaded file data or false.
	 */
	private function try_download_original( $url, $options ) {
		$downloaded = $this->try_download( $url, $options );
		if ( ! $downloaded || ! isset( $downloaded['file'] ) ) {
			return false;
		}
		if ( $this->is_placeholder_image( $downloaded['file'] ) ) {
			wp_delete_file( $downloaded['file'] );
			return false;
		}
		return $downloaded;
	}

	/**
	 * Process archive download result.
	 *
	 * @param array|false $archive_result    Result from try_download_from_archive.
	 * @param string      $archive_error_msg Reference to error message.
	 * @param bool        $need_retry_delay  Reference to retry delay flag.
	 * @return array|false Downloaded file data or false.
	 */
	private function process_archive_result( $archive_result, &$archive_error_msg, &$need_retry_delay ) {
		if ( ! $archive_result ) {
			return false;
		}
		if ( isset( $archive_result['archive_error'] ) ) {
			$archive_error_msg = $archive_result['message'];
			$need_retry_delay  = ! empty( $archive_result['retry'] );
			return false;
		}
		if ( isset( $archive_result['file'] ) ) {
			return array(
				'file'       => $archive_result['file'],
				'type'       => $archive_result['type'],
				'source_url' => $archive_result['source_url'],
			);
		}
		return false;
	}

	/**
	 * Download an image and add it to the media library.
	 *
	 * @param string $url      Image URL.
	 * @param int    $post_id  Post ID.
	 * @param bool   $is_local Whether the image is local (404).
	 * @param array  $options  Plugin options.
	 * @return array Result array with success status and new URL.
	 */
	private function download_image( $url, $post_id, $is_local, $options ) {
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$downloaded        = false;
		$source            = '';
		$source_url        = '';
		$used_archive      = false;
		$archive_error_msg = '';
		$need_retry_delay  = false;

		$post      = get_post( $post_id );
		$post_date = $post ? strtotime( $post->post_date ) : time();

		if ( $is_local ) {
			// Local 404 - try archive only.
			$archive_result = $this->try_download_from_archive( $url, $post_date, $options );
			$downloaded     = $this->process_archive_result( $archive_result, $archive_error_msg, $need_retry_delay );
			if ( $downloaded ) {
				$source       = 'Web Archive';
				$source_url   = $downloaded['source_url'];
				$used_archive = true;
			}
		} else {
			switch ( $options['download_mode'] ) {
				case 'original':
					$downloaded = $this->try_download_original( $url, $options );
					if ( $downloaded ) {
						$source     = 'Original';
						$source_url = $url;
					}
					break;

				case 'archive':
					$archive_result = $this->try_download_from_archive( $url, $post_date, $options );
					$downloaded     = $this->process_archive_result( $archive_result, $archive_error_msg, $need_retry_delay );
					if ( $downloaded ) {
						$source       = 'Web Archive';
						$source_url   = $downloaded['source_url'];
						$used_archive = true;
					}
					break;

				case 'original_then_archive':
					$downloaded = $this->try_download_original( $url, $options );
					if ( $downloaded ) {
						$source     = 'Original';
						$source_url = $url;
					} else {
						$archive_result = $this->try_download_from_archive( $url, $post_date, $options );
						$downloaded     = $this->process_archive_result( $archive_result, $archive_error_msg, $need_retry_delay );
						if ( $downloaded ) {
							$source       = 'Web Archive';
							$source_url   = $downloaded['source_url'];
							$used_archive = true;
						}
					}
					break;

				case 'archive_then_original':
					$archive_result = $this->try_download_from_archive( $url, $post_date, $options );
					$downloaded     = $this->process_archive_result( $archive_result, $archive_error_msg, $need_retry_delay );
					if ( $downloaded ) {
						$source       = 'Web Archive';
						$source_url   = $downloaded['source_url'];
						$used_archive = true;
					} else {
						// Archive failed or had error, try original.
						$downloaded = $this->try_download_original( $url, $options );
						if ( $downloaded ) {
							$source     = 'Original';
							$source_url = $url;
						}
					}
					break;
			}
		}

		if ( ! $downloaded ) {
			$message = __( 'Could not download from any source', 'archivarix-external-images-importer' );
			if ( $archive_error_msg ) {
				$message = $archive_error_msg;
			}
			return array(
				'success'          => false,
				'message'          => $message,
				'source_url'       => $source_url,
				'used_archive'     => $used_archive,
				'archive_error'    => ! empty( $archive_error_msg ),
				'need_retry_delay' => $need_retry_delay,
			);
		}

		$filename = $this->generate_filename( $url, $post_id, $options );
		// Get upload directory for post date (creates folder structure like 2012/05).
		$upload_dir = wp_upload_dir( gmdate( 'Y/m', $post_date ) );
		$filepath   = $upload_dir['path'] . '/' . $filename;

		// Check if this exact file already exists in media library.
		$existing = $this->find_existing_attachment( $filename, $upload_dir['path'] );
		if ( $existing ) {
			wp_delete_file( $downloaded['file'] );
			return array(
				'success'      => true,
				'new_url'      => $existing['url'],
				'source'       => $source . ' (existing)',
				'source_url'   => $source_url,
				'action'       => 'existing',
				'message'      => __( 'Used existing file from media library', 'archivarix-external-images-importer' ),
				'used_archive' => $used_archive,
			);
		}

		$i    = 1;
		$name = pathinfo( $filename, PATHINFO_FILENAME );
		$ext  = pathinfo( $filename, PATHINFO_EXTENSION );
		while ( file_exists( $filepath ) ) {
			$filename = $name . '-' . $i . '.' . $ext;
			$filepath = $upload_dir['path'] . '/' . $filename;
			++$i;
		}

		$filesystem = $this->init_filesystem();
		if ( ! $filesystem || ! $filesystem->copy( $downloaded['file'], $filepath ) ) {
			wp_delete_file( $downloaded['file'] );
			return array(
				'success'      => false,
				'message'      => __( 'Could not save file', 'archivarix-external-images-importer' ),
				'source_url'   => $source_url,
				'used_archive' => $used_archive,
			);
		}
		wp_delete_file( $downloaded['file'] );

		$attach_id = wp_insert_attachment(
			array(
				'post_mime_type' => $downloaded['type'],
				'post_title'     => sanitize_file_name( $name ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$filepath,
			$post_id
		);

		if ( is_wp_error( $attach_id ) ) {
			wp_delete_file( $filepath );
			return array(
				'success'      => false,
				'message'      => $attach_id->get_error_message(),
				'source_url'   => $source_url,
				'used_archive' => $used_archive,
			);
		}

		// Set attachment date to match the post date.
		if ( $post ) {
			wp_update_post(
				array(
					'ID'                => $attach_id,
					'post_date'         => $post->post_date,
					'post_date_gmt'     => $post->post_date_gmt,
					'post_modified'     => $post->post_date,
					'post_modified_gmt' => $post->post_date_gmt,
				)
			);
		}

		wp_update_attachment_metadata( $attach_id, wp_generate_attachment_metadata( $attach_id, $filepath ) );

		return array(
			'success'      => true,
			'new_url'      => wp_get_attachment_url( $attach_id ),
			'source'       => $source,
			'source_url'   => $source_url,
			'action'       => 'downloaded',
			/* translators: %s: download source name (e.g., "Original", "Web Archive") */
			'message'      => sprintf( __( 'Downloaded from %s', 'archivarix-external-images-importer' ), $source ),
			'used_archive' => $used_archive,
		);
	}

	/**
	 * Find existing attachment by filename in specified directory
	 *
	 * @param string $filename Filename to search for.
	 * @param string $upload_path Upload directory path.
	 * @return array|false Array with 'id' and 'url' if found, false otherwise.
	 */
	private function find_existing_attachment( $filename, $upload_path ) {
		$filepath = $upload_path . '/' . $filename;

		// First check if file exists on disk.
		if ( ! file_exists( $filepath ) ) {
			return false;
		}

		// Find attachment by file path.
		global $wpdb;

		// Get relative path from uploads directory.
		$upload_dir    = wp_upload_dir();
		$relative_path = str_replace( $upload_dir['basedir'] . '/', '', $filepath );

		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s",
				$relative_path
			)
		);

		if ( $attachment_id ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( $url ) {
				return array(
					'id'  => $attachment_id,
					'url' => $url,
				);
			}
		}

		// File exists but no attachment found - this shouldn't happen normally.
		// but let's handle it by returning false to proceed with normal flow.
		return false;
	}

	/**
	 * Build URL for downloading from Web Archive.
	 *
	 * @param string $url            Original image URL.
	 * @param int    $post_timestamp Post timestamp.
	 * @return string Web Archive URL.
	 */
	private function build_archive_url( $url, $post_timestamp ) {
		$archive_timestamp = gmdate( 'YmdHis', $post_timestamp );
		return 'https://web.archive.org/web/' . $archive_timestamp . 'id_/' . $url;
	}

	/**
	 * Build Web Archive calendar URL for displaying in logs.
	 *
	 * @param string $url            Original image URL.
	 * @param int    $post_timestamp Post timestamp.
	 * @return string Web Archive calendar URL.
	 */
	private function build_archive_calendar_url( $url, $post_timestamp ) {
		$archive_timestamp = gmdate( 'YmdHis', $post_timestamp );
		return 'https://web.archive.org/web/' . $archive_timestamp . '*/' . $url;
	}

	/**
	 * Handle Web Archive download error (500 or 429).
	 *
	 * @param int $error_code HTTP error code.
	 * @return array|null Error array or null if error should be ignored.
	 */
	private function handle_archive_error( $error_code ) {
		if ( 500 === $error_code ) {
			update_option( 'aeii_archive_error', '500' );
			update_option( 'aeii_archive_error_time', time() );
			return array(
				'archive_error' => 500,
				'message'       => __( 'Web Archive unavailable (500 error)', 'archivarix-external-images-importer' ),
			);
		}

		if ( 429 === $error_code ) {
			$retries = get_option( 'aeii_archive_429_retries', 0 );
			if ( $retries < 3 ) {
				update_option( 'aeii_archive_429_retries', $retries + 1 );
				update_option( 'aeii_archive_error', '429_retry' );
				update_option( 'aeii_archive_error_time', time() );
				return array(
					'archive_error' => 429,
					'retry'         => true,
					/* translators: %d: current retry attempt number (1-3). */
					'message'       => sprintf( __( 'Web Archive rate limit (429). Retry %d/3 after delay.', 'archivarix-external-images-importer' ), $retries + 1 ),
				);
			}
			update_option( 'aeii_archive_error', '429_blocked' );
			update_option( 'aeii_archive_error_time', time() );
			return array(
				'archive_error' => 429,
				'message'       => __( 'Web Archive blocked - too many requests', 'archivarix-external-images-importer' ),
			);
		}

		return null;
	}

	/**
	 * Try single archive download attempt.
	 *
	 * @param string $url       Original image URL.
	 * @param int    $timestamp Timestamp to use.
	 * @param array  $options   Plugin options.
	 * @return array Result with 'file', 'error', or 'placeholder' key.
	 */
	private function try_single_archive_download( $url, $timestamp, $options ) {
		$archive_url = $this->build_archive_url( $url, $timestamp );
		$downloaded  = $this->try_download( $archive_url, $options, true );

		if ( isset( $downloaded['error'] ) ) {
			return array( 'error' => $downloaded['error'] );
		}

		if ( $downloaded && isset( $downloaded['file'] ) ) {
			if ( $this->is_placeholder_image( $downloaded['file'] ) ) {
				wp_delete_file( $downloaded['file'] );
				return array( 'placeholder' => true );
			}
			return array(
				'file'       => $downloaded['file'],
				'type'       => $downloaded['type'],
				'source_url' => $this->build_archive_calendar_url( $url, $timestamp ),
			);
		}

		return array( 'failed' => true );
	}

	/**
	 * Try to download from Web Archive with error handling for 500 and 429 errors.
	 *
	 * @param string $url            Original image URL.
	 * @param int    $post_timestamp Post timestamp.
	 * @param array  $options        Plugin options.
	 * @return array|false Returns array with file data, or false, or array with 'archive_error' key.
	 */
	private function try_download_from_archive( $url, $post_timestamp, $options ) {
		// Check if Web Archive is disabled due to errors.
		$archive_error = get_option( 'aeii_archive_error', '' );
		if ( '500' === $archive_error ) {
			return array(
				'archive_error' => 500,
				'message'       => __( 'Web Archive unavailable (500 error)', 'archivarix-external-images-importer' ),
			);
		}
		if ( '429_blocked' === $archive_error ) {
			return array(
				'archive_error' => 429,
				'message'       => __( 'Web Archive blocked - too many requests', 'archivarix-external-images-importer' ),
			);
		}

		// Try timestamps: post date, then 2 years earlier.
		$timestamps = array( $post_timestamp, strtotime( '-2 years', $post_timestamp ) );

		foreach ( $timestamps as $timestamp ) {
			$result = $this->try_single_archive_download( $url, $timestamp, $options );

			if ( isset( $result['error'] ) ) {
				$error_response = $this->handle_archive_error( $result['error'] );
				if ( $error_response ) {
					return $error_response;
				}
				continue; // Other error, try next timestamp.
			}

			if ( isset( $result['file'] ) ) {
				$this->reset_archive_errors();
				return $result;
			}

			// Placeholder or failed - try next timestamp.
		}

		return false;
	}

	/**
	 * Reset Web Archive error counters
	 */
	private function reset_archive_errors() {
		delete_option( 'aeii_archive_error' );
		delete_option( 'aeii_archive_error_time' );
		delete_option( 'aeii_archive_429_retries' );
	}

	/**
	 * Get current Web Archive error status
	 */
	public function get_archive_error_status() {
		$error = get_option( 'aeii_archive_error', '' );
		$time  = get_option( 'aeii_archive_error_time', 0 );

		if ( empty( $error ) ) {
			return array( 'status' => 'ok' );
		}

		return array(
			'status'  => 'error',
			'type'    => $error,
			'time'    => $time,
			'message' => $this->get_archive_error_message( $error ),
		);
	}

	/**
	 * Get human-readable error message for archive error.
	 *
	 * @param string $error_type Error type code.
	 * @return string Error message.
	 */
	private function get_archive_error_message( $error_type ) {
		switch ( $error_type ) {
			case '500':
				return __( 'Web Archive is unavailable (500 Internal Server Error). Downloads will continue from original sources only.', 'archivarix-external-images-importer' );
			case '429_retry':
				$retries = get_option( 'aeii_archive_429_retries', 0 );
				/* translators: %d: current retry attempt number (1-3) */
				return sprintf( __( 'Web Archive rate limit hit (429). Retry %d/3. Next attempt will be delayed.', 'archivarix-external-images-importer' ), $retries );
			case '429_blocked':
				return __( 'Web Archive blocked due to too many requests (429). Downloads will continue from original sources only.', 'archivarix-external-images-importer' );
			default:
				return '';
		}
	}

	/**
	 * Try to download a file from URL.
	 *
	 * @param string $url               URL to download.
	 * @param array  $options           Plugin options.
	 * @param bool   $return_error_code Whether to return error code on failure.
	 * @return string|array|false File content, error array, or false.
	 */
	private function try_download( $url, $options, $return_error_code = false ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => $options['timeout'],
				'user-agent'  => $options['user_agent'],
				// phpcs:ignore WordPress.WP.AlternativeFunctions.wp_remote_get_sslverify -- Downloading images from arbitrary external URLs that may have invalid/expired certificates.
				'sslverify'   => false,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $return_error_code ? array(
				'error'   => 0,
				'message' => $response->get_error_message(),
			) : false;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return $return_error_code ? array( 'error' => $status_code ) : false;
		}

		$body = wp_remote_retrieve_body( $response );
		if ( empty( $body ) ) {
			return $return_error_code ? array(
				'error'   => 0,
				'message' => 'Empty response',
			) : false;
		}

		// Always validate content as image (don't trust Content-Type header).
		$type = $this->detect_image_mime_type( $body );
		if ( false === $type ) {
			return $return_error_code ? array(
				'error'   => 0,
				'message' => 'Not a valid image',
			) : false;
		}

		$tmp        = wp_tempnam();
		$filesystem = $this->init_filesystem();
		if ( ! $filesystem || ! $filesystem->put_contents( $tmp, $body ) ) {
			return $return_error_code ? array(
				'error'   => 0,
				'message' => 'Could not save temp file',
			) : false;
		}

		return array(
			'file' => $tmp,
			'type' => $type,
		);
	}

	/**
	 * Detect image MIME type from content (not headers)
	 * Uses finfo if available, falls back to magic bytes detection
	 *
	 * @param string $data Binary content.
	 * @return string|false MIME type or false if not an image.
	 */
	private function detect_image_mime_type( $data ) {
		if ( empty( $data ) ) {
			return false;
		}

		// Method 1: Use finfo if available (most reliable).
		if ( class_exists( 'finfo' ) ) {
			$finfo = new finfo( FILEINFO_MIME_TYPE );
			$type  = $finfo->buffer( $data );
			if ( $type && strpos( $type, 'image/' ) === 0 ) {
				return $type;
			}
			// finfo returned non-image, but let's also check magic bytes as fallback.
		}

		// Method 2: Check magic bytes (file signatures).
		$type = $this->detect_image_by_magic_bytes( $data );
		if ( false !== $type ) {
			return $type;
		}

		return false;
	}

	/**
	 * Detect image type by magic bytes (file signature)
	 *
	 * @param string $data Binary content.
	 * @return string|false MIME type or false if not recognized.
	 */
	private function detect_image_by_magic_bytes( $data ) {
		if ( strlen( $data ) < 12 ) {
			return false;
		}

		$bytes = substr( $data, 0, 12 );

		// JPEG: FF D8 FF.
		if ( substr( $bytes, 0, 3 ) === "\xFF\xD8\xFF" ) {
			return 'image/jpeg';
		}

		// PNG: 89 50 4E 47 0D 0A 1A 0A.
		if ( substr( $bytes, 0, 8 ) === "\x89PNG\r\n\x1A\n" ) {
			return 'image/png';
		}

		// GIF: GIF87a or GIF89a.
		if ( substr( $bytes, 0, 6 ) === 'GIF87a' || substr( $bytes, 0, 6 ) === 'GIF89a' ) {
			return 'image/gif';
		}

		// WebP: RIFF....WEBP.
		if ( substr( $bytes, 0, 4 ) === 'RIFF' && substr( $data, 8, 4 ) === 'WEBP' ) {
			return 'image/webp';
		}

		// BMP: BM.
		if ( substr( $bytes, 0, 2 ) === 'BM' ) {
			return 'image/bmp';
		}

		// ICO: 00 00 01 00.
		if ( substr( $bytes, 0, 4 ) === "\x00\x00\x01\x00" ) {
			return 'image/x-icon';
		}

		// TIFF: II (little-endian) or MM (big-endian).
		if ( substr( $bytes, 0, 2 ) === 'II' || substr( $bytes, 0, 2 ) === 'MM' ) {
			return 'image/tiff';
		}

		// AVIF: ....ftypavif or ....ftypavis.
		if ( substr( $data, 4, 4 ) === 'ftyp' ) {
			$brand = substr( $data, 8, 4 );
			if ( in_array( $brand, array( 'avif', 'avis', 'mif1', 'miaf' ), true ) ) {
				return 'image/avif';
			}
		}

		return false;
	}

	/**
	 * Check if image is a placeholder or invalid.
	 *
	 * Returns true if:
	 * - File is not a valid image (getimagesize fails)
	 * - Image is 1x1 pixel
	 * - Image is very small (up to 3x3) and file size < 500 bytes
	 *
	 * @param string $filepath File path.
	 * @return bool True if placeholder or invalid.
	 */
	private function is_placeholder_image( $filepath ) {
		if ( ! file_exists( $filepath ) ) {
			return true; // Missing file = treat as placeholder.
		}

		$size = wp_getimagesize( $filepath );

		// If getimagesize fails, it's not a valid image - treat as placeholder.
		if ( false === $size ) {
			return true;
		}

		// Exact 1x1 pixel.
		if ( 1 === $size[0] && 1 === $size[1] ) {
			return true;
		}

		// Very small (up to 3x3) and lightweight (< 500 bytes).
		if ( $size[0] <= 3 && $size[1] <= 3 && filesize( $filepath ) < 500 ) {
			return true;
		}

		return false;
	}

	/**
	 * Generate a filename for the downloaded image.
	 *
	 * @param string $url     Image URL.
	 * @param int    $post_id Post ID.
	 * @param array  $options Plugin options.
	 * @return string Generated filename.
	 */
	private function generate_filename( $url, $post_id, $options ) {
		$ext = strtolower( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico' ), true ) ) {
			$ext = 'jpg';
		}

		if ( $options['keep_original_name'] ) {
			return sanitize_file_name( pathinfo( wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_FILENAME ) ) . '.' . $ext;
		}

		$post    = get_post( $post_id );
		$idx     = get_transient( 'aeii_idx_' . $post_id );
		$idx     = $idx ? $idx : 0;
		set_transient( 'aeii_idx_' . $post_id, ++$idx, 3600 );

		$pattern = $options['rename_pattern'];

		// Use default pattern if empty.
		if ( empty( trim( $pattern ) ) ) {
			$pattern = '{title}-{index}';
		}

		// Ensure unique filenames: if no dynamic tags present, append {index}.
		$unique_tags = array( '{index}', '{random}' );
		$has_unique  = false;
		foreach ( $unique_tags as $tag ) {
			if ( strpos( $pattern, $tag ) !== false ) {
				$has_unique = true;
				break;
			}
		}
		if ( ! $has_unique ) {
			$pattern .= '-{index}';
		}

		$name = str_replace(
			array( '{title}', '{post_id}', '{index}', '{date}', '{random}' ),
			array( sanitize_title( $post->post_title ), $post_id, $idx, gmdate( 'Y-m-d' ), wp_generate_password( 6, false ) ),
			$pattern
		);

		return sanitize_file_name( $name ) . '.' . $ext;
	}

	/**
	 * Remove an image tag from content.
	 *
	 * @param string $content Post content.
	 * @param string $url     Image URL to remove.
	 * @return string Content with image removed.
	 */
	private function remove_image_tag( $content, $url ) {
		return preg_replace( '/<img[^>]*\ssrc=["\']' . preg_quote( $url, '/' ) . '["\'][^>]*>/i', '', $content );
	}

	/**
	 * Increment a statistics counter.
	 *
	 * @param string $key Option key for the counter.
	 * @return void
	 */
	private function increment_stat( $key ) {
		update_option( $key, get_option( $key, 0 ) + 1 );
	}

	/**
	 * Get processing statistics.
	 *
	 * @return array Statistics array.
	 */
	public function get_statistics() {
		return array(
			'success'     => get_option( 'aeii_success_count', 0 ),
			'cached'      => get_option( 'aeii_cached_count', 0 ),
			'failed'      => get_option( 'aeii_failed_count', 0 ),
			'removed'     => get_option( 'aeii_removed_count', 0 ),
			'placeholder' => get_option( 'aeii_placeholder_count', 0 ),
		);
	}

	/**
	 * AJAX handler to reset statistics.
	 *
	 * @return void
	 */
	public function ajax_reset_statistics() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		delete_option( 'aeii_success_count' );
		delete_option( 'aeii_cached_count' );
		delete_option( 'aeii_failed_count' );
		delete_option( 'aeii_removed_count' );
		delete_option( 'aeii_placeholder_count' );
		delete_option( 'aeii_scan_results' );
		delete_option( 'aeii_queue_position' );
		delete_option( 'aeii_url_cache' );

		// Delete all log files.
		$this->delete_all_logs();

		// Clear Web Archive error status.
		delete_option( 'aeii_archive_error' );
		delete_option( 'aeii_archive_error_time' );
		delete_option( 'aeii_archive_429_retries' );

		// Clear filename index transients and URL locks.
		global $wpdb;
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
				$wpdb->esc_like( '_transient_aeii_idx_' ) . '%',
				$wpdb->esc_like( '_transient_timeout_aeii_idx_' ) . '%',
				$wpdb->esc_like( 'aeii_lock_' ) . '%'
			)
		);

		wp_send_json_success();
	}

	/**
	 * Initialize WP_Filesystem
	 *
	 * @return WP_Filesystem_Base|false
	 */
	private function init_filesystem() {
		global $wp_filesystem;

		if ( $wp_filesystem ) {
			return $wp_filesystem;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		if ( WP_Filesystem() ) {
			return $wp_filesystem;
		}

		return false;
	}

	/**
	 * Get logs directory path
	 *
	 * @return string
	 */
	private function get_logs_dir() {
		$upload_dir = wp_upload_dir();
		return $upload_dir['basedir'] . '/archivarix-logs';
	}

	/**
	 * Ensure logs directory exists with protection files
	 *
	 * @return bool
	 */
	private function ensure_logs_dir() {
		$dir = $this->get_logs_dir();

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );

			$filesystem = $this->init_filesystem();
			if ( $filesystem ) {
				// Create .htaccess to deny direct access.
				$filesystem->put_contents( $dir . '/.htaccess', "Order deny,allow\nDeny from all" );

				// Create index.php for additional protection.
				$filesystem->put_contents( $dir . '/index.php', '<?php // Silence is golden' );
			}
		}

		return is_dir( $dir ) && wp_is_writable( $dir );
	}

	/**
	 * Get log file path for a specific date
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return string
	 */
	private function get_log_file( $date = null ) {
		if ( null === $date ) {
			$date = current_time( 'Y-m-d' );
		}
		return $this->get_logs_dir() . '/archivarix-log-' . $date . '.log';
	}

	/**
	 * Add log entry to file
	 *
	 * @param array $entry Log entry data.
	 */
	private function add_log( $entry ) {
		if ( ! $this->ensure_logs_dir() ) {
			return;
		}

		$entry['id']        = 'log_' . uniqid();
		$entry['timestamp'] = current_time( 'U' );

		$log_file = $this->get_log_file();
		$line     = wp_json_encode( $entry, JSON_UNESCAPED_UNICODE ) . "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- FILE_APPEND not supported by WP_Filesystem.
		file_put_contents( $log_file, $line, FILE_APPEND | LOCK_EX );

		// Run rotation check occasionally (1% chance per log write).
		if ( wp_rand( 1, 100 ) === 1 ) {
			$this->rotate_logs();
		}
	}

	/**
	 * Rotate logs - delete files older than 100 days or when total exceeds 10000 entries
	 */
	private function rotate_logs() {
		$dir = $this->get_logs_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$max_age_days = 100;
		$max_entries  = 10000;
		$cutoff_time  = time() - ( $max_age_days * DAY_IN_SECONDS );

		$files = glob( $dir . '/archivarix-log-*.log' );
		if ( empty( $files ) ) {
			return;
		}

		// Sort files by date (oldest first).
		usort(
			$files,
			function ( $a, $b ) {
				return strcmp( $a, $b );
			}
		);

		// First pass: delete files older than 100 days.
		foreach ( $files as $key => $file ) {
			if ( preg_match( '/archivarix-log-(\d{4}-\d{2}-\d{2})\.log$/', $file, $matches ) ) {
				$file_date = strtotime( $matches[1] );
				if ( $file_date && $file_date < $cutoff_time ) {
					wp_delete_file( $file );
					unset( $files[ $key ] );
				}
			}
		}

		$files = array_values( $files ); // Re-index.
		if ( empty( $files ) ) {
			return;
		}

		// Second pass: count total entries and delete oldest files if over limit.
		$total_entries = 0;
		$file_counts   = array();

		foreach ( $files as $file ) {
			$lines                = @file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			$count                = $lines ? count( $lines ) : 0;
			$file_counts[ $file ] = $count;
			$total_entries       += $count;
		}

		// Delete oldest files until we're under limit.
		$file_counts_num = count( $file_counts );
		while ( $total_entries > $max_entries && $file_counts_num > 1 ) {
			// Get the oldest file (first in sorted array).
			reset( $file_counts );
			$oldest_file    = key( $file_counts );
			$total_entries -= $file_counts[ $oldest_file ];
			wp_delete_file( $oldest_file );
			unset( $file_counts[ $oldest_file ] );
			--$file_counts_num;
		}

		// If still over limit with single file, truncate it.
		if ( $total_entries > $max_entries && count( $file_counts ) === 1 ) {
			reset( $file_counts );
			$file = key( $file_counts );
			$this->truncate_log_file( $file, $max_entries );
		}
	}

	/**
	 * Truncate a log file to keep only the newest N entries
	 *
	 * @param string $file File path.
	 * @param int    $max_entries Maximum entries to keep.
	 */
	private function truncate_log_file( $file, $max_entries ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file -- Reading log lines with flags not available in WP_Filesystem.
		$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		if ( ! $lines || count( $lines ) <= $max_entries ) {
			return;
		}

		// Keep only the newest entries (last N lines).
		$lines = array_slice( $lines, -$max_entries );
		$filesystem = $this->init_filesystem();
		if ( $filesystem ) {
			$filesystem->put_contents( $file, implode( "\n", $lines ) . "\n" );
		}
	}

	/**
	 * Read all logs from files
	 *
	 * @param string $sort Sort order: 'desc' (newest first) or 'asc' (oldest first).
	 * @return array
	 */
	private function read_all_logs( $sort = 'desc' ) {
		$dir = $this->get_logs_dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$all_logs = array();
		$files    = glob( $dir . '/archivarix-log-*.log' );

		// Sort files by date (newest first for desc, oldest first for asc).
		usort(
			$files,
			function ( $a, $b ) use ( $sort ) {
				$cmp = strcmp( $b, $a ); // Default: newest first.
				return 'asc' === $sort ? -$cmp : $cmp;
			}
		);

		foreach ( $files as $file ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( ! $lines ) {
				continue;
			}

			// For desc sort, reverse lines within each file (newest entries at end of file).
			if ( 'desc' === $sort ) {
				$lines = array_reverse( $lines );
			}

			foreach ( $lines as $line ) {
				$entry = json_decode( $line, true );
				if ( $entry ) {
					$all_logs[] = $entry;
				}
			}
		}

		return $all_logs;
	}

	/**
	 * Delete all log files
	 */
	private function delete_all_logs() {
		$dir = $this->get_logs_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$files = glob( $dir . '/archivarix-log-*.log' );
		foreach ( $files as $file ) {
			wp_delete_file( $file );
		}
	}

	/**
	 * Delete specific log entries by IDs
	 *
	 * @param array $ids Log entry IDs to delete.
	 */
	private function delete_logs_by_ids( $ids ) {
		$dir = $this->get_logs_dir();
		if ( ! is_dir( $dir ) ) {
			return;
		}

		$ids_map = array_flip( $ids );
		$files   = glob( $dir . '/archivarix-log-*.log' );

		foreach ( $files as $file ) {
			$lines = file( $file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( ! $lines ) {
				continue;
			}

			$modified  = false;
			$new_lines = array();

			foreach ( $lines as $line ) {
				$entry = json_decode( $line, true );
				if ( $entry && isset( $entry['id'] ) && isset( $ids_map[ $entry['id'] ] ) ) {
					$modified = true; // Skip this line (delete it).
				} else {
					$new_lines[] = $line;
				}
			}

			if ( $modified ) {
				if ( empty( $new_lines ) ) {
					wp_delete_file( $file );
				} else {
					$filesystem = $this->init_filesystem();
					if ( $filesystem ) {
						$filesystem->put_contents( $file, implode( "\n", $new_lines ) . "\n" );
					}
				}
			}
		}
	}

	/**
	 * AJAX handler to get logs.
	 *
	 * @return void
	 */
	public function ajax_get_logs() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$sort     = isset( $_POST['sort'] ) ? sanitize_text_field( wp_unslash( $_POST['sort'] ) ) : 'desc';
		$page     = isset( $_POST['page'] ) ? absint( $_POST['page'] ) : 1;
		$page     = max( 1, $page );
		$per_page = 30;

		$logs = $this->read_all_logs( $sort );

		$total  = count( $logs );
		$pages  = max( 1, ceil( $total / $per_page ) );
		$offset = ( $page - 1 ) * $per_page;

		wp_send_json_success(
			array(
				'logs'        => array_slice( $logs, $offset, $per_page ),
				'total'       => $total,
				'page'        => $page,
				'total_pages' => $pages,
			)
		);
	}

	/**
	 * AJAX handler to delete logs.
	 *
	 * @return void
	 */
	public function ajax_delete_logs() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		if ( ! empty( $_POST['delete_all'] ) ) {
			$this->delete_all_logs();
		} else {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via array_map below.
			$raw_ids = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : array();
			$ids     = array_map( 'sanitize_text_field', (array) $raw_ids );
			if ( $ids ) {
				$this->delete_logs_by_ids( $ids );
			}
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler to download logs as CSV.
	 *
	 * @return void
	 */
	public function ajax_download_logs() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Permission denied', 'archivarix-external-images-importer' ) );
		}

		$logs = $this->read_all_logs( 'asc' );

		if ( empty( $logs ) ) {
			wp_die( esc_html__( 'No logs to download', 'archivarix-external-images-importer' ) );
		}

		// Create CSV content with BOM for Excel UTF-8 support.
		$csv = "\xEF\xBB\xBFDate,Post ID,Post Title,Image URL,Success,Action,Source\n";

		foreach ( $logs as $log ) {
			$csv .= sprintf(
				'"%s","%s","%s","%s","%s","%s","%s"' . "\n",
				$this->escape_csv( $log['date'] ?? '' ),
				$this->escape_csv( $log['post_id'] ?? '' ),
				$this->escape_csv( $log['post_title'] ?? '' ),
				$this->escape_csv( $log['image_url'] ?? '' ),
				( $log['success'] ?? false ) ? 'Yes' : 'No',
				$this->escape_csv( $log['action'] ?? '' ),
				$this->escape_csv( $log['source'] ?? '' )
			);
		}

		// Send CSV file.
		$filename = sanitize_file_name( 'archivarix-logs-' . gmdate( 'Y-m-d-His' ) . '.csv' );

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $csv ) );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV output with proper Content-Type header
		echo $csv;
		wp_die();
	}

	/**
	 * Escape value for CSV with injection protection
	 *
	 * @param string $value Value to escape.
	 * @return string Escaped value.
	 */
	private function escape_csv( $value ) {
		$value = (string) $value;
		// Escape double quotes.
		$value = str_replace( '"', '""', $value );
		// Protect against CSV injection (formulas starting with =, +, -, @, tab, or carriage return).
		// Use tab prefix which Excel hides (unlike single quote which is visible).
		if ( preg_match( '/^[=+\-@\t\r]/', $value ) ) {
			$value = "\t" . $value;
		}
		return $value;
	}

	/**
	 * AJAX handler to start background processing.
	 *
	 * @return void
	 */
	public function ajax_start_background() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$scan = get_option( 'aeii_scan_results', array() );
		if ( empty( $scan ) ) {
			wp_send_json_error( array( 'message' => 'No images. Run scan first.' ) );
		}

		// Cancel any existing process.
		$this->background_process->cancel_process();

		// Clear any stale URL locks from previous runs.
		$this->cleanup_stale_locks();

		// Get current URL cache to skip already processed images.
		$url_cache = $this->get_url_cache();

		// Count images to process (not in cache).
		$images_to_process = array();
		foreach ( $scan as $img ) {
			// Skip invalid URLs (data URIs, malformed URLs, etc.).
			if ( ! empty( $img['is_invalid'] ) ) {
				continue;
			}
			// Skip if already in cache (successfully processed or failed).
			if ( isset( $url_cache[ $img['url'] ] ) ) {
				continue;
			}
			$images_to_process[] = array(
				'url'      => $img['url'],
				'post_id'  => $img['post_id'],
				'is_local' => $img['is_local'] ?? false,
			);
		}

		if ( empty( $images_to_process ) ) {
			// All images already processed.
			update_option( 'aeii_queue_position', count( $scan ) );
			update_option( 'aeii_background_running', false );
			wp_send_json_success( array( 'message' => 'All images already processed' ) );
		}

		// Set position to number of already processed images.
		$already_processed = count( $scan ) - count( $images_to_process );
		update_option( 'aeii_queue_position', $already_processed );
		update_option( 'aeii_background_running', true );

		// Save images in batches of 50 to avoid memory/timeout issues.
		$batch_size = 50;
		$batches    = array_chunk( $images_to_process, $batch_size );

		foreach ( $batches as $batch ) {
			foreach ( $batch as $img ) {
				$this->background_process->push_to_queue( $img );
			}
			$this->background_process->save();
		}

		// Dispatch to start processing.
		$this->background_process->dispatch();

		wp_send_json_success( array( 'remaining' => count( $images_to_process ) ) );
	}

	/**
	 * AJAX handler to stop background processing.
	 *
	 * @return void
	 */
	public function ajax_stop_background() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		// Cancel the background process.
		$this->background_process->cancel_process();
		update_option( 'aeii_background_running', false );

		wp_send_json_success();
	}

	/**
	 * AJAX handler to get queue status.
	 *
	 * @return void
	 */
	public function ajax_get_queue_status() {
		check_ajax_referer( 'aeii_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Permission denied' ) );
		}

		$scan  = get_option( 'aeii_scan_results', array() );
		$total = count( $scan );

		// Check if process completed.
		$completed = $this->background_process->is_complete();
		if ( $completed ) {
			update_option( 'aeii_background_running', false );
		}

		// Get position from saved counter (updated by process_single_image).
		$position = get_option( 'aeii_queue_position', 0 );

		// Ensure position is within valid range.
		if ( $position < 0 ) {
			$position = 0;
		}
		if ( $position > $total ) {
			$position = $total;
		}

		// Check if process is actually running.
		$running = get_option( 'aeii_background_running', false );
		if ( $running && $this->background_process->is_queue_empty() && ! $this->background_process->is_process_running() ) {
			// Queue is empty and process not running - mark as complete.
			update_option( 'aeii_background_running', false );
			$running = false;
			// Set position to total when complete.
			$position = $total;
			update_option( 'aeii_queue_position', $position );
		}

		wp_send_json_success(
			array(
				'total'         => $total,
				'position'      => $position,
				'running'       => $running,
				'statistics'    => $this->get_statistics(),
				'archive_error' => $this->get_archive_error_status(),
			)
		);
	}

	/**
	 * Process a single image (called from background process task)
	 *
	 * @param string $url      Image URL.
	 * @param int    $post_id  Post ID.
	 * @param bool   $is_local Whether the image is local (404).
	 * @return array Result.
	 */
	public function process_single_image( $url, $post_id, $is_local = false ) {
		// Process the image.
		$result = $this->process_image( $post_id, $url, $is_local );

		// Update position counter.
		$pos = get_option( 'aeii_queue_position', 0 );
		update_option( 'aeii_queue_position', $pos + 1 );

		// Apply delay if needed.
		$options = $this->get_options();
		$delay   = intval( $options['download_delay'] );

		if ( isset( $result['used_archive'] ) && $result['used_archive'] ) {
			$delay = max( $delay, AEII_MIN_ARCHIVE_DELAY );
		}

		if ( $delay > 0 ) {
			sleep( $delay );
		}

		return $result;
	}
}

add_action(
	'plugins_loaded',
	function () {
		Archivarix_External_Images_Importer::get_instance();
	}
);

register_activation_hook(
	__FILE__,
	function () {
		if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
			deactivate_plugins( plugin_basename( __FILE__ ) );
			wp_die( esc_html__( 'This plugin requires PHP 7.4 or higher.', 'archivarix-external-images-importer' ) );
		}
	}
);

register_deactivation_hook(
	__FILE__,
	function () {
		global $wpdb;

		// Delete scan results and status.
		delete_option( 'aeii_scan_results' );
		delete_option( 'aeii_queue_position' );
		delete_option( 'aeii_background_running' );
		delete_option( 'aeii_url_cache' );
		delete_option( 'aeii_archive_error' );
		delete_option( 'aeii_archive_error_time' );
		delete_option( 'aeii_archive_429_retries' );

		// Clear old cron hook.
		wp_clear_scheduled_hook( 'aeii_background_process' );

		// Clear new background process hooks and data.
		wp_clear_scheduled_hook( 'aeii_images_process_cron' );

		// Delete process lock.
		delete_site_transient( 'aeii_images_process_process_lock' );
		delete_site_transient( 'aeii_images_process_completed' );

		// Delete all batch data.
		$table  = $wpdb->options;
		$column = 'option_name';
		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table/column names cannot use placeholders.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE {$column} LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->esc_like( 'aeii_images_process_batch_' ) . '%'
			)
		);
	}
);
