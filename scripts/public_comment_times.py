#!/usr/bin/env python3
"""
Measure how long members of the public speak during a meeting's public portions.

Reads web/files/{board}/{date}.revai.json (word-level timestamps + diarized speaker numbers).
Speaker numbers are stable within a meeting, so anyone who talks outside the public portions
is an official. So is anyone shared/speakers/{board}/{date}.speakers.json names with a role,
e.g. "Raj Goomer (Township Attorney)", which catches officials who only speak to answer questions.
Anyone left is the public; those who never give a name or address are marked "?" for a human to check.

For each speaker in each public portion:
    floor     first word -> last word of the statement, including brief interruptions.
              This is what a stopwatch at the dais would show; compare it with the 3:00 limit.
    talk      only the speaker's own speech within the statement
    total     all the speaker's own speech in that public portion, including answering questions
    elapsed   first word -> last word across the whole exchange, including officials' replies
    over      how far floor exceeds 3:00 (not total: answering the council shouldn't count against anyone)
    warned    time since their first word at a phrase like "wrap it up"; a lead to check, not proof
    ended     last words of the statement, to judge if they finished or were cut off

Usage:
    python3 public_comment_times.py council 2026-11-05 [--official 4,5] [--dry-run]

Each run appends the meeting to shared/public_comment_times.csv. A meeting already in the
file is skipped, so hand corrections survive re-runs; delete its rows to regenerate it.
New rows have reviewed=no: their speaker names and how_ended are machine guesses, and summary
is left blank to be written from the transcript during review.
"""
import argparse
import csv
import json
import os
import re
import tempfile

BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))

LIMIT = 180             # the 3 minute rule
INTERJECTION_MAX = 8    # seconds others may talk without ending a statement
OFFICIAL_MIN = 30       # seconds of speech outside public portions that make someone an official
SPEAKER_MIN = 10        # seconds of speech below which a voice in a public portion is ignored
WORD_GAP_MAX = 2        # longer silences inside a turn are not counted as talking

OPEN_RE = re.compile(r"state your name|have three minutes|unmute|(?<!be )(?<!it'll )open(ing)?\b.{0,20}\bpublic", re.I)
CLOSE_RE = re.compile(r"(clos(e|ed|ing)\b.{0,30}\b(public|portion)|portion is (now )?closed|public portion is)", re.I)
WARN_RE = re.compile(r"wrap (it|this|that) up|wrap up what|sum (it |this )?up|finish up|time('s| is) up|out of time|exceeded|over (your |the )?(three|3|time)|past (your )?(three|3)|your (three|3) minutes", re.I)

INTRO_RE = re.compile(r"my name|name's|\bthis is\b|\bI'm [A-Z]|\bI live|resident|\b\d+ \w+ (road|rd|avenue|ave|street|drive|court|lane|terrace|place)\b", re.I)
NEW_PERSON_RE = re.compile(r"my name|name's|\bI live\b|\b\d+ (\w+ ){1,4}(road|rd|avenue|ave|street|drive|court|lane|terrace|place|circle|boulevard|blvd)\b", re.I)
OFFICIAL_INTRO_RE = re.compile(r"my name|\bI live\b", re.I)   # officials also cite addresses

CSV_PATH = os.path.join(BASE_DIR, 'shared', 'public_comment_times.csv')
CSV_FIELDS = ['meeting_date', 'board', 'portion', 'speaker', 'start', 'floor', 'floor_seconds', 'over', 'total',
              'elapsed', 'warned', 'how_ended', 'opening', 'last_words', 'reviewed', 'summary']


def load_turns(path):
    """One dict per diarized monologue, with its words as (start, end) spans."""
    with open(path) as f:
        data = json.load(f)
    turns = []
    for mono in data['monologues']:
        words = [e for e in mono['elements'] if e['type'] == 'text' and 'ts' in e]
        spans = [(e['ts'], e['end_ts']) for e in words]
        if spans:
            turns.append({
                'speaker': mono['speaker'],
                'start': spans[0][0],
                'end': spans[-1][1],
                'spans': spans,
                'text': ''.join(e['value'] for e in mono['elements']),
            })
    return turns


