from __future__ import annotations

import logging
import os
import subprocess

import numpy as np
import soundfile as sf
from scipy.fft import dct, idct
from scipy.ndimage import maximum_filter1d

logger = logging.getLogger(__name__)

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


def _load_head(path: str, max_samples: int) -> tuple[np.ndarray, int, bool]:
    """Load up to max_samples from the start of audio as mono float64.

    Returns (samples, sample_rate, file_is_longer_than_max_samples).
    Uses soundfile for WAV/FLAC (partial read — only max_samples decoded into
    memory regardless of total file size) and ffmpeg pipe for MP3/other formats
    (also bounded: only the needed duration is decoded).
    """
    if not os.path.isfile(path):
        raise FileNotFoundError(f"No such audio file: {path}")

    try:
        with sf.SoundFile(path) as f:
            sr = f.samplerate
            file_is_longer = f.frames > max_samples
            raw = f.read(frames=min(max_samples, f.frames), dtype="float64", always_2d=True)
        if raw.shape[1] > 1:
            raw = raw.mean(axis=1)
        else:
            raw = raw[:, 0]
        return raw.copy(), sr, file_is_longer
    except sf.SoundFileError:
        pass  # fall through to ffmpeg for MP3 / unsupported formats

    # Probe for sample rate and duration without decoding the full file.
    try:
        probe = subprocess.run(
            [
                "ffprobe", "-v", "error", "-select_streams", "a:0",
                "-show_entries", "stream=sample_rate,duration",
                "-of", "default=noprint_wrappers=1:nokey=1",
                path,
            ],
            capture_output=True,
            text=True,
            check=True,
        )
    except subprocess.CalledProcessError as exc:
        raise RuntimeError(f"ffprobe failed on '{path}': {exc.stderr}") from exc

    lines = probe.stdout.strip().split("\n")
    sr = int(lines[0])
    try:
        file_is_longer = float(lines[1]) * sr > max_samples
    except (IndexError, ValueError):
        file_is_longer = True  # assume longer if duration unavailable

    head_seconds = max_samples / sr
    try:
        decoded = subprocess.run(
            [
                "ffmpeg", "-y",
                "-t", f"{head_seconds:.6f}",
                "-i", path,
                "-f", "f64le",
                "-ac", "1",
                "-ar", str(sr),
                "pipe:1",
            ],
            capture_output=True,
            check=True,
        )
    except subprocess.CalledProcessError as exc:
        raise RuntimeError(f"ffmpeg decode failed on '{path}': {exc.stderr.decode()}") from exc

    samples = np.frombuffer(decoded.stdout, dtype=np.float64).copy()
    return samples[:max_samples], sr, file_is_longer


def _stitch_with_tail(head_wav: str, original: str, skip_seconds: float, sr: int, out_wav: str) -> None:
    """Append original[skip_seconds:] (converted to mono at sr) after head_wav."""
    try:
        subprocess.run(
            [
                "ffmpeg", "-y",
                "-i", head_wav,
                "-ss", f"{skip_seconds:.6f}",
                "-i", original,
                "-filter_complex",
                (
                    f"[1:a]aresample={sr},"
                    "aformat=sample_fmts=s16:channel_layouts=mono[tail];"
                    "[0:a][tail]concat=n=2:v=0:a=1[out]"
                ),
                "-map", "[out]",
                out_wav,
            ],
            check=True,
            capture_output=True,
        )
    except subprocess.CalledProcessError as exc:
        raise RuntimeError(f"ffmpeg stitch failed: {exc.stderr.decode()}") from exc


def transcode_to_mp3(wav_path: str, mp3_path: str, bitrate: str = "128k") -> str:
    """Transcode a WAV to mono MP3 at the given bitrate using ffmpeg."""
    try:
        subprocess.run(
            ["ffmpeg", "-y", "-i", wav_path, "-b:a", bitrate, "-ac", "1", mp3_path],
            check=True,
            capture_output=True,
        )
    except subprocess.CalledProcessError as exc:
        raise RuntimeError(f"ffmpeg transcode failed: {exc.stderr.decode()}") from exc
    return mp3_path


