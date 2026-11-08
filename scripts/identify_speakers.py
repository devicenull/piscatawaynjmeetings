#!/usr/bin/env python3
"""
Identify who is speaking in each turn of a meeting transcript, using voice embeddings.

Rev.ai sometimes gives several people one speaker number, so each turn is named on its own.
A turn too short to name by voice takes its number's name, if that number is one person.

Reads:  web/files/{board}/{date}.whisperx.json or .revai.json  - speaker turns (preferred)
        web/files/{board}/{date}.txt          - Plain transcript (fallback; uses line timestamps)
        web/files/{board}/{date}.mp3/.m4a     - Meeting recording
        shared/speakers/profiles.json         - Known speaker profiles with embeddings

Writes: shared/speakers/{board}/{date}.speakers.json  - name per speaker number
        shared/speakers/{board}/{date}.turns.json     - name per turn (what transcripts display)
        data/speaker_embeddings/{board}/{date}.npz    - embedding cache, so reruns skip the audio

Usage:
    python3 identify_speakers.py zoning 2026-04-23 [--threshold 0.6] [--dry-run]
    python3 identify_speakers.py --self-check
"""
import argparse
import json
import os
import subprocess
import sys
import warnings

warnings.filterwarnings('ignore')

BASE_DIR    = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SHARED_DIR  = os.path.join(BASE_DIR, 'shared')
TOKEN_FILE  = os.path.join(BASE_DIR, 'data', 'hf_token')
PROFILES    = os.path.join(SHARED_DIR, 'speakers', 'profiles.json')
CACHE_DIR   = os.path.join(BASE_DIR, 'data', 'speaker_embeddings')

EMBEDDING_MODEL   = 'pyannote/wespeaker-voxceleb-resnet34-LM'
SAMPLE_RATE       = 16000
MIN_CHUNK_SECONDS = 1.5   # shorter audio can't be named reliably
MAX_CHUNK_SECONDS = 10.0  # long turns are scored in pieces
DEFAULT_THRESHOLD = 0.60  # cosine similarity needed to name a chunk
PURE_NUMBER_SHARE = 0.80  # share of a number's named speech one person needs to own the number
MANUAL_MARGIN     = 0.20  # how much closer another voice must be to override a manual label


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


def recording_duration(recording: str) -> float:
    out = subprocess.run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', recording],
                         capture_output=True, check=True, text=True).stdout
    return float(out)


def load_model(token: str):
    # CPU on purpose: this card also serves other things on the host and compute work has wedged it.
    import torch
    from pyannote.audio.pipelines.speaker_verification import PretrainedSpeakerEmbedding
    torch.set_num_threads(4)
    return PretrainedSpeakerEmbedding(EMBEDDING_MODEL, device=torch.device('cpu'), token=token)


def embed(samples, embedder):
    """Unit-length embedding of float32 mono samples."""
    import numpy as np
    import torch
    with torch.no_grad():
        arr = np.asarray(embedder(torch.tensor(samples.copy())[None, None])).squeeze()
    return arr / (np.linalg.norm(arr) + 1e-8)


def load_audio(recording: str):
    import numpy as np
    out = subprocess.run(['ffmpeg', '-loglevel', 'error', '-i', recording, '-ac', '1', '-ar', str(SAMPLE_RATE),
                          '-f', 'f32le', 'pipe:1'], capture_output=True, check=True).stdout
    return np.frombuffer(out, dtype=np.float32)


def parse_whisperx_segments(data: dict) -> dict[int, list[tuple[float, float]]]:
    """Return {speaker_num: [(start, end), ...]} from WhisperX JSON."""
    segments: dict[int, list[tuple[float, float]]] = {}
    for seg in data.get('segments', []):
        label = seg.get('speaker', 'SPEAKER_00')
        sid   = int(label.split('_')[-1])
        segments.setdefault(sid, []).append((float(seg['start']), float(seg['end'])))
    return segments


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
    The last segment gets a 60s window; main() trims turns to the recording's length.
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


def split_chunks(start: float, end: float) -> list[tuple[float, float]]:
    """Pieces of a turn to embed. Empty when the turn is too short to name."""
    chunks = []
    t = start
    while end - t >= MIN_CHUNK_SECONDS:
        u = min(t + MAX_CHUNK_SECONDS, end)
        if end - u < MIN_CHUNK_SECONDS:
            u = end
        chunks.append((t, u))
        t = u
    return chunks


def name_turn(chunks: list[tuple[float, str | None, float]]) -> tuple[str | None, float]:
    """Name from a turn's (seconds, matched name or None, score) chunks: needs most of its seconds."""
    # ponytail: one name per turn, so a turn Rev.ai ran across two people goes to whoever spoke more.
    seconds: dict[str, float] = {}
    for dur, name, _ in chunks:
        if name:
            seconds[name] = seconds.get(name, 0.0) + dur
    if not seconds:
        return None, 0.0
    best = max(seconds, key=seconds.get)
    if seconds[best] * 2 < sum(c[0] for c in chunks):
        return None, 0.0
    scores = [c[2] * c[0] for c in chunks if c[1] == best]
    return best, sum(scores) / seconds[best]


