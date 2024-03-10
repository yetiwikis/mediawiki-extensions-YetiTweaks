<?php

namespace MediaWiki\Extension\YetiTweaks;

use CdnCacheUpdate;
use DeferredUpdates;
use ErrorPageError;
use Html;
use MediaWiki\Extension\YetiTweaks\ResourceLoader\ThemeStylesModule;
use MediaWiki\Extension\YetiTweaks\StopForumSpam\StopForumSpam;
use MediaWiki\MediaWikiServices;
use MediaWiki\ResourceLoader\ResourceLoader;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\Storage\EditResult;
use MediaWiki\User\UserIdentity;
use OutputPage;
use RequestContext;
use Skin;
use Title;
use WikiMap;
use WikiPage;

/**
 * Hooks for YetiTweaks extension
 *
 * @file
 * @ingroup Extensions
 */
class YetiTweaksHooks {
	/**
	 * When core requests certain messages, change the key to a Yeti Wikis version.
	 *
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/MessageCacheFetchOverrides
	 * @param string[] &$keys
	 */
	public static function onMessageCacheFetchOverrides( array &$keys ): void {
		global $wgYetiTweaksEnableMessageOverrides;
		if ( !$wgYetiTweaksEnableMessageOverrides ) return;

		static $keysToOverride = [
			'mobile-frontend-copyright'
		];

		foreach( $keysToOverride as $key ) {
			$keys[$key] = "yetiwikis-$key";
		}
	}

	/**
	 * Override with Yeti Wikis's site-specific copyright message.
	 *
	 * @param Title $title
	 * @param string $type
	 * @param string &$msg
	 * @param string &$link
	 */
	public static function onSkinCopyrightFooter( $title, $type, &$msg, &$link ) {
		global $wgYetiTweaksEnableMessageOverrides;

		if ($wgYetiTweaksEnableMessageOverrides) {
			if ( $type !== 'history' ) {
				$msg = 'yetiwikis-copyright';
			}
		}
	}

	/**
	 * Add some links at the bottom of pages
	 *
	 * @param Skin $skin
	 * @param string $key
	 * @param array &$footerLinks
	 */
	public static function onSkinAddFooterLinks( Skin $skin, string $key, array &$footerLinks ) {
		global $wgYetiTweaksAddFooterLinks;

		if ( $wgYetiTweaksAddFooterLinks && $key === 'places' ) {
			$footerLinks['tou'] = Html::rawElement( 'a', [
				'href' => Title::newFromText(
					$skin->msg( 'yetiwikis-tou-url' )->inContentLanguage()->text()
				)
			], $skin->msg( 'yetiwikis-tou' ) );
			$footerLinks['contact'] = Html::rawElement( 'a', [
				'href' => Title::newFromText(
					$skin->msg( 'yetiwikis-contact-url' )->inContentLanguage()->text()
				)
			], $skin->msg( 'yetiwikis-contact' ) );
		}
	}

