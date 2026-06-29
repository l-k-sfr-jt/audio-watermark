# Architecture

Forensic audio-watermarking pipeline for a WooCommerce audiobook shop. This
document describes **what the code actually does today** — every claim below is
traceable to a file/line in the repo. Where the implementation has a gap or a
known limitation it is called out explicitly rather than glossed over.

> Source of truth: `src/handler.py`, `src/watermark.py`, `src/storage.py`,
> `template.yaml`, and `wordpress/audio-watermark-woo/`. If this doc and the
> code disagree, the code wins — please fix the doc.

---

## 1. Components

```mermaid
flowchart TB
    subgraph WP["WordPress / WooCommerce site"]
        SET["Audio_WM_Settings\n(WC Settings → Audiobook WM)\napi_url + api_key"]
        PP["Audio_WM_Product_Panel\nproduct edit screen + upload AJAX"]
        OH["Audio_WM_Order_Handler\non order completed"]
        DH["Audio_WM_Download_Handler\nMy Account + thank-you buttons + AJAX"]
        EM["Audio_WM_Email_Download\nguest delivery email (signed links)"]
        JS["assets/admin.js\nbrowser upload driver"]
    end

    subgraph AWS["AWS (SAM stack: template.yaml)"]
        APIGW["API Gateway\nx-api-key + usage plan"]
        L["Lambda: AudioWatermarkFunction\nsrc/handler.py (container image)"]
        S3[("S3 bucket\nmasters/  +  orders/")]
    end

    ADMIN(["Shop admin"])
    BUYER(["Buyer / customer"])
    BROWSER(["Browser"])

    ADMIN --> SET
    ADMIN --> PP
    PP -->|"wp_ajax: get presigned PUT"| APIGW
    JS -->|"PUT file (presigned)"| S3
    BUYER --> DH
    EM -->|"signed download links"| BUYER
    BUYER -->|"click email/thank-you link"| DH
    OH -->|"POST /watermark"| APIGW
    DH -->|"POST /watermark"| APIGW
    APIGW --> L
    L --> S3
    L -->|"CloudFront signed URL"| APIGW
    BROWSER -->|"302 → signed URL"| CF
    CF -->|"OAC (SigV4) origin fetch"| S3
```

(`CF` = CloudFront distribution fronting `orders/*`. When CloudFront is not
configured the Lambda returns an S3 presigned GET instead and the browser is
redirected straight to S3.)

| Component | File | Responsibility |
|-----------|------|----------------|
| Watermark core | `src/watermark.py` | DCT spread-spectrum embed/detect; `embed_mp3` stream-copy mux + MP3 transcode fallback. **No AWS imports.** |
| S3 / CloudFront helpers | `src/storage.py` | download, upload, `object_exists`, presigned PUT; CloudFront-signed (or S3-presigned) GET — reads the signing key from SSM. **Only file with boto3/cryptography.** |
| Lambda router | `src/handler.py` | Routes `/products/upload-url` and `/watermark`; input validation; idempotency. |
| Local CLI | `cli.py` | `embed` / `detect` / `roundtrip-test` without AWS (used for forensic detection). |
| Infra | `template.yaml` | S3 bucket, Lambda, API Gateway, API key, usage plan. |
| WC settings | `wordpress/.../class-settings.php` | Stores `audio_wm_api_url`, `audio_wm_api_key`. |
| WC product panel | `wordpress/.../class-product-panel.php` | Enable checkbox, S3 key field, upload button + AJAX proxy. |
| WC order handler | `wordpress/.../class-order-handler.php` | On `woocommerce_order_status_processing` **and** `_completed`, watermark each enabled item. |
| WC download handler | `wordpress/.../class-download-handler.php` | Download buttons (My Account nonce + thank-you/email signed token) + AJAX redirect; order-key HMAC verify; product-key fallback. |
| WC delivery email | `wordpress/.../includes/class-email-download.php` | `Audio_WM_Email_Download` WC email class — guest delivery email with per-part download links + a "request a new link" link. Templates in `templates/emails/`. |
| Upload JS | `wordpress/.../assets/admin.js` | Drives browser → presigned-PUT upload (multi-file: uploads all selected files sequentially). |

---

## 2. HTTP API (both endpoints behind `x-api-key`)

Defined in `src/handler.py`; auth + throttling in `template.yaml`.

### `POST /products/upload-url`
Mint a presigned S3 PUT so a browser can upload a master directly (no file data
through WordPress, no AWS keys in WordPress).