def load_names(board, date):
    """{speaker number: name} from identify_speakers.py, if it has run."""
    path = os.path.join(BASE_DIR, 'shared', 'speakers', board, f'{date}.speakers.json')
    if not os.path.exists(path):
        return {}
    with open(path) as f:
        return {int(k): v['name'] for k, v in json.load(f).items() if v and v.get('name')}


def speech(turn):
    """Seconds of actual talking in a turn, leaving out silences."""
    total = 0.0
    for (s, e), (next_s, _) in zip(turn['spans'], turn['spans'][1:] + [(None, None)]):
        total += e - s
        if next_s is not None and next_s - e <= WORD_GAP_MAX:
            total += next_s - e
    return total


def find_windows(turns):
    """[(first_index, last_index)] of the turns inside each public portion."""
    windows, opened = [], None
    for i, t in enumerate(turns):
        # Closing the remote part only hands over to the in-person part of the same portion.
        marks = [(m.start(), 'open') for m in OPEN_RE.finditer(t['text'])] + \
                [(m.start(), 'close') for m in CLOSE_RE.finditer(t['text'])
                 if 'remote' not in t['text'][max(0, m.start() - 30):m.end() + 30].lower()]
        for _, kind in sorted(marks):
            if kind == 'open' and opened is None:
                opened = i
            elif kind == 'close' and opened is not None:
                windows.append((opened, i))
                opened = None
    if opened is not None:
        windows.append((opened, len(turns) - 1))
    # "Closing the remote portion, now open to in-person" is one public portion, not two.
    merged = []
    for a, b in windows:
        if merged and a <= merged[-1][1]:
            merged[-1] = (merged[-1][0], b)
        else:
            merged.append((a, b))
    return merged


def find_officials(turns, windows, names, extra=()):
    inside = {i for a, b in windows for i in range(a, b + 1)}
    outside = {}
    for i, t in enumerate(turns):
        if i not in inside:
            outside[t['speaker']] = outside.get(t['speaker'], 0) + speech(t)
    return {s for s, secs in outside.items() if secs >= OFFICIAL_MIN} | \
           {s for s, name in names.items() if '(' in name} | set(extra)


def measure(turns, first, last, officials):
    """One row per public speaker in a public portion, in order of appearance."""
    window = split_people(turns[first:last + 1], officials)
    rows = []
    for spk in dict.fromkeys(t['speaker'] for t in window if t['speaker'] not in officials):
        idx = [i for i, t in enumerate(window) if t['speaker'] == spk]
        total = sum(speech(window[i]) for i in idx)
        if total < SPEAKER_MIN:
            continue

        # Split the speaker's turns into runs wherever someone else talked at length.
        runs = [[idx[0]]]
        for i in idx[1:]:
            if sum(speech(t) for t in window[runs[-1][-1] + 1:i]) > INTERJECTION_MAX:
                runs.append([])
            runs[-1].append(i)

        # The longest run is the statement: the first can be a stray "can you hear me".
        statement = max(runs, key=lambda run: sum(speech(window[i]) for i in run))
        s_turns = [window[i] for i in statement]
        begin, finish = window[idx[0]]['start'], window[idx[-1]]['end']

        # Rev.ai often folds a brief "wrap it up" into the speaker's own turn, so search those too.
        warned = None
        for t in window[idx[0]:idx[-1] + 2]:
            m = WARN_RE.search(t['text'])
            if m:
                word = min(len(t['text'][:m.start()].split()), len(t['spans']) - 1)
                warned = t['spans'][word][0] - begin
                break

        rows.append({
            'speaker': spk,
            'start': s_turns[0]['start'],
            'floor': s_turns[-1]['end'] - s_turns[0]['start'],
            'talk': sum(speech(t) for t in s_turns),
            'total': total,
            'elapsed': finish - begin,
            'interruptions': len({t['start'] for t in window[statement[0]:statement[-1] + 1] if t['speaker'] != spk}),
            'warned': warned,
            'after_warning': None if warned is None else max(0, finish - begin - warned),
            'intro': bool(INTRO_RE.search(' '.join(window[i]['text'] for i in idx)[:400])),
            'opening': window[idx[0]]['text'].strip()[:70],
            'ended': s_turns[-1]['text'].strip()[-50:],
        })
    return sorted(rows, key=lambda r: r['start'])


