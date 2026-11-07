#!/usr/bin/env python3
"""
Build or update speaker embedding profiles from registered audio clips.

Each profile is the average of its clips' embeddings. Clips under 3 seconds ("here", "yes")
are left out, because they say too little about a voice.

Reads:  shared/speakers/profiles.json   - speaker metadata + clip list
Writes: shared/speakers/profiles.json   - updated with embedding arrays
        (embeddings stored inline as lists for simplicity)

Usage:
    python3 build_speaker_profiles.py [--speaker SPEAKER_ID] [--all]
"""
import argparse
import json
import os
import subprocess
import sys

import numpy as np

from identify_speakers import PROFILES, SAMPLE_RATE, embed, load_model, load_token, recording_path

MIN_CLIP_SECONDS = 3.0


def parse_clip(clip: str) -> tuple[str, str, float, float]:
    """Clip format: "board/date:start-end", e.g. "zoning/2026-04-23:244.5-252.0"."""
    loc, timerange = clip.rsplit(':', 1)
    board, date    = loc.split('/')
    start, end     = map(float, timerange.split('-'))
    return board, date, start, end


def usable(clips: list) -> list:
    return [c for c in clips if parse_clip(c)[3] - parse_clip(c)[2] >= MIN_CLIP_SECONDS]


def extract_clip(recording: str, start: float, end: float):
    out = subprocess.run(['ffmpeg', '-loglevel', 'error', '-ss', str(start), '-t', str(end - start), '-i', recording,
                          '-ac', '1', '-ar', str(SAMPLE_RATE), '-f', 'f32le', 'pipe:1'], capture_output=True, check=True).stdout
    return np.frombuffer(out, dtype=np.float32)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--speaker', help='Only rebuild this speaker ID')
    parser.add_argument('--all', action='store_true', help='Rebuild every speaker')
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
    if args.speaker:
        # Explicit target: always rebuild, even if embedding already exists
        needs_rebuild = [args.speaker] if args.speaker in speakers else []
    else:
        needs_rebuild = [sid for sid, info in speakers.items()
                         if usable(info.get('clips', [])) and (args.all or info.get('embedding') is None)]
    if not needs_rebuild:
        print('All embeddings up to date, nothing to do.')
        sys.exit(0)

    print(f'{len(needs_rebuild)} speaker(s) need rebuilding: {", ".join(needs_rebuild)}')
    embedder = load_model(token)

    changed = False
    for sid in needs_rebuild:
        info  = speakers[sid]
        clips = usable(info.get('clips', []))
        print(f'  {sid} ({info["name"]}): {len(clips)} of {len(info.get("clips", []))} clip(s) long enough')
        embeddings = []
        for clip in clips:
            try:
                board, date, start, end = parse_clip(clip)
                samples = extract_clip(recording_path(board, date), start, end)
                if len(samples) >= MIN_CLIP_SECONDS * SAMPLE_RATE:
                    embeddings.append(embed(samples, embedder))
            except (FileNotFoundError, subprocess.CalledProcessError) as e:
                print(f'    WARNING: skipping clip {clip!r}: {e}')

        if not embeddings:
            print(f'    No usable clips for {sid}, skipping.')
            continue

        mean = np.mean(embeddings, axis=0)
        info['embedding'] = (mean / np.linalg.norm(mean)).tolist()
        changed = True
        print(f'    embedding updated ({len(info["embedding"])}-d)')

    if changed:
        with open(PROFILES, 'w') as f:
            json.dump(data, f, indent=2)
        print(f'\nProfiles saved to {PROFILES}')
    else:
        print('\nNothing changed.')


if __name__ == '__main__':
    main()
