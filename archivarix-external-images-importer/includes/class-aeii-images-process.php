<?php
/**
 * Images Background Process
 *
 * @package Archivarix External Images Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AEII_Images_Process class.
 */
class AEII_Images_Process extends AEII_Background_Process {

	/**
	 * Action
	 *
	 * @var string
	 */
	protected $action = 'images_process';

	/**
	 * Time limit in seconds
	 *
	 * @var int
	 */
	protected $time_limit = 25;

	/**
	 * Cron interval in minutes
	 *
	 * @var int
	 */
	protected $cron_interval = 1;

	/**
	 * Task
	 *
	 * @param mixed $item Queue item (image data array).
	 * @return mixed False to remove from queue, item to keep
	 */
	protected function task( $item ) {
		// Handle both array and serialized data.
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize, WordPress.PHP.NoSilencedErrors.Discouraged
		$data = is_string( $item ) ? @unserialize( $item ) : $item;

		if ( empty( $data ) || empty( $data['url'] ) || empty( $data['post_id'] ) ) {
			return false; // Invalid data, remove from queue.
		}

		// Get plugin instance.
		$plugin = Archivarix_External_Images_Importer::get_instance();

		// Process the image.
		$plugin->process_single_image( $data['url'], $data['post_id'], $data['is_local'] ?? false );

		// Return false to remove from queue (processed).
		return false;
	}

	/**
	 * Complete
	 */
	protected function complete() {
		parent::complete();

		// Update running flag.
		update_option( 'aeii_background_running', false );
	}
}
