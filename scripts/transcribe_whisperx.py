#!/usr/bin/env python3
"""
Transcribe a meeting recording using WhisperX (local GPU-accelerated STT).

Reads:  web/files/{board}/{date}.mp3 or .m4a
Writes: web/files/{board}/{date}.whisperx.json  - word-level segments with speaker IDs
        web/files/{board}/{date}.txt             - plain transcript (Speaker N  HH:MM:SS  text)

Usage:
    python3 transcribe_whisperx.py council 2026-05-14 [options]

Options:
    --model MODEL       WhisperX model (default: large-v3-turbo)
    --min-speakers N    Minimum speakers for diarization
    --max-speakers N    Maximum speakers for diarization
    --batch-size N      Batch size (default: 16, reduce if out of memory)
    --dry-run           Transcribe but do not write output files
"""
import argparse
import ctypes
import json
import os
import sys
import warnings

warnings.filterwarnings('ignore')

# Must run before torch import so the Level Zero adapter can enumerate the GPU
try:
    ctypes.CDLL('libze_loader.so.1').zeInit(1)
except OSError:
    pass

BASE_DIR   = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TOKEN_FILE = os.path.join(BASE_DIR, 'data', 'hf_token')


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
        supported = ctranslate2.get_supported_compute_types('opencl')
        if supported:
            print('CTranslate2 device: opencl')
            return 'opencl', 'default'
    except Exception:
        pass
    print('CTranslate2 device: cpu (int8)')
    return 'cpu', 'int8'


def detect_torch_device():
    """Return torch device for pyannote diarization."""
    import torch
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


def write_txt(segments: list, output_path: str) -> None:
    """Write plain transcript in the existing Speaker N  HH:MM:SS  text format."""
    lines = []
    for seg in segments:
        label  = seg.get('speaker', 'SPEAKER_00')
        num    = speaker_label_to_int(label)
        ts     = seconds_to_hms(seg['start'])
        text   = seg['text'].strip()
        if text:
            lines.append(f'Speaker {num}    {ts}    {text}')
    with open(output_path, 'w', encoding='utf-8') as f:
        f.write('\n'.join(lines) + '\n')


def main():
    parser = argparse.ArgumentParser(description='Transcribe a meeting with WhisperX')
    parser.add_argument('board', help='Board type (council, zoning, planning, ...)')
    parser.add_argument('date',  help='Meeting date (YYYY-MM-DD)')
    parser.add_argument('--model',        default='large-v3-turbo')
    parser.add_argument('--min-speakers', type=int, default=None)
    parser.add_argument('--max-speakers', type=int, default=None)
    parser.add_argument('--batch-size',   type=int, default=16)
    parser.add_argument('--dry-run',      action='store_true')
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
    align_model, metadata = whisperx.load_align_model(
        language_code=lang, device=str(torch_device)
    )
    result = whisperx.align(
        result['segments'], align_model, metadata, audio,
        str(torch_device), return_char_alignments=False,
    )

    print('Running speaker diarization...')
    diarize_kwargs = {}
    if args.min_speakers:
        diarize_kwargs['min_speakers'] = args.min_speakers
    if args.max_speakers:
        diarize_kwargs['max_speakers'] = args.max_speakers
    diarize_pipeline = whisperx.DiarizationPipeline(use_auth_token=token, device=torch_device)
    diarize_segments = diarize_pipeline(audio, **diarize_kwargs)
    result = whisperx.assign_word_speakers(diarize_segments, result)

    segments   = result['segments']
    n_speakers = len({s.get('speaker', '') for s in segments if s.get('speaker')})
    print(f'Done: {len(segments)} segments, {n_speakers} speaker(s)')

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
