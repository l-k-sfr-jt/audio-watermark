# audio-watermark-lambda

Forensic audio watermarking service for an audiobook shop. It embeds a unique
32-bit **WooCommerce order ID** into each buyer's copy using DCT spread-spectrum,
so a leaked file can be traced back to the buyer. Detection recovers the order
ID from any copy — even after MP3 re-encoding down to 64 kbps.

**Current phase:** Phase 5 — deployable AWS service (S3 + Lambda + API Gateway,
behind an API key) plus a WooCommerce plugin that uploads masters, watermarks on
order completion, and serves self-renewing buyer downloads. No SES — WordPress
handles all customer email.

For a full picture see **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** (components,
API contract, data model, security) and **[docs/USE-CASES.md](docs/USE-CASES.md)**
(end-to-end flows + known limitations).

---

## How it works (one paragraph)

The admin uploads an audiobook master from the WooCommerce product screen; the
browser PUTs it straight to S3 under `masters/` via a presigned URL (no AWS keys
in WordPress). When an order completes, the plugin calls the service, which
copies the master, embeds the order ID, transcodes to MP3 128 kbps, and stores
the buyer copy at `orders/<order_id>.mp3`. Buyer downloads are minted fresh on
every click (1-hour presigned URL), and the stored copy auto-expires after 30
days — re-created on demand from the master if the buyer returns. To trace a
leak, run `cli.py detect` on the file; the recovered code is the order ID.

```
ADMIN ── upload master ──▶ S3 masters/<product_id>/<file>
ORDER completed ── POST /watermark ──▶ embed order_id → MP3 → S3 orders/<order_id>.mp3
BUYER ── download ──▶ fresh presigned GET (re-generated after 30-day expiry)
LEAK ── cli.py detect ──▶ order_id ──▶ WooCommerce order ──▶ buyer
```

---

## Local development (no AWS required)

### 1. Prerequisites

- **Python 3.11+**
- **ffmpeg** on `PATH` (required for MP3 decode/transcode and the roundtrip test)

```bash
brew install python@3.11 ffmpeg     # macOS
```

### 2. Set up

```bash
git clone https://github.com/l-k-sfr-jt/audio-watermark.git
cd audio-watermark
python3.11 -m venv .venv && source .venv/bin/activate
pip install -r requirements.txt
```

### 3. Run the tests

```bash
pytest tests/ -v
```

The MP3 robustness tests skip gracefully if ffmpeg is not installed.

### 4. Use the CLI

```bash
python cli.py embed input.mp3 12345 output.wav    # embed code 12345
python cli.py detect output.wav                   # → Detected user_id: 12345
python cli.py roundtrip-test input.mp3 12345      # embed → 64 & 128 kbps MP3 → detect
```

`detect` is also the forensic step: run it on a recovered leaked file to recover
the order ID.

---

## Project structure

```
src/
  handler.py    # Lambda entry — routes /products/upload-url and /watermark
  watermark.py  # DCT algorithm: embed/detect + transcode_to_mp3 (NO AWS imports)
  storage.py    # S3 helpers: download/upload/object_exists/presign (boto3)
tests/
  test_watermark.py   # embed/detect roundtrip + MP3 robustness
  sample_audio/       # short test fixtures
cli.py          # local embed / detect / roundtrip-test (no AWS)
requirements.txt
Dockerfile      # container-image Lambda (bundles ffmpeg + scientific stack)
template.yaml   # SAM: S3 + Lambda + API Gateway + API key + usage plan
samconfig.toml  # SAM deploy defaults (region eu-central-1)
scripts/        # check-prereqs / deploy / smoke-test / teardown
docs/
  ARCHITECTURE.md   # components, API, data model, security
  USE-CASES.md      # flows + known limitations
  DEPLOYMENT.md     # zero-to-deployed AWS guide
web/            # Next.js test console (upload → watermark → play → detect)
wordpress/
  audio-watermark-woo/   # WooCommerce plugin
```

---

## API endpoints (both behind `x-api-key`)

| Endpoint | Purpose |
|----------|---------|
| `POST /products/upload-url` | Returns a presigned S3 PUT (15 min) so a browser uploads a master directly. |
| `POST /watermark` | Idempotent: watermark a master for an order ID and return a presigned GET (1 h). Serves both first purchase and re-download. |

See [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for the full request/response
contract and validation rules.

---

## Environment variables (Lambda)

| Variable      | Description             |
|---------------|-------------------------|
| `BUCKET_NAME` | S3 bucket for all audio |

---

## AWS deployment

Full zero-to-deployed instructions (including creating the AWS account) are in
**[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md)**. Quick path once you have an AWS
account and `aws`/`sam`/Docker installed:

```bash
./scripts/check-prereqs.sh                          # verify tooling + creds
./scripts/deploy.sh                                 # build image + deploy stack
./scripts/smoke-test.sh path/to/audiobook.wav 123   # upload → watermark → download → idempotency → 403 check
./scripts/teardown.sh                               # remove everything when done
```

`deploy.sh` prints the **API base URL** and **API key** to paste into the
WooCommerce plugin (WooCommerce → Settings → Audiobook WM). The Lambda is a
**container image** so ffmpeg and the scientific stack are bundled. Region
defaults to **eu-central-1**.

---

## WooCommerce plugin

In `wordpress/audio-watermark-woo/`. Declares HPOS compatibility; provides a
settings tab (API URL + key), a product panel (enable checkbox + master upload),
order-completion watermarking, and per-item self-renewing download buttons on
the customer's order page. See [docs/USE-CASES.md](docs/USE-CASES.md) for the
end-to-end flows.

---

## Tuning the watermark

| Parameter | Location | Effect |
|-----------|----------|--------|
| `ALPHA` | `src/watermark.py` | Embedding strength — the mark is `ALPHA ×` a per-frequency masking envelope. Higher = more robust, more audible. Start 0.05; raise toward 0.1 if 64 kbps detection fails, lower toward 0.03 if audible. |
| `SPREAD_BINS` | `src/watermark.py` | How far the masking envelope spreads around each tone (~120 Hz at 11). Smaller hugs tones tighter (quieter) but gives detection less margin. |
| `SILENCE_FLOOR` | `src/watermark.py` | Minimum mark level in deep gaps / silence. Lower if you hear faint noise in quiet passages; raise if detection is shaky on very quiet audio. |
| `NUM_BLOCKS` | `src/watermark.py` | More blocks = stronger mark but longer processing region. 256 ≈ 12 s at 44.1 kHz. |
| `FREQ_LOW/HIGH` | `src/watermark.py` | Mid-frequency band. Avoid below 100 (speech fundamentals) or above 600 (MP3 strips highs at low bitrate). |

---

## Troubleshooting

**`SoundFileError` loading MP3 locally** — libsndfile lacks MP3 support; the code
falls back to ffmpeg automatically, so just ensure ffmpeg is installed.

**`ModuleNotFoundError: No module named 'src'`** — run commands from the repo
root, not inside `src/`.

**`/watermark` returns 404 "Master not found in S3"** — the `master_key` doesn't
exist under `masters/`. Confirm the upload succeeded and the key matches exactly.

**Detection returns the wrong code on a real audiobook** — the audio may have
very low energy in the embedding band. Increase `ALPHA` and re-embed; confirm
with `python cli.py roundtrip-test`.
