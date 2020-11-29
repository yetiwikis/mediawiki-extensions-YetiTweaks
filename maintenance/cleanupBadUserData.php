<?php
if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
    $IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class CleanupBadUserData extends Maintenance {
    // Mapping of botched user names to correct user names for accounts renamed on Fandom between the osrsw/rsw and ptrsw/rscw migrations.
    private $botchedNames = [
        'The Buzzfizzler'  => 'Gaz Lloyd',
        'C886553' => 'Ajraddatz',
        'Kerri Amber' => 'Sactage',
        'Dǐll Kevlar' => 'Imdill3',
        'The Fizzbuzzler' => 'Cook Me Plox',
    ];

    // User IDs existing on Fandom remapped to user IDs on weirdgloop. Caused by accounts created on the fork with the names of accounts existing on later imported wikis.
    private $remappedIDs = [
        '27169676' => '40001264', // Zerothar
        '35274577' => '40001946', // Saraholiveira92
    ];

    public function __construct() {
		parent::__construct();
		$this->addDescription( 'Fix bad user data' );
		$this->addOption( 'fix', 'Actually fix bad user data' );
    }

    public function execute() {
        // Fix bad user data in table.
        $this->fixTable( 'abuse_filter', 'af' );
        $this->fixTable( 'abuse_filter_history', 'afh' );
        $this->fixTable( 'abuse_filter_log', 'afl', false ); // Not actor migrated.
        $this->fixTable( 'archive', 'ar' );
        $this->fixTable( 'cu_changes', 'cuc', false ); // Not actor migrated.
        $this->fixTable( 'cu_log', 'cul', false ); // Not actor migrated.
        $this->fixTable( 'filearchive', 'fa' );
        $this->fixTable( 'image', 'img' );
        $this->fixTable( 'logging', 'log' );
        $this->fixTable( 'oldimage', 'oi' );
        $this->fixTable( 'recentchanges', 'rc' );
        $this->fixTable( 'revision', 'rev' );
    }

    private function fixTable( $table, $prefix, $actorMigrated = true ) {
        $this->output( "Checking for bad user data in $table...\n" );

        // User ID wasn't found in user table or belonged to a different user name.
        $this->fixMismatchedIDs( $table, $prefix );

        // Non-IP user_text was found recorded as IP.
        // Only a concern if the data will be actor migrated, otherwise the data likely isn't intended to be mapped to user IDs.
        if ( $actorMigrated ) {
            $this->fixMissingIDs( $table, $prefix );
        }
    }

    private function fixMismatchedIDs( $table, $prefix ) {
        $this->output( "Fixing mismatched user IDs in $table...\n" );
        $dbw = $this->getDB( DB_MASTER );

        $res = $dbw->select(
            [$table, 'user'],
            [
                'found_id' => "{$prefix}_user",
                'found_name' => "{$prefix}_user_text",
                'user_name',
            ],
            [
                "{$prefix}_user != 0",
                "user_id IS NULL OR (user_id IS NOT NULL AND {$prefix}_user_text != user_name)",
            ],
            __METHOD__,
            [
                'DISTINCT',
            ],
            [
                'user' => [
                    'LEFT JOIN',
                    "{$prefix}_user = user_id",
                ],
            ]
        );

		foreach ( $res as $row ) {
            if ( array_key_exists( $row->found_id, $this->remappedIDs ) ) {
                $this->output( sprintf(
                    "\t%8d => %8d: '%s' (remapping ID to correct ID for name)\n",
                    $row->found_id,
                    $this->remappedIDs[$row->found_id],
                    $row->found_name
                ) );
                if ( $this->hasOption( 'fix' ) ) {
                    $dbw->update(
                        $table,
                        [
                            "{$prefix}_user" => $this->remappedIDs[$row->found_id],
                        ],
                        [
                            "{$prefix}_user" => $row->found_id,
                            "{$prefix}_user_text" => $row->found_name,
                        ],
                        __METHOD__
                    );
                }
            } elseif ( $row->user_name === null && $row->found_name == '127.0.0.1' ) {
                // Old Wikia account deletions set user text to 127.0.0.1, but left user ID intact. Solution is to create dummy accounts for attributing the edits.
                $dummyUser = 'Disabled' . mt_rand(10000000, 99999999);
                $this->output( sprintf(
                    "\t%8d: '%s' => '%s' (NEEDS MANUAL ACTION: make dummy account)\n",
                    $row->found_id,
                    $row->found_name,
                    $dummyUser
                ) );
            } elseif ( $row->user_name !== null ) {
                $this->output( sprintf(
                    "\t%8d: '%s' => '%s'\n",
                    $row->found_id,
                    $row->found_name,
                    $row->user_name
                ) );
                if ( $this->hasOption( 'fix' ) ) {
                    $dbw->update(
                        $table,
                        [
                            "{$prefix}_user_text" => $row->user_name,
                        ],
                        [
                            "{$prefix}_user" => $row->found_id,
                            "{$prefix}_user_text" => $row->found_name,
                        ],
                        __METHOD__
                    );
                }
            }
        }
    }

    private function fixMissingIDs( $table, $prefix ) {
        $this->output( "Fixing missing user IDs in $table...\n" );
        $dbw = $this->getDB( DB_MASTER );

        $res = $dbw->select(
            $table,
            [
                'found_id' => "{$prefix}_user",
                'found_name' => "{$prefix}_user_text",
            ],
            [
                "{$prefix}_user_text = '' OR ({$prefix}_user = 0 AND NOT (IS_IPV4({$prefix}_user_text) OR IS_IPV6({$prefix}_user_text)))",
            ],
            __METHOD__,
            [
                'DISTINCT',
            ]
        );

		foreach ( $res as $row ) {
            if ( $row->found_name == '' ) {
                $this->output("Found bad entry! MISSING USER TEXT\n");
                continue;
            }
            $name = $row->found_name;

            // Check if this is an interwiki name and convert it to a local name if so.
            if ( ( $pos = strpos( $name, '>') ) !== false ) {
                $name = substr( $name, $pos + 1 );
            }

            // If this is a known botched name, then correct it.
            if ( array_key_exists( $name, $this->botchedNames )) {
                $name = $this->botchedNames[$name];
            }

            $id = User::idFromName( $name );
            if ( $id !== null ) {
                $this->output( sprintf(
                    "\t'%s' => '%s': '%d'\n",
                    $row->found_name,
                    $name,
                    $id
                ) );
                if ( $this->hasOption( 'fix' ) ) {
                    $dbw->update(
                        $table,
                        [
                            "{$prefix}_user" => $id,
                            "{$prefix}_user_text" => $name,
                        ],
                        [
                            "{$prefix}_user" => 0,
                            "{$prefix}_user_text" => $row->found_name,
                        ],
                        __METHOD__
                    );
                }
            } else {
                $this->output( sprintf(
                    "\t'%s' => '%s': ACTUALLY MISSING\n",
                    $row->found_name,
                    $name
                ) );
            }
        }
    }
}

$maintClass = cleanupBadUserData::class;

require_once RUN_MAINTENANCE_IF_MAIN;
