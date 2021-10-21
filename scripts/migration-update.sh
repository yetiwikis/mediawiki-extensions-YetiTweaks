#!/bin/bash

cd /srv/mediawiki
echo Updating $1...
php extensions/GloopTweaks/maintenance/cleanupUpdatelog.php --wiki $1
if [ "$1" == 'metawiki' ]; then
    php maintenance/update.php --quick --skip-optimize --wiki metawiki --doshared
else
    php maintenance/update.php --quick --skip-optimize --wiki $1
fi
php maintenance/migrateRevisionActorTemp.php --wiki $1
php extensions/SemanticMediaWiki/maintenance/updateEntityCountMap.php --wiki $1
