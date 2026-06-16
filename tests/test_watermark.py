import shutil
import tempfile
from pathlib import Path

import numpy as np
import pytest
import soundfile as sf

from src.watermark import BLOCK_SIZE, NUM_BLOCKS, detect_watermark, embed_watermark

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
