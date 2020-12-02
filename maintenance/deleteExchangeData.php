<?php
if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
    $IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\MediaWikiServices;

class DeleteExchangeData extends Maintenance {
    private $dbr;
    private $dbw;
    private $lbFactory;

    private $pageCount = 0;
    private $revCount = 0;
    private $totalRevCount = 0;

    private $deletableArchivedRevs = [];
    private $deletableRevs = [];
    private $deletableText = [];

    private $botSummaryRegexes = [
        'en_osrswiki' => [
            '/^Updat(e|ing) price( data)?$/'
        ],
        'en_rswiki' => [
            '/.*GE UPDATE.*/', // Only non-strict entry.
            '/^GEBot.* automated update of item ".+"$/',
            //'/^Updated data$/',
            '/^[Uu]pdat(ed?|ing) price( data)?$/',
            '/^Update price full update all items$/',
            '/^Update price( |-| - )\(?[Ww]eekly full upday?te[\)\.]?$/',
            '/^Update price Thursday full update\.$/',
            '/^Update price - all items$/',
            '/^Update price and\/or remove comments$/',
            '/^Update prices and\/or insert commas$/',
            '/^Update prices? - full update$/',
            '/^Update price \(full weekly upday?te\)$/',
            '/^Update price - \(?[Ff]ull? weekly update\)?\.?$/',
            '/^Update price,$/',
            '/^Updating \[\[Exchange:.+$/',
            '/^Weekly full  update$/' // Extra space is intentional.
        ],
        'pt_rswiki' => [
            '/^Atualiza(ndo [Pp]|ção do (volume do)?p)reço$/',
            '/^Atualizando item data$/'
        ]
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

    private $metaTables = [
        'change_tag' => 'ct_rev_id',
        'cu_changes' => 'cuc_this_oldid',
        'ip_changes' => 'ipc_rev_id',
        'recentchanges' => 'rc_this_oldid',
        'tag_summary' => 'ts_rev_id'
    ];

    private $pageTables = [
        'archive' => 'ar',
        'page' => 'page'
    ];

    private $revisionTables = [
        'archive' => [ 'archive', 'ar' ],
        'page' => [ 'revision', 'rev' ]
    ];

    public function __construct() {
		parent::__construct();
        $this->addDescription( 'Deletes old exchange bot data.' );
        $this->addOption( 'check', 'Print details of how many revisions are deletable, according to page name, user name, and edit summary.' );
        $this->addOption( 'delete', 'Actually perform the deletion.' );
        $this->addOption( 'quick', "Skip 5 second countdown before starting if '--delete' option is passed." );
        $this->addOption( 'verbose', 'Print verbose debugging information.' );
        // How many revision deletions to perform at a time, doesn't affect querying for the pages.
        $this->setBatchSize( 50 );
    }

    public function execute() {
        global $wgDBname;

        // Sanity checks.
        if ( !array_key_exists( $wgDBname, $this->botUsers ) ||
             !array_key_exists( $wgDBname, $this->botSummaryRegexes ) ||
             !array_key_exists( $wgDBname, $this->modulesOnly ) ||
             !array_key_exists( $wgDBname, $this->nameMapping ) ||
             !array_key_exists( $wgDBname, $this->namespaceMapping ) ) {
                $this->output( "Error: Missing wiki-specific data from mappings.\n" );
                return;
        }

        // Avoid accidental deletions by waiting 5 seconds unless the --quick option is passed.
        if ( $this->hasOption( 'delete') ) {
            if ( $this->hasOption( 'quick' ) ) {
                $this->output( "The '--delete' option has been passed with the '--quick' option, revisions will immediately be deleted.\n" );
            } else {
                $this->output( "The '--delete' option has been passed, revisions will be deleted, if not aborted with "
                    . "control-c in the next five seconds. (skip this countdown with --quick)\n" );
                $this->countDown( 5 );
            }
        }

        // Setup DB access.
        $this->dbr = $this->getDB( DB_REPLICA );
        $this->dbw = $this->getDB( DB_MASTER );
        $this->lbFactory = MediaWikiServices::getInstance()->getDBLoadBalancerFactory();

        // Process Exchange namespace and data subpages.
        if ( $this->hasExchangeSubpageData() ) {
            // Pre-module data.
            $this->processPages( $this->getExchangeId(), '%' );
        }
        // Process Exchange module subpages.
        // Pre-bulk module data and subpages.
        $this->processPages( 828 /* NS_MODULE */, $this->getExchangeName() . '/%' );

        // Start deletion.
        // Delete this batch of revisions, text, and their associated metadata.
        if ( $this->hasOption( 'delete' ) && !empty( $this->deletableText ) ) {
            $this->output( "Processed a total of {$this->pageCount} pages and {$this->totalRevCount} revisions ($this->revCount will be deleted).\n" );
            $this->deleteRevisions();
            $this->output( "Processed a total of {$this->pageCount} pages and {$this->totalRevCount} revisions ($this->revCount deleted).\n" );
        } else {
            $this->output( "Processed a total of {$this->pageCount} pages and {$this->totalRevCount} revisions ($this->revCount deletable).\n" );
        }
    }

    private function getBotUsers() {
        global $wgDBname;
        return $this->botUsers[$wgDBname];
    }

    private function getBotSummaryRegexes() {
        global $wgDBname;
        return $this->botSummaryRegexes[$wgDBname];
    }

    private function getExchangeId() {
        global $wgDBname;
        return $this->namespaceMapping[$wgDBname];
    }

    private function getExchangeName() {
        global $wgDBname;
        return $this->nameMapping[$wgDBname];
    }

    private function hasExchangeSubpageData() {
        global $wgDBname;
        return !$this->modulesOnly[$wgDBname];
    }

    private function processPages( $namespaceId, $titlePattern, $excludeTitlePattern = '' ) {
        $namespace = ( $namespaceId === 828 ) ? 'Module' : $this->getExchangeName();

        foreach ( $this->pageTables as $table => $prefix ) {
            if ( $excludeTitlePattern === '' ) {
                $this->output( "Scanning the '$table' table for titles matching '$namespace:$titlePattern'...\n" );
            } else {
                $this->output( "Scanning the '$table' table for titles matching '$namespace:$titlePattern', but not '$namespace:$excludeTitlePattern'...\n" );
            }

            $conds = [ "{$prefix}_namespace=$namespaceId" ];
            if ( $titlePattern !== '%' ) {
                $conds[] = "{$prefix}_title LIKE '$titlePattern'";
            }
            if ( $excludeTitlePattern !== '' ) {
                $conds[] = "{$prefix}_title NOT LIKE '$excludeTitlePattern'";
            }

            $res = $this->dbr->select(
                $table,
                [
                    'id' => "{$prefix}_id",
                    'title' => "{$prefix}_title"
                ],
                $conds,
                __METHOD__,
                [
                    // Using GROUP BY prevents the need to worry about archive vs page table differences.
                    'GROUP BY' => "{$prefix}_title",
                ]
            );

            $pageCount = $res->numRows();
            // Check to see if any entries were found.
            if ( $pageCount === 0 ) {
                $this->output( "Did not find any matches in the '$table' table.\n" );
                return;
            }

            $revCount = 0;
            $totalRevCount = 0;
            foreach ( $res as $row ) {
                if ( $this->hasOption( 'verbose' ) ) {
                    $this->output( "\tWorking on page titled '$namespace:{$row->title}' in the '$table' table.\n" );
                }

                [ $rc, $trc ] = $this->processRevisions( $table, $namespaceId, $row->title, $row->id );
                $revCount += $rc;
                $totalRevCount += $trc;
            }

            $this->pageCount += $pageCount;
            $this->revCount += $revCount;
            $this->totalRevCount += $totalRevCount;
            $this->output( "Processed $pageCount pages and $totalRevCount revisions ($revCount deletable) in the '$table' table.\n" );
        }
    }

    private function processRevisions( $pageTable, $namespaceId, $title, $id ) {
        [ $table, $prefix ] = $this->revisionTables[ $pageTable ];
        $checking = $this->hasOption('check');
        $namespace = ( $namespaceId === 828 ) ? 'Module' : $this->getExchangeName();

        if ( $this->hasOption( 'verbose' ) ) {
            $this->output( "Scanning for revision data in the '$table' table belonging to page '$namespace:$title'...\n" );
        }

        $pageConds = [];
        $revIdField = 'rev_id';
        if ( $table === 'archive' ) {
            // For the 'archive' table we can't guarantee a page ID, so we use namespace with title to approximate.
            $pageConds['ar_namespace'] = $namespaceId;
            $pageConds['ar_title'] = $title;
            // For the 'archive' table we need a different prefix to get the original revision ID.
            $revIdField = 'ar_rev_id';
        } elseif ( $table === 'revision' ) {
            // For the 'revision' table we have a page ID we can use.
            $pageConds['rev_page'] = $id;
        }

        // Using MAX($revIdField + 1) avoids need to special case conditionals.
        $currentRevId = $this->dbr->selectField( $table, "MAX($revIdField + 1)", $pageConds, __METHOD__ );
        $oldestRevId = $this->dbr->selectField( $table, "MIN($revIdField)", $pageConds, __METHOD__ );

        // Check to see if any revisions were found.
        if ( $currentRevId === null ) {
            $this->output( "Did not find any matching revision data in the '$table' table.\n" );
            return 0;
        }

        $checkData = [];
        $revCount = 0;
        $totalRevCount = 0;
        $revId = 0;
        $isBotPriceRevision = false;
        $keepRevision = true;
        // Next = newer.
        $isNewerRevisionBotRevision = false;
        $userFromNewerBotRevision = 0;
        do {
            $conds = $pageConds;
            $conds[] = "$revIdField < $currentRevId";
            $res = $this->dbr->select(
                $table,
                [
                    'revComment'  => "{$prefix}_comment",
                    'revId'       => $revIdField,
                    'revParentId' => "{$prefix}_parent_id",
                    'revUser'     => "{$prefix}_user",
                    'revUserText' => "{$prefix}_user_text",
                    'textId'      => "{$prefix}_text_id"
                ],
                $conds,
                __METHOD__,
                [
                    'ORDER BY' => "$revIdField DESC",
                    'LIMIT' => $this->getBatchSize(),
                ]
            );
            $totalRevCount += $res->numRows();
            foreach ( $res as $row ) {
                $revComment = $row->revComment;
                $revId = (int)$row->revId;
                $revParentId = $row->revParentId;
                $revUser = (int)$row->revUser;
                $revUserText = $row->revUserText;
                $textId = (int)$row->textId;

                if ( $this->hasOption( 'verbose' ) ) {
                    $this->output( "\tWorking on revision in the '$table' table with ID '$revId'.\n" );
                }

                if ( $revParentId === '0' || $revId <= $oldestRevId ) {
                    $keepRevision = true;
                }

                $isBotPriceRevision = $this->checkForBotPriceRevision( $revUser, $revComment );

                // Only delete the revision if we know it and the newer revision are bot revisions made by the same bot and is not the oldest or newest revision.
                if ( $isBotPriceRevision && !$keepRevision && $isNewerRevisionBotRevision && $revUser === $userFromNewerBotRevision ) {
                    $revCount++;
                    if ( $checking ) {
                        if ( !isset( $checkData[$revUserText] ) ) {
                            $checkData[$revUserText] = [];
                        }
                        if ( !isset( $checkData[$revUserText][$revComment] ) ) {
                            $checkData[$revUserText][$revComment] = 0;
                        }
                        $checkData[$revUserText][$revComment]++;
                    }
                    if ( $this->hasOption( 'delete' ) ) {
                        if ( $table === 'archive' ) {
                            $this->deletableArchivedRevs[] = $revId;
                        } else {
                            $this->deletableRevs[] = $revId;
                        }
                        $this->deletableText[] = $textId;
                    }
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
                if ( $revParentId  !== '0' ) {
                    $keepRevision = false;
                }
            }
            $currentRevId = $revId;
        } while ( $res->numRows() );

        if ( $checking ) {
            foreach ( $checkData as $user => $comments ) {
                foreach ( $comments as $comment => $count ) {
                    $this->output("\t'$namespace:$title'\t'$user'\t'$comment' => $count\n");
                }
            }
        }

        return [ $revCount, $totalRevCount ];
    }

    private function deleteRevisions() {
        foreach ( [ 'archive' => 'ar_rev_id', 'revision' => 'rev_id' ] as $revTable => $revIdField ) {
            $this->output( "Deleting '$revTable' entries...\n" );
            $batches = [];
            $count = 0;
            if ( $revTable === 'archive' ) {
                $batches = array_chunk( $this->deletableArchivedRevs, $this->getBatchSize() );
            } else {
                $batches = array_chunk( $this->deletableRevs, $this->getBatchSize() );
            }

            foreach ( $batches as $batch ) {
                $count += count( $batch );
                foreach ( $this->metaTables as $table => $field ) {
                    $this->dbw->delete( $table, [ $field => $batch ], __METHOD__ );
                    $this->lbFactory->waitForReplication();
                }
                $this->dbw->delete( $revTable, [ $revIdField => $batch ], __METHOD__ );
                $this->lbFactory->waitForReplication();
                if ( $count % 5000 === 0 ) {
                    $this->output( "Deleted $count '$revTable' entries so far...\n" );
                }
            }
            $this->output( "Deleted a total of $count '$revTable' entries.\n" );
        }

        $this->output( "Deleting 'text' entries...\n" );
        $batches = array_chunk( $this->deletableText, $this->getBatchSize() );
        $count = 0;
        foreach ( $batches as $batch ) {
            $count += count( $batch );
            $this->dbw->delete( 'text', [ 'old_id' => $batch ], __METHOD__ );
            $this->lbFactory->waitForReplication();
            if ( $count % 5000 === 0 ) {
                $this->output( "Deleted $count 'text' entries so far...\n" );
            }
        }
        $this->output( "Deleted a total of $count 'text' entries.\n" );
    }

    private function checkForBotPriceRevision( $userId, $summary ) {
        // IP edits are not bot edits.
        if ( $userId === 0 ) {
            return false;
        }

        // Check that the editor is a known GE updating bot.
        if ( !in_array( $userId, $this->getBotUsers() ) ) {
            return false;
        }

        // GE updating bots must give an edit summary.
        if ( $summary === '' ) {
            return false;
        }

        // Check that the edit summary is known to be given for GE updates by bots.
        foreach ( $this->getBotSummaryRegexes() as $regex ) {
            if ( preg_match( $regex, $summary ) ) {
                return true;
            }
        }

        // Otherwise no edit summary match was found.
        return false;
    }
}

$maintClass = DeleteExchangeData::class;

require_once RUN_MAINTENANCE_IF_MAIN;