def split_people(window, officials):
    """Copy of the turns where each self-introduction starts a new person, e.g. speaker 8 -> '8.1'.

    Diarization can give several residents one number, even a council member's. A person
    lasts until anyone's next introduction, then that number's stray turns revert to the base.
    ponytail: only the start of a turn is checked, because "as I said, my name is..." mid-speech is
    not a new person. Someone introduced mid-turn stays merged, so read the full text during review.
    """
    people, count, out = {}, 0, []
    for t in window:
        base = t['speaker']
        intro_re = OFFICIAL_INTRO_RE if base in officials else NEW_PERSON_RE
        if intro_re.search(t['text'][:120]):
            count += 1
            people = {base: f'{base}.{count}'}
        out.append({**t, 'speaker': people.get(base, base)})
    return out


def how_ended(row):
    """A first guess; rows get a human's wording when they are reviewed."""
    if row['warned'] is not None:
        return f'Warned at {clock(row["warned"])}; spoke {clock(row["after_warning"])} more'
    return 'Finished' if re.search(r"thank|that's all|that's it", row['ended'], re.I) else 'Unclear'


def portion_type(window):
    text = ' '.join(t['text'] for t in window[:3]).lower()
    if 'consent' in text:
        return 'Consent agenda'
    return 'Ordinance' if re.search(r'second\W+reading', text) else 'General'


def append_csv(path, board, date, rows):
    """Add a meeting's rows. Meetings already in the file are left alone, keeping reviewed edits."""
    exists = os.path.exists(path) and os.path.getsize(path) > 0
    if exists:
        with open(path, newline='') as f:
            if any(r['board'] == board and r['meeting_date'] == date for r in csv.DictReader(f)):
                return False
    with open(path, 'a', newline='') as f:
        writer = csv.DictWriter(f, fieldnames=CSV_FIELDS)
        if not exists:
            writer.writeheader()
        for r in rows:
            writer.writerow({
                'meeting_date': date,
                'board': board,
                'portion': r['portion_type'],
                'speaker': r['name'],
                'start': clock(r['start']),
                'floor': clock(r['floor']),
                'floor_seconds': round(r['floor']),
                'over': f'+{clock(r["over"])}' if r['over'] > 0 else '',
                'total': clock(r['total']),
                'elapsed': clock(r['elapsed']),
                'warned': '' if r['warned'] is None else clock(r['warned']),
                'how_ended': how_ended(r),
                'opening': r['opening'],
                'last_words': r['ended'],
                'reviewed': 'no',
            })
    return True


def clock(secs):
    if secs is None:
        return '-'
    secs = int(round(secs))
    return f'{secs // 3600}:{secs % 3600 // 60:02}:{secs % 60:02}' if secs >= 3600 else f'{secs // 60}:{secs % 60:02}'


