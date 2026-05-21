# Speaker Identification

Transcripts from Rev.ai label speakers as `Speaker 0`, `Speaker 1`, etc. Speaker numbers
are **not consistent across meetings** — the same person may be Speaker 1 in one meeting and
Speaker 4 in the next. This pipeline identifies who each speaker number is and substitutes
real names in the transcript display.

## How it works

1. Rev.ai transcription jobs are saved in two formats:
   - `.txt` — human-readable, used for display
   - `.revai.json` — word-level timestamps and speaker IDs, used for clip extraction

2. Known speakers accumulate audio clips in `data/speakers/profiles.json`. Each clip is a
   `board/date:start-end` reference into the meeting recording.

3. `build_speaker_profiles.py` extracts those clips via ffmpeg, runs them through the
   pyannote speaker embedding model, and stores a 512-d voiceprint per person.

4. `identify_speakers.py` processes a meeting: for each speaker number it gathers all their
   audio, computes an embedding, and matches against known profiles by cosine similarity.
   Output goes to `output/speakers/{board}/{date}.speakers.json`.

5. `Meeting.php` loads that JSON and substitutes names for `Speaker N` labels in the
   transcript view. Unmatched speakers remain as `Speaker N`.

## Prerequisites

### Python environment
```bash
# One-time setup (already done on this machine)
uv venv /home/piscataway/venv
uv pip install --python venv/bin/python "pyannote.audio>=3.1" torch numpy scipy soundfile omegaconf
```

### HuggingFace token
The pyannote embedding model is gated. You need to:
1. Create an account at https://huggingface.co
2. Accept the license at https://hf.co/pyannote/embedding
3. Accept the license at https://hf.co/pyannote/segmentation-3.0
4. Put your token in `data/hf_token` (one line, no newline required, already populated)
5. Also add `define('HUGGINGFACE_TOKEN', '...')` to `config.php` for PHP access

## Regular workflow: identifying a new meeting

### Step 1 — make sure the .revai.json exists

New meetings get their JSON saved automatically when `monitor_revai_progress.php` runs.
For older meetings, backfill while the Rev.ai job is still live (30-day retention):

```bash
php scripts/fetch_revai_json.php
```

### Step 2 — run identification

```bash
venv/bin/python scripts/identify_speakers.py zoning 2026-04-23 --threshold 0.70
```

The output file is written to `output/speakers/zoning/2026-04-23.speakers.json`.

Check the results. Known regulars (Cahill, Rahi, Kinneally, Laura the clerk) should
match confidently (≥ 0.80). Board members who only speak briefly score lower.

### Step 3 — deploy

`output/speakers/` is included in `deploy.sh`'s rsync, so running `./deploy.sh` pushes
the speaker mappings to production where they're read by the transcript page.

## Improving profiles

The quality of identification depends directly on the quality and quantity of registered
clips. Short "yes/here" clips (~0.7 s) are nearly useless. Clips of 10–45 seconds of
natural speech work well.

### Finding good clips in a meeting

```bash
# Show the longest speaking turn per speaker, with timestamps ready to copy
venv/bin/python scripts/extract_speaker_clips.py longturns zoning 2026-04-23 --min-seconds 10
```

The output includes the first 80 characters of each turn — use that to identify who is
speaking. Meeting context clues:
- Opens with "Zoning Board of adjustment meeting will please come to order" → **Chairman**
- Calls roll ("Mr. Tillery? ... Mr. Weisman? ...") → **Clerk**
- Announces agenda changes / explains postponements → **Board Attorney**
- Delivers staff report on applications → **Planner**
- "As I indicated in my application" / describes their property → **Applicant** (skip)
- Asks "Have you considered...?" / makes a motion → **Board member**

### Registering a clip manually

```bash
venv/bin/python scripts/extract_speaker_clips.py add zoning 2026-04-23 cahill 11.7 29.2 "Shawn Cahill (Chair)"
#                                                     ^board ^date      ^id   ^start ^end  ^display name (only for new speakers)
```

Speaker IDs in use:

| ID           | Name                    | Role          |
|--------------|-------------------------|---------------|
| `cahill`     | Shawn Cahill (Chair)    | Chair         |
| `laura`      | Laura (Clerk)           | Clerk         |
| `kinneally`  | Jim Kinneally (Atty)    | Board Attorney|
| `rahi`       | Jonathan Rahi (Planner) | Planner       |
| `weisman`    | Steven Weisman          | Member        |
| `tillery`    | Jeffrey Tillery         | Member        |
| `regio`      | Roy O'Reggio            | Member        |
| `mitterando` | William Mitterando      | Member        |
| `patel`      | Kalpesh Patel           | Member        |
| `ali`        | Waqar Ali               | Alternate     |
| `blount`     | Rodney Blount           | Member        |

### Auto-extracting roll call clips

Roll call responses ("here" / "yes") are short but accumulate across meetings. After
collecting enough of them per person, the averaged embedding becomes more reliable.

```bash
# Interactive — prompts for each candidate
venv/bin/python scripts/extract_speaker_clips.py rollcall zoning 2026-04-23

# Non-interactive — accept all candidates automatically (uses last name as speaker ID)
venv/bin/python scripts/extract_speaker_clips.py rollcall --auto zoning 2026-04-23
```

### Rebuild embeddings after adding clips

```bash
venv/bin/python scripts/build_speaker_profiles.py

# Rebuild only one speaker
venv/bin/python scripts/build_speaker_profiles.py --speaker mitterando
```

## Adding a new board member

1. Find a meeting where they speak (check recent zoning transcripts).
2. Run `longturns` on that meeting to find their speaker number and a good timestamp range.
3. Register a clip:
   ```bash
   venv/bin/python scripts/extract_speaker_clips.py add zoning 2026-XX-XX new_member_id 120.0 150.0 "Full Name"
   ```
4. Rebuild: `venv/bin/python scripts/build_speaker_profiles.py --speaker new_member_id`
5. Re-run identification on any meetings you want updated.

## Backfilling existing transcripts

To batch-run identification across all meetings that have a `.revai.json`:

```bash
for f in web/files/zoning/*.revai.json; do
    date=$(basename "$f" .revai.json)
    echo "=== $date ==="
    venv/bin/python scripts/identify_speakers.py zoning "$date" --threshold 0.70
done
```

## Troubleshooting

**All scores below threshold** — profiles need longer clips. Run `longturns` on a meeting
where you can identify the speaker from context, register the clip, rebuild, retry.

**Wrong person matched** — usually means a profile has noisy clips (short "yes" responses
dominating the average). Use `build_speaker_profiles.py --speaker ID` after removing bad
clips from `profiles.json` manually, or register a longer clip to dilute the noise.

**`.revai.json` missing** — Rev.ai jobs expire after 30 days. Run `fetch_revai_json.php`
immediately after a transcript becomes available. Going forward, `monitor_revai_progress.php`
saves the JSON automatically alongside the `.txt`.

**`forced_alignment` note** — new transcription jobs submit with `forced_alignment: true`,
which improves word-boundary accuracy in the `.revai.json` and therefore clip extraction
quality. Older transcripts were submitted without it.
