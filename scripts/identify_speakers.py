#!/usr/bin/env python3
"""
Identify speakers in a meeting transcript using pyannote embeddings.

Reads:  web/files/{board}/{date}.revai.json   - Rev.ai JSON with word-level speaker IDs (preferred)
        web/files/{board}/{date}.txt          - Plain transcript (fallback; uses line timestamps)
        web/files/{board}/{date}.mp3/.m4a     - Meeting recording
        data/speakers/profiles.json           - Known speaker profiles with embeddings

Writes: output/speakers/{board}/{date}.speakers.json
        e.g. {"0": {"name": "Shawn Cahill (Chair)", "confidence": 0.91}, "1": null, ...}

Usage:
    python3 identify_speakers.py zoning 2026-04-23 [--threshold 0.75] [--dry-run]
"""
import argparse
import ctypes
import json
import os
import subprocess
import sys
import tempfile
import warnings

warnings.filterwarnings('ignore')

# Must run before torch import so the UR level-zero adapter can enumerate the GPU
try:
    ctypes.CDLL('libze_loader.so.1').zeInit(1)
except OSError:
    pass

BASE_DIR   = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TOKEN_FILE = os.path.join(BASE_DIR, 'data', 'hf_token')
PROFILES   = os.path.join(BASE_DIR, 'data', 'speakers', 'profiles.json')

MIN_CLIP_SECONDS = 2.0   # ignore segments shorter than this when building embedding
DEFAULT_THRESHOLD = 0.75  # cosine similarity threshold for a confident match


def load_token() -> str:
    if os.path.exists(TOKEN_FILE):
        return open(TOKEN_FILE).read().strip()
    return os.environ.get('HF_TOKEN', '')


def load_profiles() -> dict:
    if not os.path.exists(PROFILES):
        return {}
    with open(PROFILES) as f:
        return json.load(f).get('speakers', {})


def recording_path(board: str, date: str) -> str:
    for ext in ('mp3', 'm4a'):
        p = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.{ext}')
        if os.path.exists(p):
            return p
    raise FileNotFoundError(f'No recording found for {board}/{date}')


def extract_wav(recording: str, segments: list[tuple[float, float]]) -> bytes:
    """Extract multiple time segments from a recording, concatenate as 16kHz mono WAV."""
    parts = []
    for start, end in segments:
        if end - start < MIN_CLIP_SECONDS:
            continue
        result = subprocess.run([
            'ffmpeg', '-y', '-loglevel', 'error',
            '-i', recording,
            '-ss', str(start), '-to', str(end),
            '-ac', '1', '-ar', '16000',
            '-f', 'wav', 'pipe:1',
        ], capture_output=True)
        if result.returncode == 0:
            parts.append(result.stdout)

    if not parts:
        return b''

    if len(parts) == 1:
        return parts[0]

    # Concatenate via ffmpeg concat filter
    with tempfile.TemporaryDirectory() as tmp:
        inputs  = []
        filters = []
        for i, wav in enumerate(parts):
            p = os.path.join(tmp, f'part{i}.wav')
            with open(p, 'wb') as f:
                f.write(wav)
            inputs.extend(['-i', p])
            filters.append(f'[{i}:a]')

        filter_str = ''.join(filters) + f'concat=n={len(parts)}:v=0:a=1[out]'
        out_path   = os.path.join(tmp, 'concat.wav')
        result     = subprocess.run(
            ['ffmpeg', '-y', '-loglevel', 'error'] + inputs +
            ['-filter_complex', filter_str, '-map', '[out]', out_path],
            capture_output=True,
        )
        if result.returncode != 0:
            # Fall back to using just the first segment
            return parts[0]
        with open(out_path, 'rb') as f:
            return f.read()


def compute_embedding(wav_bytes: bytes, embedder) -> list | None:
    if not wav_bytes:
        return None
    import io
    import numpy as np
    import soundfile as sf
    import torch
    try:
        wav_io   = io.BytesIO(wav_bytes)
        waveform, sr = sf.read(wav_io, dtype='float32')
        tensor   = torch.tensor(waveform).unsqueeze(0).unsqueeze(0)
        with torch.no_grad():
            emb = embedder(tensor)
        arr = emb.squeeze()
        if hasattr(arr, 'numpy'):
            arr = arr.numpy()
        arr = arr / (np.linalg.norm(arr) + 1e-8)
        return arr.tolist()
    except Exception as e:
        print(f'    WARNING: embedding failed: {e}')
        return None


def cosine_similarity(a: list, b: list) -> float:
    import numpy as np
    va, vb = np.array(a), np.array(b)
    return float(np.dot(va, vb) / (np.linalg.norm(va) * np.linalg.norm(vb) + 1e-8))


def load_model(token: str):
    import torch
    from pyannote.audio import Model
    from pyannote.audio.pipelines.speaker_verification import PretrainedSpeakerEmbedding
    device = torch.device('xpu') if torch.xpu.is_available() else torch.device('cpu')
    model = Model.from_pretrained('pyannote/embedding', use_auth_token=token)
    model = model.to(device)
    return PretrainedSpeakerEmbedding(embedding=model, device=device)


