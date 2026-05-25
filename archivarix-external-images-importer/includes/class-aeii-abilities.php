<?php
/**
 * WordPress Abilities API integration.
 *
 * Registers the plugin's capabilities as abilities via the core Abilities API
 * (WordPress 7.0+, experimental in 6.9). Any ability flagged `meta.mcp.public`
 * is automatically discoverable and callable as an MCP tool by the WordPress
 * MCP Adapter, exposing the plugin to AI agents (Claude Desktop, Claude Code,
 * Cursor, VS Code, etc.).
 *
 * No third-party dependencies are bundled: registration is guarded by
 * function_exists(), so the plugin runs unchanged on WordPress < 7.0 where the
 * Abilities API is absent.
 *
 * @package Archivarix External Images Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers ability category and abilities for the importer.
 */
class AEII_Abilities {

	/**
	 * Ability namespace (matches the plugin slug).
	 */
	const NAMESPACE = 'archivarix';

	/**
	 * Ability category slug.
	 */
	const CATEGORY = 'archivarix-images';

	/**
	 * Hook into the Abilities API.
	 *
	 * Safe to call on any WordPress version: the hooks only fire when the
	 * Abilities API is loaded.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_abilities_api_categories_init', array( __CLASS__, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( __CLASS__, 'register_abilities' ) );
	}

	/**
	 * Register the ability category.
	 *
	 * @return void
	 */
	public static function register_category() {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( self::CATEGORY ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'External Images Importer', 'archivarix-external-images-importer' ),
				'description' => __( 'Scan posts for external or missing images and import them from the original source or the Web Archive.', 'archivarix-external-images-importer' ),
				'meta'        => array( 'icon' => 'dashicons-format-image' ),
			)
		);
	}

	/**
	 * Register all abilities.
	 *
	 * @return void
	 */
	public static function register_abilities() {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$plugin = Archivarix_External_Images_Importer::get_instance();

		// --- archivarix/scan-images -------------------------------------------------
		wp_register_ability(
			self::NAMESPACE . '/scan-images',
			array(
				'label'               => __( 'Scan for external images', 'archivarix-external-images-importer' ),
				'description'         => __( 'Scans all posts and pages for external and locally-missing (404) images, then stores the results so they can be imported. Run this before starting an import.', 'archivarix-external-images-importer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total_posts'          => array(
							'type'        => 'integer',
							'description' => __( 'Number of posts and pages scanned.', 'archivarix-external-images-importer' ),
						),
						'total_images'         => array(
							'type'        => 'integer',
							'description' => __( 'Total images found.', 'archivarix-external-images-importer' ),
						),
						'external_images'      => array(
							'type'        => 'integer',
							'description' => __( 'External images that can be imported.', 'archivarix-external-images-importer' ),
						),
						'local_missing_images' => array(
							'type'        => 'integer',
							'description' => __( 'Local images returning 404 that can be restored.', 'archivarix-external-images-importer' ),
						),
						'invalid_urls'         => array(
							'type'        => 'integer',
							'description' => __( 'Skipped invalid URLs (data URIs, malformed, etc.).', 'archivarix-external-images-importer' ),
						),
					),
				),
				'execute_callback'    => static function () use ( $plugin ) {
					return $plugin->scan_for_images();
				},
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		// --- archivarix/start-import ------------------------------------------------
		wp_register_ability(
			self::NAMESPACE . '/start-import',
			array(
				'label'               => __( 'Start image import', 'archivarix-external-images-importer' ),
				'description'         => __( 'Queues a background process that downloads the scanned external/missing images (from the original source or the Web Archive) and rewrites the image URLs in post content. Requires a prior scan.', 'archivarix-external-images-importer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'remaining' => array(
							'type'        => 'integer',
							'description' => __( 'Number of images queued for processing.', 'archivarix-external-images-importer' ),
						),
						'message'   => array(
							'type'        => 'string',
							'description' => __( 'Informational message (e.g. when all images were already processed).', 'archivarix-external-images-importer' ),
						),
					),
				),
				'execute_callback'    => static function () use ( $plugin ) {
					return $plugin->start_import();
				},
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'     => false,
						'destructive'  => true,
						'idempotent'   => false,
						'instructions' => __( 'Modifies post content by replacing external image URLs with locally hosted copies. Run archivarix/scan-images first.', 'archivarix-external-images-importer' ),
					),
				),
			)
		);

		// --- archivarix/stop-import -------------------------------------------------
		wp_register_ability(
			self::NAMESPACE . '/stop-import',
			array(
				'label'               => __( 'Stop image import', 'archivarix-external-images-importer' ),
				'description'         => __( 'Cancels the running background import process.', 'archivarix-external-images-importer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'stopped' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the process was stopped.', 'archivarix-external-images-importer' ),
						),
					),
				),
				'execute_callback'    => static function () use ( $plugin ) {
					return $plugin->stop_import();
				},
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'    => false,
						'destructive' => false,
						'idempotent'  => true,
					),
				),
			)
		);

		// --- archivarix/get-status --------------------------------------------------
		wp_register_ability(
			self::NAMESPACE . '/get-status',
			array(
				'label'               => __( 'Get import status', 'archivarix-external-images-importer' ),
				'description'         => __( 'Returns the current import progress: total images, processed count, whether the background process is running, statistics, and any Web Archive error.', 'archivarix-external-images-importer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'total'         => array( 'type' => 'integer' ),
						'position'      => array( 'type' => 'integer' ),
						'running'       => array( 'type' => 'boolean' ),
						'statistics'    => array( 'type' => 'object' ),
						'archive_error' => array( 'type' => array( 'object', 'boolean', 'null' ) ),
					),
				),
				'execute_callback'    => static function () use ( $plugin ) {
					return $plugin->get_queue_status_data();
				},
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		// --- archivarix/get-statistics ----------------------------------------------
		wp_register_ability(
			self::NAMESPACE . '/get-statistics',
			array(
				'label'               => __( 'Get import statistics', 'archivarix-external-images-importer' ),
				'description'         => __( 'Returns cumulative import statistics: successfully imported, served from cache, failed, removed, and placeholder counts.', 'archivarix-external-images-importer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'success'     => array( 'type' => 'integer' ),
						'cached'      => array( 'type' => 'integer' ),
						'failed'      => array( 'type' => 'integer' ),
						'removed'     => array( 'type' => 'integer' ),
						'placeholder' => array( 'type' => 'integer' ),
					),
				),
				'execute_callback'    => static function () use ( $plugin ) {
					return $plugin->get_statistics();
				},
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'   => true,
						'idempotent' => true,
					),
				),
			)
		);

		// --- archivarix/reset-statistics --------------------------------------------
		wp_register_ability(
			self::NAMESPACE . '/reset-statistics',
			array(
				'label'               => __( 'Reset statistics and cache', 'archivarix-external-images-importer' ),
				'description'         => __( 'Clears all statistics, scan results, log files, the processed-URL cache and stale locks. This cannot be undone.', 'archivarix-external-images-importer' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'reset' => array(
							'type'        => 'boolean',
							'description' => __( 'Whether the reset completed.', 'archivarix-external-images-importer' ),
						),
					),
				),
				'execute_callback'    => static function () use ( $plugin ) {
					return $plugin->reset_all();
				},
				'permission_callback' => array( __CLASS__, 'can_manage' ),
				'meta'                => array(
					'show_in_rest' => true,
					'mcp'          => array( 'public' => true ),
					'annotations'  => array(
						'readonly'     => false,
						'destructive'  => true,
						'idempotent'   => true,
						'instructions' => __( 'Permanently deletes logs, statistics, scan results and URL cache. Ask the user to confirm before calling.', 'archivarix-external-images-importer' ),
					),
				),
			)
		);
	}

	/**
	 * Permission callback shared by all abilities.
	 *
	 * Mirrors the capability check used by the plugin's AJAX handlers.
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'manage_options' );
	}
}
