#!/usr/bin/python3

import csv
import re
import sys

def normalize_address(address):
	# fix "98RT4" into multiple words
	address = re.sub('([0-9]+)([A-Z].+)', r'\1 \2', address)
	address = re.sub('(.[A-Z]+)([0-9]+)', r'\1 \2', address)
	return address

def normalize_reason(reason):
	reasons = {
		'AREACK': 'AREA CHECK',
	}
	for find,repl in reasons.items():
		if reason == find:
			return repl

	return reason	

with open(sys.argv[1], 'r') as f:
	out = csv.writer(open('out.csv', 'a', encoding='ASCII'))
	for line in f:
		# 18008545 02/22/2018 21:43:47 26 WESTMINSTER BL               WARR
		# tesseract loves to add random periods, luckily they dont appear in what we care about
		line = line.strip().replace('.', '').replace('=', '')
		if line == "":
			continue

		res = re.search('([0-9]+)\s([0-9\/]+)\s([0-9\:]+)\s(.+)\s\s\s(.+)', line)
		if not res:
			print("NO MATCH: %s" % line)
			continue

		data = [
			res.group(1).strip(),
			res.group(2).strip()+' '+res.group(3).strip(),
			normalize_address(res.group(4).strip()),
			normalize_reason(res.group(5).strip()),
		]
		out.writerow(data)

