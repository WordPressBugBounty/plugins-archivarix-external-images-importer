<?php

namespace ArchivarixExternalImagesImporter\Classes;


class ImageFileHandlers {
	private $filePath;
	private $image;
	public $imageSize;

	public function __construct( $src ) {
		$this->filePath = $src;

		if ( is_numeric( $src ) ) {
			$this->filePath = get_attached_file( $src );
		}

		$this->image = wp_get_image_editor( $this->filePath );

		if ( ! is_wp_error( $this->image ) ) {
			$this->imageSize = $this->image->get_size();
		}
	}

	public function resize( $w, $h, $file = false ) {

		if ( ! is_wp_error( $this->image ) ) {

			$this->image->resize( intval( $w ), intval( $h ), false );

			if ( ! $file ) {
				$this->image->save( $this->filePath );
			} else {
				$this->image->save( $file );
			}

		}
	}

	public function regenerateAttachmentMeta( $id ) {
		$metadata = wp_generate_attachment_metadata( $id, get_attached_file( $id ) );
		wp_update_attachment_metadata( $id, $metadata );
	}
}
