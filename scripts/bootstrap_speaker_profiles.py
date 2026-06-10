#!/usr/bin/env python3
"""
Bootstrap speaker profiles for a board meeting by mining the .revai.json
for identification evidence.

Three heuristics (applied in priority order):
  1. Self-introduction: speaker says "This is Councilwoman X" / "My name is Councilman X"
  2. Address+response: moderator ends with "Councilwoman X?" then a different speaker responds
  3. Roll-call tail: clerk ends monologue with "Council President X?" then next speaker acks

For each identified speaker, registers their longest clean monologue (≥ MIN_CLIP_SECONDS) as
a voice clip in data/speakers/profiles.json, then runs build_speaker_profiles.py automatically.

Speakers identified only from very short turns (< MIN_CLIP_SECONDS total audio) are skipped
with a warning — their profiles won't be reliable.

Usage:
    venv/bin/python scripts/bootstrap_speaker_profiles.py council 2026-05-14
    venv/bin/python scripts/bootstrap_speaker_profiles.py council 2026-05-14 --dry-run
"""

import argparse
import json
import os
import re
import subprocess
import sys

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
PROFILES = os.path.join(BASE_DIR, 'data', 'speakers', 'profiles.json')

MIN_CLIP_SECONDS = 10.0   # minimum monologue length to register as a clip

# Government / official title keywords (distinguishes council members from public commenters)
GOVT_TITLE = re.compile(
    r'\b(councilman|councilwoman|council\s+(?:member|president)|mayor|deputy\s+mayor'
    r'|township\s+administrator|township\s+attorney|administrator|chief\s+of\s+police)\b',
    re.IGNORECASE,
)

# "This is Councilwoman X" / "My name is Council Member X"
SELF_INTRO = re.compile(
    r'\b(?:this\s+is|my\s+name\s+is)\s+'
    r'(?P<title>councilman|councilwoman|council\s+(?:member|president)|mayor|deputy\s+mayor)\s+'
    r'(?P<name>[A-Z][a-zA-Z\-]+)',
    re.IGNORECASE,
)

# Address tail: monologue ends with "Councilwoman X?" or "Council President X?"
# Also handles "Mr./Ms. X?" when the overall context is an officials section
ADDR_TAIL = re.compile(
    r'\b(?P<title>councilman|councilwoman|council\s+(?:member|president)|madam\s+president'
    r'|mr\.|ms\.|mrs\.)\s+(?P<name>[A-Z][a-zA-Z\-]+)[?.!]?\s*$',
    re.IGNORECASE,
)

# Public commenter anti-pattern — skip these self-intros
PUBLIC_COMMENT = re.compile(
    r'\bi\s+live\s+at\b|\bi\'?m\s+a\s+resident\b|,\s*\d+\s+\w+\s+(street|avenue|road|drive|lane|court|blvd)',
    re.IGNORECASE,
)


def mono_text(mono: dict) -> str:
    return ''.join(e.get('value', '') for e in mono['elements']).strip()


def mono_range(mono: dict) -> tuple[float, float]:
    elems = [e for e in mono['elements'] if e['type'] == 'text' and 'ts' in e]
    if not elems:
        return (0.0, 0.0)
    return (elems[0]['ts'], elems[-1]['end_ts'])


def normalise_title(raw: str) -> str:
    s = raw.strip().rstrip('.')
    mapping = {
        'councilman':       'Councilman',
        'councilwoman':     'Councilwoman',
        'council member':   'Council Member',
        'council president':'Council President',
        'mayor':            'Mayor',
        'deputy mayor':     'Deputy Mayor',
        'mr':               '',
        'ms':               '',
        'mrs':              '',
        'madam president':  '',
    }
    return mapping.get(s.lower(), s.title())


def name_slug(name: str) -> str:
    return re.sub(r'[^a-z0-9]+', '_', name.lower()).strip('_')


def find_identifications(monologues: list) -> dict[int, list[tuple[str, str, str]]]:
    """
    Return {speaker_num: [(name, title, heuristic), ...]} from all evidence found.
    Higher-priority heuristics appear first in the list.
    """
    evidence: dict[int, list] = {}

    for i, mono in enumerate(monologues):
        text  = mono_text(mono)
        spk   = mono['speaker']
        start, end = mono_range(mono)

        # Heuristic 1: self-introduction with a government title
        m = SELF_INTRO.search(text)
        if m and not PUBLIC_COMMENT.search(text):
            evidence.setdefault(spk, []).insert(
                0, (m.group('name'), normalise_title(m.group('title')), 'self_intro')
            )

        # Heuristics 2+3: address-tail → next different speaker responds
        if i + 1 < len(monologues):
            next_mono = monologues[i + 1]
            next_spk  = next_mono['speaker']
            if next_spk != spk:
                m = ADDR_TAIL.search(text)
                if m:
                    name  = m.group('name')
                    title = normalise_title(m.group('title'))
                    next_text = mono_text(next_mono)
                    if not PUBLIC_COMMENT.search(next_text):
                        evidence.setdefault(next_spk, []).append(
                            (name, title, 'addr_response')
                        )

    return evidence


