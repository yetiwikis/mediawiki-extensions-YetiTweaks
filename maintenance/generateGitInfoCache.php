<?php

namespace MediaWiki\Extension\YetiTweaks\Maintenance;

use GitInfo;
use Maintenance;

if ( getenv( 'MW_INSTALL_PATH' ) ) {
	$IP = getenv( 'MW_INSTALL_PATH' );
} else {
	$IP = __DIR__ . '/../../..';
}
require_once "$IP/maintenance/Maintenance.php";

class GenerateGitInfoCache extends Maintenance {
	public function execute() {
		global $IP;

		$this->output( "Generating GitInfo cache...\n" );

		$patterns = [
			"$IP",
			"$IP/extensions/*",
			"$IP/skins/*",
		];

		foreach ($patterns as $pattern) {
			$directories = glob($pattern);

			foreach ($directories as $directory) {
				if (is_dir($directory)) {
					$this->output( "Generating GitInfo cache for '$directory'.\n" );
					(new GitInfo( $directory, false ))->precomputeValues();
				}
			}
		}
	}
}

$maintClass = GenerateGitInfoCache::class;
require_once RUN_MAINTENANCE_IF_MAIN;
