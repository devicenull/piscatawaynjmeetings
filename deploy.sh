#!/bin/bash

cd /home/piscataway/
rsync -rt .git classes config.php init.php templates vendor web root@www.piscatawaynjmeetings.com:/home/piscataway/
mysqldump --add-drop-table piscataway > piscataway.sql
scp piscataway.sql root@www.piscatawaynjmeetings.com:/root/
ssh root@www.piscatawaynjmeetings.com 'mysql piscataway < piscataway.sql'
