#!/usr/bin/env python3
"""
Transcribe a meeting recording using WhisperX (local GPU-accelerated STT).

Reads:  web/files/{board}/{date}.mp3 or .m4a
Writes: web/files/{board}/{date}.whisperx.json  - word-level segments with speaker IDs
        web/files/{board}/{date}.txt             - plain transcript (Speaker N  HH:MM:SS  text)

Usage:
    python3 transcribe_whisperx.py council 2026-05-14 [options]

Options:
    --model MODEL           WhisperX model (default: large-v3-turbo)
    --min-speakers N        Minimum speakers for diarization
    --max-speakers N        Maximum speakers for diarization
    --batch-size N          Batch size (default: 16, reduce if out of memory)
    --merge-threshold F     Cosine similarity threshold for merging over-split speaker labels
                            (default: 0.82; set to 0 to disable)
    --dry-run               Transcribe but do not write output files
"""
import argparse
import ctypes
import json
import os
import sys
import warnings

warnings.filterwarnings('ignore')

# Required for Intel Arc GPU enumeration via Level Zero
os.environ.setdefault('ZES_ENABLE_SYSMAN', '1')
# Inference is GPU-bound; excess OpenMP threads just spin-wait at barriers
os.environ.setdefault('OMP_NUM_THREADS', '1')
os.environ.setdefault('MKL_NUM_THREADS', '1')
try:
    ctypes.CDLL('libze_loader.so.1').zeInit(1)
except OSError:
    pass

BASE_DIR         = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TOKEN_FILE       = os.path.join(BASE_DIR, 'data', 'hf_token')
SAMPLE_RATE      = 16000
MIN_CLIP_SECONDS = 2.0


def load_token() -> str:
    if os.path.exists(TOKEN_FILE):
        return open(TOKEN_FILE).read().strip()
    return os.environ.get('HF_TOKEN', '')


def find_recording(board: str, date: str) -> str | None:
    for ext in ('mp3', 'm4a'):
        path = os.path.join(BASE_DIR, 'web', 'files', board, f'{date}.{ext}')
        if os.path.exists(path):
            return path
    return None


def detect_ct2_device() -> tuple[str, str]:
    """Return (device, compute_type) for CTranslate2 / faster-whisper."""
    try:
        import ctranslate2
        if ctranslate2.get_supported_compute_types('cuda'):
            print('CTranslate2 device: cuda (float16)')
            return 'cuda', 'float16'
    except Exception:
        pass
    try:
        import ctranslate2
        if ctranslate2.get_supported_compute_types('opencl'):
            print('CTranslate2 device: opencl')
            return 'opencl', 'default'
    except Exception:
        pass
    print('CTranslate2 device: cpu (int8)')
    return 'cpu', 'int8'


def detect_torch_device():
    """Return torch device for pyannote diarization."""
    import torch
    if torch.cuda.is_available():
        print('Torch device: cuda')
        return torch.device('cuda')
    try:
        import intel_extension_for_pytorch  # noqa: F401 — registers XPU backend
    except ImportError:
        pass
    if torch.xpu.is_available():
        print('Torch device: xpu')
        return torch.device('xpu')
    print('Torch device: cpu')
    return torch.device('cpu')


def seconds_to_hms(seconds: float) -> str:
    s = int(seconds)
    h, remainder = divmod(s, 3600)
    m, sec = divmod(remainder, 60)
    return f'{h:02d}:{m:02d}:{sec:02d}'


def speaker_label_to_int(label: str) -> int:
    """'SPEAKER_00' → 0, 'SPEAKER_01' → 1, etc."""
    try:
        return int(label.split('_')[-1])
    except (ValueError, IndexError):
        return 0


def cosine_similarity(a: list, b: list) -> float:
    import numpy as np
    va, vb = np.array(a), np.array(b)
    return float(np.dot(va, vb) / (np.linalg.norm(va) * np.linalg.norm(vb) + 1e-8))


def extract_speaker_audio(audio, segments: list[tuple[float, float]]):
    """Slice and concatenate a numpy audio array for the given time ranges."""
    import numpy as np
    parts = [
        audio[int(s * SAMPLE_RATE):int(e * SAMPLE_RATE)]
        for s, e in segments
        if e - s >= MIN_CLIP_SECONDS
    ]
    return np.concatenate(parts) if parts else None