Request:
```json
{ "product_id": "123", "filename": "audiobook.wav", "content_type": "audio/wav" }
```
Response:
```json
{ "upload_url": "https://s3...presigned-put", "s3_key": "masters/123/audiobook.wav" }
```

Validation (`route_upload_url`):
- `product_id` must match `^[A-Za-z0-9_\-]+$`.
- `filename` required; sanitized to `[A-Za-z0-9._-]` via `_safe_filename`.
- `content_type` must be in the audio allowlist (`_AUDIO_CONTENT_TYPES`), else `400`.
- Presigned PUT expiry: **900 s (15 min)**.

### `POST /watermark` (idempotent)
Request:
```json
{ "master_key": "masters/123/chapter-01.wav", "order_id": 456789, "item_id": 7, "part": "chapter-01" }
```
`item_id` and `part` are optional (see output key table below).

Response:
```json
{ "download_url": "https://d111abcdef8.cloudfront.net/orders/456789/7/chapter-01.mp3?Expires=…&Signature=…&Key-Pair-Id=…", "watermark_code": 456789, "from_cache": false }
```

The `download_url` is a **CloudFront signed URL** (or an S3 presigned GET when
CloudFront isn't configured). The `Content-Disposition: attachment; filename="<stem>_order<order_id>.mp3"`
that makes the browser save a meaningful filename is stored on the S3 object at
upload time, so it applies on both delivery paths.

Validation (`route_watermark`):
- `master_key` required, **must start with `masters/`**, must not contain `..` segments.
- `order_id` must be an integer `1 … 2^32-1` (also the embedded 32-bit code).
- `item_id` **optional**; if present must be a positive integer (WooCommerce
  order-item ID). It namespaces the stored copy — it is **not** part of the
  embedded code.
- `part` **optional**; sanitized filename stem (e.g. `"chapter-01"`); only valid
  when `item_id` is also present; must match `^[A-Za-z0-9._\-]+$`, max 128 chars.
- Download URL expiry: **3600 s (1 h)**. CloudFront signed URLs are signed with
  the RSA private key in SSM (`CF_PRIVATE_KEY_PARAM`); the S3-presigned fallback
  is signed with the Lambda execution role.

Output key (precedence):
| Inputs | Output key |
|--------|-----------|
| `order_id` + `item_id` + `part` | `orders/<order_id>/<item_id>/<part>.mp3` |
| `order_id` + `item_id` | `orders/<order_id>/<item_id>.mp3` |
| `order_id` only | `orders/<order_id>.mp3` (single-item / web-console path) |

Behaviour:
- **Fast path** — if the target copy already exists (`object_exists`), return a
  fresh signed URL with `from_cache: true`. No re-embedding.
- **Slow path** — download master → `embed_mp3(order_id)` → upload to the output
  key (with the `Content-Disposition` filename) → sign, `from_cache: false`.
  `embed_mp3` **stream-copies** an MP3 master: it re-encodes only the ~12 s
  watermarked head and concatenates the original tail with `ffmpeg -c copy`
  (bit-identical, no quality loss). It falls back to
  `embed_watermark` → `transcode_to_mp3` (128 kbps) for non-MP3 / very short
  masters, or if the stream-copy mux fails.

> The embedded watermark code is always `order_id`, independent of `item_id` and
> `part`, so forensic tracing remains per-order (the buyer). Per-item and per-part
> keying only fixes *delivery* so each title/chapter is served correctly.

---

## 3. Data model

### S3 layout (`template.yaml`)
| Prefix | Content | Lifecycle |
|--------|---------|-----------|
| `masters/<product_id>/<filename>` | Product master files (one or more per product) | Permanent (no rule) |
| `orders/<order_id>/<item_id>/<part>.mp3` | Buyer copy, per chapter/part (multi-file product) | 30-day expiry; non-current versions purged after 1 day |
| `orders/<order_id>/<item_id>.mp3` | Buyer copy, per line item (single-file product) | 30-day expiry |
| `orders/<order_id>.mp3` | Buyer copy when no `item_id` is sent (single-item / web console) | 30-day expiry |

Bucket hardening: all public access blocked, SSE-AES256 default encryption,
versioning enabled. CORS allows PUT from the configured WordPress domain so
the browser can upload masters directly via presigned URL without routing
the binary through WordPress.

### WordPress metadata
| Storage | Key | Set by | Meaning |
|---------|-----|--------|---------|
| `wp_options` | `audio_wm_api_url` | Settings page | API base URL |
| `wp_options` | `audio_wm_api_key` | Settings page | `x-api-key` secret |
| Product (post) meta | `_audio_wm_enabled` | Product panel | `'yes'`/`'no'` |
| Product (post) meta | `_audio_wm_s3_keys` | Product panel (after upload) | JSON array of master S3 keys; falls back to `_audio_wm_s3_key` (legacy single string) |
| Product (post) meta | `_audio_wm_s3_key` | Legacy (kept for back-compat reads) | Original single master key |
| **Order item** meta | `_audio_wm_master_keys` | Order handler | JSON array of master keys successfully watermarked for this item; falls back to `_audio_wm_master_key` (legacy) |
| **Order item** meta | `_audio_wm_master_key` | Legacy (kept for back-compat reads) | Original single watermarked master key |
| Order meta | `_watermark_code` | Order handler | `= order_id`; flags "≥1 item watermarked" |
| Order meta | `_audio_wm_email_sent` | Email class | Timestamp of the automatic delivery email; guards against double-emailing on processing→completed |
| Order meta | `_audio_wm_last_resend` | Resend handler | Timestamp of the last resend; throttles re-requests to once per hour |

> **Mixed catalog:** Products without `_audio_wm_enabled=yes` (e.g. PDF books,
> physical goods) are skipped transparently by the order handler and download
> handler — no configuration or filtering needed.

> **Multi-file:** One product can have N master files (chapters/parts). Each is
> watermarked separately and delivered as its own download button. The `part`
> value (sanitized filename stem of the master) distinguishes per-chapter copies
> within one item's S3 prefix. Per-item meta uses a JSON array so the order handler
> can retry individual missing parts without re-watermarking already-done ones.

> **Product-key fallback (on-demand minting):** the download handler and button
> renderers resolve master keys with the precedence item `_audio_wm_master_keys`
> → legacy item `_audio_wm_master_key` → product `_audio_wm_s3_keys` → legacy
> product `_audio_wm_s3_key`. Because `/watermark` is idempotent, a link works and
> mints the file on demand even before `process_order` has populated item meta —
> so there is no "is being prepared" pending state.

---

## 4. WordPress hooks used

| Hook | Handler | Purpose |
|------|---------|---------|
| `before_woocommerce_init` | bootstrap closure | Declare HPOS (`custom_order_tables`) compatibility |
| `plugins_loaded` | bootstrap closure | Require classes once WooCommerce is confirmed active |
| `woocommerce_settings_tabs_array` / `_settings_audio_wm` / `_settings_save_audio_wm` | `Audio_WM_Settings` | Settings tab render + save |
| `woocommerce_product_options_general_product_data` | `Product_Panel::add_fields` | Product fields |
| `woocommerce_process_product_meta` | `Product_Panel::save_fields` | Persist product fields |
| `admin_enqueue_scripts` | `Product_Panel::enqueue_scripts` | Load `admin.js` on product screens only |
| `wp_ajax_audio_wm_get_upload_url` | `Product_Panel::ajax_get_upload_url` | Proxy presigned-PUT request (key stays server-side) |
| `woocommerce_order_status_processing` | `Order_Handler::process_order` | Watermark all enabled items (digital goods often stop at "processing") |
| `woocommerce_order_status_completed` | `Order_Handler::process_order` | Watermark all enabled items (idempotent — no-op if processing already ran) |
| `woocommerce_email_classes` | bootstrap / plugin file | Register `Audio_WM_Email_Download` so it appears under WC → Settings → Emails |
| `woocommerce_order_details_after_order_table` | `Download_Handler::add_download_buttons` | Render nonce-authenticated download buttons on the logged-in My Account order page |
| `woocommerce_thankyou` | `Download_Handler::render_thankyou_downloads` | Render signed-token download buttons on the order-received page (guest-capable) |
| `wp_ajax_audio_wm_download` / `wp_ajax_nopriv_audio_wm_download` | `Download_Handler::handle_download` | Redirect to fresh presigned GET; logged-in nonce **or** guest signed token |
| `wp_ajax_audio_wm_resend` / `wp_ajax_nopriv_audio_wm_resend` | `Download_Handler` resend handler | Verify resend token, throttle, re-send the delivery email |

> The logged-in My Account button path (`add_download_buttons`) returns early when
> `get_current_user_id()` doesn't own the order, so nothing leaks into WooCommerce
> HTML order emails. Guest delivery instead uses the dedicated
> `Audio_WM_Email_Download` class with signed-token links, and the thank-you page
> renders the same signed-token buttons.

---

## 5. Security model

- **Transport auth:** API Gateway enforces `x-api-key` on both routes before the
  Lambda runs; bad requests cost nothing.
- **Throttling:** usage plan — burst 10, rate 5/s, quota 10 000/day — caps a
  leaked key.
- **Path confinement:** `/watermark` rejects any `master_key` not under
  `masters/`, so a caller cannot read `orders/` copies or arbitrary keys.
- **Upload AJAX:** nonce (`audio_wm_upload_nonce`) + `current_user_can('edit_products')`.
- **Download AJAX (two auth models):**
  - *Logged-in My Account:* per-order nonce + buyer-ownership check.
  - *Guest (thank-you page + email):* an HMAC signature over the WooCommerce
    **order key** (`$order->get_order_key()`) — chosen because WP nonces don't
    survive in email and the shop checks customers out as guests (no login). The
    raw order key never appears in any URL; only the signature does, and it is
    verified with `hash_equals()`.
    - Download links sign `"dl|{order_id}|{item_id}|{part}|{expires}"` and
      **expire after 30 days** (`LINK_TTL = 30 * DAY_IN_SECONDS`, matching the S3
      `ExpireBuyerCopies` lifecycle); an expired link shows a "link expired" page
      with a resend button.
    - Resend links sign `"resend|{order_id}"` (durable — no expiry) and are
      **throttled to once per hour per order** (`RESEND_THROTTLE = HOUR_IN_SECONDS`,
      tracked in `_audio_wm_last_resend`); resend only ever emails the address on
      the order.
  - Both models also enforce the item-belongs-to-order check
    (`$order->get_item($item_id)`) + open-redirect guard (URL host must end with
    `.amazonaws.com` or `.cloudfront.net` and scheme `https`).
- **Least-privilege IAM:** Lambda gets only `s3:GetObject`/`PutObject` on
  `masters/*` + `orders/*`, `s3:HeadObject` on `orders/*`, and `ssm:GetParameter`
  on the CloudFront signing key — no delete, no list. Buyer downloads of
  `orders/*` reach S3 only through CloudFront Origin Access Control (the bucket
  blocks all public access).
- **No long-lived AWS keys in WordPress:** browser PUTs via presigned URL; the
  WP server only ever holds the API key.
- **No SES:** all customer email is handled by WordPress.

---

## 6. Memory-bounded embedding

`embed_watermark` only decodes the first `_REQUIRED = BLOCK_SIZE × NUM_BLOCKS`
samples (~12 s at 44.1 kHz) into numpy via `_load_head` (soundfile partial read
for WAV/FLAC, ffmpeg pipe for MP3). For longer files the untouched tail is
stitched back with an ffmpeg `concat` filter (`_stitch_with_tail`), so a
multi-hour master never loads more than a few MB into Python. Detection
(`detect_watermark`) likewise reads only the opening ~12 s.

**Consequence (documented honestly):** the mark lives only in the first ~12 s,
so trimming the opening removes it. See `docs/USE-CASES.md` → Known limitations.

---

## 7. Observability

### CloudWatch metrics (Embedded Metrics Format)
`handler.py` writes [EMF](https://docs.aws.amazon.com/AmazonCloudWatch/latest/monitoring/CloudWatch_Embedded_Metric_Format.html)
JSON to stdout on every `/watermark` call. CloudWatch ingests it as real metrics
with no extra cost (no `PutMetricData` calls):

| Metric | Unit | When emitted |
|--------|------|--------------|
| `CacheHit` | Count | Fast path — buyer copy already in S3 |
| `CacheMiss` | Count | Slow path — new embed + transcode |
| `EmbedDuration` | Milliseconds | Slow path only (embedding time) |

Namespace: `AudioWM`. Dimension: `Service = audio-watermark`.
All `logger.info` entries are structured JSON, so CloudWatch Logs Insights can
query on `event`, `order_id`, `embed_ms`, etc.

### X-Ray tracing
`Tracing: Active` in `template.yaml`. SAM attaches the X-Ray daemon automatically.
Traces API Gateway → Lambda → S3 calls. Free tier: 100k traces/month.

### WooCommerce order notes
`class-order-handler.php` adds a note to every order on each watermark attempt
(success, retry, and final failure). Staff see watermark status directly in
WC Admin → Orders without needing AWS console access.

### `detect_watermark` confidence score
`watermark.py::detect_watermark()` returns `(code: int, confidence: float)`.
Confidence = `min(|votes|) / max(|votes|)` — a 0-1 score computed from the
per-bit DCT correlation margins at zero extra cost. Values > ~0.1 are reliably
correct; the CLI prints it alongside the code.
