import os

import numpy as np
import soundfile as sf
from pydub import AudioSegment
from scipy.fft import dct, idct
from scipy.ndimage import maximum_filter1d

BLOCK_SIZE = 2048
NUM_BLOCKS = 256
FREQ_LOW = 150
FREQ_HIGH = 500
REPETITIONS = 8
USER_ID_BITS = 32

# Embedding is shaped by a PERCEPTUAL MASKING ENVELOPE. For each block we build
# an envelope by spreading the host's band magnitude across neighbouring bins
# (a crude frequency-masking model: a tone masks nearby frequencies too). The
# watermark on each coefficient is ALPHA times that envelope, so the mark is
# loud near the tones that hide it and fades to near-zero in the quiet gaps
# between them — where flat broadband noise would otherwise be audible as
# "graining". This is what keeps it inaudible on tonal/music content; speech is
# noise-like and forgiving, but music exposes any energy added between its tones.
#
# Detection then WHITENS: it divides each coefficient by the same envelope
# before correlating, so the few loud host tones can no longer swamp the vote
# (the failure mode of naive multiplicative embedding). See detect_watermark.
ALPHA = 0.05
# Bins to spread the masking envelope over (each side). 1 DCT bin ≈ 10.8 Hz, so
# ~11 bins ≈ 120 Hz — roughly local critical-band masking. Smaller = the mark
# hugs the tones more tightly (quieter) but gives detection less to work with.
SPREAD_BINS = 11
# Envelope floor so coefficients in deep gaps / silence still carry a faint,
# detectable mark without exposing audible noise. Keeps detection graceful on
# very quiet or sparse material instead of dropping those blocks entirely.
SILENCE_FLOOR = 0.001

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

# Which payload bit each block carries. INTERLEAVED: block i holds bit
# (i % USER_ID_BITS), so each bit's REPETITIONS copies are spread evenly across
# the whole 12 s window (every ~1.5 s) rather than clustered into one contiguous
# stretch. A brief quiet intro therefore can't knock out the low-numbered bits.
_BLOCK_TO_BIT = np.arange(NUM_BLOCKS) % USER_ID_BITS


def _masking_envelope(band: np.ndarray) -> np.ndarray:
    """Per-coefficient masking envelope for a batch of band slices.

    Spreads each block's band magnitude across +/- SPREAD_BINS neighbours (a
    tone masks nearby frequencies), then floors it. Used identically by embed
    (to shape the mark) and detect (to whiten the host out). `band` is
    (NUM_BLOCKS, _BAND_WIDTH); the envelope has the same shape.
    """
    spread = maximum_filter1d(np.abs(band), size=2 * SPREAD_BINS + 1, axis=1, mode="nearest")
    return np.maximum(spread, SILENCE_FLOOR)


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

    # Shape the mark by the masking envelope so it sits under the audio's own
    # spectrum: loud near the tones that hide it, near-silent in the gaps.
    band = coeffs[:, FREQ_LOW:FREQ_HIGH]
    envelope = _masking_envelope(band)
    coeffs[:, FREQ_LOW:FREQ_HIGH] += (
        ALPHA * bit_per_block[:, None] * envelope * _PN_MATRIX
    )
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
    # against its PN sequence. WHITEN first: divide each coefficient by the same
    # masking envelope used at embed time. Where the host is loud (tones), the
    # ratio is bounded ~O(1) instead of dominating, so a few big tonal
    # coefficients can no longer swamp the vote. The mark (which was ALPHA *
    # envelope * PN) whitens to a clean ALPHA * PN, while the host whitens to
    # bounded noise that averages out across the band.
    blocks = samples[:_REQUIRED].reshape(NUM_BLOCKS, BLOCK_SIZE)
    coeffs = dct(blocks, type=2, norm="ortho", axis=1)
    band = coeffs[:, FREQ_LOW:FREQ_HIGH]
    whitened = band / _masking_envelope(band)
    correlations = (whitened * _PN_MATRIX).sum(axis=1) / _BAND_WIDTH

    # Sum each bit's REPETITIONS correlations, then take the sign. Blocks are
    # interleaved (block i holds bit i % USER_ID_BITS), so reshaping to
    # (REPETITIONS, USER_ID_BITS) puts all copies of bit c in column c. Louder
    # blocks naturally contribute larger correlations, weighting reliable blocks.
    votes = correlations.reshape(REPETITIONS, USER_ID_BITS).sum(axis=0)
    recovered = (votes >= 0).astype(int)

    return sum(int(b) << i for i, b in enumerate(recovered))