def compute_embedding(audio_arr, embedder) -> list | None:
    import torch
    try:
        tensor = torch.tensor(audio_arr).unsqueeze(0).unsqueeze(0)
        with torch.no_grad():
            emb = embedder(tensor)
        arr = emb.squeeze()
        if hasattr(arr, 'numpy'):
            arr = arr.numpy()
        import numpy as np
        arr = arr / (np.linalg.norm(arr) + 1e-8)
        return arr.tolist()
    except Exception as e:
        print(f'    WARNING: embedding failed: {e}')
        return None


def merge_similar_speakers(result: dict, audio, token: str, threshold: float,
                           debug: bool = False) -> dict:
    """Post-process: merge speaker labels whose voice embeddings are above threshold."""
    import torch
    from pyannote.audio import Model
    from pyannote.audio.pipelines.speaker_verification import PretrainedSpeakerEmbedding

    # Collect time ranges per speaker label
    speaker_segs: dict[str, list[tuple[float, float]]] = {}
    for seg in result['segments']:
        label = seg.get('speaker')
        if label:
            speaker_segs.setdefault(label, []).append((seg['start'], seg['end']))

    if len(speaker_segs) <= 1:
        return result

    print(f'  Computing embeddings for {len(speaker_segs)} speaker labels...')
    # Always CPU — XPU has UR errors with pyannote, CUDA embedder not worth loading twice
    device   = torch.device('cpu')
    model    = Model.from_pretrained('pyannote/embedding', token=token)
    embedder = PretrainedSpeakerEmbedding(embedding=model.to(device), device=device)

    embeddings: dict[str, list] = {}
    for label, segs in speaker_segs.items():
        arr = extract_speaker_audio(audio, segs)
        if arr is not None:
            emb = compute_embedding(arr, embedder)
            if emb is not None:
                embeddings[label] = emb

    labels = sorted(embeddings.keys(), key=lambda l: int(l.split('_')[-1]))

    if debug:
        print('\n  Audio per speaker label:')
        for label in labels:
            total = sum(e - s for s, e in speaker_segs[label])
            nseg  = len(speaker_segs[label])
            flag  = '  *** low audio' if total < 10 else ''
            print(f'    {label}: {total:.1f}s across {nseg} segment(s){flag}')
        print('\n  Pairwise cosine similarities (rows vs cols):')
        print('       ' + '  '.join(f'{l[-2:]:>6}' for l in labels))
        for a in labels:
            row = '  '.join(
                f'{cosine_similarity(embeddings[a], embeddings[b]):6.3f}' for b in labels
            )
            print(f'  {a[-2:]:>4}  {row}')
        print()

    # Union-find over labels with similarity >= threshold
    parent = {l: l for l in embeddings}

    def find(x):
        while parent[x] != x:
            parent[x] = parent[parent[x]]
            x = parent[x]
        return x

    merged = []
    for i in range(len(labels)):
        for j in range(i + 1, len(labels)):
            a, b = labels[i], labels[j]
            sim = cosine_similarity(embeddings[a], embeddings[b])
            if sim >= threshold:
                parent[find(a)] = find(b)
                merged.append((a, b, sim))

    if not merged:
        print(f'  No labels to merge at threshold {threshold}')
        return result

    # Map each label to the lowest-numbered member of its group
    groups: dict[str, list[str]] = {}
    for label in labels:
        groups.setdefault(find(label), []).append(label)

    remap: dict[str, str] = {}
    for members in groups.values():
        canonical = min(members, key=lambda l: int(l.split('_')[-1]))
        for m in members:
            remap[m] = canonical

    for a, b, sim in merged:
        print(f'  Merged {a} + {b} → {remap[find(a)]}  (similarity={sim:.3f})')

    for seg in result['segments']:
        if seg.get('speaker') in remap:
            seg['speaker'] = remap[seg['speaker']]
        for w in seg.get('words', []):
            if w.get('speaker') in remap:
                w['speaker'] = remap[w['speaker']]

    n_after = len({s.get('speaker') for s in result['segments'] if s.get('speaker')})
    print(f'  Speaker labels: {len(speaker_segs)} → {n_after}')
    return result


def write_txt(segments: list, output_path: str) -> None:
    """Write plain transcript in the existing Speaker N  HH:MM:SS  text format."""
    lines = []
    for seg in segments:
        label = seg.get('speaker', 'SPEAKER_00')
        num   = speaker_label_to_int(label)
        ts    = seconds_to_hms(seg['start'])
        text  = seg['text'].strip()
        if text:
            lines.append(f'Speaker {num}    {ts}    {text}')
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines) + '\n')


