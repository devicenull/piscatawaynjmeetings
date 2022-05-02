#!/bin/bash

cd /home/piscataway/

echo "Syncing content"
rsync -rt --exclude=web/files/youtube .git classes config.php init.php templates vendor web scripts root@www.piscatawaynjmeetings.com:/home/piscataway/

echo "Importing files"
php scripts/import_files.php

mysqldump --add-drop-table piscataway > piscataway.sql
scp piscataway.sql root@www.piscatawaynjmeetings.com:/root/
ssh root@www.piscatawaynjmeetings.com 'mysql piscataway < piscataway.sql'

echo "Starting transcription"
php scripts/transcribe_meetings.php

echo "Done with file sync, backing up to S3"
cd /home/piscataway/web/files
rclone --retries-sleep 5s -L --config=/home/piscataway/.config/rclone/rclone.conf sync . vultr_ewr1:piscataway
