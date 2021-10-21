#!/bin/bash
WIKIS="en_osrswiki en_rscwiki en_rswiki pt_rswiki"

cd /srv/mediawiki
echo Updating metawiki...
php extensions/GloopTweaks/maintenance/cleanupUpdatelog.php --wiki metawiki
php maintenance/update.php --wiki metawiki --quick --skip-optimize --doshared
php maintenance/migrateRevisionActorTemp.php --wiki metawiki
php extensions/SemanticMediaWiki/maintenance/updateEntityCountMap.php --wiki metawiki
parallel --progress -j4 "echo Updating {}...; php extensions/GloopTweaks/maintenance/cleanupUpdatelog.php --wiki {}; php maintenance/update.php --wiki {} --quick --skip-optimize; php maintenance/migrateRevisionActorTemp.php --wiki {}; php extensions/SemanticMediaWiki/maintenance/updateEntityCountMap.php --wiki {}" ::: $WIKIS
