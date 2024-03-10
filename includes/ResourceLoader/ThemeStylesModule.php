<?php

namespace MediaWiki\Extension\YetiTweaks\ResourceLoader;

use MediaWiki\MediaWikiServices;
use ResourceLoaderContext;
use MediaWiki\ResourceLoader\WikiModule;

class ThemeStylesModule extends WikiModule {
	/** @var string[] What client platforms the module targets (e.g. desktop, mobile) */
	protected $targets = [ 'desktop', 'mobile' ];

	/**
	 * @var string
	 */
	private $theme;

	/**
	 * @param array $options
	 */
	public function __construct( array $options ) {
		$this->theme = $options['theme'];
	}

	// 'site' should be used, but can't as this module needs to load after 'site.styles'.
	public function getGroup() {
		return 'user';
	}

	/**
	 * Get list of pages used by this module
	 *
	 * @param ResourceLoaderContext $context
	 * @return array[]
	 */
	protected function getPages( ResourceLoaderContext $context ) {
		$pages = [];
		$skin = ucfirst( $context->getSkin() );

		// i.e. MediaWiki:Vector-theme-dark.css
		$pages["MediaWiki:$skin-theme-{$this->theme}.css"] = [ 'type' => 'style' ];

		// Legacy dark mode stylesheet
		if ( $this->theme === 'dark' ) {
			$pages['MediaWiki:' . ucfirst( $skin ) . '-darkmode.css'] = [ 'type' => 'style' ];
		}
		return $pages;
	}
}