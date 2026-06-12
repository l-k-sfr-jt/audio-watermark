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
1.6–5.4 kHz DCT band. Increase `ALPHA` in `src/watermark.py` (try `0.04`)
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
template.yaml   # SAM scaffold — DO NOT deploy yet
```

---

## Environment variables (Lambda)

| Variable          | Description                           |
|-------------------|---------------------------------------|
| `BUCKET_NAME`     | S3 bucket for source and output audio |
| `SES_FROM_EMAIL`  | SES-verified sender address           |

---

## Next steps — AWS deployment (Phase 3)

Once local roundtrip tests pass on real audiobooks, the deployment sequence is:

### Step 1 — AWS account prerequisites
- An AWS account with CLI access configured (`aws configure`)
- Install the SAM CLI: `brew install aws-sam-cli`
- Verify: `sam --version`

### Step 2 — Create the S3 bucket
```bash
aws s3 mb s3://your-audiobook-bucket --region eu-west-1
```
Upload a test master:
```bash
aws s3 cp /path/to/audiobook.mp3 s3://your-audiobook-bucket/masters/test.mp3
```

### Step 3 — Verify your SES sender email
```bash
aws ses verify-email-identity --email-address your@email.com --region eu-west-1
```
Check your inbox and click the verification link.

### Step 4 — Review and complete `template.yaml`
Before deploying, open `template.yaml` and:
1. Uncomment the IAM policy block (S3ReadPolicy, S3WritePolicy, SESCrudPolicy)
2. Add `CodeUri: .` under the function properties
3. Add a `Layers` entry if you need to package numpy/scipy as a Lambda layer
   (they exceed the 50 MB inline limit — see the note below)

**Lambda layer note:** numpy + scipy + soundfile together are ~120 MB. You need
to either:
- Use a public [AWS-managed scientific Python layer](https://github.com/keithrozario/Klayers), or
- Build your own: `pip install -r requirements.txt -t python/ && zip -r layer.zip python/`
  then `aws lambda publish-layer-version ...`

### Step 5 — Build and deploy
```bash
sam build
sam deploy --guided
```
The guided wizard will ask for `BucketName` and `SesFromEmail` parameter values.

### Step 6 — Test the deployed Lambda
```bash
aws lambda invoke \
  --function-name AudioWatermarkFunction \
  --payload '{"s3_key":"masters/test.mp3","user_id":4582,"email":"you@example.com","order_id":"wc-test-001"}' \
  --cli-binary-format raw-in-base64-out \
  response.json
cat response.json
```

Expected: `{"statusCode": 200, "body": "{\"status\": \"ok\", \"watermark_id\": 4582}"}`

### Step 7 — Add API Gateway (Phase 4)
After the Lambda works in isolation, expose it via API Gateway and integrate
with WooCommerce. The `template.yaml` already has the `WatermarkApi` event
scaffold — it activates automatically when you deploy.

---

## Tuning the watermark

| Parameter | Location | Effect |
|-----------|----------|--------|
| `ALPHA` | `src/watermark.py` | Higher = more robust, more audible. Start at 0.025, go up to 0.05 if 64 kbps fails. |
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
