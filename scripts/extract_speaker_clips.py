#!/usr/bin/env python3
"""
Register audio clips for known speakers, with optional auto-extraction from roll call.

Manual mode:
    python3 extract_speaker_clips.py zoning 2026-04-23 chairman_cahill 00:04:07 00:04:20 "Chairman Cahill"

    The last argument (display name) is only required when creating a new speaker entry.
    Timestamps can be HH:MM:SS or MM:SS or raw seconds.

Auto roll-call mode:
    python3 extract_speaker_clips.py --rollcall zoning 2026-04-23

    Parses the .revai.json to find roll call responses and offers to register them.
    Each board member's "Here" response after their name is called becomes a clip.

Clips are recorded in data/speakers/profiles.json (created if missing).
Run build_speaker_profiles.py afterwards to compute/update embeddings.
"""
import argparse
import json
import os
import re
import sys

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROFILES = os.path.join(BASE_DIR, 'data', 'speakers', 'profiles.json')


def parse_time(t: str) -> float:
    """Parse HH:MM:SS, MM:SS, or raw seconds into float seconds."""
    t = t.strip()
    parts = t.split(':')
    if len(parts) == 3:
        return int(parts[0]) * 3600 + int(parts[1]) * 60 + float(parts[2])
    if len(parts) == 2:
        return int(parts[0]) * 60 + float(parts[1])
    return float(t)


def load_profiles() -> dict:
    if os.path.exists(PROFILES):
        with open(PROFILES) as f:
            return json.load(f)
    return {'speakers': {}}


def save_profiles(data: dict):
    os.makedirs(os.path.dirname(PROFILES), exist_ok=True)
    with open(PROFILES, 'w') as f:
        json.dump(data, f, indent=2)


def add_clip(data: dict, speaker_id: str, board: str, date: str,
             start: float, end: float, display_name: str = '') -> bool:
    speakers = data.setdefault('speakers', {})
    if speaker_id not in speakers:
        if not display_name:
            display_name = speaker_id
        speakers[speaker_id] = {
            'name':      display_name,
            'boards':    [board],
            'clips':     [],
            'embedding': None,
        }
    else:
        if board not in speakers[speaker_id].get('boards', []):
            speakers[speaker_id].setdefault('boards', []).append(board)

    clip_key = f'{board}/{date}:{start:.1f}-{end:.1f}'
    clips    = speakers[speaker_id].setdefault('clips', [])
    if clip_key in clips:
        print(f'  Clip already registered: {clip_key}')
        return False
    clips.append(clip_key)
    print(f'  Added clip: {clip_key}  ({end - start:.1f}s)')
    return True


def load_revai_json(board: str, date: str) -> dict:
    path = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.revai.json')
    if not os.path.exists(path):
        raise FileNotFoundError(f'No .revai.json found at {path}')
    with open(path) as f:
        return json.load(f)


def monologue_time_range(monologue: dict) -> tuple[float, float]:
    """Return (start, end) in seconds for a monologue."""
    elements = [e for e in monologue['elements'] if e['type'] == 'text']
    if not elements:
        return (0.0, 0.0)
    return (elements[0]['ts'], elements[-1]['end_ts'])


def monologue_text(monologue: dict) -> str:
    return ''.join(e['value'] for e in monologue['elements']).strip()


def rollcall_extract(board: str, date: str):
    """
    Find roll call in the transcript and offer to register the "Here" responses
    as speaker clips.  Returns a list of (name, speaker_id, start, end) tuples.
    """
    revai = load_revai_json(board, date)
    monologues = revai.get('monologues', [])

    # Find the monologue that contains a dense roll-call pattern:
    # the clerk says several names in sequence.
    # Heuristic: look for a monologue whose text contains 3+ of a known name-like
    # pattern followed closely by "here" responses.
    #
    # Strategy: scan monologues looking for pairs where:
    #   - monologue[i] text matches "Mr./Ms. <Name>" type pattern
    #   - monologue[i+1] text is very short (the "Here" or "Yes" response)

    results = []
    name_pattern = re.compile(r'\b(Mr\.?|Ms\.?|Mrs\.?|Dr\.?)\s+([A-Z][a-z]+)', re.IGNORECASE)

    i = 0
    while i < len(monologues) - 1:
        text = monologue_text(monologues[i])
        names_in_text = name_pattern.findall(text)

        # If this monologue calls a single name and the next is a short ack
        next_text = monologue_text(monologues[i + 1]).strip().lower()
        is_ack    = next_text in ('here', 'yes', 'here.', 'yes.', 'present', 'present.', 'yep', 'yep.')

        if names_in_text and is_ack:
            # The next speaker is responding to the last name called
            last_name   = names_in_text[-1][1]  # surname
            next_mono   = monologues[i + 1]
            start, end  = monologue_time_range(next_mono)
            # Expand window slightly for context
            start = max(0.0, start - 0.2)
            end   = end + 0.3
            speaker_num = next_mono['speaker']
            results.append((last_name, speaker_num, start, end, monologue_text(next_mono)))

        i += 1

    return results


