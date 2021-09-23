#!/bin/bash

cd /home/piscataway/
rsync -rt --exclude=web/files/youtube .git classes config.php init.php templates vendor web root@www.piscatawaynjmeetings.com:/home/piscataway/
mysqldump --add-drop-table piscataway > piscataway.sql
scp piscataway.sql root@www.piscatawaynjmeetings.com:/root/
ssh root@www.piscatawaynjmeetings.com 'mysql piscataway < piscataway.sql'

echo "Doing S3 backup"
cd /home/piscataway/web/files
rclone --config=/home/piscataway/.config/rclone/rclone.conf sync . vultr_ewr1:piscataway
