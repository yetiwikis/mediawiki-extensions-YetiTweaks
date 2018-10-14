<?php

namespace WeirdGloopMessages\ResourceLoaderModules;

use ResourceLoaderWikiModule;
use ResourceLoaderContext;

class DarkmodeStyleModule extends ResourceLoaderWikiModule {
	protected $targets = [ 'desktop' ];

	/**
	 * Get a list of pages used by this module.
	 *
	 * @param ResourceLoaderContext $context
	 * @return array
	 */
	protected function getPages( ResourceLoaderContext $context ) {
		$pages = [];
		if ( $this->getConfig()->get( 'UseSiteCss' ) ) {
			$pages += [
				'MediaWiki:Vector-darkmode.css' => [ 'type' => 'style' ],
			];
		}
		return $pages;
	}

	/**
	 * @return string
	 */
	public function getType() {
		return self::LOAD_STYLES;
	}
}
