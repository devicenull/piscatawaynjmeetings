#!/usr/bin/python3

import requests
import scrapetube
import subprocess

videos = scrapetube.get_channel('UClvOfAfDVKKd8T-becTCVow')
for video in videos:
	data = requests.get('https://www.googleapis.com/youtube/v3/videos?id=%s&part=snippet,statistics,recordingDetails&key=AIzaSyBmQcXmAHD2h5ZurlNKHvHRwMVHbBQqbvc' % video['videoId']).json()
	#print("%s %s %s" % (video['videoId'], video['title']['runs'][0]['text'], data['items'][0]['snippet']['publishedAt']))

	subprocess.call(['php', 'import_youtube.php', video['videoId'], video['title']['runs'][0]['text'], data['items'][0]['snippet']['publishedAt']])