def parse_revai_segments(revai: dict) -> dict[int, list[tuple[float, float]]]:
    """Return {speaker_id: [(start, end), ...]} from Rev.ai JSON."""
    segments: dict[int, list[tuple[float, float]]] = {}
    for mono in revai.get('monologues', []):
        sid      = mono['speaker']
        elements = [e for e in mono['elements'] if e['type'] == 'text' and 'ts' in e]
        if not elements:
            continue
        start = elements[0]['ts']
        end   = elements[-1]['end_ts']
        segments.setdefault(sid, []).append((start, end))
    return segments


def parse_txt_segments(txt_path: str) -> dict[int, list[tuple[float, float]]]:
    """Return {speaker_num: [(start, end), ...]} from a plain .txt transcript.

    Uses each line's timestamp as segment start; end = next line's timestamp.
    The last segment gets a 60s window (ffmpeg clips at EOF regardless).
    Boundary imprecision (~1 word bleed) is acceptable for voice embeddings.
    """
    import re
    lines = []
    with open(txt_path) as f:
        for line in f:
            m = re.match(r'Speaker\s+(\d+)\s+(\d+):(\d+):(\d+)', line)
            if m:
                spk  = int(m.group(1))
                secs = int(m.group(2)) * 3600 + int(m.group(3)) * 60 + int(m.group(4))
                lines.append((spk, secs))

    segments: dict[int, list[tuple[float, float]]] = {}
    for i, (spk, start) in enumerate(lines):
        end = lines[i + 1][1] if i + 1 < len(lines) else start + 60
        if end > start:
            segments.setdefault(spk, []).append((float(start), float(end)))
    return segments


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('board')
    parser.add_argument('date')
    parser.add_argument('--threshold', type=float, default=DEFAULT_THRESHOLD,
                        help=f'Cosine similarity threshold (default {DEFAULT_THRESHOLD})')
    parser.add_argument('--dry-run', action='store_true', help='Print results but do not write .speakers.json')
    args = parser.parse_args()

    board, date = args.board, args.date

    revai_path = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.revai.json')
    txt_path   = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.txt')

    out_dir       = os.path.join(BASE_DIR, 'output', 'speakers', board)
    os.makedirs(out_dir, exist_ok=True)
    speakers_path = os.path.join(out_dir, f'{date}.speakers.json')

    profiles = load_profiles()
    known    = {sid: info for sid, info in profiles.items() if info.get('embedding')}
    if not known:
        print('No speaker profiles with embeddings found. Run build_speaker_profiles.py first.')
        sys.exit(1)

    token = load_token()
    if not token:
        print('HuggingFace token not found.')
        sys.exit(1)

    rec = recording_path(board, date)

    if os.path.exists(revai_path):
        with open(revai_path) as f:
            revai = json.load(f)
        segments = parse_revai_segments(revai)
        source   = 'revai'
    elif os.path.exists(txt_path):
        segments = parse_txt_segments(txt_path)
        source   = 'txt'
        print(f'NOTE: No .revai.json found, using .txt timestamps (lower boundary precision)')
    else:
        print(f'ERROR: No transcript found for {board}/{date}')
        sys.exit(1)

    print(f'{board}/{date}: {len(segments)} speaker(s), {len(known)} known profile(s) [{source}]')
    print('Loading pyannote model...')
    embedder = load_model(token)
    print('Model ready.\n')

    results: dict[str, dict | None] = {}

    for speaker_num in sorted(segments.keys()):
        segs  = segments[speaker_num]
        total = sum(e - s for s, e in segs)
        print(f'  Speaker {speaker_num}: {len(segs)} segment(s), {total:.1f}s total')

        wav   = extract_wav(rec, segs)
        emb   = compute_embedding(wav, embedder)
        if emb is None:
            print(f'    Could not compute embedding (too little audio?)')
            results[str(speaker_num)] = None
            continue

        best_sid   = None
        best_score = 0.0
        for sid, info in known.items():
            score = cosine_similarity(emb, info['embedding'])
            if score > best_score:
                best_score = score
                best_sid   = sid

        if best_sid and best_score >= args.threshold:
            name = known[best_sid]['name']
            print(f'    → {name}  (score={best_score:.3f})')
            results[str(speaker_num)] = {'name': name, 'speaker_id': best_sid, 'confidence': round(best_score, 3)}
        else:
            best_name = known[best_sid]['name'] if best_sid else '?'
            print(f'    → unidentified  (best: {best_name} @ {best_score:.3f}, below threshold {args.threshold})')
            results[str(speaker_num)] = None

    print()
    if args.dry_run:
        print('Dry run — not writing output.')
        print(json.dumps(results, indent=2))
    else:
        with open(speakers_path, 'w') as f:
            json.dump(results, f, indent=2)
        print(f'Written: {speakers_path}')


if __name__ == '__main__':
    main()
