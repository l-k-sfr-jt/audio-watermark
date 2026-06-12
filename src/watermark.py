import os

import numpy as np
import soundfile as sf
from pydub import AudioSegment
from scipy.fft import dct, idct

BLOCK_SIZE = 2048
NUM_BLOCKS = 256
FREQ_LOW = 150
FREQ_HIGH = 500
REPETITIONS = 8
ALPHA = 0.025

_BAND_WIDTH = FREQ_HIGH - FREQ_LOW  # 350

# Pre-compute all PN sequences once at import time — eliminates 512 per-call
# RNG constructions per embed+detect cycle. Seeded by block index so embed and
# detect always use identical sequences without any shared state.
_PN_SEQUENCES: list[np.ndarray] = [
    np.random.default_rng(seed=b).choice(np.array([-1.0, 1.0]), size=_BAND_WIDTH)
    for b in range(NUM_BLOCKS)
]


def _load_mono_float(path: str) -> tuple[np.ndarray, int]:
    """Load any audio file as mono float64 in [-1.0, 1.0].

    soundfile handles WAV/FLAC/OGG natively.  MP3 (and anything else
    libsndfile can't read) falls back to pydub/ffmpeg.  Only format errors
    trigger the fallback — OS-level errors (missing file, permission denied)
    are re-raised immediately so callers see the real cause.
    """
    try:
        samples, sr = sf.read(path, always_2d=False, dtype="float64")
    except sf.SoundFileError:
        # libsndfile cannot decode this format (e.g. MP3) — try pydub/ffmpeg
        seg = AudioSegment.from_file(path)
        seg = seg.set_channels(1)
        raw = np.array(seg.get_array_of_samples(), dtype=np.float64)
        samples = raw / (2 ** (seg.sample_width * 8 - 1))
        sr = seg.frame_rate
        return samples, sr

    if samples.ndim == 2:
        samples = samples.mean(axis=1)
    return samples, sr


def embed_watermark(input_path: str, user_id: int, output_path: str | None = None) -> str:
    """Embed user_id into the first ~12 s of audio. Returns path to WAV output."""
    samples, sr = _load_mono_float(input_path)

    required = BLOCK_SIZE * NUM_BLOCKS
    if len(samples) < required:
        samples = np.pad(samples, (0, required - len(samples)))

    bits = np.array([(user_id >> i) & 1 for i in range(32)], dtype=np.float64)
    bits = bits * 2 - 1  # map {0,1} → {-1,+1}

    out = samples.copy()
    for b in range(NUM_BLOCKS):
        bit_val = bits[b // REPETITIONS]
        start = b * BLOCK_SIZE
        coeffs = dct(out[start : start + BLOCK_SIZE], type=2, norm="ortho")
        coeffs[FREQ_LOW:FREQ_HIGH] += ALPHA * bit_val * _PN_SEQUENCES[b]
        out[start : start + BLOCK_SIZE] = idct(coeffs, type=2, norm="ortho")

    out = np.clip(out, -1.0, 1.0)
    pcm = (out * 32767).astype(np.int16)

    if output_path is None:
        base = os.path.splitext(input_path)[0]
        output_path = base + "_wm.wav"

    sf.write(output_path, pcm, sr, subtype="PCM_16")
    return output_path


def detect_watermark(input_path: str) -> int:
    """Detect and return the embedded user_id, or -1 if signal is too short."""
    samples, _ = _load_mono_float(input_path)

    if len(samples) < BLOCK_SIZE * NUM_BLOCKS:
        return -1

    correlations = np.zeros(NUM_BLOCKS)
    for b in range(NUM_BLOCKS):
        start = b * BLOCK_SIZE
        coeffs = dct(samples[start : start + BLOCK_SIZE], type=2, norm="ortho")
        # Normalise by band width: averages 350 coefficients → noise ∝ 1/√N
        correlations[b] = np.dot(coeffs[FREQ_LOW:FREQ_HIGH], _PN_SEQUENCES[b]) / _BAND_WIDTH

    recovered = []
    for bit_idx in range(32):
        chunk = correlations[bit_idx * REPETITIONS : (bit_idx + 1) * REPETITIONS]
        bit = 1 if np.sum(chunk) >= 0 else 0
        recovered.append(bit)

    return sum(b << i for i, b in enumerate(recovered))
