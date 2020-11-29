<?php
if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
    $IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class DeleteOldExchangeBotData extends Maintenance {
    private $dbr;
    private $dbw;
    private $lbFactory;

    private $pageCount = 0;
    private $revCount = 0;

    private $rswFullMatches = [
        'GEBot.* automated update of item ".+"',
        'GEMW Bot TESTING',
        'Price update by Immibot',
        'Testing - Report issues here: \[\[User talk:Thebrains222\]\]',
        'Thurdsday full update\.', // Typo is intentional.
        'Updated',
        'Updated volume',
        'Updating \[\[Exchange:.+',
        'Weekly full  update' // Extra space is intentional.
    ];

    private $botSummaries = [
        'en_osrswiki' => '/^Updat(e|ing) price$/',
        // en_rswiki is added in a hack in execute() in order to speed up full matches.
        'pt_rswiki' => '/^Atualiza(ndo [Pp]|ção do p)reço$/'
    ];

    private $botUsers = [
        'en_osrswiki' => [
            3044102,  // TyBot
            3295184,  // Gaz Bot
            27662621, // TyBot OS
            29739861, // ShoyBot
            40019741  // Gaz GEBot
        ],
        'en_rswiki' => [
            103988,  // Rich Farmbrough (Early SmackBot edits)
            833999,  // Immibot
            1053666, // SmackBot
            1252854, // GEBot
            1444022, // AzBot
            1674736, // AmauriceBot
            3044102, // TyBot
            3075930, // BrainBot
            3116284, // JSBot
            4760294, // A proofbot
            40019741 // Gaz GEBot
        ],
        'pt_rswiki' => [
            4827187, // SandroHcBot
            26009116 // Pedro Torres BOT
        ]
    ];

    // Populated during init.
    private $exchangeBots;
    private $exchangeId;
    private $exchangeModulesOnly;
    private $exchangeName;
    private $exchangeSummaries;

    private $modulesOnly = [
        'en_osrswiki' => true, // Exchange implementation was setup after en_rswiki moved to module-based implementation.
        'en_rswiki'   => false, // Exchange:%/Data is archived.
        'pt_rswiki'   => false // Exchange:%/Data still exists.
    ];

    private $nameMapping = [
        'en_osrswiki' => 'Exchange',
        'en_rswiki'   => 'Exchange',
        'pt_rswiki'   => 'Mercado'
    ];

    private $namespaceMapping = [
        'en_osrswiki' => 114,
        'en_rswiki'   => 112,
        'pt_rswiki'   => 112
    ];

    public function __construct() {
		parent::__construct();
		$this->addDescription( 'Delete old exchange bot data.' );
        $this->addOption( 'delete', 'Actually delete old exchange bot data.' );
        $this->addOption( 'verbose', 'Verbose debugging information.' );
        $this->setBatchSize( 50 );
    }

    public function execute() {
        global $wgDBname;

        // Hack to use one regex rather than several.
        $this->botSummaries['en_rswiki'] = '/(.*GE UPDATE.*|.*[Uu]pdat(ed?|ing) price.*|^(' . implode( '|', $this->rswFullMatches ) . ')$)/';

        // Sanity checks.
        if ( !array_key_exists( $wgDBname, $this->botUsers ) ||
             !array_key_exists( $wgDBname, $this->botSummaries ) ||
             !array_key_exists( $wgDBname, $this->modulesOnly ) ||
             !array_key_exists( $wgDBname, $this->nameMapping ) ||
             !array_key_exists( $wgDBname, $this->namespaceMapping ) ) {
                $this->output( "Error: Missing wiki-specific data from mappings.\n" );
                return;
        }

        // Populate wiki-specific data from mappings.
        $this->exchangeBots = $this->botUsers[$wgDBname];
        $this->exchangeId = $this->namespaceMapping[$wgDBname];
        $this->exchangeModulesOnly = $this->modulesOnly[$wgDBname];
        $this->exchangeName = $this->nameMapping[$wgDBname];
        $this->exchangeSummaries = $this->botSummaries[$wgDBname];

        // Setup DB access.
        $this->dbr = $this->getDB( DB_REPLICA );
        $this->dbw = $this->getDB( DB_MASTER );
        $this->lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

        // Delete revisions for Exchange namespace pages.
        if ( !$this->exchangeModulesOnly ) {
            // Pre-module history data.
            $this->deleteRevisions( $this->exchangeId, '%/Data', true );
            // Pre-module data.
            $this->deleteRevisions( $this->exchangeId, '%', false, '%/Data' );
        }
        // Delete revisions for Exchange module subpages.
        // Pre-bulk module history data.
        $this->deleteRevisions( 828 /* NS_MODULE */, $this->exchangeName . '/%/Data', true );
        // Pre-bulk module data.
        $this->deleteRevisions( 828 /* NS_MODULE */, $this->exchangeName . '/%', false, $this->exchangeName . '/%/Data' );

        $this->output( "Processed a total of {$this->pageCount} pages and {$this->revCount} revisions.\n" );
    }

    private function deleteRevisions( $namespaceId, $titlePattern, $fullDelete, $excludeTitlePattern = '' ) {
        $this->deletePageTable( $namespaceId, $titlePattern, $fullDelete, $excludeTitlePattern );
        $this->deleteArchiveTable( $namespaceId, $titlePattern, $fullDelete, $excludeTitlePattern );
    }

    private function deleteArchiveTable( $namespaceId, $titlePattern, $fullDelete, $excludeTitlePattern ) {
        if ( $excludeTitlePattern === '' ) {
            $this->output( "Scanning archived revisions for titles matching '$titlePattern' in namespace ID '$namespaceId'...\n" );
        } else {
            $this->output( "Scanning archived revisions for titles matching '$titlePattern', but not '$excludeTitlePattern', in namespace ID '$namespaceId'...\n" );
        }

        $pageConds = [ "ar_namespace=$namespaceId" ];
        if ( $titlePattern !== '%' ) {
            $pageConds[] = "ar_title LIKE '$titlePattern'";
        }
        if ( $excludeTitlePattern !== '' ) {
            $pageConds[] = "ar_title NOT LIKE '$excludeTitlePattern'";
        }

        $res = $this->dbr->select(
            'archive',
            [ 'ar_title' ],
            $pageConds,
            __METHOD__,
            [
                'DISTINCT'
            ]
        );

        $pageCount = $res->numRows();
        // Check to see if any archived revisions were found.
        if ( $pageCount === 0 ) {
            $this->output( "Did not find any archived revision data to delete.\n" );
            return;
        }

        $revCount = 0;
        foreach ( $res as $row ) {
            if ( $this->hasOption( 'verbose' ) ) {
                $this->output( "\tWorking on page titled '{$row->ar_title}'.\n" );
            }

            $revCount += $this->deleteArchivedRevisions( $namespaceId, $row->ar_title, $fullDelete );
        }

        $this->pageCount += $pageCount;
        $this->revCount += $revCount;
        $this->output( "Processed $pageCount pages and $revCount revisions.\n" );
    }

    private function deletePageTable( $namespaceId, $titlePattern, $fullDelete, $excludeTitlePattern ) {
        if ( $excludeTitlePattern === '' ) {
            $this->output( "Scanning for page titles matching '$titlePattern' in namespace ID '$namespaceId'...\n" );
        } else {
            $this->output( "Scanning for page titles matching '$titlePattern', but not '$excludeTitlePattern', in namespace ID '$namespaceId'...\n" );
        }

        $pageConds = [ "page_namespace=$namespaceId" ];
        if ( $titlePattern !== '%' ) {
            $pageConds[] = "page_title LIKE '$titlePattern'";
        }
        if ( $excludeTitlePattern !== '' ) {
            $pageConds[] = "page_title NOT LIKE '$excludeTitlePattern'";
        }

        // Using MAX(page_id + 1) avoids need to special case conditionals.
        $currentPageId = $this->dbr->selectField( 'page', 'MAX(page_id + 1)', $pageConds, __METHOD__ );

        // Check to see if any pages were found.
        if ( $currentPageId === null ) {
            $t = $fullDelete ? 'page' : 'revision';
            $this->output( "Did not find any $t data to delete.\n" );
            return;
        }

        $pageCount = 0;
        $revCount = 0;
        do {
            $conds = $pageConds;
            $conds[] = "page_id < $currentPageId";
            $res = $this->dbr->select(
                'page',
                [ 'page_id' ],
                $conds,
                __METHOD__,
                [
                    'ORDER BY' => 'page_id DESC',
                    'LIMIT' => $this->getBatchSize(),
                ]
            );
            $pageCount += $res->numRows();
            foreach ( $res as $row ) {
                if ( $this->hasOption( 'verbose' ) ) {
                    $this->output( "\tWorking on page ID '{$row->page_id}'.\n" );
                }

                $revCount += $this->deleteRevisionTable( (int)$row->page_id, $fullDelete );

                // Only delete the page if a full deletion is being performed.
                /*
                if ( $fullDelete && $this->hasOption( 'delete' ) ) {
                    $this->dbw->delete(
                        'page',
                        [ 'page_id' => $row->page_id ],
                        __METHOD__
                    );
                }
                */
            }
            $currentPageId = $row->page_id;
        } while ( $res->numRows() );
        $this->pageCount += $pageCount;
        $this->revCount += $revCount;
        $this->output( "Processed $pageCount pages and $revCount revisions.\n" );
    }

    private function deleteRevisionTable( $pageId, $fullDelete ) {
        if ( $this->hasOption( 'verbose' ) ) {
            $this->output( "Scanning for revision data belonging to page ID '$pageId'...\n" );
        }

        // Using MAX(rev_id + 1) avoids need to special case conditionals.
        $currentRevId = $this->dbr->selectField( 'revision', 'MAX(rev_id + 1)', [ 'rev_page' => $pageId ], __METHOD__ );
        $oldestRevId = $this->dbr->selectField( 'revision', 'MIN(rev_id)', [ 'rev_page' => $pageId ], __METHOD__ );

        // Check to see if any revisions were found.
        if ( $currentRevId === null ) {
            $this->output( "Did not find any revision data to delete.\n" );
            return 0;
        }

        $revCount = 0;
        $revId = 0;
        $isBotPriceRevision = false;
        $isNewestRevision = true;
        $isOldestRevision = false;
        // Next = newer.
        $isNewerRevisionBotRevision = false;
        $userFromNewerBotRevision = 0;
        do {
            $res = $this->dbr->select(
                'revision',
                [ 'rev_id', 'rev_user', 'rev_comment' ],
                [
                    "rev_page = $pageId",
                    "rev_id < $currentRevId"
                ],
                __METHOD__,
                [
                    'ORDER BY' => 'rev_id DESC',
                    'LIMIT' => $this->getBatchSize(),
                ]
            );

            foreach ( $res as $row ) {
                $revId = (int)$row->rev_id;
                $revUser = (int)$row->rev_user;
                if ( $this->hasOption( 'verbose' ) ) {
                    $this->output( "\tWorking on revision with ID '{$row->rev_id}'.\n" );
                }
                if ( $fullDelete ) {
                    $revCount++;
                    continue;
                }
                if ( $revId <= $oldestRevId ) {
                    $isOldestRevision = true;
                }

                $isBotPriceRevision = $this->checkForBotPriceRevision( $revUser, $row->rev_comment );

                // Only delete the revision if we know it and the newer revision are bot revisions made by the same bot and is not the oldest or newest revision.
                if ( $isBotPriceRevision && !$isNewestRevision && !$isOldestRevision && $isNewerRevisionBotRevision && $revUser === $userFromNewerBotRevision ) {
                    $revCount++;
                }

                if ( $isBotPriceRevision ) {
                    $isNewerRevisionBotRevision = true;
                    $userFromNewerBotRevision = $revUser;
                } else {
                    $isNewerRevisionBotRevision = true;
                    $userFromNewerBotRevision = 0;
                }

                $isBotPriceRevision = false;
                $isNewestRevision = false;
/*
                $this->deleteRevisionTable( $revId, $fullDelete );

                // Only delete the page if a full deletion is being performed.
                if ( $fullDelete && $this->hasOption( 'delete' ) ) {
                    $this->dbw->delete(
                        'page',
                        [ 'page_id' => $row->page_id ],
                        __METHOD__
                    );
                }
*/
            }
            $currentRevId = $revId;
        } while ( $res->numRows() );
        return $revCount;
    }

    private function deleteArchivedRevisions( $namespaceId, $pageTitle, $fullDelete ) {
        $pageConds = [
            'ar_namespace' => $namespaceId,
            'ar_title' => $pageTitle
        ];

        // Using MAX(ar_rev_id + 1) avoids need to special case conditionals.
        $currentRevId = $this->dbr->selectField( 'archive', 'MAX(ar_rev_id + 1)', $pageConds, __METHOD__ );
        $oldestRevId = $this->dbr->selectField( 'archive', 'MIN(ar_rev_id)', $pageConds, __METHOD__ );

        // Check to see if any revisions were found.
        if ( $currentRevId === null ) {
            $this->output( "Did not find any archived revision data to delete.\n" );
            return 0;
        }

        $revCount = 0;
        $revId = 0;
        $isBotPriceRevision = false;
        $keepRevision = true;
        // Next = newer.
        $isNewerRevisionBotRevision = false;
        $userFromNewerBotRevision = 0;
        do {
            $conds = $pageConds;
            $conds[] = "ar_rev_id < $currentRevId";
            $res = $this->dbr->select(
                'archive',
                [ 'ar_rev_id', 'ar_user', 'ar_comment', 'ar_parent_id' ],
                $conds,
                __METHOD__,
                [
                    'ORDER BY' => 'ar_rev_id DESC',
                    'LIMIT' => $this->getBatchSize(),
                ]
            );

            foreach ( $res as $row ) {
                $revId = (int)$row->ar_rev_id;
                $revUser = (int)$row->ar_user;

                if ( $this->hasOption( 'verbose' ) ) {
                    $this->output( "\tWorking on archived revision with ID '{$row->ar_rev_id}'.\n" );
                }
                if ( $fullDelete ) {
                    $revCount++;
                    continue;
                }
                if ( $row->ar_parent_id === '0' || $revId <= $oldestRevId ) {
                    $keepRevision = true;
                }

                $isBotPriceRevision = $this->checkForBotPriceRevision( $revUser, $row->ar_comment );

                // Only delete the revision if we know it and the newer revision are bot revisions made by the same bot and is not the oldest or newest revision.
                if ( $isBotPriceRevision && !$keepRevision && $isNewerRevisionBotRevision && $revUser === $userFromNewerBotRevision ) {
                    $revCount++;
                }

                if ( $isBotPriceRevision ) {
                    $isNewerRevisionBotRevision = true;
                    $userFromNewerBotRevision = $revUser;
                } else {
                    $isNewerRevisionBotRevision = true;
                    $userFromNewerBotRevision = 0;
                }

                $isBotPriceRevision = false;
                // We want the following, older revision to be kept as well if the newer revision is a page creation.
                if ( $row->ar_parent_id  !== '0' ) {
                    $keepRevision = false;
                }
/*
                $this->deleteRevisionTable( $revId, $fullDelete );

                // Only delete the page if a full deletion is being performed.
                if ( $fullDelete && $this->hasOption( 'delete' ) ) {
                    $this->dbw->delete(
                        'page',
                        [ 'page_id' => $row->page_id ],
                        __METHOD__
                    );
                }
*/
            }
            $currentRevId = $revId;
        } while ( $res->numRows() );
        return $revCount;
    }

    private function checkForBotPriceRevision( $userId, $summary ) {
        // IP edits are not bot edits.
        if ( $userId === 0 ) {
            return false;
        }

        // Check that the editor is a known GE updating bot.
        if ( !in_array( $userId, $this->exchangeBots ) ) {
            return false;
        }

        // GE updating bots must give an edit summary.
        if ( $summary === '' ) {
            return false;
        }

        // Check that the edit summary is known to be given for GE updates by bots.
        if ( preg_match( $this->exchangeSummaries, $summary ) ) {
            return true;
        }

        // Otherwise no edit summary match was found.
        return false;
    }
}

$maintClass = DeleteOldExchangeBotData::class;

require_once RUN_MAINTENANCE_IF_MAIN;
