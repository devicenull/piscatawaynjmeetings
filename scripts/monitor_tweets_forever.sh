#!/bin/bash

# In theory, systemd could do this... in practice I've never gotten it to work reliably enough
while [ 1==1 ]; do
	/usr/bin/php /home/piscataway/scripts/monitor_tweets.php
	sleep 60
done
