#!/usr/bin/env python3
"""Generate WaveSurfer.js-compatible peaks JSON from an audio file via ffmpeg.

Usage: generate_waveform.py <input_audio> <output_json>

Output format: {"peaks": [[min0, max0, min1, max1, ...]]}
Each min/max pair covers one second of audio, values normalized to [-1.0, 1.0].
"""
import sys
import json
import struct
import subprocess

SAMPLE_RATE = 200   # Hz for ffmpeg extraction
WINDOW_SIZE = 200   # samples per peak pair = 1 second resolution


def generate_peaks(input_path: str, output_path: str) -> None:
    cmd = [
        'ffmpeg', '-i', input_path,
        '-ac', '1',
        '-ar', str(SAMPLE_RATE),
        '-f', 's16le',
        '-loglevel', 'error',
        'pipe:1',
    ]
    result = subprocess.run(cmd, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
    if result.returncode != 0:
        print(f'ffmpeg error: {result.stderr.decode().strip()}', file=sys.stderr)
        sys.exit(1)

    raw = result.stdout
    count = len(raw) // 2
    samples = struct.unpack(f'<{count}h', raw[:count * 2])

    peaks = []
    for i in range(0, len(samples), WINDOW_SIZE):
        chunk = samples[i:i + WINDOW_SIZE]
        if not chunk:
            continue
        mn = round(min(chunk) / 32768.0, 4)
        mx = round(max(chunk) / 32768.0, 4)
        peaks.append(mn)
        peaks.append(mx)

    with open(output_path, 'w') as f:
        json.dump({'peaks': [peaks]}, f, separators=(',', ':'))

    duration_sec = len(samples) / SAMPLE_RATE
    print(f'  {len(peaks) // 2} peak pairs, {duration_sec:.0f}s audio -> {output_path}')


if __name__ == '__main__':
    if len(sys.argv) != 3:
        print(f'Usage: {sys.argv[0]} <input_audio> <output_json>', file=sys.stderr)
        sys.exit(1)
    generate_peaks(sys.argv[1], sys.argv[2])
