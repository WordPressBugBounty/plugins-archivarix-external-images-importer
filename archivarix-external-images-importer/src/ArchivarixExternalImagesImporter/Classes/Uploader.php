<?php


namespace ArchivarixExternalImagesImporter\Classes;


use ArchivarixExternalImagesImporter;

class Uploader
{
  private $maxImageWidth;
  private $maxImageHeight;


  public function __construct( $mw = 0, $mh = 0 )
  {
    $this->maxImageWidth  = $mw;
    $this->maxImageHeight = $mh;

    if ( !function_exists( 'media_sideload_image' ) ) {
      require_once( ABSPATH . 'wp-admin/includes/file.php' );
    }

    require_once( ABSPATH . 'wp-admin/includes/image.php' );
    require_once( ABSPATH . 'wp-admin/includes/media.php' );
  }

  private function sideLoadImage( $file, $pid = 0, $desc = null )
  {
    if ( !empty( $file ) ) {

      preg_match( '/[^\?]+\.(jpe?g|jpe|gif|png)\b/i', $file, $matches );

      if ( !$matches ) {
        return new \WP_Error( 'image_sideload_failed', __( 'Invalid image URL' ) );
      }

      $fileArray             = array();
      $fileArray['name']     = wp_basename( $matches[0] );
      $fileArray['tmp_name'] = download_url( $file );
      if ( is_wp_error( $fileArray['tmp_name'] ) ) {
        return $fileArray['tmp_name'];
      }

      if ( empty( $pid ) ) {
        $id = media_handle_sideload( $fileArray, $pid, $desc );
      } else {
        $post = get_post( $pid );
        $id   = media_handle_sideload( $fileArray, $pid, $desc, [
          'post_date_gmt' => $post->post_date_gmt,
          'post_date'     => $post->post_date,
        ] );
      }

      if ( is_wp_error( $id ) ) {
        @unlink( $fileArray['tmp_name'] );

        return $id;
      }

      return $id;
    }

    return new \WP_Error( 'image_sideload_failed' );
  }

  public function sideLoadWrapper( $url, $postID = 0, $desc = null )
  {

    do_action( 'ArchivarixExternalImagesImporter__download-image-start', $url );

    $id = $this->findByUrl( $url );

    if ( false == $id ) {
      $id = $this->sideLoadImage( $url, $postID, $desc );
    } else {
      return $id;
    }

    if ( !is_wp_error( $id ) ) {
      update_post_meta( $id, 'ArchivarixExternalImagesImporter_source_url', $url );
      $handler = new ImageFileHandlers( $id );
      $sizes   = $handler->imageSize;

      if ( (int)$sizes['width'] > 0 ) {
        if ( (int)$sizes['width'] > (int)$this->maxImageWidth ) {
          $sizes['width'] = $this->maxImageWidth;
        }
      }

      if ( (int)$sizes['height'] > 0 ) {
        if ( (int)$sizes['height'] > (int)$this->maxImageHeight ) {
          $sizes['height'] = $this->maxImageHeight;
        }
      }

      $handler->resize( $sizes['width'], $sizes['height'] );

      $handler->regenerateAttachmentMeta( $id );

    } else {
      do_action( 'ArchivarixExternalImagesImporter__download-image-not-found', $url );
    }

    return $id;
  }


  public static function checkExistFileImage( $url )
  {
    $query  = wp_remote_head( $url );
    $status = wp_remote_retrieve_response_code( $query );

    if ( 200 == $status ) {
      return true;
    }

    return false;
  }

  private function findByUrl( $url )
  {
    global $wpdb;

    $url   = trim( $url );
    $query = "
		SELECT post_id FROM {$wpdb->postmeta} 
		WHERE meta_key = 'ArchivarixExternalImagesImporter_source_url' 
		AND meta_value = '%s'
		LIMIT 1;
		";
    $query = trim( $query );

    $out = $wpdb->get_var( $wpdb->prepare( $query, $url ) );

    if ( empty( $out ) ) {
      return false;
    }

    return $out;
  }

  public function errorHandler( $var )
  {
    if ( is_wp_error( $var ) ) {
      if ( 'http_404' == $var->get_error_code() ) {
        return true;
      }
    }

    return false;
  }

  public function loadInWebArchive( $url, $timestamp = false, $pid = 0 )
  {
    if ( empty( $timestamp ) ) {
      $timestamp = gmdate( 'YmdHis' );
    }

    $url = "http://web.archive.org/web/{$timestamp}id_/{$url}";

    return $this->sideLoadWrapper( $url, $pid );
  }

}
