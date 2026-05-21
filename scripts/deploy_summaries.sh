#!/bin/bash
# Copy generated AI summaries from output/ into the web file tree.
# Source:      output/zoning-2026-02-26.sections.json
# Destination: web/files/zoning/2026-02-26.json

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
OUTPUT_DIR="$SCRIPT_DIR/../output"
WEB_FILES="$SCRIPT_DIR/../web/files"

shopt -s nullglob
files=("$OUTPUT_DIR"/*.sections.json)

if [ ${#files[@]} -eq 0 ]; then
	echo "No .sections.json files found in $OUTPUT_DIR"
	exit 0
fi

copied=0
skipped=0

for src in "${files[@]}"; do
	name=$(basename "$src" .sections.json)   # e.g. zoning-2026-02-26

	# Split on the date: everything before YYYY-MM-DD is the type
	if [[ "$name" =~ ^(.+)-([0-9]{4}-[0-9]{2}-[0-9]{2})$ ]]; then
		type="${BASH_REMATCH[1]}"
		date="${BASH_REMATCH[2]}"
	else
		echo "SKIP (unrecognised filename): $name"
		(( skipped++ )) || true
		continue
	fi

	dest_dir="$WEB_FILES/$type"
	if [ ! -d "$dest_dir" ]; then
		echo "SKIP (no such directory $dest_dir): $name"
		(( skipped++ )) || true
		continue
	fi

	dest="$dest_dir/$date.json"
	cp "$src" "$dest"
	echo "OK  $type/$date.json"
	(( copied++ )) || true
done

echo ""
echo "Deployed $copied file(s), skipped $skipped."
