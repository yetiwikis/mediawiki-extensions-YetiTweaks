<?php

namespace GloopTweaks\ResourceLoaderModules;

use MediaWiki\MediaWikiServices;
use ResourceLoaderSiteStylesModule;
use ResourceLoaderContext;

class ReadermodeStyleModule extends ResourceLoaderSiteStylesModule {
	// Readermode only makes sense for desktop.
	protected $targets = [ 'desktop' ];

	// Override getDB() to use metawiki rather than having a per-wiki MediaWiki:Vector-readermode.css.
	protected function getDB() {
		global $wglCentralDB;
		$lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();
		$lb = $lbFactory->getMainLB( $wglCentralDB );
		return $lb->getLazyConnectionRef( DB_REPLICA, [], $wglCentralDB );
	}

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
			$pages['MediaWiki:' . ucfirst( $skin ) . '-readermode.css'] = [ 'type' => 'style' ];
		}
		return $pages;
	}
}
