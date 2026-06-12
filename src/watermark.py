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
_REQUIRED = BLOCK_SIZE * NUM_BLOCKS

# Pre-compute all PN sequences once at import time as a single (NUM_BLOCKS,
# _BAND_WIDTH) matrix. Seeded by block index so embed and detect always use
# identical sequences with no shared state, and so the whole watermark can be
# applied with vectorised array ops instead of a per-block Python loop.
_PN_MATRIX = np.stack(
    [np.random.default_rng(seed=b).choice(np.array([-1.0, 1.0]), size=_BAND_WIDTH)
     for b in range(NUM_BLOCKS)]
)

# Which payload bit each block carries: blocks [0..7]→bit 0, [8..15]→bit 1, …
_BLOCK_TO_BIT = np.arange(NUM_BLOCKS) // REPETITIONS


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

    if len(samples) < _REQUIRED:
        samples = np.pad(samples, (0, _REQUIRED - len(samples)))

    bits = np.array([(user_id >> i) & 1 for i in range(32)], dtype=np.float64)
    bits = bits * 2 - 1  # map {0,1} → {-1,+1}
    bit_per_block = bits[_BLOCK_TO_BIT]  # (NUM_BLOCKS,)

    out = samples.copy()

    # Reshape the embedding region into (NUM_BLOCKS, BLOCK_SIZE) and run a
    # single batched DCT over axis=1 instead of 256 separate calls.
    blocks = out[:_REQUIRED].reshape(NUM_BLOCKS, BLOCK_SIZE)
    coeffs = dct(blocks, type=2, norm="ortho", axis=1)
    coeffs[:, FREQ_LOW:FREQ_HIGH] += ALPHA * bit_per_block[:, None] * _PN_MATRIX
    out[:_REQUIRED] = idct(coeffs, type=2, norm="ortho", axis=1).reshape(-1)

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

    if len(samples) < _REQUIRED:
        return -1

    # Batched DCT over all blocks, then correlate each block's embedding band
    # against its PN sequence. Normalise by band width so noise scales as 1/√N.
    blocks = samples[:_REQUIRED].reshape(NUM_BLOCKS, BLOCK_SIZE)
    coeffs = dct(blocks, type=2, norm="ortho", axis=1)
    correlations = (coeffs[:, FREQ_LOW:FREQ_HIGH] * _PN_MATRIX).sum(axis=1) / _BAND_WIDTH

    # Majority vote: sum the REPETITIONS correlations per bit, take the sign.
    votes = correlations.reshape(32, REPETITIONS).sum(axis=1)
    recovered = (votes >= 0).astype(int)

    return sum(int(b) << i for i, b in enumerate(recovered))
