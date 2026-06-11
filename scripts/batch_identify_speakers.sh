#!/bin/bash
# Run identify_speakers.py for all meetings that have a .revai.json (or .txt fallback),
# a local recording, and no .speakers.json output yet.
#
# Usage: batch_identify_speakers.sh [board ...]  (default: council planning zoning)
#
# Skips silently if no local recording exists (recording may be on Cloudflare only).

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="$SCRIPT_DIR/.."
FILES_DIR="$BASE_DIR/web/files"
OUTPUT_DIR="$BASE_DIR/output/speakers"

if [ $# -gt 0 ]; then
	BOARDS=("$@")
else
	BOARDS=("council" "planning" "zoning")
fi

done_count=0
skipped_count=0
errors=0

for board in "${BOARDS[@]}"; do
	board_files="$FILES_DIR/$board"
	board_output="$OUTPUT_DIR/$board"

	shopt -s nullglob
	revai_files=("$board_files"/*.revai.json)
	shopt -u nullglob

	if [ ${#revai_files[@]} -eq 0 ]; then
		echo "[$board] No .revai.json files found, skipping."
		continue
	fi

	echo "[$board] Found ${#revai_files[@]} .revai.json file(s)."

	for revai in "${revai_files[@]}"; do
		date=$(basename "$revai" .revai.json)
		speakers_file="$board_output/$date.speakers.json"

		if [ -f "$speakers_file" ]; then
			echo "[$board/$date] already done, skipping"
			(( skipped_count++ )) || true
			continue
		fi

		# Require a local recording
		if [ ! -f "$board_files/$date.mp3" ] && [ ! -f "$board_files/$date.m4a" ]; then
			echo "[$board/$date] no local recording, skipping"
			(( skipped_count++ )) || true
			continue
		fi

		echo "[$board/$date] identifying speakers..."
		if "$BASE_DIR/venv/bin/python3" "$SCRIPT_DIR/identify_speakers.py" "$board" "$date"; then
			(( done_count++ )) || true
		else
			echo "[$board/$date] FAILED (exit $?)"
			(( errors++ )) || true
		fi
	done
done

echo ""
echo "Done: $done_count  Skipped: $skipped_count  Errors: $errors"
