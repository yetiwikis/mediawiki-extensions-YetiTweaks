<?php

namespace GloopTweaks\ResourceLoaderModules;

use ResourceLoaderSiteStylesModule;
use ResourceLoaderContext;

class DarkmodeStyleModule extends ResourceLoaderSiteStylesModule {
	/**
	 * Get list of pages used by this module
	 *
	 * @param ResourceLoaderContext $context
	 * @return array[]
	 */
	protected function getPages( ResourceLoaderContext $context ) {
		$pages = [];
		if ( $this->getConfig()->get( 'UseSiteCss' ) ) {
			$skin = $context->getSkin();
			$pages['MediaWiki:' . ucfirst( $skin ) . '-darkmode.css'] = [ 'type' => 'style' ];
		}
		return $pages;
	}
}