def main():
    parser = argparse.ArgumentParser(description='Transcribe a meeting with WhisperX')
    parser.add_argument('board', help='Board type (council, zoning, planning, ...)')
    parser.add_argument('date',  help='Meeting date (YYYY-MM-DD)')
    parser.add_argument('--model',           default='large-v3-turbo')
    parser.add_argument('--min-speakers',    type=int,   default=None)
    parser.add_argument('--max-speakers',    type=int,   default=None)
    parser.add_argument('--batch-size',      type=int,   default=16)
    parser.add_argument('--clustering-threshold', type=float, default=None, metavar='F',
                        help='Pyannote clustering threshold (lower = more splits, higher = more merging; '
                             'omit to use model default, pass --debug-merge to see current default)')
    parser.add_argument('--merge-threshold', type=float, default=0.82,
                        help='Cosine similarity to merge over-split speaker labels (0 to disable)')
    parser.add_argument('--debug-merge', action='store_true',
                        help='Print full pairwise similarity matrix and diarization model params')
    parser.add_argument('--dry-run', action='store_true')
    args = parser.parse_args()

    recording = find_recording(args.board, args.date)
    if not recording:
        print(f'ERROR: No recording found for {args.board}/{args.date}')
        sys.exit(1)

    token = load_token()
    if not token:
        print('ERROR: HuggingFace token not found (data/hf_token)')
        sys.exit(1)
    os.environ.setdefault('HF_TOKEN', token)

    import whisperx

    ct_device, compute_type = detect_ct2_device()
    torch_device = detect_torch_device()

    print(f'Loading model {args.model}...')
    model = whisperx.load_model(args.model, ct_device, compute_type=compute_type)

    print(f'Loading audio: {recording}')
    audio = whisperx.load_audio(recording)

    print('Transcribing...')
    result = model.transcribe(audio, batch_size=args.batch_size)
    lang   = result.get('language', 'en')
    print(f'Language: {lang}, {len(result["segments"])} segments')

    print('Aligning word timestamps...')
    align_model, metadata = whisperx.load_align_model(language_code=lang, device='cpu')
    result = whisperx.align(
        result['segments'], align_model, metadata, audio,
        'cpu', return_char_alignments=False,
    )

    print('Running speaker diarization...')
    diarize_kwargs = {}
    if args.min_speakers:
        diarize_kwargs['min_speakers'] = args.min_speakers
    if args.max_speakers:
        diarize_kwargs['max_speakers'] = args.max_speakers

    def run_diarization(device):
        pipeline = whisperx.diarize.DiarizationPipeline(token=token, device=device)
        if args.clustering_threshold is not None:
            try:
                params = dict(pipeline.model.parameters(instantiated=True))
                # Print current defaults on first call so the user knows the baseline
                if str(device) == str(torch_device):
                    print(f'  Diarization model params: {params}')
                pipeline.model.instantiate(
                    {'clustering': {'threshold': args.clustering_threshold}}
                )
                print(f'  Clustering threshold set to {args.clustering_threshold}')
            except Exception as e:
                print(f'  WARNING: could not set clustering threshold: {e}')
        return pipeline(audio, **diarize_kwargs)

    try:
        diarize_segments = run_diarization(torch_device)
    except RuntimeError as e:
        if 'UR error' in str(e) and str(torch_device) == 'xpu':
            print(f'  XPU diarization failed ({e}), retrying on CPU...')
            import torch
            diarize_segments = run_diarization(torch.device('cpu'))
        else:
            raise
    result = whisperx.assign_word_speakers(diarize_segments, result)

    segments   = result['segments']
    n_speakers = len({s.get('speaker', '') for s in segments if s.get('speaker')})
    print(f'Diarization: {len(segments)} segments, {n_speakers} speaker(s)')

    if args.merge_threshold > 0 or args.debug_merge:
        print(f'Merging similar speakers (threshold={args.merge_threshold})...')
        result = merge_similar_speakers(result, audio, token, args.merge_threshold,
                                        debug=args.debug_merge)
        segments = result['segments']

    n_final = len({s.get('speaker', '') for s in segments if s.get('speaker')})
    print(f'Done: {len(segments)} segments, {n_final} speaker(s)')

    if args.dry_run:
        print('Dry run — not writing output.')
        return

    out_dir  = os.path.join(BASE_DIR, 'web', 'files', args.board)
    json_out = os.path.join(out_dir, f'{args.date}.whisperx.json')
    txt_out  = os.path.join(out_dir, f'{args.date}.txt')

    with open(json_out, 'w', encoding='utf-8') as f:
        json.dump({'segments': segments}, f, indent=2, ensure_ascii=False)
    print(f'Written: {json_out}')

    write_txt(segments, txt_out)
    print(f'Written: {txt_out}')


if __name__ == '__main__':
    main()
