#!/bin/bash

cd /home/piscataway/

#DEST="www.piscatawaynjmeetings.com"
DEST="185.101.97.102"

echo "Syncing content"
rsync -rt --exclude=web/files .git classes config.php init.php templates vendor web scripts root@$DEST:/home/piscataway/
# sync only transcripts, the rest are too big!
find ./web/ -iname \*.txt | rsync -rt --files-from=/dev/stdin . root@185.101.97.102:/home/piscataway/

echo "Importing files"
php scripts/import_files.php
php scripts/monitor_revai_progress.php

mysqldump --add-drop-table --ignore-table=piscataway.textcopy piscataway > piscataway.sql
scp piscataway.sql root@$DEST:/root/
ssh root@$DEST 'mysql piscataway < piscataway.sql'

echo "Dumping textcopy from remote"
ssh root@$DEST 'mysqldump --add-drop-table piscataway textcopy > /root/textcopy.sql'
scp root@$DEST:/root/textcopy.sql ./textcopy.sql
cat textcopy.sql | mysql piscataway

echo "Starting transcription"
php scripts/transcribe_meetings.php

echo "Done with file sync, backing up to S3"
rclone -v --delete-excluded --exclude=**youtube** --fast-list -L --config=/home/piscataway/.config/rclone/rclone.conf sync web/files/ cloudflare:piscataway
