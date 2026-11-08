#!/bin/bash
# Run identify_speakers.py for all 2026 meetings that have a transcript (identify_speakers.py
# uses the .revai.json when present, else the .txt), a local recording, and no up-to-date
# .turns.json output.
#
# Usage: batch_identify_speakers.sh [board ...]  (default: council planning zoning)
#
# Skips silently if no local recording exists (recording may be on Cloudflare only).

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
BASE_DIR="$SCRIPT_DIR/.."
FILES_DIR="$BASE_DIR/web/files"
OUTPUT_DIR="$BASE_DIR/shared/speakers"

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
	transcripts=("$board_files"/2026-*.txt)
	shopt -u nullglob

	if [ ${#transcripts[@]} -eq 0 ]; then
		echo "[$board] No 2026 transcripts found, skipping."
		continue
	fi

	echo "[$board] Found ${#transcripts[@]} 2026 transcript(s)."

	for transcript in "${transcripts[@]}"; do
		date=$(basename "$transcript" .txt)
		speakers_file="$board_output/$date.speakers.json"
		turns_file="$board_output/$date.turns.json"

		# Rerun when profiles or manual labels changed since this meeting was last named.
		if [ -f "$turns_file" ] && [ "$turns_file" -nt "$OUTPUT_DIR/profiles.json" ] && { [ ! -f "$speakers_file" ] || [ ! "$speakers_file" -nt "$turns_file" ]; }; then
			echo "[$board/$date] up to date, skipping"
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
