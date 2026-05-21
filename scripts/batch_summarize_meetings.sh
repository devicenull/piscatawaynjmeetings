#!/bin/bash
# Summarize all zoning meetings that have transcripts but no sections file yet.
# Writes output to output/zoning-YYYY-MM-DD.sections.json
#
# Exit codes from generate_meeting_summary.php:
#   0  success
#   1  error (skipped)
#   2  rate limited (retried after RATE_LIMIT_SLEEP seconds)

set -uo pipefail

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PHP_SCRIPT="$SCRIPT_DIR/generate_meeting_summary.php"
OUTPUT_DIR="$SCRIPT_DIR/../output"
TRANSCRIPT_DIR="$SCRIPT_DIR/../web/files/zoning"

SLEEP_BETWEEN=30       # seconds to wait between successful runs
RATE_LIMIT_SLEEP=300   # seconds to wait after a rate-limit response
MAX_RETRIES=5

shopt -s nullglob
txts=("$TRANSCRIPT_DIR"/*.txt)

if [ ${#txts[@]} -eq 0 ]; then
	echo "No transcripts found in $TRANSCRIPT_DIR"
	exit 0
fi

# Reverse so newest date is processed first
for (( i=0, j=${#txts[@]}-1; i<j; i++, j-- )); do
	tmp="${txts[$i]}"; txts[$i]="${txts[$j]}"; txts[$j]="$tmp"
done

total=${#txts[@]}
done_count=0
skipped=0
errors=0

echo "Found $total transcript(s)."
echo ""

for txt in "${txts[@]}"; do
	date=$(basename "$txt" .txt)
	sections_file="$OUTPUT_DIR/zoning-$date.sections.json"

	if [ -f "$sections_file" ]; then
		echo "[$date] already done, skipping"
		(( done_count++ )) || true
		continue
	fi

	retries=0
	while true; do
		echo "[$date] summarizing... (attempt $((retries + 1)))"
		php "$PHP_SCRIPT" --file "$txt"
		rc=$?

		if [ $rc -eq 0 ]; then
			(( done_count++ )) || true
			echo "[$date] done. Sleeping ${SLEEP_BETWEEN}s..."
			sleep "$SLEEP_BETWEEN"
			break
		elif [ $rc -eq 2 ]; then
			(( retries++ )) || true
			if [ $retries -ge $MAX_RETRIES ]; then
				echo "[$date] rate limited $MAX_RETRIES times, giving up."
				(( errors++ )) || true
				break
			fi
			echo "[$date] rate limited. Sleeping ${RATE_LIMIT_SLEEP}s before retry..."
			sleep "$RATE_LIMIT_SLEEP"
		else
			echo "[$date] error (exit $rc), skipping."
			(( errors++ )) || true
			break
		fi
	done
done

echo ""
echo "Done: $done_count  Errors: $errors"
