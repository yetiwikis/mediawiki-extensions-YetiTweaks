<?php
if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
    $IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class CleanupUpdatelog extends Maintenance {
    public function __construct() {
		parent::__construct();
		$this->addDescription( 'Cleanup updatelog table to use current directory scheme.' );
    }

    public function execute() {
        $dbw = $this->getDB( DB_MASTER );
        $res = $dbw->select( 'updatelog', [ 'ul_key', 'ul_value' ] );
        $cleanedEntries = [];
        foreach ( $res as $row ) {
            $ul_key = preg_replace( '/\/srv\/releases\/release_[0-9-]+\/mediawiki\//', '/srv/mediawiki/', $row->ul_key );
            $cleanedEntries[$ul_key] = $row->ul_value;
        }
        $dbw->truncate( 'updatelog' );
        foreach( $cleanedEntries as $ul_key => $ul_value ) {
            $dbw->insert( 'updatelog', [ 'ul_key' => $ul_key, 'ul_value' => $ul_value ] );
        }
    }
}

$maintClass = CleanupUpdatelog::class;

require_once RUN_MAINTENANCE_IF_MAIN;
