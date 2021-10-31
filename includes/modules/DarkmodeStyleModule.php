<?php

namespace GloopTweaks\ResourceLoaderModules;

use MediaWiki\MediaWikiServices;
use ResourceLoaderSiteStylesModule;
use ResourceLoaderContext;

class DarkmodeStyleModule extends ResourceLoaderSiteStylesModule {
	// Darkmode is only supported for desktop, at least for now.
	protected $targets = [ 'desktop' ];

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

	// 'site' should be used, but can't as this module needs to load after 'site.styles'.
	public function getGroup() {
		return 'user';
	}
}
