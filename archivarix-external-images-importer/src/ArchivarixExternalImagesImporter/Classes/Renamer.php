<?php


namespace ArchivarixExternalImagesImporter\Classes;


class Renamer {

	private $mageNamePattern;
	private $mageAltPattern;

	public function __construct( $options ) {
		$this->mageNamePattern = $options->getOption( 'image_name', '%filename%' );
		$this->mageAltPattern  = $options->getOption( 'image_alt', '%filename%' );

		add_action( 'add_attachment', [ $this, 'alt' ], 10 );
		add_filter( 'sanitize_file_name', [ $this, 'filename' ], 10 );
	}

	public function filename( $filename ) {
		$info      = pathinfo( $filename );
		$extension = $info['extension'];
		$slug      = $info['filename'];

		return str_replace(
			[
				'%filename%',
				'%date%',
				'%year%',
				'%month%',
				'%day%',
				'%timestamp%',
				'%random%',
			],
			[
				$slug,
				current_time( 'YmdHis' ),
				current_time( 'Y' ),
				current_time( 'm' ),
				current_time( 'd' ),
				current_time( 'timestamp' ),
				rand( 1000000, 9999999 ),
			],
			"{$this->mageNamePattern}.{$extension}"
		);
	}

	public function alt( $id ) {
		$post = get_post( $id );

		$slug = $post->post_name;

		$alt = str_replace(
			[
				'%filename%',
				'%date%',
				'%year%',
				'%month%',
				'%day%',
				'%timestamp%',
				'%random%',
			],
			[
				$slug,
				current_time( 'YmdHis' ),
				current_time( 'Y' ),
				current_time( 'm' ),
				current_time( 'd' ),
				current_time( 'timestamp' ),
				rand( 1000000, 9999999 ),
			],
			"{$this->mageAltPattern}"
		);

		update_post_meta( $id, '_wp_attachment_image_alt', $alt );

	}
}

