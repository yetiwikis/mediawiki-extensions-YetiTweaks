<?php
/**
 * Hooks for WeirdGloopMessages extension
 *
 * @file
 * @ingroup Extensions
 */
class WeirdGloopMessagesHooks {
	/**
	 * When core requests certain messages, change the key to a Weird Gloop version.
	 *
	 * @note Don't make this a closure, it causes the Database Dumps to fail.
	 *   See https://bugs.php.net/bug.php?id=52144
	 *
	 *   mwscript getSlaveServer.php --wiki='dewiki' --group=dump --globals
	 *   print_r( $GLOBALS['wgHooks']['MessageCache::get'] );
	 *
	 * @param String &$lcKey message key to check and possibly convert
	 *
	 * @return bool
	 */
	public static function onMessageCacheGet( &$lcKey ) {
		global $wgLanguageCode;

		static $keys = [
			'privacypage'
		];

		if ( in_array( $lcKey, $keys, true ) ) {
			$transformedKey = "weirdgloop-$lcKey";
		} else {
			return true;
		}

		// MessageCache uses ucfirst if ord( key ) is < 128, which is true of all
		// of the above.  Revisit if non-ASCII keys are used.
		$ucKey = ucfirst( $lcKey );

		$cache = MessageCache::singleton();
		if (
			/*
				* Override order:
				* 1. If the MediaWiki:$ucKey page exists, use the key unprefixed
				* (in all languages) with normal fallback order.  Specific
				* language pages (MediaWiki:$ucKey/xy) are not checked when
				* deciding which key to use, but are still used if applicable
				* after the key is decided.
				*
				* 2. Otherwise, use the prefixed key with normal fallback order
				* (including MediaWiki pages if they exist).
				*/
			$cache->getMsgFromNamespace( $ucKey, $wgLanguageCode ) === false
		) {
			$lcKey = $transformedKey;
		}
		return true;
	}

	/**
	 * Override with Weird Gloop's site-specific copyright message.
	 *
	 * @param Title $title
	 * @param string $type
	 * @param string &$msg
	 * @param string &$link
	 *
	 * @return bool
	 */
	public static function onSkinCopyrightFooter( $title, $type, &$msg, &$link ) {
		if ( $type !== 'history' ) {
			$msg = 'weirdgloop-copyright';
		}

		return true;
	}

	/**
	 * Add some links at the bottom of pages
	 *
	 * @param SkinTemplate &$skin
	 * @param QuickTemplate &$template
	 *
	 * @return bool
	 */
	public static function onSkinTemplateOutputPageBeforeExec( &$skin, &$template ) {
		$touDest = Skin::makeInternalOrExternalUrl(
			$skin->msg( 'weirdgloop-tou-url' )->inContentLanguage()->text() );
		$touLink = Html::element(
			'a',
			[ 'href' => $touDest ],
			$skin->msg( 'weirdgloop-tou' )->text()
		);
		$template->set( 'tou', $touLink );
		$template->data['footerlinks']['places'][] = 'tou';
		return true;
	}

	/**
	 * Set the message on TorBlock being triggered
	 *
	 * @param string &$msg The message to over-ride
	 *
	 * @return bool
	 */
	public static function onTorBlockBlockedMsg( &$msg ) {
		$msg = 'weirdgloop-torblock-blocked';
		return true;
	}

	/**
	 * Set the message on GlobalBlocking IP block being triggered
	 *
	 * @param string &$msg The message to over-ride
	 *
	 * @return bool
	 */
	public static function onGlobalBlockingBlockedIpMsg( &$msg ) {
		$msg = 'weirdgloop-globalblocking-ipblocked';
		return true;
	}

	/**
	 * Set the message on GlobalBlocking XFF block being triggered
	 *
	 * @param string &$msg The message to over-ride
	 *
	 * @return bool
	 */
	public static function onGlobalBlockingBlockedIpXffMsg( &$msg ) {
		$msg = 'weirdgloop-globalblocking-ipblocked-xff';
		return true;
	}

	/**
	 * Require the creation of MediaWiki:Licenses to enable uploading.
	 *
	 * Do not require it when licenses is in $wgForceUIMsgAsContentMsg,
	 * to prevent checking each subpage of MediaWiki:Licenses.
	 *
	 * @param BaseTemplate $tpl
	 * @return bool
	 * @throws ErrorPageError
	 */
	public static function onUploadFormInitial( $tpl ) {
		global $wgForceUIMsgAsContentMsg;
		if ( !in_array( 'licenses', $wgForceUIMsgAsContentMsg )
			&& wfMessage( 'licenses' )->inContentLanguage()->isDisabled()
		) {
			throw new ErrorPageError( 'uploaddisabled', 'weirdgloop-upload-nolicenses' );
		}
		return true;
	}

	/**
	 * Protect Weird Gloop system messages from being edited by those that do not have
	 * the "editinterfacesite" right. This is because system messages that are prefixed
	 * with "weirdgloop" are probably there for a legal reason or to ensure consistency
	 * across the site.
	 * 
	 * @return str
	 * @return bool
	 */
	public static function ongetUserPermissionsErrors( $title, $user, $action, &$result ) {
		global $wglProtectSiteInterface;

		if ( $wglProtectSiteInterface === true
			&& $title->inNamespace( NS_MEDIAWIKI )
			&& strpos( lcfirst( $title->getDBKey() ), 'weirdgloop-' ) === 0
			&& !$user->isAllowed( 'editinterfacesite' )
			&& $action !== 'read' ) {
				$result = 'weirdgloop-siteinterface';
				return false;
		}

		return true;
	}

}
