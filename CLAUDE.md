# CLAUDE.md — audio-watermark-lambda

Forensic audio watermarking service: embeds a unique 32-bit order ID into
audiobook WAV/MP3 files using DCT spread-spectrum so leaked copies can be traced
back to the buyer via the WooCommerce order ID.

## Project Structure

```
src/
  handler.py      # Lambda entry point — routes /products/upload-url and /watermark
  watermark.py    # Core DCT algorithm: embed_watermark() / detect_watermark() / transcode_to_mp3()
                  #   *** NO boto3/AWS imports allowed here — must stay portable ***
  storage.py      # S3 helpers: download, upload, presign GET/PUT, object_exists (boto3)
tests/
  test_watermark.py   # Unit tests: embed/detect roundtrip + MP3 robustness
  sample_audio/       # Short test .mp3 / .wav fixtures
cli.py            # Local CLI — runs embed/detect/roundtrip without AWS
requirements.txt  # numpy, scipy, soundfile, pydub, boto3, pytest
Dockerfile        # Container-image Lambda — bundles ffmpeg + scientific stack
template.yaml     # SAM template — provisions S3 + Lambda + API (no SES)
samconfig.toml    # SAM deploy defaults (region eu-central-1)
scripts/          # check-prereqs / deploy / smoke-test / teardown
docs/DEPLOYMENT.md # Zero-to-deployed AWS guide
web/              # Next.js test console (upload master → watermark → play MP3 → detect)
wordpress/
  audio-watermark-woo/   # WooCommerce plugin (Phase 5)
README.md
```

## Algorithm Constants (watermark.py)

| Constant      | Value | Purpose                                           |
|---------------|-------|---------------------------------------------------|
| `BLOCK_SIZE`  | 2048  | Samples per DCT block                             |
| `NUM_BLOCKS`  | 256   | Blocks embedded (~12 s at 44.1 kHz)               |
| `FREQ_LOW`    | 150   | First DCT index in embedding band                 |
| `FREQ_HIGH`   | 500   | Last DCT index in embedding band (mid-freq)       |
| `REPETITIONS` | 8     | Bit repetition factor; majority vote on detection |
| `ALPHA`       | 0.05  | Embedding strength: mark amplitude as a fraction of the per-coefficient masking envelope |
| `SPREAD_BINS` | 11    | Frequency-masking spread (each side); ~120 Hz at 44.1 kHz |
| `SILENCE_FLOOR` | 0.001 | Envelope floor so deep gaps / silence still carry a faint, detectable mark |

The watermark encodes a 32-bit integer across `NUM_BLOCKS` DCT blocks, each
`BLOCK_SIZE` samples. Each bit's 8 repetitions are **interleaved** across the
whole window (block `i` carries bit `i % 32`), so a quiet intro can't wipe out
any single bit. Embedding is **perceptual**: a masking envelope is built per
block by spreading the host's band magnitude across `±SPREAD_BINS` neighbours,
and the mark is scaled to it (`ALPHA × envelope`). Detection **whitens** before
correlating. Output of `embed_watermark()` is always WAV (PCM 16-bit); the
handler calls `transcode_to_mp3()` to convert the result to MP3 128 kbps.

**Memory-bounded embed**: only the first `_REQUIRED` samples (~12 s at 44.1 kHz)
are decoded into numpy. For longer files the untouched tail is stitched back via
ffmpeg concat, so a multi-hour master never loads more than ~4 MB into Python.

## Development Commands

```bash
# Install dependencies
pip install -r requirements.txt

# CLI usage (no AWS required)
python cli.py embed input.mp3 12345 output.wav
python cli.py detect output.wav
python cli.py roundtrip-test input.mp3 12345   # embed → ffmpeg MP3 @ 64 & 128 kbps → detect

# Run tests
pytest tests/ -v
```

The `roundtrip-test` command and the MP3 robustness unit test both require
`ffmpeg` on `PATH`. If absent they skip gracefully with a clear message.

## API Endpoints (both behind x-api-key)

### POST /products/upload-url
Request: `{ "product_id": "123", "filename": "audiobook.wav", "content_type": "audio/wav" }`
Response: `{ "upload_url": "https://s3.amazonaws.com/presigned-put...", "s3_key": "masters/123/audiobook.wav" }`

The upload_url is a presigned S3 PUT valid for 15 min. The browser/client PUTs
the file directly to S3 — no AWS keys in WordPress.

### POST /watermark (idempotent)
Request: `{ "master_key": "masters/123/audiobook.wav", "order_id": 456789 }`
Response: `{ "download_url": "https://s3.amazonaws.com/presigned-get...", "watermark_code": 456789, "from_cache": false }`

`order_id` (numeric WooCommerce order ID) is embedded as the 32-bit watermark
code. If `orders/<order_id>.mp3` already exists in S3, the call just presigns it
(fast path, `from_cache: true`). After the 30-day S3 lifecycle expiry the slow
path (re-watermark from master) runs automatically on the next request.

## S3 Key Layout

| Prefix          | Content                  | Lifecycle     |
|-----------------|--------------------------|---------------|
| `masters/`      | Product master files     | Permanent     |
| `orders/`       | Buyer watermarked copies | 30-day expiry |

## Environment Variables (Lambda)

| Variable      | Description                           |
|---------------|---------------------------------------|
| `BUCKET_NAME` | S3 bucket for all audio               |

## Architectural Rules

1. `watermark.py` must never import `boto3` or any AWS SDK — it must run
   identically in Lambda and the local CLI without AWS credentials.
2. All S3 calls live exclusively in `storage.py`.
3. The Lambda is packaged as a **container image** (`Dockerfile`), not zip —
   ffmpeg and scipy are bundled. Deploy via `scripts/deploy.sh`.
4. Presigned GET URLs use the Lambda execution role (1 h expiry). Since buyer
   copies are re-minted on every download, they are always fresh before the
   STS session (≤12 h) could expire.
5. SES is not used — WordPress handles all customer email.

## WooCommerce Plugin (wordpress/audio-watermark-woo/)

Admin product panel:
- Enable watermarking checkbox + S3 key field + "Upload master" button
- Upload flow: AJAX → /products/upload-url → browser PUT to presigned URL → save s3_key to product meta

Order processing (woocommerce_order_status_completed):
- POST /watermark { master_key, order_id }; save _watermark_code to order meta

Customer download (My Account):
- "Download Audiobook" link → AJAX endpoint → POST /watermark (idempotent, fresh URL) → 302 redirect

Forensic lookup: order_id (the watermark code) → look up WooCommerce order → buyer info
