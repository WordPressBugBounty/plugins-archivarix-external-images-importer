<?php

namespace ArchivarixExternalImagesImporter\Classes;

class UrlHelper {

	public static function getExcludeDomains( $string ) {

		$out = [];

		if ( false !== $string ) {
			$out = explode( PHP_EOL, (string) $string );
			$out = array_map( function ( $str ) {
				$str = trim( $str );

				$str = UrlHelper::getHost( $str );

				return $str;
			}, $out );
		}

		return $out;
	}


	/**
	 * Получает имя хоста из url строки и возвращет его,
	 * если воспользоваться опциональным аргументом то
	 * еще и протокол добавит к хосту.
	 *
	 * @param $url
	 * @param bool $scheme
	 *
	 * @return bool|string
	 */
	public static function getHost( $url, $scheme = false ) {
		$data = parse_url( $url );

		if ( ! isset( $data['host'] ) ) {
			return false;
		}

		if ( ! $scheme ) {
			return $data['host'];
		}

		return "{$data['scheme']}://{$data['host']}";
	}

	/**
	 * Получает basename от url исключая попадание в него get параметров
	 *
	 * @param $url
	 *
	 * @return string
	 */
	public function getImageName( $url ) {
		$path = parse_url( $url );

		return basename( $path['path'] );
	}


	public static function checkExternalImages( $images ) {
		foreach ( $images as $image ) {
			$image = ( is_array( $image ) && ! empty( $image ) ) ? $image[0] : false;

			if ( empty( $image ) ) {
				return false;
			}

			if ( self::checkExternalImage( $image ) ) {
				return true;
			}
		}

		return false;
	}

	private static function checkExternalImage( $image ) {

		$host = UrlHelper::getHost( home_url() );

		$src = str_replace(
			[ 'http://', 'https://' ],
			'',
			ReplaceHelper::getAttribute( 'src', $image )
		);

		if ( 0 !== strpos( $src, $host ) ) {
			return true;
		}

		return false;
	}

}
