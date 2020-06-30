<?php

use MediaWiki\MediaWikiServices;

class GloopTweaksUtils {
	/**
	 * Adds an extra link to the footer, should only be called when
   * hooking onto SkinTemplateOutputPageBeforeExec
   * 
   * @param String $name - The name of the footer link
   * @param String $url - Interface message for the URL to use. Passed to Skin::makeInternalOrExternalUrl
   * @param String $msg - Interface message for the text to show
	 * @param SkinTemplate &$skin
	 * @param QuickTemplate &$template
	 */
	public static function addFooterLink ( $name, $url, $msg, &$skin, &$template ) {
    $dest = Skin::makeInternalOrExternalUrl(
      $skin->msg( $url )->inContentLanguage()->text() );
    $link = Html::element(
      'a',
      [ 'href' => $dest ],
      $skin->msg( $msg )->text()
    );
    $template->set( $name, $link );
    $template->data['footerlinks']['places'][] = $name;
  }

  // Retrieve Special:Contact filter text from local DB or via HTTP action=raw.
  private static function getContactFilterText() {
    // TODO: With MW 1.35 and the MCS migration, RevisionStoreFactory can be used for DB-based cross-wiki retrieval instead.
    global $wglCentralDB, $wgDBname;
    // Until MCS migration is complete, only the central wiki can use DB-based access.
    if ( $wgDBname === $wglCentralDB ) {
      $page = WikiPage::factory( Title::newFromText( 'MediaWiki:weirdgloop-contact-filter' ) );
      if ( $page->exists() ) {
        return ContentHandler::getContentText( $page->getContent() );
      }
    } else {
      // Fallback to HTTP action=raw for other wikis.
      $url = wfAppendQuery( WikiMap::getForeignURL( $wglCentralDB, 'MediaWiki:weirdgloop-contact-filter' ), [ 'action' => 'raw' ]);
      return Http::get( $url );
    }
  }

  // Prepare the Special:Contact filter regexes.
  private static function getContactFilter() {
    $filterText = self::getContactFilterText();
    $regexes = [];

    $lines = preg_split( "/\r?\n/", $filterText );
    foreach ( $lines as $line ) {
      // Strip comments and whitespace.
      $line = preg_replace( '/#.*$/', '', $line );
      $line = trim( $line );

      // If anything is left, assume it's a valid regex.
      if ( $line !== '' ) {
        $regexes[] = $line;
      }
    }

    return $regexes;
  }

  /**
   * Implements spam filter for Special:Contact, checks against [[MediaWiki:weirdgloop-contact-filter]] on metawiki. Regex per line and use '#' for comments.
   *
   * @param string $text - The message text to check for spam.
   * @return bool
   */
  public static function checkContactFilter( $text ) {
    $cache = MediaWikiServices::getInstance()->getMainWANObjectCache();

    $regexes = $cache->getWithSetCallback(
			$cache->makeGlobalKey(
        'GloopTweaks',
        'contact-filter-regexes'
      ),
			300, // 5 minute cache time as this isn't a high frequency check.
			function () {
				return self::getContactFilter();
			}
    );

    if ( !count( $regexes ) ) {
      // No regexes to check.
      return true;
    }

    // Compare message text against each regex.
    foreach ( $regexes as $regex ) {
      Wikimedia\suppressWarnings();
      $match = preg_match( $regex, $text );
      Wikimedia\restoreWarnings();
      if ( $match ) {
        return false;
      }
    }

    return true;
  }
}