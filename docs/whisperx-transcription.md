# WhisperX Transcription

Replaces Rev.ai as the speech-to-text backend. WhisperX runs locally on the Intel Arc A380
GPU, eliminating per-minute costs and keeping audio data on-premises. It produces word-level
timestamps and speaker diarization compatible with the rest of the speaker identification
pipeline.

## How it fits in the pipeline

```
Meeting recording (MP3/M4A)
    ↓
transcribe_whisperx.py
    ↓
web/files/{board}/{date}.txt          ← plain transcript (same format as Rev.ai)
web/files/{board}/{date}.whisperx.json ← word-level timestamps + speaker diarization
    ↓
identify_speakers.py                  ← matches diarized speakers to known profiles
    ↓
shared/speakers/{board}/{date}.speakers.json ← name assignments
    ↓
Transcript page (Meeting.php)         ← renders with names + clickable timestamps
```

WhisperX handles both transcription and speaker diarization in one pass, using pyannote
internally — the same library already used for speaker identification. The diarization
assigns generic labels (`SPEAKER_00`, `SPEAKER_01`, …); `identify_speakers.py` then matches
those to real names via voice embeddings.

## Output format (`.whisperx.json`)

```json
{
  "segments": [
    {
      "start": 7.375,
      "end": 9.2,
      "text": "Board of adjustment",
      "speaker": "SPEAKER_00",
      "words": [
        {"word": "Board",      "start": 7.375, "end": 7.725, "score": 0.97},
        {"word": "of",         "start": 7.725, "end": 7.845, "score": 0.86},
        {"word": "adjustment", "start": 7.85,  "end": 9.2,   "score": 0.91}
      ]
    }
  ]
}
```

Speaker labels map to integer IDs: `SPEAKER_00` → 0, `SPEAKER_01` → 1, etc.

Old meetings keep their `.revai.json` files. All parsers try `.whisperx.json` first,
fall back to `.revai.json`, then `.txt`.

## Prerequisites

### Python environment

```bash
# Install WhisperX and Intel GPU support
uv pip install --python venv/bin/python whisperx intel_extension_for_pytorch
```

`intel_extension_for_pytorch` enables `torch.xpu` on the Intel Arc A380. WhisperX uses
`faster-whisper` (CTranslate2) for inference; see **GPU acceleration** below for how both
layers use the hardware.

### HuggingFace token

WhisperX diarization uses the same gated pyannote models as `identify_speakers.py`. The
token at `data/hf_token` is reused automatically — no additional setup needed.

## GPU acceleration

The system has an **Intel Arc A380** (6 GB GDDR6) at `/dev/dri/renderD128` with OpenCL 3.0
confirmed (`clinfo` shows the device).

### Layer 1 — pyannote diarization (torch.xpu via IPEX)

PyTorch with Intel Extension (`intel_extension_for_pytorch`) exposes the Arc A380 as an
XPU device:

```python
import torch
import intel_extension_for_pytorch  # noqa: F401 — registers XPU backend
print(torch.xpu.is_available())     # should print True after installation
```

If this still prints False after installing IPEX, check that the Level Zero driver is loaded:

```bash
# Should list the Arc A380
clinfo | grep "Device Name"

# Level Zero loader must be present
ldconfig -p | grep libze_loader
```

If `libze_loader.so.1` is missing, install the Intel compute runtime:

```bash
# Debian/Ubuntu
apt install intel-opencl-icd intel-level-zero-gpu level-zero
```

### Layer 2 — faster-whisper inference (CTranslate2)

CTranslate2 (which `faster-whisper` uses) ships CPU+CUDA wheels only. OpenCL support
requires building from source. Try `device="opencl"` first:

```python
from faster_whisper import WhisperModel
model = WhisperModel("large-v3-turbo", device="opencl")   # try this first
# if it raises, fall back to:
model = WhisperModel("large-v3-turbo", device="cpu", compute_type="int8")
```

CPU `int8` on the i7-9700 is still 3–4× faster than the original Whisper and adequate
for overnight batch processing of a 3-hour meeting.

`transcribe_whisperx.py` tries OpenCL first and falls back to CPU automatically.

## Transcribing a meeting

```bash
# Single meeting
venv/bin/python scripts/transcribe_whisperx.py council 2026-05-14

# All meetings with recordings but no transcript yet
bash scripts/batch_transcribe.sh

# Specific boards only
bash scripts/batch_transcribe.sh zoning planning
```

Output is written to `web/files/{board}/{date}.whisperx.json` and
`web/files/{board}/{date}.txt`. The DB record (`transcript_available`) is updated
automatically.

After transcribing, run speaker identification as usual:

```bash
venv/bin/python scripts/identify_speakers.py council 2026-05-14
```

## Model selection

`large-v3-turbo` is the default — near-identical accuracy to `large-v3` at roughly 8× the
speed. To use a different model:

```bash
venv/bin/python scripts/transcribe_whisperx.py council 2026-05-14 --model large-v3
```

Available models (faster-whisper): `tiny`, `base`, `small`, `medium`, `large-v2`,
`large-v3`, `large-v3-turbo`.

## Troubleshooting

**`No module named 'whisperx'`** — run the pip install above.

**`torch.xpu.is_available()` is False** — install IPEX and check the Level Zero driver
(see GPU acceleration above). WhisperX still runs on CPU in the meantime.

**`device="opencl"` raises** — CTranslate2 was built without OpenCL. The script falls
back to CPU automatically; no action needed.

**Alignment fails on a segment** — WhisperX falls back to segment-level timestamps when
word alignment fails. The transcript still renders correctly; clip extraction for that
segment uses the full segment boundaries.

**Diarization assigns too many speakers** — pass `--min-speakers` / `--max-speakers` to
constrain pyannote:

```bash
venv/bin/python scripts/transcribe_whisperx.py zoning 2026-04-23 --min-speakers 3 --max-speakers 12
```

**Old `.revai.json` meeting not rendering** — parsers fall back to `.revai.json`
automatically. If a meeting shows "Speaker N" labels instead of names, run
`identify_speakers.py` for that meeting and deploy.
