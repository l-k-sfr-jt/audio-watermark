# CLAUDE.md — audio-watermark-lambda

Forensic audio watermarking service: embeds a unique 32-bit order ID into
audiobook WAV/MP3 files using DCT spread-spectrum so leaked copies can be traced
back to the buyer via the WooCommerce order ID.

## Project Structure

```
src/
  handler.py      # Lambda entry point — routes /products/upload-url and /watermark
  watermark.py    # Core DCT algorithm: embed_watermark() / detect_watermark() / embed_mp3() / transcode_to_mp3()
                  #   *** NO boto3/AWS imports allowed here — must stay portable ***
  storage.py      # S3 + CloudFront helpers: download, upload, object_exists, presign PUT,
                  #   CloudFront-signed (or S3-presigned) GET (boto3 + cryptography)
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
correlating. Output of `embed_watermark()` is always WAV (PCM 16-bit).

The handler's mux entry point is **`embed_mp3()`**, which writes the final MP3
directly. For an MP3 master longer than the ~12 s marked head it **stream-copies**:
only the watermarked head is re-encoded (at the source's own bitrate) and the
untouched tail is concatenated with `ffmpeg -c copy`, so the tail stays
bit-identical to the master — zero quality loss, near-instant (a 42-min chapter
processes in ~3 s instead of re-encoding the whole file). It falls back to the
full `embed_watermark()` → `transcode_to_mp3()` (128 kbps) path for non-MP3
masters, very short files, or if the stream-copy mux fails for any reason.

**Memory-bounded embed**: only the first `_REQUIRED` samples (~12 s at 44.1 kHz)
are decoded into numpy. For longer files the untouched tail is stitched back via
ffmpeg concat, so a multi-hour master never loads more than ~4 MB into Python.

**`detect_watermark()` returns `(code: int, confidence: float)`** — a tuple, not
a bare int. `confidence` is `min(|votes|) / max(|votes|)`, a 0-1 score where 1.0
means every bit voted unanimously and values > ~0.1 are reliably correct. Returns
`(-1, 0.0)` when the audio is too short. CLI usage: `python cli.py detect file.mp3`
prints both the code and the confidence score.

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
Request: `{ "master_key": "masters/123/chapter-01.wav", "order_id": 456789, "item_id": 7, "part": "chapter-01" }`
Response: `{ "download_url": "https://d111abcdef8.cloudfront.net/orders/456789/7/chapter-01.mp3?Expires=...&Signature=...&Key-Pair-Id=...", "watermark_code": 456789, "from_cache": false }`

`order_id` (numeric WooCommerce order ID) is embedded as the 32-bit watermark
code. `item_id` (optional, WooCommerce order-item ID) namespaces the stored copy
per line item. `part` (optional, sanitized filename stem — e.g. `"chapter-01"`)
further namespaces per master file within one item, enabling multi-file/chapter
audiobooks. Output key precedence: `orders/<order_id>/<item_id>/<part>.mp3` →
`orders/<order_id>/<item_id>.mp3` → `orders/<order_id>.mp3`. The embedded code
is always `order_id`, so forensic tracing is per-order. If the target copy already
exists in S3 the call just re-signs it (fast path, `from_cache: true`). After the
30-day S3 lifecycle expiry the slow path (re-watermark from master) runs
automatically on the next request. `download_url` is a **CloudFront signed URL**
(1 h expiry) when CloudFront is configured, otherwise an S3 presigned GET. The
attachment filename `<stem>_order<order_id>.mp3` is stored on the object as
`Content-Disposition` at upload time (CloudFront carries no query-param filename),
so it is honoured on both delivery paths.

The WooCommerce order handler and download handler always send the **same**
`item_id` and `part`, so a buyer's "Download" click resolves to exactly the copy
made for that line item's chapter.

## S3 Key Layout

| Prefix          | Content                  | Lifecycle     |
|-----------------|--------------------------|---------------|
| `masters/<product_id>/<file>`               | Product master files (one or more per product) | Permanent |
| `orders/<order_id>/<item_id>/<part>.mp3`    | Buyer copy, per chapter (multi-file product)   | 30-day expiry |
| `orders/<order_id>/<item_id>.mp3`           | Buyer copy, per line item (single-file product) | 30-day expiry |
| `orders/<order_id>.mp3`                     | Buyer copy when no item_id (web console)       | 30-day expiry |

Buyer downloads of `orders/*` are served through **CloudFront** (Origin Access
Control–only bucket policy; the bucket itself blocks all public access). The
`masters/*` prefix is never exposed via CloudFront — admins upload masters with a
presigned S3 PUT only.

## Environment Variables (Lambda)

| Variable               | Description                                                  |
|------------------------|--------------------------------------------------------------|
| `BUCKET_NAME`          | S3 bucket for all audio                                       |
| `CF_DOMAIN`            | CloudFront distribution domain (enables signed-URL delivery)  |
| `CF_KEY_ID`            | CloudFront public-key ID used to verify signed URLs           |
| `CF_PRIVATE_KEY_PARAM` | SSM SecureString path holding the CloudFront signing key      |

When `CF_DOMAIN` / `CF_KEY_ID` / `CF_PRIVATE_KEY_PARAM` are unset, `storage.py`
falls back to S3 presigned GET URLs, so the service still works without
CloudFront (e.g. local/dev). `deploy.sh` auto-creates the RSA key pair in SSM on
first deploy.

## Observability

**CloudWatch metrics** are emitted from `handler.py` via [Embedded Metrics Format](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/CloudWatch_Embedded_Metric_Format.html) (structured JSON written to Lambda stdout — no `PutMetricData` calls, no extra cost):

| Metric | Namespace | Unit | When |
|--------|-----------|------|------|
| `CacheHit` | `AudioWM` | Count | Fast path (buyer copy already in S3) |
| `CacheMiss` | `AudioWM` | Count | Slow path (new embed + transcode) |
| `EmbedDuration` | `AudioWM` | Milliseconds | Slow path only |

Dimensions: `{"Service": "audio-watermark"}`. All log entries are structured JSON queryable in CloudWatch Logs Insights.

**X-Ray tracing**: `Tracing: Active` in `template.yaml` — SAM attaches the X-Ray daemon automatically. Free tier: 100k traces/month.

**WooCommerce order notes**: `class-order-handler.php` adds an order note on every watermark attempt (success / each retry / exhausted). Staff can see watermark status directly in WC Admin → Orders without checking CloudWatch.

## Architectural Rules

1. `watermark.py` must never import `boto3` or any AWS SDK — it must run
   identically in Lambda and the local CLI without AWS credentials.
2. All S3 calls live exclusively in `storage.py`.
3. The Lambda is packaged as a **container image** (`Dockerfile`), not zip —
   ffmpeg and scipy are bundled. Deploy via `scripts/deploy.sh`.
4. Buyer download URLs are **CloudFront signed URLs** (1 h expiry): the viewer
   request is verified at the edge against a trusted key group, and CloudFront
   reaches the private S3 origin via Origin Access Control. The signing private
   key lives in SSM (SecureString) and is read+cached by `storage.py`. When
   CloudFront env vars are absent, `storage.py` falls back to S3 presigned GETs
   signed with the Lambda execution role. Either way the URL is re-minted on
   every download, so it is always fresh well before any session expiry.
5. SES is not used — WordPress handles all customer email.

## WooCommerce Plugin (wordpress/audio-watermark-woo/)

Admin product panel:
- Enable watermarking checkbox + multi-file master list + "Add master audio files" button (multiple file input; uploads all selected files sequentially)
- Upload flow: AJAX → /products/upload-url → browser PUT to presigned URL → append s3_key to _audio_wm_s3_keys JSON array
- Non-audio products (PDFs, physical goods): leave checkbox unchecked; order/download handlers ignore them automatically

Order processing (woocommerce_order_status_processing AND _completed):
- Watermarking fires on BOTH processing and completed (digital goods often stop
  at "processing" and may never be marked completed). The per-key idempotency
  guard makes the duplicate event a no-op.
- For each watermark-enabled line item and each master key in _audio_wm_s3_keys:
  POST /watermark { master_key, order_id, item_id[, part] };
  append the master key to that item's _audio_wm_master_keys JSON array and set
  _watermark_code on the order
- A delivery email (Audio_WM_Email_Download) is sent at most once automatically
  per order (guarded by _audio_wm_email_sent), so processing→completed does not
  double-email.

Customer download (email + thank-you page + My Account):
- Email links and the order-received/thank-you page work for guests (no login
  required); the logged-in My Account order page also shows the buttons.
- One "Download: <product> [— <part>]" button per watermarked master file per item.
- Auth: logged-in My Account uses a WP nonce; guest (email + thank-you) links use
  an HMAC signature over the order key ($order->get_order_key(), verified with
  hash_equals) — never a nonce (won't survive email) and never login. The raw
  order key never appears in a URL, only the signature.
- Download links expire after 30 days (matches the S3 ExpireBuyerCopies lifecycle);
  an expired link shows a "link expired" page with a self-service "request a new
  link" button. Resend is throttled to once per hour per order
  (_audio_wm_last_resend) and only emails the address stored on the order.
- Master-key resolution falls back: item _audio_wm_master_keys → legacy item key →
  product _audio_wm_s3_keys → legacy product key, so a link mints the file on
  demand (idempotent /watermark) even before process_order populated item meta.
- Click → AJAX endpoint (priv + nopriv) → POST /watermark { master_key, order_id, item_id[, part] } (idempotent, fresh URL) → 302 redirect
- Browser saves as `<stem>_order<order_id>.mp3` (via Content-Disposition)

Forensic lookup: order_id (the watermark code) → look up WooCommerce order → buyer info
