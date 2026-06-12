#!/bin/bash

cd /home/piscataway/

#DEST="www.piscatawaynjmeetings.com"
DEST="185.101.97.102"

echo "Generating DBs"
pushd data
rm -f cad_calls.*
php ../scripts/cad_calls.php > cad_calls.csv
/home/piscataway/bin/csv-to-sqlite --file cad_calls.csv -o cad_calls.db
popd

echo "Syncing content"
rsync --copy-links -rt --exclude=web/files .git classes config.php init.php templates vendor web scripts data output datasette.service datasette_metadata.json root@$DEST:/home/piscataway/
# sync only transcripts, the rest are too big!
find ./web/ -iname \*.txt | rsync -rt --files-from=/dev/stdin . root@185.101.97.102:/home/piscataway/

echo "Importing files"
php scripts/import_files.php
php scripts/monitor_revai_progress.php

echo "Rebuilding speaker embeddings"
venv/bin/python scripts/build_speaker_profiles.py

echo "Identifying speakers"
bash scripts/batch_identify_speakers.sh council
rsync -rt shared/speakers/ root@$DEST:/home/piscataway/shared/speakers/

mysqldump --add-drop-table --ignore-table=piscataway.textcopy piscataway > piscataway.sql
scp piscataway.sql root@$DEST:/root/
ssh root@$DEST 'mysql piscataway < piscataway.sql'

echo "Dumping textcopy from remote"
ssh root@$DEST 'mysqldump --add-drop-table piscataway textcopy > /root/textcopy.sql'
scp root@$DEST:/root/textcopy.sql ./textcopy.sql
rsync -a root@$DEST:/root/pway_visiting_site ./
rsync -a root@$DEST:/var/log/nginx ./access_logs
cat textcopy.sql | mysql piscataway

echo "Done with main file sync, syncing to S3"
rclone -v --delete-excluded --exclude=**youtube** --fast-list -L --config=/home/piscataway/.config/rclone/rclone.conf sync web/files/ cloudflare:piscataway

echo "Starting transcription"
php scripts/transcribe_meetings.php

echo "Restarting datasette"
ssh root@$DEST "cp /home/piscataway/datasette.service /etc/systemd/system/; systemctl daemon-reload; systemctl restart datasette"
systemctl restart datasette
