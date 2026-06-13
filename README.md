# audio-watermark-lambda

Forensic audio watermarking service. Embeds a unique 32-bit `user_id` into
audiobook WAV files using DCT spread-spectrum so leaked copies can be traced
back to the buyer. Detection recovers the `user_id` from any copy — even after
MP3 re-encoding at 64 kbps.

**Current phase:** local algorithm + Lambda code. No AWS resources deployed yet.

---

## Continuing development on your Mac

### 1. Prerequisites

Install the following before cloning:

**Homebrew** (if not already installed):
```bash
/bin/bash -c "$(curl -fsSL https://raw.githubusercontent.com/Homebrew/install/HEAD/install.sh)"
```

**Python 3.11+:**
```bash
brew install python@3.11
```

Verify: `python3.11 --version` should print `3.11.x` or higher.

**ffmpeg** (required for MP3 roundtrip tests and the `roundtrip-test` CLI command):
```bash
brew install ffmpeg
```

Verify: `ffmpeg -version` should print version info.

---

### 2. Clone and set up

```bash
git clone https://github.com/l-k-sfr-jt/audio-watermark.git
cd audio-watermark
```

Create and activate a virtual environment (keeps dependencies isolated):
```bash
python3.11 -m venv .venv
source .venv/bin/activate
```

Install dependencies:
```bash
pip install -r requirements.txt
```

---

### 3. Run the test suite

```bash
pytest tests/ -v
```

Expected output — all 6 tests should pass (including the 2 MP3 robustness
tests now that ffmpeg is installed):

```
tests/test_watermark.py::test_embed_detect_roundtrip     PASSED
tests/test_watermark.py::test_different_user_ids         PASSED
tests/test_watermark.py::test_output_is_wav              PASSED
tests/test_watermark.py::test_short_audio_padded         PASSED
tests/test_watermark.py::test_mp3_64kbps_roundtrip       PASSED
tests/test_watermark.py::test_mp3_128kbps_roundtrip      PASSED
```

If the MP3 tests still skip, run `which ffmpeg` — it must be on your `PATH`
and visible to the Python process. With Homebrew you may need to restart your
terminal or run `eval "$(/opt/homebrew/bin/brew shellenv)"` first.

---

### 4. Validate the watermark on a real audiobook

This is the primary local validation step before moving to AWS.

**Embed:**
```bash
python cli.py embed /path/to/audiobook.mp3 12345 output.wav
```

**Detect:**
```bash
python cli.py detect output.wav
# → Detected user_id: 12345
```

**Full MP3 roundtrip test** (embed → 64 kbps + 128 kbps MP3 → detect):
```bash
python cli.py roundtrip-test /path/to/audiobook.mp3 12345
```

Expected output:
```
Embedding watermark (user_id=12345) …
  [PASS] 64 kbps — expected 12345, detected 12345
  [PASS] 128 kbps — expected 12345, detected 12345
```

A `[FAIL]` at 64 kbps means the audio has unusually low energy in the
1.6–5.4 kHz DCT band. Increase `ALPHA` in `src/watermark.py` (try `0.08`)
and re-test.

**Listen for audibility:** open `output.wav` in any audio player and compare
it to the original. The watermark should be completely inaudible. If you hear
any artefacts, reduce `ALPHA`.

---

## Project structure

```
src/
  handler.py    # Lambda entry point — validates input, orchestrates all steps
  watermark.py  # Core DCT algorithm — no AWS imports (runs identically locally)
  storage.py    # S3 download / upload / presigned URL (boto3)
  notify.py     # SES email dispatch (boto3)
tests/
  test_watermark.py   # Roundtrip + MP3 robustness unit tests
  sample_audio/       # Drop short test fixtures here
cli.py          # Local CLI: embed / detect / roundtrip-test
requirements.txt
Dockerfile      # Container-image Lambda (bundles ffmpeg for MP3 decoding)
template.yaml   # SAM template — provisions S3 + SES + Lambda + API
samconfig.toml  # SAM deploy defaults (region eu-central-1)
scripts/        # check-prereqs / deploy / verify-recipient / smoke-test / teardown
docs/
  DEPLOYMENT.md # Zero-to-deployed AWS guide (account → live service)
```

---

## Environment variables (Lambda)

| Variable          | Description                           |
|-------------------|---------------------------------------|
| `BUCKET_NAME`     | S3 bucket for source and output audio |
| `SES_FROM_EMAIL`  | SES-verified sender address           |

---

## AWS deployment (Phase 3)

Full zero-to-deployed instructions — including creating the AWS account — are in
**[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**. The infrastructure is defined in
`template.yaml` (S3 bucket + SES identity + container-image Lambda + API
Gateway) and deployed with one script per stage.

Quick path once you have an AWS account and `aws`/`sam`/Docker installed:

```bash
./scripts/check-prereqs.sh                              # verify tooling + creds
./scripts/deploy.sh noreply@yourdomain.com              # build image + deploy stack
# → click the SES verification link AWS emails to that address
./scripts/verify-recipient.sh you@example.com           # SES sandbox: verify a test inbox
./scripts/smoke-test.sh audiobook.mp3 you@example.com   # upload, invoke, email the link
./scripts/teardown.sh                                   # remove everything when done
```

The Lambda is packaged as a **container image** (`Dockerfile`) so ffmpeg and the
scientific Python stack are bundled — no Lambda layer juggling, and MP3/WAV/FLAC
masters all decode. Region defaults to **eu-central-1**.

**Next (Phase 4):** the `/watermark` API endpoint is open by design for now;
add an API key or Lambda authorizer before wiring it to WooCommerce.

---

## Tuning the watermark

| Parameter | Location | Effect |
|-----------|----------|--------|
| `ALPHA` | `src/watermark.py` | Embedding strength — the mark is `ALPHA ×` a per-frequency masking envelope, so it hides under the audio's own spectrum. Higher = more robust, more audible. Start at 0.05; raise toward 0.1 if 64 kbps detection fails, lower toward 0.03 if you can still hear it. |
| `SPREAD_BINS` | `src/watermark.py` | How far the masking envelope spreads around each tone (~120 Hz at 11). Smaller hugs the tones more tightly (quieter, especially on sparse/tonal music) but leaves detection less margin. |
| `SILENCE_FLOOR` | `src/watermark.py` | Minimum mark level in deep spectral gaps / silence. Lower it (e.g. 0.0005) if you hear faint noise in quiet passages; raise it if detection is shaky on very quiet audio. |
| `NUM_BLOCKS` | `src/watermark.py` | More blocks = stronger watermark but longer processing region. 256 ≈ 12 s at 44.1 kHz. |
| `FREQ_LOW/HIGH` | `src/watermark.py` | Mid-frequency band. Avoid going below 100 (speech fundamentals) or above 600 (MP3 strips high freq at low bitrates). |

---

## Troubleshooting

**`SoundFileError` when loading MP3 locally**
Your libsndfile build doesn't support MP3. The code falls back to pydub/ffmpeg
automatically — make sure ffmpeg is installed.

**`FileNotFoundError` from pydub**
ffmpeg is not on PATH. Run `which ffmpeg` and `brew install ffmpeg` if missing.

**`ModuleNotFoundError: No module named 'src'`**
Run commands from the repo root (`audio-watermark/`), not from inside `src/`.

**Detection returns wrong user_id on a real audiobook**
The audio may have very low energy in the 1.6–5.4 kHz band. Increase `ALPHA`
to `0.04` and re-embed. Run `python cli.py roundtrip-test` again to confirm.
