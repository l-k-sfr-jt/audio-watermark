import shutil
import subprocess
import tempfile
from pathlib import Path

import numpy as np
import pytest
import soundfile as sf

from src.watermark import (
    BLOCK_SIZE,
    NUM_BLOCKS,
    detect_watermark,
    embed_mp3,
    embed_watermark,
)

SAMPLE_RATE = 44100
# Enough samples to cover all 256 blocks with a little extra
SIGNAL_LEN = BLOCK_SIZE * NUM_BLOCKS + 4096


def _write_noise_wav(path: str) -> None:
    rng = np.random.default_rng(42)
    samples = rng.uniform(-0.5, 0.5, SIGNAL_LEN).astype(np.float32)
    sf.write(path, samples, SAMPLE_RATE, subtype="PCM_16")


# ---------------------------------------------------------------------------
# Test 1: basic embed / detect roundtrip (no ffmpeg required)
# ---------------------------------------------------------------------------

def test_embed_detect_roundtrip(tmp_path):
    wav_in = str(tmp_path / "noise.wav")
    _write_noise_wav(wav_in)

    wm_path = embed_watermark(wav_in, 12345)
    assert Path(wm_path).exists()
    code, confidence = detect_watermark(wm_path)
    assert code == 12345
    assert 0.0 <= confidence <= 1.0


def test_different_user_ids(tmp_path):
    wav_in = str(tmp_path / "noise.wav")
    _write_noise_wav(wav_in)

    for uid in [0, 1, 2**32 - 1, 99999, 4582]:
        wm_path = embed_watermark(wav_in, uid, str(tmp_path / f"wm_{uid}.wav"))
        code, _conf = detect_watermark(wm_path)
        assert code == uid, f"Failed for user_id={uid}"


def test_output_is_wav(tmp_path):
    wav_in = str(tmp_path / "noise.wav")
    _write_noise_wav(wav_in)
    wm_path = embed_watermark(wav_in, 1)
    assert wm_path.endswith(".wav")
    info = sf.info(wm_path)
    assert info.subtype == "PCM_16"


def test_short_audio_padded(tmp_path):
    """Audio shorter than NUM_BLOCKS * BLOCK_SIZE should be padded, not raise."""
    short = np.zeros(1000, dtype=np.float32)
    wav_in = str(tmp_path / "short.wav")
    sf.write(wav_in, short, SAMPLE_RATE, subtype="PCM_16")
    wm_path = embed_watermark(wav_in, 7)
    code, _conf = detect_watermark(wm_path)
    assert code == 7


# ---------------------------------------------------------------------------
# Test 2: MP3 64 kbps robustness (requires ffmpeg)
# ---------------------------------------------------------------------------

@pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg not on PATH — skipping MP3 robustness test")
def test_mp3_64kbps_roundtrip(tmp_path):
    from pydub import AudioSegment

    wav_in = str(tmp_path / "noise.wav")
    _write_noise_wav(wav_in)

    wm_path = embed_watermark(wav_in, 12345)

    mp3_path = str(tmp_path / "test_64k.mp3")
    AudioSegment.from_wav(wm_path).export(mp3_path, format="mp3", bitrate="64k")

    detected, _conf = detect_watermark(mp3_path)
    assert detected == 12345, f"MP3 64kbps roundtrip failed: detected {detected}, expected 12345"


@pytest.mark.skipif(shutil.which("ffmpeg") is None, reason="ffmpeg not on PATH — skipping MP3 robustness test")
def test_mp3_128kbps_roundtrip(tmp_path):
    from pydub import AudioSegment

    wav_in = str(tmp_path / "noise.wav")
    _write_noise_wav(wav_in)

    wm_path = embed_watermark(wav_in, 99999)

    mp3_path = str(tmp_path / "test_128k.mp3")
    AudioSegment.from_wav(wm_path).export(mp3_path, format="mp3", bitrate="128k")

    detected, _conf = detect_watermark(mp3_path)
    assert detected == 99999, f"MP3 128kbps roundtrip failed: detected {detected}, expected 99999"


# ---------------------------------------------------------------------------
# Test 3: embed_mp3 stream-copy path (requires ffmpeg + ffprobe)
# ---------------------------------------------------------------------------

def _make_mp3(path: str, seconds: int, channels: int, bitrate: str = "128k") -> None:
    """Synthesize a tone+noise MP3 master of the given length/channels."""
    n = int(seconds * SAMPLE_RATE)
    t = np.arange(n) / SAMPLE_RATE
    rng = np.random.RandomState(0)
    sig = 0.3 * np.sin(2 * np.pi * 220 * t) + 0.05 * rng.randn(n)
    data = sig if channels == 1 else np.stack([sig, 0.9 * sig], axis=1)
    wav = path + ".src.wav"
    sf.write(wav, data.astype(np.float32), SAMPLE_RATE)
    subprocess.run(
        ["ffmpeg", "-y", "-i", wav, "-b:a", bitrate, "-ac", str(channels), path],
        check=True, capture_output=True,
    )
    Path(wav).unlink()


def _decode_mono(path: str) -> np.ndarray:
    out = subprocess.run(
        ["ffmpeg", "-y", "-i", path, "-f", "f32le", "-ac", "1", "-ar", str(SAMPLE_RATE), "pipe:1"],
        check=True, capture_output=True,
    )
    return np.frombuffer(out.stdout, dtype=np.float32)


@pytest.mark.skipif(
    shutil.which("ffmpeg") is None or shutil.which("ffprobe") is None,
    reason="ffmpeg/ffprobe not on PATH — skipping stream-copy test",
)
@pytest.mark.parametrize("channels", [1, 2])
def test_embed_mp3_streamcopy_detects(tmp_path, channels):
    """embed_mp3 watermarks an MP3 master and the code is recoverable (mono+stereo)."""
    master = str(tmp_path / "master.mp3")
    out = str(tmp_path / "out.mp3")
    _make_mp3(master, seconds=20, channels=channels)

    embed_mp3(master, 456789, out)
    assert Path(out).exists()

    detected, conf = detect_watermark(out)
    assert detected == 456789, f"channels={channels}: detected {detected}"
    assert conf > 0.1, f"channels={channels}: low confidence {conf}"


@pytest.mark.skipif(
    shutil.which("ffmpeg") is None or shutil.which("ffprobe") is None,
    reason="ffmpeg/ffprobe not on PATH — skipping stream-copy fidelity test",
)
def test_embed_mp3_tail_is_bit_identical(tmp_path):
    """The untouched tail of an embed_mp3 output must be bit-faithful to the master.

    MP3 carries a fixed encoder delay (one 1152-sample frame), so the output is
    shifted by exactly that; after compensating, the tail past the watermarked
    head must match the original sample-for-sample (zero residual = stream-copied,
    not re-encoded).
    """
    master = str(tmp_path / "master.mp3")
    out = str(tmp_path / "out.mp3")
    _make_mp3(master, seconds=30, channels=1)
    embed_mp3(master, 999, out)

    a = _decode_mono(master)
    b = _decode_mono(out)
    sr = SAMPLE_RATE
    lag = 1152  # standard MP3 encoder delay (one frame)
    seg = slice(15 * sr, 28 * sr)  # well past the ~11.9 s watermarked head
    aa = a[seg]
    bb = b[15 * sr + lag:28 * sr + lag]
    n = min(len(aa), len(bb))
    assert n > sr, "not enough tail to compare"
    residual = float(np.max(np.abs(aa[:n] - bb[:n])))
    assert residual == 0.0, f"tail not bit-identical after de-delay: max residual {residual}"
