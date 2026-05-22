#!/bin/bash
# Summarize all zoning and planning meetings that have transcripts but no sections file yet.
# Writes output to output/{type}-YYYY-MM-DD.sections.json
#
# Usage: batch_summarize_meetings.sh [zoning|planning]  (default: both)
#
# Exit codes from generate_meeting_summary.php:
#   0  success
#   1  error (skipped)
#   2  rate limited (retried after RATE_LIMIT_SLEEP seconds)

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_SCRIPT="$SCRIPT_DIR/generate_meeting_summary.php"
OUTPUT_DIR="$SCRIPT_DIR/../output"
FILES_DIR="$SCRIPT_DIR/../web/files"

SLEEP_BETWEEN=30       # seconds to wait between successful runs
RATE_LIMIT_SLEEP=300   # seconds to wait after a rate-limit response
MAX_RETRIES=5

# Determine which board types to process
if [ "${1:-}" = "zoning" ]; then
	BOARD_TYPES=("zoning")
elif [ "${1:-}" = "planning" ]; then
	BOARD_TYPES=("planning")
else
	BOARD_TYPES=("zoning" "planning")
fi

done_count=0
errors=0

process_transcript() {
	local txt="$1"
	local board_type="$2"
	local date
	date=$(basename "$txt" .txt)
	local sections_file="$OUTPUT_DIR/$board_type-$date.sections.json"

	if [ -f "$sections_file" ]; then
		echo "[$board_type/$date] already done, skipping"
		(( done_count++ )) || true
		return
	fi

	local retries=0
	while true; do
		echo "[$board_type/$date] summarizing... (attempt $((retries + 1)))"
		php "$PHP_SCRIPT" --file "$txt"
		local rc=$?

		if [ $rc -eq 0 ]; then
			(( done_count++ )) || true
			echo "[$board_type/$date] done. Sleeping ${SLEEP_BETWEEN}s..."
			sleep "$SLEEP_BETWEEN"
			break
		elif [ $rc -eq 2 ]; then
			(( retries++ )) || true
			if [ $retries -ge $MAX_RETRIES ]; then
				echo "[$board_type/$date] rate limited $MAX_RETRIES times, giving up."
				(( errors++ )) || true
				break
			fi
			echo "[$board_type/$date] rate limited. Sleeping ${RATE_LIMIT_SLEEP}s before retry..."
			sleep "$RATE_LIMIT_SLEEP"
		else
			echo "[$board_type/$date] error (exit $rc), skipping."
			(( errors++ )) || true
			break
		fi
	done
}

for board_type in "${BOARD_TYPES[@]}"; do
	transcript_dir="$FILES_DIR/$board_type"

	shopt -s nullglob
	txts=("$transcript_dir"/*.txt)
	shopt -u nullglob

	if [ ${#txts[@]} -eq 0 ]; then
		echo "No transcripts found in $transcript_dir"
		continue
	fi

	# Reverse so newest date is processed first
	for (( i=0, j=${#txts[@]}-1; i<j; i++, j-- )); do
		tmp="${txts[$i]}"; txts[$i]="${txts[$j]}"; txts[$j]="$tmp"
	done

	echo "[$board_type] Found ${#txts[@]} transcript(s)."
	echo ""

	for txt in "${txts[@]}"; do
		process_transcript "$txt" "$board_type"
	done
done

echo ""
echo "Done: $done_count  Errors: $errors"
