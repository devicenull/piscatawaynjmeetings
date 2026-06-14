#!/bin/bash
# Transcribe a meeting on the remote GPU machine (RTX 3080, 192.168.5.119:2222).
# The remote is treated as stateless: a venv is cached at /root/whisperx_venv and
# rebuilt automatically if missing.
#
# Usage: ./scripts/remote_transcribe.sh <board> <date> [extra whisperx args...]
#   e.g. ./scripts/remote_transcribe.sh zoning 2026-06-11
#        ./scripts/remote_transcribe.sh council 2026-05-14 --min-speakers 4 --max-speakers 8

set -euo pipefail

BOARD=${1:?Usage: $0 <board> <date> [whisperx args...]}
DATE=${2:?Usage: $0 <board> <date> [whisperx args...]}
shift 2

REMOTE_HOST="192.168.5.119"
REMOTE_PORT="2222"
SSH="ssh -p $REMOTE_PORT root@$REMOTE_HOST"

REMOTE_VENV="/root/whisperx_venv"
REMOTE_WORK="/root/whisperx"

LOCAL_BASE="$(cd "$(dirname "$0")/.." && pwd)"
SCP="scp -P $REMOTE_PORT"

# Find recording
RECORDING=""
for ext in mp3 m4a; do
    f="$LOCAL_BASE/web/files/$BOARD/$DATE.$ext"
    if [ -f "$f" ]; then
        RECORDING="$f"
        break
    fi
done

if [ -z "$RECORDING" ]; then
    echo "ERROR: No recording found for $BOARD/$DATE" >&2
    exit 1
fi

echo "=== [1/4] Setting up remote environment ==="
$SSH bash -s << 'SETUP'
set -euo pipefail
VENV="/root/whisperx_venv"

if [ -f "$VENV/bin/python" ] && "$VENV/bin/python" -c "import whisperx, torch; assert torch.cuda.is_available()" 2>/dev/null; then
    echo "Venv OK, skipping install"
    exit 0
fi

echo "Installing system packages..."
DEBIAN_FRONTEND=noninteractive apt-get install -y -qq ffmpeg python3.13-venv > /dev/null

echo "Creating venv..."
rm -rf "$VENV"
python3.13 -m venv "$VENV"

echo "Installing PyTorch with CUDA 12.8..."
"$VENV/bin/pip" install -q --upgrade pip
"$VENV/bin/pip" install -q torch torchaudio --index-url https://download.pytorch.org/whl/cu128

echo "Installing WhisperX..."
"$VENV/bin/pip" install -q whisperx

echo "Setup complete."
SETUP

echo "=== [2/4] Copying files to remote ==="
$SSH "mkdir -p $REMOTE_WORK/web/files/$BOARD $REMOTE_WORK/scripts $REMOTE_WORK/data"
$SCP "$RECORDING" "root@$REMOTE_HOST:$REMOTE_WORK/web/files/$BOARD/"
$SCP "$LOCAL_BASE/data/hf_token" "root@$REMOTE_HOST:$REMOTE_WORK/data/"
$SCP "$LOCAL_BASE/scripts/transcribe_whisperx.py" "root@$REMOTE_HOST:$REMOTE_WORK/scripts/"

echo "=== [3/4] Running transcription ==="
$SSH "cd $REMOTE_WORK && $REMOTE_VENV/bin/python scripts/transcribe_whisperx.py $BOARD $DATE $*"

echo "=== [4/4] Copying results back ==="
DEST="$LOCAL_BASE/web/files/$BOARD"
if ! touch "$DEST/.write_test" 2>/dev/null; then
    DEST="$LOCAL_BASE/shared/$BOARD"
    mkdir -p "$DEST"
    echo "NOTE: web/files is read-only, writing to shared/$BOARD/ instead"
fi
rm -f "$DEST/.write_test"

$SCP "root@$REMOTE_HOST:$REMOTE_WORK/web/files/$BOARD/$DATE.whisperx.json" "$DEST/"
$SCP "root@$REMOTE_HOST:$REMOTE_WORK/web/files/$BOARD/$DATE.txt" "$DEST/"

echo ""
echo "Done: $DEST/$DATE.{whisperx.json,txt}"
