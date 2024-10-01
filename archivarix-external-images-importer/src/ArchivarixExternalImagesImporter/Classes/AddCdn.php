<?php

namespace ArchivarixExternalImagesImporter\Classes;


class AddCdn
{
  private $replaceHost;

  public function __construct( $options )
  {
    $host = $options->getOption( 'base_url', false );

    if ( false !== $host ) {
      $this->replaceHost = untrailingslashit( $host );
      if ( home_url() !== $this->replaceHost ) {
        add_filter( 'wp_get_attachment_image_src', [$this, 'src'], 10, 3 );
        add_filter( 'wp_calculate_image_srcset', [$this, 'srcSet'], 10, 5 );
        add_filter( 'the_content', [$this, 'postHtml'] );
      }
    }
  }

  public function srcSet( $sources, $size_array, $image_src, $image_meta, $attachment_id )
  {

    foreach ( $sources as $k => $v ) {
      $host     = UrlHelper::getHost( $v['url'], true );
      $v['url'] = str_replace( $host, $this->replaceHost, $v['url'] );

      $sources[$k] = $v;
    }

    return $sources;
  }

  public function src( $image, $attachment_id, $size )
  {

    $host = UrlHelper::getHost( $image[0], true );

    $image[0] = str_replace( $host, $this->replaceHost, $image[0] );

    return $image;
  }

  public function postHtml( $html )
  {
    preg_match_all( '~<img.*>~im', $html, $images );

    if ( isset( $images[0] ) && !empty( $images[0] ) ) {

      foreach ( $images[0] as $image ) {

        $src    = ReplaceHelper::getAttribute( 'src', $image );
        $srcset = ReplaceHelper::getAttribute( 'set', $image );

        $host = UrlHelper::getHost( $src, true );

        $tmpImage = ReplaceHelper::replaceAttributeValue(
          'src',
          str_replace( $host, $this->replaceHost, $src ),
          $image
        );

        $tmpImage = ReplaceHelper::replaceAttributeValue(
          'srcset',
          str_replace( $host, $this->replaceHost, $srcset ),
          $tmpImage
        );

        $html = str_replace( $image, $tmpImage, $html );

      }
    }

    return $html;
  }

}