def self_check():
    def turn(spk, start, end, text='words'):
        spans = [(float(s), s + 1.0) for s in range(start, end)]
        return {'speaker': spk, 'start': float(start), 'end': float(end), 'spans': spans, 'text': text}
    turns = [
        turn(0, 0, 40, 'roll call'),
        turn(0, 40, 45, 'state your name'),
        turn(9, 45, 46),                               # stray word: not the start of the statement
        turn(0, 46, 60, 'please go ahead'),
        turn(9, 60, 160),
        turn(0, 160, 165, 'who are you addressing'),   # interjection: statement goes on
        turn(9, 165, 210),
        turn(0, 210, 240, 'long answer'),              # response: statement over
        turn(9, 240, 250),                             # back-and-forth: counts toward total only
        turn(0, 250, 265, 'next please'),
        turn(9, 265, 300, 'my name is someone else'),  # same diarized number, different person
        turn(0, 300, 305, 'close the public portion'),
    ]
    windows = find_windows(turns)
    assert windows == [(1, 11)], windows
    assert find_officials(turns, windows, {}) == {0}
    assert find_officials(turns, windows, {9: 'Pat Doe (Mayor)'}) == {0, 9}
    row, other = measure(turns, 1, 11, {0})
    assert row['floor'] == 150 and row['talk'] == 145 and row['total'] == 156, row
    assert row['interruptions'] == 1 and row['elapsed'] == 205, row
    assert other['start'] == 265 and other['total'] == 35, other

    # A resident diarized under an official's number is still measured, and only until the next person.
    resident = measure([
        turn(0, 0, 5, 'state your name'),
        turn(5, 5, 45, 'hi my name is someone'),
        turn(0, 45, 50, 'can you wrap it up'),
        turn(9, 50, 90, 'my name is someone else'),
        turn(5, 90, 130, 'as a council member I think'),
        turn(0, 130, 135, 'close the public portion'),
    ], 0, 5, {0, 5})
    assert [r['speaker'] for r in resident] == ['5.1', '9.2'], resident
    assert resident[0]['floor'] == 40 and resident[0]['warned'] == 42, resident[0]
    assert resident[0]['after_warning'] == 0, resident[0]    # stopped when told

    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, 'times.csv')
        rows = [{**row, 'name': 'A', 'portion_type': 'General', 'over': -30}]
        assert append_csv(path, 'council', '2026-01-01', rows) is True
        assert append_csv(path, 'council', '2026-01-01', rows) is False    # no duplicates
        assert append_csv(path, 'council', '2026-02-01', rows) is True     # appended, one header
        with open(path, newline='') as f:
            assert [r['meeting_date'] for r in csv.DictReader(f)] == ['2026-01-01', '2026-02-01']


def main():
    self_check()
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('board')
    ap.add_argument('date')
    ap.add_argument('--official', default='', help='speaker numbers to force as officials, e.g. 4,5')
    ap.add_argument('--dry-run', action='store_true', help='print the table without adding to the CSV')
    args = ap.parse_args()

    turns = load_turns(os.path.join(BASE_DIR, 'web', 'files', args.board, f'{args.date}.revai.json'))
    windows = find_windows(turns)
    names = load_names(args.board, args.date)
    officials = find_officials(turns, windows, names, [int(s) for s in args.official.split(',') if s])

    rows = []
    for n, (first, last) in enumerate(windows, 1):
        for row in measure(turns, first, last, officials):
            # A voiceprint name applies to a person split out of that number, unless it's an official's.
            base = int(str(row['speaker']).split('.')[0])
            name = names.get(base) if base not in officials else None
            rows.append({'portion': n, 'portion_type': portion_type(turns[first:last + 1]),
                         'name': name or f'Speaker {row["speaker"]}', **row, 'over': row['floor'] - LIMIT})
    introduced = {r['speaker'] for r in rows if r['intro']}
    for r in rows:
        r['intro'] = r['speaker'] in introduced

    print(f'{args.board} {args.date}: {len(windows)} public portions, officials = speakers {sorted(officials)}')
    # A missed public portion would silently hide a resident as an "official", so show the unverified ones.
    for spk in sorted(officials - set(names)):
        first_turn = next((t for t in turns if t['speaker'] == spk), None)
        if first_turn:
            print(f'  check: unnamed official, speaker {spk} at {clock(first_turn["start"])}: {first_turn["text"].strip()[:70]!r}')
    print(f'{"#":>2} {"name":<22} {"at":>8} {"floor":>6} {"talk":>6} {"total":>6} {"over":>6} {"warned":>6} {"int":>3}  opening / ended')
    for r in rows:
        over = f'+{clock(r["over"])}' if r['over'] > 0 else ''
        name = r['name'] + ('' if r['intro'] else ' ?')
        print(f'{r["portion"]:>2} {name:<22} {clock(r["start"]):>8} {clock(r["floor"]):>6} {clock(r["talk"]):>6} {clock(r["total"]):>6} '
              f'{over:>6} {clock(r["warned"]):>6} {r["interruptions"]:>3}  {r["opening"]!r} ... {r["ended"]!r}')

    if not args.dry_run:
        added = append_csv(CSV_PATH, args.board, args.date, rows)
        print(f'{"Added" if added else "Already in"} {CSV_PATH}: {args.board} {args.date}')


if __name__ == '__main__':
    main()
