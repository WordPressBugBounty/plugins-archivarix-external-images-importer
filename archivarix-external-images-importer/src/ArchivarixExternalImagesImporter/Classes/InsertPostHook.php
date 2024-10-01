<?php

namespace ArchivarixExternalImagesImporter\Classes;


use ArchivarixExternalImagesImporter\Background\AsyncPush;

class InsertPostHook
{

  private static $baseSize     = 'full';
  private        $post;
  private        $imageNotFoundAction;
  private        $allowedPosts = [];
  private        $excludeDomains;
  private        $maxImageWidth;
  private        $maxImageHeight;
  private        $imageSource;
  private        $pushStrategy;
  private        $asyncTask;
  private        $stop         = false;

  public function __construct( $options )
  {

    $this->imageNotFoundAction = $options->getOption( 'replace_image', 'keep' );
    $this->allowedPosts        = $options->getOption( 'posts_types', [] );
    $this->maxImageWidth       = $options->getOption( 'image_width', 0 );
    $this->maxImageHeight      = $options->getOption( 'image_height', 0 );
    $this->excludeDomains      = UrlHelper::getExcludeDomains( $options->getOption( 'exclude_domains', false ) );
    $this->imageSource         = $options->getOption( 'image_source', 'site' );
    $this->pushStrategy        = $options->getOption( 'push_strategy', 'on_push' );

    $this->asyncTask = new AsyncPush();

    add_action( 'ArchivarixExternalImagesImporter__bath-item', [$this, 'batchPayloadData'], 10, 1 );

    add_action( 'ArchivarixExternalImagesImporter__async', [$this, 'postHandler'], 10, 1 );

    add_action( 'wp_insert_post', function () {
      add_action( 'ArchivarixExternalImagesImporter__async', [$this, 'postHandler'], 10, 1 );
    } );

    add_action( 'import_start', function () {
      $this->stop = true;
    } );
  }

  public function applySavePostFilter()
  {
    add_filter( 'wp_insert_post_data', [$this, 'savePost'], 10, 2 );
  }

  public function batchPayloadData( $string )
  {

    $data = unserialize( $string );

    if ( is_numeric( $data['post'] ) ) {
      $data['post'] = get_post( $data['post'], ARRAY_A );
    }

    $this->batchPayload( $data );
  }

  public function batchPayload( $data )
  {
    global $wpdb;

    if ( isset( $data['post'] ) && !empty( $data['post'] ) ) {
      $this->post = (array)$data['post'];
      $pid        = $this->post['ID'];

      if ( isset( $this->post['post_type'] ) && in_array( $this->post['post_type'], $this->allowedPosts ) ) {

        if ( isset( $data['item']['src'] ) && !empty( $data['item']['src'] ) ) {

          if ( ReplaceHelper::checkReplace( $data['item']['src'] ) ) {
            $search = $data['item']['raw'];

            if ( false !== mb_stripos( $this->post['post_content'], $search ) ) {

              $replace = $this->ReplaceUpload(
                $data['item']['raw'],
                $data['item']['src'],
                $data['item']['srcset']
              );

              $query = "UPDATE $wpdb->posts SET post_content = REPLACE(post_content, '%s', '%s') WHERE ID = {$pid};";

              $query = $wpdb->prepare( $query, $search, $replace );

              $wpdb->query( $query );

              do_action( 'ArchivarixExternalImagesImporter__download-image-end', $data['item']['src'] );
            }
          }
        }
      }
    }
  }

  public function payload( $string, $imageData )
  {
    $tmp = [];

    foreach ( $imageData as $item ) {
      if ( isset( $item['src'] ) && !empty( $item['src'] ) ) {
        if ( ReplaceHelper::checkReplace( $item['src'] ) ) {
          $tmp['search'][]  = $item['raw'];
          $tmp['replace'][] = $this->ReplaceUpload( $item['raw'], $item['src'], $item['srcset'] );
        }
      }
    }

    if ( !empty( $tmp['search'] ) ) {
      $string = str_replace( $tmp['search'], $tmp['replace'], $string );
    }

    return $string;
  }

  public function postHandler( $id )
  {
    $post       = get_post( $id, ARRAY_A );
    $this->post = $post;

    if ( in_array( $post['post_type'], $this->allowedPosts ) ) {
      $content = $post['post_content'];

      $helper = new ExtractHelpers();
      $data   = $helper->getImagesData( $content );

      $content = $this->payload( $content, $data );

      $o = wp_update_post( ['ID' => $id, 'post_content' => $content] );


      return $o;
    }

    return false;
  }

  /**
   * Пытается найти записи медиафайлов в бд
   *
   * @param $search
   *
   * @return int
   */
  public function brokenImagesCollector( $search )
  {
    global $wpdb;

    $query = "
		SELECT post_id FROM {$wpdb->postmeta} 
		WHERE meta_value LIKE '%{$search}%' 
		AND meta_key = '_wp_attachment_metadata' 
		LIMIT 1";

    $query = trim( $query );

    $out = $wpdb->get_var( $query );

    if ( empty( $out ) ) {
      return false;
    }

    return (int)$out;
  }