def name_number(turns: list[tuple[float, str | None]]) -> str | None:
    """Name for a speaker number from its (seconds, name or None) turns, if it is one person."""
    seconds: dict[str, float] = {}
    for dur, name in turns:
        if name:
            seconds[name] = seconds.get(name, 0.0) + dur
    if not seconds:
        return None
    best = max(seconds, key=seconds.get)
    pure = seconds[best] >= PURE_NUMBER_SHARE * sum(seconds.values())
    return best if pure and seconds[best] * 2 >= sum(t[0] for t in turns) else None


def overrides_manual(voice_id: str | None, manual_id: str, voice_score: float, manual_score: float | None) -> bool:
    """A manual label covers its whole number unless the voice is clearly somebody else."""
    return bool(voice_id) and voice_id != manual_id and manual_score is not None \
        and voice_score - manual_score >= MANUAL_MARGIN


def self_check():
    assert split_chunks(0, 1) == []
    assert split_chunks(0, 5) == [(0, 5)]
    assert split_chunks(0, 11) == [(0, 11)]      # a short tail joins the last chunk
    assert split_chunks(0, 25) == [(0, 10), (10, 20), (20, 25)]
    assert name_turn([(10, 'a', .8), (5, None, .3)]) == ('a', .8)
    assert name_turn([(4, 'a', .8), (10, None, .3)]) == (None, 0.0)
    assert name_turn([(10, None, .3)]) == (None, 0.0)
    assert name_number([(100, 'a'), (5, None)]) == 'a'
    assert name_number([(60, 'a'), (40, 'b')]) is None, 'two people on one number'
    assert name_number([(30, 'a'), (70, None)]) is None, 'mostly an unknown voice'
    assert overrides_manual('b', 'a', .7, .3)
    assert not overrides_manual('b', 'a', .7, .6), 'near tie stays manual'
    assert not overrides_manual('a', 'a', .7, .7)
    assert not overrides_manual(None, 'a', 0, .1)
    assert not overrides_manual('b', 'a', .9, None), 'manual person has no voice profile'
    print('self-check ok')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('board', nargs='?')
    parser.add_argument('date', nargs='?')
    parser.add_argument('--threshold', type=float, default=DEFAULT_THRESHOLD,
                        help=f'Cosine similarity threshold (default {DEFAULT_THRESHOLD})')
    parser.add_argument('--dry-run', action='store_true', help='Print results but do not write output')
    parser.add_argument('--self-check', action='store_true')
    args = parser.parse_args()

    if args.self_check:
        self_check()
        return
    if not args.board or not args.date:
        parser.error('board and date are required')

    import numpy as np

    board, date = args.board, args.date

    whisperx_path = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.whisperx.json')
    revai_path    = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.revai.json')
    txt_path      = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.txt')

    out_dir       = os.path.join(SHARED_DIR, 'speakers', board)
    os.makedirs(out_dir, exist_ok=True)
    speakers_path = os.path.join(out_dir, f'{date}.speakers.json')
    turns_path    = os.path.join(out_dir, f'{date}.turns.json')

    profiles = load_profiles()
    voices   = {sid: np.array(info['embedding']) for sid, info in profiles.items() if info.get('embedding')}
    # Only people who appear on this board, so a resident can't be matched to another board's member.
    roster   = [sid for sid in voices if board in profiles[sid].get('boards', [])]
    if not roster:
        print(f'No {board} speaker profiles with embeddings found. Run build_speaker_profiles.py first.')
        sys.exit(1)
    gallery = np.array([voices[sid] for sid in roster])

    if os.path.exists(whisperx_path):
        with open(whisperx_path) as f:
            segments = parse_whisperx_segments(json.load(f))
        source = 'whisperx'
    elif os.path.exists(revai_path):
        with open(revai_path) as f:
            segments = parse_revai_segments(json.load(f))
        source = 'revai'
    elif os.path.exists(txt_path):
        segments = parse_txt_segments(txt_path)
        source   = 'txt'
        print('NOTE: No JSON transcript found, using .txt timestamps (lower boundary precision)')
    else:
        print(f'ERROR: No transcript found for {board}/{date}')
        sys.exit(1)

    # Turns can run past the recording (the .txt fallback gives the last line 60s), and pieces past the end are empty audio
    duration = recording_duration(recording_path(board, date))
    turns  = sorted((s, min(e, duration), num) for num, segs in segments.items() for s, e in segs if s < duration)
    chunks = [(a, b, i) for i, (s, e, _) in enumerate(turns) for a, b in split_chunks(s, e)]
    print(f'{board}/{date}: {len(turns)} turn(s), {len(segments)} speaker number(s), {len(roster)} profile(s) [{source}]')

    # Embeddings depend only on the audio and turns, so profile changes can rematch without redoing them.
    cache_path = os.path.join(CACHE_DIR, board, f'{date}.npz')
    starts     = np.array([c[0] for c in chunks])
    embeddings = None
    if os.path.exists(cache_path):
        cached = np.load(cache_path)
        if np.array_equal(cached['starts'], starts):
            embeddings = cached['embeddings']
    if embeddings is None:
        token = load_token()
        if not token:
            print('HuggingFace token not found.')
            sys.exit(1)
        embedder   = load_model(token)
        audio      = load_audio(recording_path(board, date))
        embeddings = np.array([embed(audio[int(a * SAMPLE_RATE):int(b * SAMPLE_RATE)], embedder) for a, b, _ in chunks])
        if not args.dry_run:
            os.makedirs(os.path.dirname(cache_path), exist_ok=True)
            np.savez(cache_path, starts=starts, embeddings=embeddings)

    existing = {}
    if os.path.exists(speakers_path):
        with open(speakers_path) as f:
            existing = json.load(f)
        if isinstance(existing, list):   # PHP wrote a list when the numbers happened to be sequential
            existing = {str(i): v for i, v in enumerate(existing)}
    manual = {int(k): v for k, v in existing.items() if v and v.get('manual')}

    by_turn: dict[int, list[int]] = {}
    for c, (_, _, i) in enumerate(chunks):
        by_turn.setdefault(i, []).append(c)

    voice_names = {}    # turn index -> (speaker id or None, score)
    manual_gaps = {}    # turn index -> score of the manual person, where the number has one
    for i, (_, _, num) in enumerate(turns):
        scored = []
        for c in by_turn.get(i, []):
            sims = gallery @ embeddings[c]
            k    = int(np.argmax(sims))
            scored.append((chunks[c][1] - chunks[c][0], roster[k] if sims[k] >= args.threshold else None, float(sims[k])))
        voice_names[i] = name_turn(scored)
        manual_id = manual.get(num, {}).get('speaker_id')
        if manual_id in voices and by_turn.get(i):
            manual_gaps[i] = float(np.mean([voices[manual_id] @ embeddings[c] for c in by_turn[i]]))

    number_turns: dict[int, list[tuple[float, str | None]]] = {}
    for i, (s, e, num) in enumerate(turns):
        if by_turn.get(i):
            number_turns.setdefault(num, []).append((e - s, voice_names[i][0]))
    number_ids = {num: name_number(t) for num, t in number_turns.items()}

    results: dict[str, dict | None] = {}
    for num in sorted(segments):
        sid = number_ids.get(num)
        if num in manual:
            results[str(num)] = manual[num]
        elif sid:
            scores = [voice_names[i][1] for i, t in enumerate(turns) if t[2] == num and voice_names[i][0] == sid]
            results[str(num)] = {'name': profiles[sid]['name'], 'speaker_id': sid, 'confidence': round(float(np.mean(scores)), 3)}
        else:
            results[str(num)] = None

    out_turns = []
    for i, (s, e, num) in enumerate(turns):
        sid, score = voice_names[i]
        turn = {'start': s, 'end': e, 'speaker': num}
        if num in manual:
            if overrides_manual(sid, manual[num].get('speaker_id'), score, manual_gaps.get(i)):
                turn.update(name=profiles[sid]['name'], speaker_id=sid, confidence=round(score, 3), override=True)
            else:
                turn.update(name=manual[num]['name'], speaker_id=manual[num].get('speaker_id'))
        elif sid:
            turn.update(name=profiles[sid]['name'], speaker_id=sid, confidence=round(score, 3))
        elif not by_turn.get(i) and results[str(num)]:
            turn.update(name=results[str(num)]['name'], speaker_id=results[str(num)]['speaker_id'])
        else:
            turn['name'] = None
        out_turns.append(turn)

    for num, r in results.items():
        seconds = sum(t['end'] - t['start'] for t in out_turns if str(t['speaker']) == num)
        names   = {t['name'] for t in out_turns if str(t['speaker']) == num and t['name']}
        print(f"  Speaker {num}: {seconds:.0f}s -> {r['name'] if r else 'no single name'}{' (manual)' if r and r.get('manual') else ''}; turns named: {sorted(names)}")

    if args.dry_run:
        print('Dry run, not writing output.')
        return
    with open(speakers_path, 'w') as f:
        json.dump(results, f, indent=2)
    with open(turns_path, 'w') as f:
        json.dump(out_turns, f, indent=1)
    print(f'Written: {speakers_path} and {turns_path}')


if __name__ == '__main__':
    main()
