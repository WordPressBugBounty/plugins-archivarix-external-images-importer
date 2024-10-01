<?php


namespace ArchivarixExternalImagesImporter\Classes;


class ReplaceHelper
{

  public static function checkReplace( $string )
  {

    if ( false == apply_filters( 'ArchivarixExternalImagesImporter__skip-local-images', false ) ) {
      return true;
    }

    $siteHost = UrlHelper::getHost( site_url() );
    if ( false === stripos( $string, $siteHost ) ) {
      return true;
    }

    return false;
  }

  public static function searchReplaceUrls( $search, $replace, $string )
  {
    $siteHost = UrlHelper::getHost( site_url() );
    if ( false === stripos( $search, $siteHost ) ) {
      $string = str_replace( $search, $replace, $string );
    }

    return $string;
  }

  public static function replaceAttributeValue( $atr, $replace, $string )
  {
    return preg_replace( "/{$atr}=[\"'].*[\"']/iU", "{$atr}='{$replace}'", $string );
  }

  public static function removeAttribute( $atr, $string )
  {
    return preg_replace( "/{$atr}=[\"'].*[\"']/iU", "", $string );
  }

  /**
   * Get html attribute by name
   *
   * @param $string
   * @param $atr
   *
   * @return mixed
   */
  public static function getAttribute( $atr, $string )
  {
    preg_match( "~{$atr}=[\"|'](.*)[\"|']\s~imU", $string, $m );
    if ( isset( $m[1] ) ) {
      return $m[1];
    }

    return '';
  }

  public static function setAttribute( $atr, $value, $string )
  {
    return preg_replace( "/<(\w+\s)/iU", "$0{$atr}='{$value}' ", $string );
  }

}
