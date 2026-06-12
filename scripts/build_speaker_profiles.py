#!/usr/bin/env python3
"""
Build or update speaker embedding profiles from registered audio clips.

Reads:  data/speakers/profiles.json   - speaker metadata + clip list
Writes: data/speakers/profiles.json   - updated with embedding arrays
        (embeddings stored inline as lists for simplicity)

Usage:
    python3 build_speaker_profiles.py [--speaker SPEAKER_ID]
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
SHARED_DIR = os.path.join(BASE_DIR, 'shared')
TOKEN_FILE = os.path.join(BASE_DIR, 'data', 'hf_token')
PROFILES   = os.path.join(SHARED_DIR, 'speakers', 'profiles.json')


def load_token() -> str:
    if os.path.exists(TOKEN_FILE):
        return open(TOKEN_FILE).read().strip()
    return os.environ.get('HF_TOKEN', '')


def extract_clip(recording_path: str, start: float, end: float) -> bytes:
    """Extract audio segment as 16kHz mono WAV bytes via ffmpeg."""
    result = subprocess.run([
        'ffmpeg', '-y', '-loglevel', 'error',
        '-i', recording_path,
        '-ss', str(start), '-to', str(end),
        '-ac', '1', '-ar', '16000',
        '-f', 'wav', 'pipe:1',
    ], capture_output=True)
    if result.returncode != 0:
        raise RuntimeError(f'ffmpeg failed: {result.stderr.decode()}')
    return result.stdout


def load_model(token: str):
    import torch
    from pyannote.audio import Model
    from pyannote.audio.pipelines.speaker_verification import PretrainedSpeakerEmbedding
    device = torch.device('xpu') if torch.xpu.is_available() else torch.device('cpu')
    model = Model.from_pretrained('pyannote/embedding', use_auth_token=token)
    model = model.to(device)
    return PretrainedSpeakerEmbedding(embedding=model, device=device)


def embed_wav_bytes(wav_bytes: bytes, embedder) -> list:
    import io
    import numpy as np
    import soundfile as sf
    import torch
    wav_io = io.BytesIO(wav_bytes)
    waveform, sr = sf.read(wav_io, dtype='float32')
    tensor = torch.tensor(waveform).unsqueeze(0).unsqueeze(0)  # (1, 1, samples)
    with torch.no_grad():
        emb = embedder(tensor)
    arr = emb.squeeze()
    if hasattr(arr, 'numpy'):
        arr = arr.numpy()
    arr = arr / (np.linalg.norm(arr) + 1e-8)
    return arr.tolist()


def recording_path(board: str, date: str) -> str:
    for ext in ('mp3', 'm4a'):
        p = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.{ext}')
        if os.path.exists(p):
            return p
    raise FileNotFoundError(f'No recording found for {board}/{date}')


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--speaker', help='Only rebuild this speaker ID')
    args = parser.parse_args()

    if not os.path.exists(PROFILES):
        print('No profiles.json found. Run extract_speaker_clips.py first.')
        sys.exit(1)

    with open(PROFILES) as f:
        data = json.load(f)

    token = load_token()
    if not token:
        print('HuggingFace token not found. Set HF_TOKEN or populate data/hf_token.')
        sys.exit(1)

    speakers = data.get('speakers', {})
    changed  = False

    if args.speaker:
        # Explicit target: always rebuild, even if embedding already exists
        needs_rebuild = [args.speaker] if args.speaker in speakers else []
    else:
        needs_rebuild = [
            sid for sid, info in speakers.items()
            if info.get('clips') and info.get('embedding') is None
        ]
    if not needs_rebuild:
        print('All embeddings up to date, nothing to do.')
        sys.exit(0)

    print(f'{len(needs_rebuild)} speaker(s) need rebuilding: {", ".join(needs_rebuild)}')
    print('Loading pyannote embedding model...')
    embedder = load_model(token)
    print('Model ready.')

    for sid, info in speakers.items():
        if args.speaker and sid != args.speaker:
            continue

        clips = info.get('clips', [])
        if not clips:
            print(f'  {sid}: no clips, skipping')
            continue

        if info.get('embedding') is not None and sid not in needs_rebuild:
            continue

        print(f'  {sid} ({info["name"]}): {len(clips)} clip(s)...')
        all_wav = b''
        skipped = 0
        for clip in clips:
            # clip format: "board/date:start-end"  e.g. "zoning/2026-04-23:244.5-252.0"
            try:
                loc, timerange = clip.rsplit(':', 1)
                board, date    = loc.split('/')
                start, end     = map(float, timerange.split('-'))
                rec            = recording_path(board, date)
                all_wav       += extract_clip(rec, start, end)
            except Exception as e:
                print(f'    WARNING: skipping clip {clip!r}: {e}')
                skipped += 1

        if not all_wav:
            print(f'    No usable clips for {sid}, skipping.')
            continue

        with tempfile.NamedTemporaryFile(suffix='.wav', delete=False) as tmp:
            tmp.write(all_wav)
            tmp_path = tmp.name

        try:
            # Re-encode concatenated audio to ensure valid WAV header
            result = subprocess.run([
                'ffmpeg', '-y', '-loglevel', 'error',
                '-i', tmp_path,
                '-ac', '1', '-ar', '16000',
                '-f', 'wav', tmp_path + '.clean.wav',
            ], capture_output=True)
            clean_path = tmp_path + '.clean.wav'
            with open(clean_path, 'rb') as f:
                clean_wav = f.read()
            emb = embed_wav_bytes(clean_wav, embedder)
        finally:
            os.unlink(tmp_path)
            if os.path.exists(tmp_path + '.clean.wav'):
                os.unlink(tmp_path + '.clean.wav')

        info['embedding'] = emb
        changed = True
        print(f'    embedding updated ({len(emb)}-d, {skipped} clip(s) skipped)')

    if changed:
        with open(PROFILES, 'w') as f:
            json.dump(data, f, indent=2)
        print(f'\nProfiles saved to {PROFILES}')
    else:
        print('\nNothing changed.')


if __name__ == '__main__':
    main()
