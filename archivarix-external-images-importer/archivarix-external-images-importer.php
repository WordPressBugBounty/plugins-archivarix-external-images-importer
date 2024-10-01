<?php
/*
 * Plugin Name: Archivarix External Images Importer
 * Plugin URI: https://archivarix.com/en/wordpress/
 * Description: Import external images in posts and pages from its original external sources or Web Archive if original source is unavailable.
 * Author: Archivarix
 * Author URI: http://archivarix.com/en/
 * Text Domain: ArchivarixExternalImagesImporter
 * Domain Path: /languages
 * Requires at least: 5.0
 * Requires PHP: 5.6
 * Version: 1.0.3
 * License: GPLv3
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ArchivarixExternalImagesImporter
*/

if ( !defined( 'ABSPATH' ) ) {
  exit;
}

require_once( plugin_dir_path( __FILE__ ) . "includes/Autoloader.php" );

if ( file_exists( plugin_dir_path( __FILE__ ) . "vendor/autoload.php" ) ) {
  require_once( plugin_dir_path( __FILE__ ) . "vendor/autoload.php" );
}

use ArchivarixExternalImagesImporter\Admin\Data;
use ArchivarixExternalImagesImporter\Admin\Settings;
use ArchivarixExternalImagesImporter\Autoloader;

new Autoloader( __FILE__, 'ArchivarixExternalImagesImporter' );

use ArchivarixExternalImagesImporter\Base\Wrap;
use ArchivarixExternalImagesImporter\Classes\AddCdn;
use ArchivarixExternalImagesImporter\Classes\Batch;
use ArchivarixExternalImagesImporter\Classes\InsertPostHook;
use ArchivarixExternalImagesImporter\Classes\Renamer;
use ArchivarixExternalImagesImporter\Classes\Stats;

class ArchivarixExternalImagesImporter extends Wrap
{
  public        $version = '1.0.3';
  public static $textdomain;
  public        $filePath;
  public        $options;

  public function __construct()
  {
    $this->filePath   = __FILE__;
    self::$textdomain = $this->setTextdomain();

    new Settings( $this );
    $this->options = new Data( $this );
    new Renamer( $this->options );
    $stats = new Stats();

    if ( 'on' == $this->options->getOption( 'temporarily_disable_auto_download' ) ) {
      $insertClass = new InsertPostHook( $this->options );
      $insertClass->applySavePostFilter();
      $stats->statInit();
      new addCdn( $this->options );
      new Batch( $this->options );
    }

  }

}

function ArchivarixExternalImagesImporter__init()
{
  new ArchivarixExternalImagesImporter();
}

add_action( 'plugins_loaded', 'ArchivarixExternalImagesImporter__init', 30 );