def _ffprobe_stream_info(path: str) -> tuple[str, int, str | None]:
    """Return (codec_name, channels, bit_rate) for the first audio stream.

    bit_rate is the per-stream bitrate in bits/s as a string (e.g. "128000"),
    or None if ffprobe doesn't report it.
    """
    out = subprocess.run(
        [
            "ffprobe", "-v", "error", "-select_streams", "a:0",
            "-show_entries", "stream=codec_name,channels,bit_rate",
            "-of", "default=noprint_wrappers=1:nokey=0",
            path,
        ],
        capture_output=True,
        text=True,
        check=True,
    )
    info: dict[str, str] = {}
    for line in out.stdout.strip().split("\n"):
        if "=" in line:
            key, value = line.split("=", 1)
            info[key] = value.strip()
    codec = info.get("codec_name", "")
    try:
        channels = int(info.get("channels", "1"))
    except ValueError:
        channels = 1
    bit_rate = info.get("bit_rate", "")
    return codec, max(channels, 1), (bit_rate if bit_rate.isdigit() else None)


def _decode_head_native(path: str, head_seconds: float, sr: int, channels: int) -> np.ndarray:
    """Decode the first head_seconds at the source channel count as float32 (n, channels)."""
    decoded = subprocess.run(
        [
            "ffmpeg", "-y",
            "-t", f"{head_seconds:.6f}",
            "-i", path,
            "-f", "f32le",
            "-ac", str(channels),
            "-ar", str(sr),
            "pipe:1",
        ],
        capture_output=True,
        check=True,
    )
    arr = np.frombuffer(decoded.stdout, dtype=np.float32)
    usable = (arr.size // channels) * channels
    return arr[:usable].reshape(-1, channels).copy()


def embed_mp3(input_path: str, user_id: int, mp3_output_path: str,
              default_bitrate: str = "128k") -> str:
    """Embed the watermark and write an MP3, stream-copying the untouched tail.

    For an MP3 source longer than the ~12 s marked head, only the head is
    re-encoded; the remainder of the original is concatenated WITHOUT re-encoding
    (`-c copy`). The tail therefore stays bit-identical to the master (no quality
    loss, near-instant) — only the watermarked head incurs one encode, at the
    source's own bitrate. Falls back to the full decode→watermark→transcode path
    for non-MP3 sources, very short files, or if stream-copy fails for any reason.
    """
    try:
        codec, channels, src_bitrate = _ffprobe_stream_info(input_path)
    except Exception as exc:  # noqa: BLE001 - probe failure → safe fallback
        logger.warning("ffprobe failed (%s); using full transcode path", exc)
        codec, channels, src_bitrate = "", 1, None

    if codec == "mp3":
        try:
            return _embed_mp3_streamcopy(
                input_path, user_id, mp3_output_path, channels, src_bitrate, default_bitrate
            )
        except Exception as exc:  # noqa: BLE001 - any failure → full re-encode fallback
            logger.warning("stream-copy mux failed (%s); falling back to full transcode", exc)

    # Fallback: decode → watermark → full WAV → transcode to MP3.
    base, _ = os.path.splitext(mp3_output_path)
    wav_path = base + ".full.wav"
    try:
        embed_watermark(input_path, user_id, wav_path)
        transcode_to_mp3(wav_path, mp3_output_path, bitrate=default_bitrate)
    finally:
        if os.path.exists(wav_path):
            os.remove(wav_path)
    return mp3_output_path


def _embed_mp3_streamcopy(input_path: str, user_id: int, mp3_output_path: str,
                          channels: int, src_bitrate: str | None, default_bitrate: str) -> str:
    """Stream-copy fast path used by embed_mp3() for MP3 sources. See embed_mp3()."""
    marked_mono, original_mono, sr, file_is_longer = _compute_marked_head(input_path, user_id)
    bitrate = src_bitrate or default_bitrate  # ffmpeg -b:a accepts raw bits/s or "128k"
    head_seconds = _REQUIRED / sr

    head_wav = mp3_output_path + ".head.wav"
    head_mp3 = mp3_output_path + ".head.mp3"
    tail_mp3 = mp3_output_path + ".tail.mp3"
    list_txt = mp3_output_path + ".concat.txt"
    cleanup = [head_wav, head_mp3, tail_mp3, list_txt]

    try:
        # Build the watermarked head WAV at the source channel count. For stereo
        # the mono mark is applied as an equal per-sample delta on every channel,
        # so the mono mixdown the detector reads still carries it while stereo is
        # preserved for clean stream-copy concatenation with the original tail.
        if channels > 1:
            native = _decode_head_native(input_path, head_seconds, sr, channels)
            delta = (marked_mono - original_mono).astype(np.float32)
            n = min(len(delta), native.shape[0])
            native[:n] += delta[:n, None]
            np.clip(native, -1.0, 1.0, out=native)
            pcm = (native * 32767).astype(np.int16)
        else:
            pcm = (marked_mono * 32767).astype(np.int16)
        sf.write(head_wav, pcm, sr, subtype="PCM_16")

        # Short file (no tail): just encode the marked head.
        if not file_is_longer:
            _encode_mp3(head_wav, mp3_output_path, bitrate, sr, channels)
            return mp3_output_path

        _encode_mp3(head_wav, head_mp3, bitrate, sr, channels)
        _copy_tail(input_path, head_seconds, tail_mp3)
        _concat_copy([head_mp3, tail_mp3], list_txt, mp3_output_path)
        return mp3_output_path
    finally:
        for path in cleanup:
            if os.path.exists(path):
                os.remove(path)


def _encode_mp3(wav_path: str, mp3_path: str, bitrate: str, sr: int, channels: int) -> None:
    """Encode a WAV to MP3 at the given bitrate/sample-rate/channel count."""
    try:
        subprocess.run(
            [
                "ffmpeg", "-y", "-i", wav_path,
                "-b:a", bitrate, "-ar", str(sr), "-ac", str(max(channels, 1)),
                mp3_path,
            ],
            check=True,
            capture_output=True,
        )
    except subprocess.CalledProcessError as exc:
        raise RuntimeError(f"ffmpeg head encode failed: {exc.stderr.decode()}") from exc


def _copy_tail(original: str, start_seconds: float, tail_path: str) -> None:
    """Copy original[start_seconds:] to tail_path WITHOUT re-encoding (-c copy)."""
    try:
        subprocess.run(
            [
                "ffmpeg", "-y",
                "-ss", f"{start_seconds:.6f}",
                "-i", original,
                "-map", "0:a:0",
                "-c", "copy",
                tail_path,
            ],
            check=True,
            capture_output=True,
        )
    except subprocess.CalledProcessError as exc:
        raise RuntimeError(f"ffmpeg tail copy failed: {exc.stderr.decode()}") from exc


def _concat_copy(parts: list[str], list_path: str, out_path: str) -> None:
    """Concatenate MP3 parts with the concat demuxer and -c copy (no re-encode)."""
    with open(list_path, "w", encoding="utf-8") as fh:
        for part in parts:
            # The concat demuxer needs single-quoted, absolute paths.
            fh.write(f"file '{os.path.abspath(part)}'\n")
    try:
        subprocess.run(
            [
                "ffmpeg", "-y",
                "-f", "concat", "-safe", "0",
                "-i", list_path,
                "-c", "copy",
                out_path,
            ],
            check=True,
            capture_output=True,
        )
    except subprocess.CalledProcessError as exc:
        raise RuntimeError(f"ffmpeg concat failed: {exc.stderr.decode()}") from exc


def embed_watermark(input_path: str, user_id: int, output_path: str | None = None) -> str:
    """Embed user_id into the first ~12 s of audio. Returns path to WAV output.

    Memory-bounded: only _REQUIRED samples (~12 s at 44.1 kHz) are decoded into
    numpy regardless of total file length. For files longer than ~12 s the
    untouched tail is stitched back via ffmpeg, so a multi-hour audiobook master
    never loads more than a few MB into Python.
    """
    if output_path is None:
        base = os.path.splitext(input_path)[0]
        output_path = base + "_wm.wav"

    marked_head, _original_mono, sr, file_is_longer = _compute_marked_head(input_path, user_id)
    pcm = (marked_head * 32767).astype(np.int16)

    if not file_is_longer:
        sf.write(output_path, pcm, sr, subtype="PCM_16")
        return output_path

    # File has a tail: write the marked head to a temp WAV, then stitch.
    head_wav = output_path + ".head.wav"
    try:
        sf.write(head_wav, pcm, sr, subtype="PCM_16")
        _stitch_with_tail(head_wav, input_path, _REQUIRED / sr, sr, output_path)
    finally:
        if os.path.exists(head_wav):
            os.remove(head_wav)
    return output_path


def _compute_marked_head(input_path: str, user_id: int) -> tuple[np.ndarray, np.ndarray, int, bool]:
    """Decode the first ~12 s as mono and embed the watermark into it.

    Returns (marked_head, original_mono, sample_rate, file_is_longer):
      - marked_head:   mono float in [-1, 1], length _REQUIRED, watermark embedded
      - original_mono: the same window before embedding (used to derive the
                       per-sample delta when re-applying the mark to a stereo head)
    """
    head, sr, file_is_longer = _load_head(input_path, _REQUIRED)
    if len(head) < _REQUIRED:
        head = np.pad(head, (0, _REQUIRED - len(head)))
    original_mono = head.copy()

    bits = np.array([(user_id >> i) & 1 for i in range(32)], dtype=np.float64)
    bits = bits * 2 - 1  # map {0,1} → {-1,+1}
    bit_per_block = bits[_BLOCK_TO_BIT]  # (NUM_BLOCKS,)

    blocks = head.reshape(NUM_BLOCKS, BLOCK_SIZE)
    coeffs = dct(blocks, type=2, norm="ortho", axis=1)

    band = coeffs[:, FREQ_LOW:FREQ_HIGH]
    envelope = _masking_envelope(band)
    coeffs[:, FREQ_LOW:FREQ_HIGH] += (
        ALPHA * bit_per_block[:, None] * envelope * _PN_MATRIX
    )
    marked_head = idct(coeffs, type=2, norm="ortho", axis=1).reshape(-1)
    marked_head = np.clip(marked_head, -1.0, 1.0)
    return marked_head, original_mono, sr, file_is_longer

    return output_path


def detect_watermark(input_path: str) -> tuple[int, float]:
    """Detect the embedded user_id.

    Returns ``(user_id, confidence)`` where confidence is a 0-1 score.
    1.0 means every bit voted unanimously; values above ~0.1 are reliable.
    Returns ``(-1, 0.0)`` when the signal is too short.
    """
    samples, _, _ = _load_head(input_path, _REQUIRED)

    if len(samples) < _REQUIRED:
        return -1, 0.0

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

    # Confidence: weakest-bit margin relative to the strongest. Near 1.0 means
    # every bit had a clear vote; near 0.0 means at least one bit was a
    # near-toss-up. No extra compute — votes were already computed above.
    abs_votes = np.abs(votes)
    max_v = float(abs_votes.max())
    confidence = float(abs_votes.min()) / max_v if max_v > 0 else 0.0

    code = sum(int(b) << i for i, b in enumerate(recovered))
    return code, confidence