  /**
   * @param $uploader Uploader
   * @param $searchUrl
   *
   * @return mixed
   */
  public function switchSource( $uploader, $searchUrl )
  {

    // Пытамся востановить картинку из вебархива если на сайте она больше не пингуется
    if ( UrlHelper::getHost( home_url() ) == UrlHelper::getHost( $searchUrl ) ) {
      if ( !Uploader::checkExistFileImage( $searchUrl ) ) {
        $deleteId = $this->brokenImagesCollector( $searchUrl );
        wp_delete_attachment( $deleteId, true );

        // загрузка из вебархива
        $timeStamp = strtotime( $this->post['post_date'] );
        $idImage   = $uploader->loadInWebArchive( $searchUrl, $timeStamp, $this->post['ID'] );

        return $idImage;
      } else {
        return true;
      }
    }

    if ( 'web_archive' == $this->imageSource ) {

      // загрузка из вебархива
      $timeStamp = strtotime( $this->post['post_date'] );

      $idImage = $uploader->loadInWebArchive( $searchUrl, $timeStamp, $this->post['ID'] );

      return $idImage;

    } elseif ( 'web_archive__site' == $this->imageSource ) {

      // загрузка из вебархива в случае неудачи с сайта
      $timeStamp = strtotime( $this->post['post_date'] );
      $idImage   = $uploader->loadInWebArchive( $searchUrl, $timeStamp, $this->post['ID'] );
      if ( $uploader->errorHandler( $idImage ) ) {
        $idImage = $uploader->sideLoadWrapper( $searchUrl, $this->post['ID'] );
      }

      return $idImage;

    } elseif ( 'web_site__archive' == $this->imageSource ) {

      // загрузка с сайта в случае неудачи из вебархива
      $idImage = $uploader->sideLoadWrapper( $searchUrl, $this->post['ID'] );

      if ( $uploader->errorHandler( $idImage ) ) {
        $timeStamp = strtotime( $this->post['post_date'] );
        $idImage   = $uploader->loadInWebArchive( $searchUrl, $timeStamp, $this->post['ID'] );
      }

      return $idImage;
    }

    // загрузка с сайта напрямую
    return $uploader->sideLoadWrapper( $searchUrl, $this->post['ID'] );
  }

  public function ReplaceUpload( $string, $searchUrl, $srcset = false )
  {

    if ( filter_var( $searchUrl, FILTER_VALIDATE_URL ) ) {

      if ( in_array( UrlHelper::getHost( $searchUrl ), $this->excludeDomains ) ) {
        return $string;
      }

      $uploader = new Uploader( $this->maxImageWidth, $this->maxImageHeight );

      $idImage = $this->switchSource( $uploader, $searchUrl );

      if ( true === $idImage ) {
        return $string;
      }

      if ( is_wp_error( $idImage ) ) {

        if ( 'keep' === $this->imageNotFoundAction ) {
          return $string;
        } else {
          do_action( 'ArchivarixExternalImagesImporter__image-string-delete', $string );
          return '';
        }
      }

      if ( is_numeric( $idImage ) ) {
        $src    = wp_get_attachment_image_src( $idImage, self::$baseSize );
        $string = ReplaceHelper::replaceAttributeValue( 'src', $src[0], $string );
        $meta   = wp_get_attachment_metadata( $idImage );
        $alt    = get_post_meta( $idImage, '_wp_attachment_image_alt', true );

        if ( empty( $alt ) ) {
          $string = ReplaceHelper::removeAttribute( 'alt', $string );
        } else {
          $string = ReplaceHelper::setAttribute( 'alt', $alt, $string );
        }

        if ( !empty( $srcset ) ) {
          $sizes  = wp_get_attachment_image_sizes( $idImage, 'full' );
          $srcset = wp_get_attachment_image_srcset( $idImage, 'full' );
          $string = ReplaceHelper::replaceAttributeValue( 'srcset', $srcset, $string );
          $string = ReplaceHelper::replaceAttributeValue( 'sizes', $sizes, $string );
          $string = ReplaceHelper::replaceAttributeValue( 'width', $meta['width'], $string );
          $string = ReplaceHelper::replaceAttributeValue( 'height', $meta['height'], $string );
        }
      }
    }

    return $string;
  }

  public function savePost( $data, $postArray )
  {

    if ( $this->stop ) {
      return $data;
    }

    if (
      wp_is_post_revision( $postArray['ID'] ) ||
      wp_is_post_autosave( $postArray['ID'] ) ||
      wp_doing_ajax() ||
      ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
      return $data;
    }

    if ( !in_array( $data['post_type'], $this->allowedPosts ) ) {
      return $data;
    }

    if ( 'on_push' == $this->pushStrategy ) {
      $this->post           = $postArray;
      $data['post_content'] = $this->save( wp_unslash( $data['post_content'] ) );
    } else {
      $this->asyncTask->push_to_queue( intval( $postArray['ID'] ) );
      $this->asyncTask->save();
      $this->asyncTask->dispatch();
    }

    return $data;
  }

  public function save( $content )
  {
    $helper = new ExtractHelpers();
    $data   = $helper->getImagesData( $content );

    return $this->payload( $content, $data );
  }

}