def resolve_speaker_id(data: dict, name: str) -> tuple[str, str]:
    """Return (speaker_id, display_name) matching existing profiles if possible."""
    slug = name_slug(name)
    speakers = data.get('speakers', {})
    # Exact slug match
    if slug in speakers:
        return slug, speakers[slug]['name']
    # Partial match in existing names (handles Liebowitz vs Libert etc.)
    for sid, info in speakers.items():
        if name.lower() in info.get('name', '').lower() or info.get('name', '').lower().startswith(name.lower()):
            return sid, info['name']
    return slug, name


def load_profiles() -> dict:
    if os.path.exists(PROFILES):
        with open(PROFILES) as f:
            return json.load(f)
    return {'speakers': {}}


def save_profiles(data: dict):
    os.makedirs(os.path.dirname(PROFILES), exist_ok=True)
    with open(PROFILES, 'w') as f:
        json.dump(data, f, indent=2)


def main():
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument('board')
    parser.add_argument('date')
    parser.add_argument('--dry-run', action='store_true', help='Print results without writing profiles')
    parser.add_argument('--min-clip-seconds', type=float, default=MIN_CLIP_SECONDS,
                        help=f'Minimum monologue length to register (default {MIN_CLIP_SECONDS})')
    args = parser.parse_args()

    board, date = args.board, args.date
    revai_path = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.revai.json')
    if not os.path.exists(revai_path):
        print(f'ERROR: {revai_path} not found.')
        sys.exit(1)

    with open(revai_path) as f:
        revai = json.load(f)
    monologues = revai.get('monologues', [])

    print(f'{board}/{date}: {len(monologues)} monologues')
    print()

    evidence = find_identifications(monologues)
    if not evidence:
        print('No identification evidence found.')
        sys.exit(0)

    print(f'Found evidence for {len(evidence)} speaker(s):\n')

    # For each identified speaker, resolve their canonical name and find best clip
    data    = load_profiles()
    changed = False

    for spk_num in sorted(evidence.keys()):
        ev_list = evidence[spk_num]

        # Pick best evidence: self_intro > addr_response; within same type, keep first
        best_name, best_title, best_heuristic = ev_list[0]

        # Check consistency — warn if different heuristics give different names
        all_names = {e[0].lower() for e in ev_list}
        if len(all_names) > 1:
            print(f'  Speaker {spk_num}: CONFLICTING evidence {[e[:2] for e in ev_list]} — using {best_name!r}')
        else:
            print(f'  Speaker {spk_num}: {best_name!r} ({best_title}) via {best_heuristic}')

        # Build display name
        if best_title:
            display_name = f'{best_name} ({best_title})'
        else:
            display_name = best_name

        # Find their longest monologue ≥ min_clip_seconds
        best_mono = None
        best_dur  = 0.0
        for mono in monologues:
            if mono['speaker'] != spk_num:
                continue
            start, end = mono_range(mono)
            dur = end - start
            if dur >= args.min_clip_seconds and dur > best_dur:
                best_mono = mono
                best_dur  = dur

        if best_mono is None:
            # Check total audio available
            total = sum(
                mono_range(m)[1] - mono_range(m)[0]
                for m in monologues if m['speaker'] == spk_num
            )
            print(f'    → SKIPPED: no single turn ≥ {args.min_clip_seconds}s '
                  f'(total audio: {total:.1f}s)\n')
            continue

        start, end = mono_range(best_mono)
        clip_key = f'{board}/{date}:{start:.1f}-{end:.1f}'

        sid, existing_display = resolve_speaker_id(data, best_name)
        if sid in data.get('speakers', {}):
            display_name = existing_display  # keep existing canonical name

        print(f'    → clip [{start:.1f}-{end:.1f}]s ({best_dur:.1f}s), id={sid!r}, name={display_name!r}')

        if not args.dry_run:
            speakers = data.setdefault('speakers', {})
            if sid not in speakers:
                speakers[sid] = {
                    'name':      display_name,
                    'boards':    [board],
                    'clips':     [],
                    'embedding': None,
                }
            else:
                if board not in speakers[sid].get('boards', []):
                    speakers[sid].setdefault('boards', []).append(board)

            if clip_key not in speakers[sid].setdefault('clips', []):
                speakers[sid]['clips'].append(clip_key)
                speakers[sid]['embedding'] = None  # invalidate so build_speaker_profiles recomputes
                changed = True
                print(f'    Registered.')
            else:
                print(f'    Already registered.')
        print()

    if args.dry_run:
        print('Dry run — nothing written.')
        return

    if changed:
        save_profiles(data)
        print('Saved profiles.json')
        print()
        print('Running build_speaker_profiles.py...')
        build_script = os.path.join(BASE_DIR, 'scripts', 'build_speaker_profiles.py')
        result = subprocess.run(
            [sys.executable, build_script],
            cwd=BASE_DIR,
        )
        if result.returncode != 0:
            print('WARNING: build_speaker_profiles.py exited with errors.')
            sys.exit(1)
    else:
        print('No new clips registered.')


if __name__ == '__main__':
    main()
