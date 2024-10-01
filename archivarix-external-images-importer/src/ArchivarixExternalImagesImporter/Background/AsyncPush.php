<?php

namespace ArchivarixExternalImagesImporter\Background;



use ArchivarixExternalImagesImporter\Lib\WpBackgroundProcess;

class AsyncPush extends WpBackgroundProcess {

	protected $cron_interval = 1;

	protected $action = 'web-archive-external-picture-async';

	/**
	 * @inheritDoc
	 */
	protected function task( $pid ) {
		do_action( 'ArchivarixExternalImagesImporter__async', $pid );

		return false;
	}
}