	/**
	 * Implement theming and add structured data for the Google Sitelinks search box.
	 */
	public static function onBeforePageDisplay( OutputPage &$out, Skin &$skin ) {
		global $wgYetiTweaksAnalyticsID, $wgCloudflareDomain, $wgYetiTweaksCSP, $wgYetiTweaksCSPAnons, $wgSitename;
		global $wgYetiTweaksEnableTheming, $wgArticlePath, $wgCanonicalServer;

		// For letting user JS import from additional sources, like the Wikimedia projects, they have a longer CSP than anons.
		if ( $wgYetiTweaksCSP !== '' ) {
			$user = RequestContext::getMain()->getUser();
			$response = $out->getRequest()->response();

			if ( $wgYetiTweaksCSPAnons === '' || ( $user && !$user->isAnon() ) ) {
				$response->header( 'Content-Security-Policy: ' . $wgYetiTweaksCSP );
			} else {
				$response->header( 'Content-Security-Policy: ' . $wgYetiTweaksCSPAnons );
			}
		}

		if ( $wgYetiTweaksAnalyticsID ) {
			$out->addScript(
				Html::element( 'script',
					[
						'async' => true,
						'nonce' => $out->getCSP()->getNonce(),
						'src' => "https://www.googletagmanager.com/gtag/js?id=$wgYetiTweaksAnalyticsID",
					]
				)
			);
			$out->addInlineScript("window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','$wgYetiTweaksAnalyticsID')");
		}

		/*
		 * Server-side logic to implement theming and fixed width styling customisations.
		 * However, for most requests, this is instead done by our Cloudflare worker to avoid cache fragmentation.
		 * The actual styling is located on the wikis and toggling implemented through Gadgets.
		 */
		$cfWorker = $out->getRequest()->getHeader( 'CF-Worker' );
		$cfWorkerHandled = $out->getRequest()->getHeader( 'WGL-Worker' );
		$workerProcessed = $cfWorker !== false && $cfWorker === $wgCloudflareDomain && $cfWorkerHandled === '1';

		// Avoid duplicate processing if this will be performed instead by our Cloudflare worker.
		if ( !$workerProcessed ) {
			/* Theming */
			if ( $wgYetiTweaksEnableTheming ) {
				$legacyDarkmode = isset( $_COOKIE['darkmode'] ) && $_COOKIE['darkmode'] === 'true';
				$theme = $_COOKIE['theme'] ?? ( $legacyDarkmode ? 'dark' : 'light' );

				// Light mode is the base styling, so it doesn't load a separate theme stylesheet.
				if ( $theme === 'light' ) {
					// Legacy non-darkmode selector.
					$out->addBodyClasses( [ 'wgl-lightmode' ] );
				} else {
					if ( $theme === 'dark' ) {
						// Legacy darkmode selector.
						$out->addBodyClasses( [ 'wgl-darkmode' ] );
					}
					$out->addModuleStyles( [ "wgl.theme.$theme" ] );
				}
				$out->addBodyClasses( [ "wgl-theme-$theme" ] );
			}
		}

		$title = $out->getTitle();
		if ( $title->isMainPage() ) {
			/* Open Graph protocol */
			$out->addMeta( 'og:title', $wgSitename );
			$out->addMeta( 'og:type', 'website' );
		} else {
			/* Open Graph protocol */
			$out->addMeta( 'og:site_name', $wgSitename );
			$out->addMeta( 'og:title', $title->getPrefixedText() );
			$out->addMeta( 'og:type', 'article' );
		}
		/* Open Graph protocol */
		$out->addMeta( 'og:url', $title->getFullURL() );
	}

	// Cache OpenSearch for 600 seconds. (10 minutes)
	public static function onOpenSearchUrls( &$urls ) {
		foreach ( $urls as &$url ) {
			if ( in_array( $url['type'], [ 'application/x-suggestions+json', 'application/x-suggestions+xml' ] ) ) {
				$url['template'] = wfAppendQuery( $url['template'], [ 'maxage' => 600, 'smaxage' => 600, 'uselang' => 'content' ] );
			}
		}
	}

	/**
	 * Prevent infinite looping of main page requests with cache parameters.
	 */
	public static function onTestCanonicalRedirect( $request, $title, $output ) {
		if ( $title->isMainPage() && strpos( $request->getRequestURL(), '/?') === 0 ) {
			return false;
		}
	}

	/**
	 * Add purging for hashless thumbnails.
	 */
	public static function onLocalFilePurgeThumbnails( $file, $archiveName, $hashedUrls ) {
		$hashlessUrls = [];
		foreach ( $hashedUrls as $url ) {
			$hashlessUrls[] = strtok( $url, '?' );
		}

		// Purge the CDN
		DeferredUpdates::addUpdate( new CdnCacheUpdate( $hashlessUrls ), DeferredUpdates::PRESEND );
	}

	/**
	* Register resource modules for themes.
	*/
	public static function onResourceLoaderRegisterModules( ResourceLoader $resourceLoader ) {
		global $wgYetiTweaksThemes;
		foreach ( $wgYetiTweaksThemes as $theme ) {
			$resourceLoader->register( "wgl.theme.$theme", [
				'class' => ThemeStylesModule::class,
				'theme' => $theme,
			] );

			// Legacy dark mode
			if ( $theme === 'dark' ) {
				$resourceLoader->register( 'wg.darkmode', [
					'class' => ThemeStylesModule::class,
					'theme' => $theme,
				] );
			}
		}
	}
}
