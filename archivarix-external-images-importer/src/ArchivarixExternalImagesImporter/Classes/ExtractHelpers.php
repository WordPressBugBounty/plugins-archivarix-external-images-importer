<?php

namespace ArchivarixExternalImagesImporter\Classes;

class ExtractHelpers {

	public function getImagesData( $string ) {
		$data   = [];
		$images = $this->getImages( $string );

		$i = 0;
		foreach ( $images as $image ) {
			$data[ $i ] = [
				'alt'    => $this->getImageAlt( $image ),
				'src'    => $this->getImageSrc( $image ),
				'srcset' => $this->getImageSrcSet( $image ),
				'raw'    => $image,
			];
			$data[ $i ] = $this->prepare( $data[ $i ] );
			$i ++;
		}

		return $data;
	}

	private function prepare( $data ) {

		foreach ( $data as $key => $val ) {
			if ( 'srcset' === $key ) {
				$data[ $key ] = empty( $val ) ? false : $val;
			}

			if ( 'src' == $key ) {
				if ( ! empty( $val ) ) {
					$data[ $key ] = is_array( $val ) ? $val[0] : false;
				} else {
					$data[ $key ] = false;
				}
			}

			if ( 'alt' == $key ) {
				if ( ! empty( $val ) ) {
					$data[ $key ] = is_array( $val ) ? $val[0] : false;
				} else {
					$data[ $key ] = false;
				}
			}
		}

		return $data;
	}

	public function getImages( $string ) {
		preg_match_all( '/<img[^>]*>/i', $string, $matches, PREG_SET_ORDER );
		if ( empty( $matches ) ) {
			return [];
		}

		$array = array_map( function ( $val ) {
			return $val[0];
		}, $matches );

		return $array;
	}

	public function getImageSrc( $string ) {
		preg_match_all( '/<img[^>]*src=["\']([^"\']*)[^"\']*["\'][^>]*>/i', $string, $matches, PREG_SET_ORDER );

		if ( empty( $matches ) ) {
			return [];
		}

		$array = array_map( function ( $val ) {
			return $val[1];
		}, $matches );

		return $array;
	}

	public function getImageSrcSet( $string ) {
		$urls = [];

		preg_match_all( '/<img[^>]*srcset=["\']([^"\']*)[^"\']*["\'][^>]*>/i', $string, $matches, PREG_SET_ORDER );

		if ( empty( $matches ) ) {
			return [];
		}

		$i = 0;
		foreach ( $matches as $key => $srcset ) {
			preg_match_all( '/https?:\/\/[^\s,]+/i', $srcset[1], $srcsetUrls, PREG_SET_ORDER );
			if ( count( $srcsetUrls ) == 0 ) {
				continue;
			}
			foreach ( $srcsetUrls as $srcsetUrl ) {
				$urls[ $i ][] = $srcsetUrl[0];
				$i ++;
			}
		}

		return $urls;
	}

	public function getImageAlt( $string ) {
		preg_match_all( '/<img[^>]*alt=["\']([^"\']*)[^"\']*["\'][^>]*>/i', $string, $matches, PREG_SET_ORDER );

		if ( empty( $matches ) ) {
			return [];
		}

		return array_map( function ( $val ) {
			return $val[1];
		}, $matches );
	}

}
