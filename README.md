# audio-watermark-lambda

Forensic audio watermarking service for an audiobook shop. It embeds a unique
32-bit **WooCommerce order ID** into each buyer's copy using DCT spread-spectrum,
so a leaked file can be traced back to the buyer. Detection recovers the order
ID from any copy — even after MP3 re-encoding down to 64 kbps.

**Current phase:** Phase 5 — deployable AWS service (S3 + Lambda + API Gateway,
behind an API key) plus a WooCommerce plugin that uploads masters, watermarks on
order **processing/completion**, emails guests their download links, and serves
self-renewing buyer downloads. No SES — WordPress sends all customer email.

**[docs/SETUP.md](docs/SETUP.md)** is the end-to-end setup guide: AWS deploy →
plugin install → product configuration → first test order → day-to-day ops.
For deeper reading see **[docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)** (components,
API contract, data model, security) and **[docs/USE-CASES.md](docs/USE-CASES.md)**
(end-to-end flows + known limitations).

---

## How it works (one paragraph)

The admin uploads one or more audiobook master files from the WooCommerce product
screen (chapters, parts, CDs — select one or more files per upload); each browser
PUTs directly to S3 under `masters/` via a presigned URL (no AWS keys in
WordPress). Non-audio products (PDFs, physical goods) are automatically ignored.
When an order reaches **processing or completed**, the plugin calls the service
once per master file per eligible line item, embedding the order ID in each part
and storing the buyer copies under `orders/<order_id>/<item_id>/` (or
`/<item_id>.mp3` for single-file products). The buyer — even a guest with no WP
login — gets a delivery email with one download link per part plus a "request a
new link" link, and the same buttons on the order-received/thank-you page (logged-
in buyers also see them in My Account). Each click mints a fresh 1-hour CloudFront
signed URL (S3 presigned GET as a fallback when CloudFront isn't configured).
Email/thank-you links are signed with an order-key HMAC and expire after 30
days, matching the S3 lifecycle; an expired link self-serves a fresh one (resend
throttled to once per hour). Stored copies auto-expire after 30 days and are
re-created transparently from the master on the next download. To trace a leak,
run `cli.py detect` on the file; the recovered code is the order ID.

```
ADMIN ── upload masters (1–N) ──▶ S3 masters/<product_id>/<file>
ORDER processing/completed ── POST /watermark (per part) ──▶ embed order_id → MP3 → S3 orders/<order_id>/<item_id>/<part>.mp3
BUYER ── email link / thank-you page / My Account (one per part) ──▶ fresh CloudFront signed URL (S3 presigned fallback)
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
  watermark.py  # DCT algorithm: embed/detect + embed_mp3 stream-copy mux (NO AWS imports)
  storage.py    # S3/CloudFront helpers: download/upload/object_exists, signed-URL GET (boto3)
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
| `POST /watermark` | Idempotent: watermark one master for an order ID and return a CloudFront signed URL (1 h; S3 presigned fallback). Accepts optional `item_id` and `part` for per-chapter multi-file delivery. Serves both first purchase and re-download. |

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
settings tab (API URL + key), a product panel (enable checkbox + multi-file
master upload), watermarking on order processing/completion, a guest delivery
email (configurable under WooCommerce → Settings → Emails) with expiring signed
download links, and per-item self-renewing download buttons on the thank-you page
and the customer's order page. See [docs/USE-CASES.md](docs/USE-CASES.md) for the
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