def manual_mode(args):
    board      = args.board
    date       = args.date
    speaker_id = args.speaker_id
    start      = parse_time(args.start)
    end        = parse_time(args.end)
    name       = args.name or speaker_id

    if end <= start:
        print(f'ERROR: end time ({end}) must be after start time ({start})')
        sys.exit(1)

    data    = load_profiles()
    changed = add_clip(data, speaker_id, board, date, start, end, name)
    if changed:
        save_profiles(data)
        print(f'Saved. Run build_speaker_profiles.py to recompute embeddings.')


def rollcall_mode(args):
    board = args.board
    date  = args.date

    print(f'Scanning roll call in {board}/{date}...\n')
    try:
        candidates = rollcall_extract(board, date)
    except FileNotFoundError as e:
        print(f'ERROR: {e}')
        sys.exit(1)

    if not candidates:
        print('No roll call patterns found. Try manual mode.')
        return

    data    = load_profiles()
    changed = False

    print(f'Found {len(candidates)} candidate(s):\n')
    for i, (last_name, speaker_num, start, end, response_text) in enumerate(candidates):
        print(f'  [{i+1}] Speaker {speaker_num}  "{response_text}"  ({start:.1f}s – {end:.1f}s)  — likely: {last_name}')

    print()
    for last_name, speaker_num, start, end, response_text in candidates:
        slug = last_name.lower()
        # Check if we already know this speaker
        existing_name = ''
        existing_id   = ''
        for sid, info in data.get('speakers', {}).items():
            if slug in sid or last_name.lower() in info.get('name', '').lower():
                existing_id   = sid
                existing_name = info['name']
                break

        if existing_id:
            prompt = f'Register clip for {existing_name} (speaker {speaker_num}, {start:.1f}-{end:.1f}s)? [y/N] '
        else:
            prompt = f'New speaker "{last_name}" (speaker {speaker_num}, {start:.1f}-{end:.1f}s)? Enter ID or blank to skip: '

        answer = input(prompt).strip()
        if not answer or answer.lower() == 'n':
            continue

        if existing_id:
            sid        = existing_id
            disp_name  = existing_name
        else:
            sid        = answer if answer.lower() not in ('y', 'yes') else slug
            disp_name  = last_name

        if add_clip(data, sid, board, date, start, end, disp_name):
            changed = True

    if changed:
        save_profiles(data)
        print(f'\nSaved. Run build_speaker_profiles.py to recompute embeddings.')
    else:
        print('\nNothing added.')


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub    = parser.add_subparsers(dest='mode')

    # Manual mode (positional args)
    man = sub.add_parser('add', help='Register a clip manually')
    man.add_argument('board')
    man.add_argument('date')
    man.add_argument('speaker_id')
    man.add_argument('start', help='Start time (HH:MM:SS or MM:SS or seconds)')
    man.add_argument('end',   help='End time')
    man.add_argument('name',  nargs='?', default='', help='Display name (for new speakers)')

    # Roll call mode
    rc = sub.add_parser('rollcall', help='Auto-extract clips from roll call')
    rc.add_argument('board')
    rc.add_argument('date')

    # Fallback: bare positional args for backwards compat
    if len(sys.argv) > 1 and sys.argv[1] not in ('add', 'rollcall', '-h', '--help'):
        sys.argv.insert(1, 'add')

    args = parser.parse_args()

    if args.mode == 'add':
        manual_mode(args)
    elif args.mode == 'rollcall':
        rollcall_mode(args)
    else:
        parser.print_help()


if __name__ == '__main__':
    main()
