<?php
// This file is intended to be symlinked into $IP.

define( 'MW_NO_SESSION', 1 );
define( 'MW_ENTRY_POINT', 'robots' );

require __DIR__ . '/includes/WebStart.php';

wfRobotsMain();

function wfRobotsMain() {
    // TODO: With MW 1.35 and the MCS migration, RevisionStoreFactory can be used for DB-based cross-wiki retrieval instead.
    global $wglCentralDB, $wgCanonicalServer, $wgDBname;

    $lastModified = null;
    $text = '';
    // Until MCS migration is complete, only the central wiki can use DB-based access.
    if ( $wgDBname === $wglCentralDB ) {
        $page = WikiPage::factory( Title::newFromText( 'MediaWiki:Robots.txt' ) );
        if ( $page->exists() ) {
            $lastModified = wfTimestamp( TS_RFC2822, $page->getTouched() );
            $text = ContentHandler::getContentText( $page->getContent() );
        }
    }
    // Fallback to HTTP action=raw for other wikis.
    else {
        $url = wfAppendQuery( WikiMap::getWiki( $wglCentralDB )->getCanonicalUrl( 'MediaWiki:Robots.txt' ), [ 'action' => 'raw' ]);

        $request = MWHttpRequest::factory( $url, [], __METHOD__ );
        $status = $request->execute();

        $lastModified = $request->getResponseHeaders()['Last-Modified'] ?? '';
        if ( $status->isOK() ) {
            $text = $request->getContent();
        }
    }

    header( 'Cache-Control: max-age=300, must-revalidate, s-maxage=3600, revalidate-while-stale=300' );
    header( 'Content-Type: text/plain; charset=utf-8' );

    if ( $lastModified ) {
        header( 'Last-Modified: ' . $lastModified );
    }

    $sitemap = "Sitemap: $wgCanonicalServer/sitemap/sitemap-index-$wgDBname.xml";
    if ( $text ) {
        echo $text . "\n\n" . $sitemap;
    } else {
        echo $sitemap;
    }
}